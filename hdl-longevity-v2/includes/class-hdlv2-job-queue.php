<?php
/**
 * Background Job Queue.
 *
 * Universal async job pattern used by heavy operations (transcription,
 * Claude calls, email sending, flight plan generation, etc.) so that
 * REST endpoints stay fast and external API failures don't surface as
 * 500s to the user.
 *
 * Flow:
 *   1. Caller: HDLV2_Job_Queue::enqueue( $type, $payload, $opts )  → job_id
 *   2. Cron worker hdlv2_job_queue_worker (every minute) claims up to
 *      JOBS_PER_TICK pending rows, calls the registered handler for
 *      each job_type, marks the row completed/failed.
 *   3. Failures retry with exponential backoff (30s → 2m → 8m → dead).
 *   4. Idempotency: unique (job_type, idem_key) prevents duplicates.
 *
 * Concurrency safety: claim_next_batch() uses SELECT ... FOR UPDATE
 * inside a transaction so two workers racing for the same row can't
 * both claim it.
 *
 * @package HDL_Longevity_V2
 * @since 0.17.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HDLV2_Job_Queue {

    const TABLE = 'hdlv2_jobs';

    /** How many jobs one worker tick processes. Keeps a single tick bounded. */
    const JOBS_PER_TICK = 5;

    /** Max concurrent running jobs across all workers. Protects VPS. */
    const MAX_CONCURRENT = 3;

    /** Backoff schedule (seconds) per attempt. 4 attempts total before dead. */
    const BACKOFF_SECONDS = array( 30, 120, 480 );

    /** Handlers registered by feature classes at plugins_loaded. */
    private static $handlers = array();

    private static $instance = null;
    public static function get_instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }
    private function __construct() {}

    public function register_hooks() {
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
        add_action( 'hdlv2_job_queue_worker', array( $this, 'run_worker_tick' ) );
    }

    // ──────────────────────────────────────────────────────────────
    //  HANDLER REGISTRATION
    // ──────────────────────────────────────────────────────────────

    /**
     * Register a callable handler for a job type. The handler receives the
     * decoded payload and returns an array (stored as result JSON) or
     * throws / returns WP_Error to fail the job.
     *
     * @param string   $job_type  e.g. 'transcribe_audio'
     * @param callable $handler   function( array $payload ): array|WP_Error
     */
    public static function register_handler( $job_type, $handler ) {
        self::$handlers[ $job_type ] = $handler;
    }

    public static function has_handler( $job_type ) {
        return isset( self::$handlers[ $job_type ] );
    }

    // ──────────────────────────────────────────────────────────────
    //  PUBLIC API
    // ──────────────────────────────────────────────────────────────

    /**
     * Enqueue a job. Returns the job row id, or an existing id if
     * idem_key collides (idempotent — safe to call on Back/Refresh).
     *
     * @param string $job_type
     * @param array  $payload
     * @param array  $opts {
     *     @type string $idem_key      Default: sha1(job_type + json(payload))
     *     @type int    $reference_id  Optional — link to owning row for lookup
     *     @type int    $priority      Lower = runs first. Default 100.
     *     @type int    $delay_seconds Start later (useful for chaining). Default 0.
     *     @type int    $max_attempts  Default 4.
     * }
     * @return int|WP_Error job_id on success
     */
    public static function enqueue( $job_type, $payload, $opts = array() ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        $idem_key     = isset( $opts['idem_key'] ) ? (string) $opts['idem_key'] : sha1( $job_type . '|' . wp_json_encode( $payload ) );
        $reference_id = isset( $opts['reference_id'] ) ? (int) $opts['reference_id'] : null;
        $priority     = isset( $opts['priority'] ) ? (int) $opts['priority'] : 100;
        $delay        = isset( $opts['delay_seconds'] ) ? (int) $opts['delay_seconds'] : 0;
        $max_attempts = isset( $opts['max_attempts'] ) ? (int) $opts['max_attempts'] : 4;
        $available_at = gmdate( 'Y-m-d H:i:s', time() + $delay );

        // Idempotent upsert — if a pending/running row with same idem_key exists, return it.
        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, status FROM $table WHERE job_type = %s AND idem_key = %s ORDER BY id DESC LIMIT 1",
            $job_type, $idem_key
        ) );
        if ( $existing && in_array( $existing->status, array( 'pending', 'running', 'completed' ), true ) ) {
            return (int) $existing->id;
        }

        $ok = $wpdb->insert( $table, array(
            'job_type'     => $job_type,
            'reference_id' => $reference_id,
            'idem_key'     => $idem_key,
            'payload'      => wp_json_encode( $payload ),
            'status'       => 'pending',
            'attempts'     => 0,
            'max_attempts' => $max_attempts,
            'priority'     => $priority,
            'available_at' => $available_at,
            'created_at'   => current_time( 'mysql', true ),
        ) );

        if ( ! $ok ) {
            return new WP_Error( 'enqueue_failed', 'Could not enqueue job: ' . $wpdb->last_error );
        }

        $job_id = (int) $wpdb->insert_id;

        // Kick the worker immediately (non-blocking) for low-latency start.
        // The cron tick picks up anything the kick misses.
        self::trigger_worker_async();

        return $job_id;
    }

    /**
     * Fetch a job by id (for status polling).
     */
    public static function get( $job_id ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, job_type, reference_id, status, attempts, max_attempts, result, error, created_at, started_at, completed_at
             FROM $table WHERE id = %d LIMIT 1",
            (int) $job_id
        ) );
        if ( ! $row ) return null;
        $row->result = $row->result ? json_decode( $row->result, true ) : null;
        return $row;
    }

    /**
     * Fetch the latest job for a (type, reference_id) pair.
     * Handy for "does a transcribe job already exist for this consultation?"
     */
    public static function find_latest( $job_type, $reference_id ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, status, attempts, result, error, created_at, completed_at
             FROM $table WHERE job_type = %s AND reference_id = %d ORDER BY id DESC LIMIT 1",
            $job_type, (int) $reference_id
        ) );
        if ( ! $row ) return null;
        $row->result = $row->result ? json_decode( $row->result, true ) : null;
        return $row;
    }

    // ──────────────────────────────────────────────────────────────
    //  WORKER
    // ──────────────────────────────────────────────────────────────

    /**
     * One worker tick. Claims up to JOBS_PER_TICK pending rows whose
     * available_at has passed, runs each handler, marks result.
     *
     * Guard: if MAX_CONCURRENT rows already have status='running', skip —
     * prevents piling up work during a stall.
     */
    public function run_worker_tick() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        // Stale-run guard: mark any 'running' rows older than 10 min as failed.
        // A handler that hangs would otherwise block the queue forever.
        $wpdb->query( $wpdb->prepare(
            "UPDATE $table
             SET status = 'failed', error = 'worker_timeout',
                 completed_at = %s
             WHERE status = 'running' AND started_at < %s",
            current_time( 'mysql', true ),
            gmdate( 'Y-m-d H:i:s', time() - 600 )
        ) );

        // Check running count against cap
        $running = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE status = 'running'" );
        if ( $running >= self::MAX_CONCURRENT ) {
            return;
        }

        $slots = max( 0, self::MAX_CONCURRENT - $running );
        $claim = min( $slots, self::JOBS_PER_TICK );

        for ( $i = 0; $i < $claim; $i++ ) {
            $job = $this->claim_next();
            if ( ! $job ) break;
            $this->run_job( $job );
        }
    }

    /**
     * Atomically claim one pending job. Uses SELECT ... FOR UPDATE in a
     * transaction so concurrent workers can't claim the same row.
     *
     * @return object|null
     */
    private function claim_next() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        $wpdb->query( 'START TRANSACTION' );

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM $table
             WHERE status = 'pending' AND available_at <= %s
             ORDER BY priority ASC, id ASC
             LIMIT 1
             FOR UPDATE",
            current_time( 'mysql', true )
        ) );

        if ( ! $row ) {
            $wpdb->query( 'COMMIT' );
            return null;
        }

        $wpdb->update( $table,
            array(
                'status'     => 'running',
                'started_at' => current_time( 'mysql', true ),
            ),
            array( 'id' => (int) $row->id )
        );
        // wpdb->update can't express `attempts = attempts + 1`; do it inline.
        $wpdb->query( $wpdb->prepare(
            "UPDATE $table SET attempts = attempts + 1 WHERE id = %d",
            (int) $row->id
        ) );

        $wpdb->query( 'COMMIT' );

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d LIMIT 1",
            (int) $row->id
        ) );
    }

    /**
     * Run a claimed job's handler and update the row.
     */
    private function run_job( $job ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        // Heavy handlers (Final Report, Flight Notes — Claude + PDFMonkey) can
        // run for minutes. Raise PHP's own execution clock so max_execution_time
        // can't kill a job mid-run (which would leave it 'running' until the
        // 10-min stale guard force-fails it permanently), and ignore_user_abort
        // so a dropped loopback connection / closed tab doesn't abort the work.
        // (v0.46.x — prod-readiness ASYNC-03. set_time_limit governs only PHP's
        // clock, not the FPM/proxy request ceiling — that is verified on LIVE.)
        if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( 300 ); }
        if ( function_exists( 'ignore_user_abort' ) ) { ignore_user_abort( true ); }

        if ( empty( self::$handlers[ $job->job_type ] ) ) {
            $this->mark_failed( $job, 'no_handler', 'No handler registered for job_type=' . $job->job_type );
            return;
        }

        $payload = json_decode( $job->payload, true );
        if ( ! is_array( $payload ) ) $payload = array();

        try {
            $result = call_user_func( self::$handlers[ $job->job_type ], $payload, $job );
        } catch ( \Throwable $e ) {
            $this->mark_failed( $job, 'exception', $e->getMessage() );
            return;
        }

        if ( is_wp_error( $result ) ) {
            $this->mark_failed( $job, $result->get_error_code() ?: 'handler_error', $result->get_error_message() );
            return;
        }

        // Success — store result
        $wpdb->update( $table,
            array(
                'status'       => 'completed',
                'result'       => wp_json_encode( is_array( $result ) ? $result : array( 'value' => $result ) ),
                'completed_at' => current_time( 'mysql', true ),
                'error'        => null,
            ),
            array( 'id' => (int) $job->id )
        );
    }

    /**
     * Mark a job failed. Schedules a retry if attempts remain, otherwise
     * leaves status='failed' for operator investigation.
     */
    private function mark_failed( $job, $code, $message ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        $attempts = (int) $job->attempts;
        $max      = (int) $job->max_attempts;

        if ( $attempts < $max ) {
            // Retry with backoff
            $backoff_idx = min( $attempts - 1, count( self::BACKOFF_SECONDS ) - 1 );
            $delay       = self::BACKOFF_SECONDS[ max( 0, $backoff_idx ) ];
            $available   = gmdate( 'Y-m-d H:i:s', time() + $delay );

            $wpdb->update( $table,
                array(
                    'status'       => 'pending',
                    'error'        => sprintf( '[attempt %d] %s: %s', $attempts, $code, $message ),
                    'available_at' => $available,
                    'started_at'   => null,
                ),
                array( 'id' => (int) $job->id )
            );
            error_log( sprintf( '[HDLV2 Job #%d %s] retry in %ds — %s: %s', $job->id, $job->job_type, $delay, $code, $message ) );
            return;
        }

        // Exhausted — permanent failure
        $wpdb->update( $table,
            array(
                'status'       => 'failed',
                'error'        => sprintf( '%s: %s', $code, $message ),
                'completed_at' => current_time( 'mysql', true ),
            ),
            array( 'id' => (int) $job->id )
        );
        error_log( sprintf( '[HDLV2 Job #%d %s] PERMANENT FAIL after %d attempts — %s: %s', $job->id, $job->job_type, $attempts, $code, $message ) );
    }

    // ──────────────────────────────────────────────────────────────
    //  ASYNC WORKER TRIGGER
    //  After an enqueue, kick wp-cron so the worker runs within
    //  seconds instead of waiting for the minute-tick.
    // ──────────────────────────────────────────────────────────────

    private static function trigger_worker_async() {
        // v0.20.11 — POST to our internal /worker-tick endpoint instead of
        // wp-cron.php. Sites commonly set DISABLE_WP_CRON=true (so they can
        // rely on a system-cron wp-cli call), and in that setup a POST to
        // wp-cron.php is a silent no-op — jobs sit pending until the next
        // minute tick. The internal endpoint is a plain REST route, so it
        // runs regardless of DISABLE_WP_CRON, and kicks the worker in ~1s.
        //
        // HMAC'd with wp_salt() so only in-process callers (which know the
        // salt) can fire it. Non-blocking fire-and-forget — the enqueue
        // response returns immediately; the tick runs on the loopback.
        //
        // v0.30.0 — timeout bumped 0.1s → 2s after diagnosing the consultation
        // "Transcribing your audio..." stall on STBY (1 vCPU Vultr): the SSL
        // handshake to https://stby.healthdatalab.net:8443 + LE cert routinely
        // takes 200-500ms, so the 100ms ceiling killed the request before the
        // handshake completed. With blocking:false the call still returns
        // synchronously (cURL fires-and-forgets in a separate fd), so 2s is
        // a safe ceiling that doesn't actually delay enqueue() callers.
        $url = rest_url( 'hdl-v2/v1/internal/worker-tick' );
        $key = hash_hmac( 'sha256', 'hdlv2-worker-tick', wp_salt( 'auth' ) );
        $resp = wp_remote_post( $url, array(
            'timeout'   => 2,
            'blocking'  => false,
            'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
            'body'      => array( 'key' => $key ),
        ) );
        // Surface silent failures so we can diagnose if the kick stops
        // working again. The cron tick is the safety net (1-min from v0.30.0)
        // so a kick miss is no longer catastrophic — but we should know.
        if ( is_wp_error( $resp ) ) {
            error_log( '[HDLV2 worker-tick] kick failed: ' . $resp->get_error_message() );
        }
    }

    // ──────────────────────────────────────────────────────────────
    //  REST — job status polling
    // ──────────────────────────────────────────────────────────────

    public function register_rest_routes() {
        register_rest_route( 'hdl-v2/v1', '/jobs/(?P<id>\d+)/status', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'rest_status' ),
            'permission_callback' => array( $this, 'permission_read' ),
        ) );

        // v0.20.11 — internal loopback worker kick. Bypasses DISABLE_WP_CRON.
        // HMAC-gated via wp_salt(), so only our own process (which knows the
        // salt) can fire it. Not listed in rate-limit policy because it's
        // called at most once per enqueue, from within the same server.
        register_rest_route( 'hdl-v2/v1', '/internal/worker-tick', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_worker_tick' ),
            'permission_callback' => '__return_true',
        ) );
    }

    /**
     * Token-based or practitioner auth. The caller provides the job ID;
     * we return status without exposing job_type/payload internals — just
     * status, progress hint, result (if completed), error (if failed).
     *
     * This is the COARSE gate (is the caller a practitioner / a valid token at
     * all). The fine, fail-closed PER-JOB ownership check now lives in
     * rest_status() via caller_owns_job() — resolved per job_type against the
     * record reference_id binds to (B.3 / §8.4). The previous "KNOWN IDOR (P2)"
     * where any practitioner/token could read any non-sensitive job by walking
     * IDs is closed: every type is gated, unknown types deny by default.
     */
    public function permission_read( $request ) {
        // Practitioner always allowed
        $user_id = get_current_user_id();
        if ( $user_id && HDLV2_Compatibility::is_practitioner( $user_id ) ) {
            return true;
        }

        // Token (client) auth — standard 64-hex pattern already used elsewhere.
        // v0.41.17 — `AND deleted_at IS NULL` so old tokens from archived
        // assessments cannot authenticate to job-status endpoints.
        $token = $request->get_param( 'token' ) ?: '';
        if ( $token && preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
            global $wpdb;
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}hdlv2_form_progress
                 WHERE token = %s AND deleted_at IS NULL LIMIT 1",
                $token
            ) );
            return (bool) $exists;
        }

        return false;
    }

    public function rest_status( $request ) {
        $job_id = (int) $request->get_param( 'id' );
        $job    = self::get( $job_id );
        if ( ! $job ) {
            return new WP_Error( 'not_found', 'Job not found.', array( 'status' => 404 ) );
        }

        // B.3 (§8.4 / finding #3) — UNCONDITIONAL, fail-closed ownership gate.
        // permission_read() only proves the caller is *a* practitioner or holds
        // *a* valid token — not that they own THIS job. Previously only the two
        // result-bearing types (transcribe_audio / organise_consultation) were
        // gated, so the other 9 types leaked status/result to any authenticated
        // caller walking job IDs. caller_owns_job() now resolves ownership per
        // job_type and denies unknown/future types by default. Non-owners get
        // 404 (not 403) so the endpoint never confirms a job exists. Kept ABOVE
        // the self-healing worker kick below so a non-owner poll can't nudge the
        // queue (AUDIT §9 same-file coupling).
        if ( ! $this->caller_owns_job( $job, $request ) ) {
            return new WP_Error( 'not_found', 'Job not found.', array( 'status' => 404 ) );
        }

        // v0.33.3 — Self-healing kick. If the job is still pending when the
        // client polls status, re-fire trigger_worker_async() so the worker
        // runs immediately. Covers the case where the original kick from
        // enqueue() was dropped (SSL handshake exceeded the 2s timeout,
        // server was momentarily busy, or the loopback HTTP failed).
        // Without this, a missed kick forced the user to wait up to 60
        // seconds for the cron tick — explained the "minute hang" reported
        // on Brave + STBY for short addendum recordings.
        //
        // Cheap to do per poll: trigger_worker_async() is non-blocking
        // (cURL fire-and-forget, fd in another process). The worker
        // queue's MAX_CONCURRENT guard + claim_next() FOR UPDATE prevent
        // duplicate work even if multiple polls fire kicks before the
        // first claim lands.
        if ( $job->status === 'pending' ) {
            self::trigger_worker_async();
        }

        $response = rest_ensure_response( array(
            'id'           => (int) $job->id,
            'status'       => $job->status,
            'attempts'     => (int) $job->attempts,
            'max_attempts' => (int) $job->max_attempts,
            'result'       => $job->status === 'completed' ? $job->result : null,
            'error'        => $job->status === 'failed' ? $job->error : null,
            'created_at'   => $job->created_at,
            'completed_at' => $job->completed_at,
        ) );

        // v0.20.11 — explicit no-store so Brave / Safari / any aggressive
        // proxy cache never serves a stale "pending" response. The client
        // also cache-busts via a `_=<ts>` query param; this is belt-and-braces.
        $response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
        $response->header( 'Pragma', 'no-cache' );
        $response->header( 'Expires', '0' );
        return $response;
    }

    /**
     * B.3 (§8.4 / finding #3) — UNCONDITIONAL, fail-closed ownership gate for
     * EVERY job type. Bindings were verified against each enqueue() call-site +
     * the live table columns (form_progress/reports/flight_plans all carry the
     * owner ids). Unknown/future types deny by default (secure-by-default).
     *
     * Ownership resolves per job_type because reference_id binds to different
     * tables:
     *  - transcribe_audio (consultation_notes ctx) / organise_consultation:
     *      RESOURCE — consultation_notes.practitioner_user_id.
     *  - transcribe_audio (why/checkin/addendum ctx):
     *      CREATOR-BOUND — payload _owner_user / _owner_token_hash (HMAC),
     *      stamped at upload; the poller carries the same identity. Historical
     *      jobs without the stamp fail closed (admin-only).
     *  - generate_final_report / regenerate_final_report / generate_flight_notes_pdf
     *    / redflag_scan: ref = form_progress.id → practitioner owner.
     *  - generate_draft_report: ref = form_progress.id → the CLIENT (token or
     *      client_user_id) OR the practitioner (the Stage-3 poller is the client).
     *  - render_final_report_pdf: ref = reports.id → reports practitioner/client.
     *  - render_flight_plan_pdf: ref = flight_plans.id → flight_plans
     *      practitioner_id / client_id.
     *  - generate_flight_plan_manual / generate_flight_plan_auto: ref = client_id
     *      → practitioner-owns-client (or the client themselves).
     *
     * @return bool true if the caller owns the job's underlying record.
     */
    private function caller_owns_job( $job, $request ) {
        // Admin escape hatch — mirrors the IDOR pattern used elsewhere.
        if ( current_user_can( 'manage_options' ) ) {
            return true;
        }

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        $uid   = (int) get_current_user_id();
        $token = (string) $request->get_param( 'token' );
        if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
            $token = '';
        }

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT reference_id, payload FROM $table WHERE id = %d LIMIT 1",
            (int) $job->id
        ) );
        if ( ! $row ) {
            return false;
        }
        $payload = $row->payload ? json_decode( $row->payload, true ) : array();
        if ( ! is_array( $payload ) ) {
            $payload = array();
        }
        $ref     = (int) $row->reference_id;
        $context = isset( $payload['context_type'] ) ? (string) $payload['context_type'] : '';

        switch ( $job->job_type ) {

            // Audio transcripts — preserve the v0.46.17 logic exactly.
            case 'transcribe_audio':
                if ( $context === 'consultation_notes' ) {
                    $cid = isset( $payload['consultation_id'] ) ? (int) $payload['consultation_id'] : $ref;
                    return self::owns_consultation( $wpdb, $uid, $cid );
                }
                return self::caller_is_job_creator( $payload, $uid, $token );

            case 'organise_consultation':
                $cid = isset( $payload['consultation_id'] ) ? (int) $payload['consultation_id'] : $ref;
                return self::owns_consultation( $wpdb, $uid, $cid );

            // Practitioner-owned, ref = form_progress.id.
            case 'generate_final_report':
            case 'regenerate_final_report':
            case 'generate_flight_notes_pdf':
            case 'redflag_scan':
                return self::practitioner_owns_progress( $wpdb, $uid, $ref );

            // Draft report poll is the CLIENT (token, no session) OR practitioner.
            case 'generate_draft_report':
                return self::owns_progress_client_or_practitioner( $wpdb, $uid, $token, $ref );

            // PDF render bound to reports.id (carries owner ids directly).
            case 'render_final_report_pdf':
                if ( ! $uid || ! $ref ) {
                    return false;
                }
                $r = $wpdb->get_row( $wpdb->prepare(
                    "SELECT practitioner_user_id, client_user_id FROM {$wpdb->prefix}hdlv2_reports WHERE id = %d LIMIT 1",
                    $ref
                ) );
                if ( ! $r ) {
                    return false;
                }
                return ( (int) $r->practitioner_user_id === $uid )
                    || HDLV2_Compatibility::practitioner_owns_client( $uid, (int) $r->client_user_id );

            // PDF render bound to flight_plans.id (carries owner ids directly).
            case 'render_flight_plan_pdf':
                if ( ! $uid || ! $ref ) {
                    return false;
                }
                $fp = $wpdb->get_row( $wpdb->prepare(
                    "SELECT practitioner_id, client_id FROM {$wpdb->prefix}hdlv2_flight_plans WHERE id = %d LIMIT 1",
                    $ref
                ) );
                if ( ! $fp ) {
                    return false;
                }
                return ( (int) $fp->practitioner_id === $uid )
                    || HDLV2_Compatibility::practitioner_owns_client( $uid, (int) $fp->client_id );

            // Flight-plan generation — ref = client_id (manual: practitioner;
            // auto: worker-created, resource-only).
            case 'generate_flight_plan_manual':
            case 'generate_flight_plan_auto':
                if ( ! $uid || ! $ref ) {
                    return false;
                }
                if ( $uid === $ref ) {
                    return true; // the client polling their own plan
                }
                return HDLV2_Compatibility::practitioner_owns_client( $uid, $ref );

            // Unknown / future job types — deny by default (secure-by-default).
            default:
                return false;
        }
    }

    /** Resource owner: a consultation_notes row's practitioner. */
    private static function owns_consultation( $wpdb, $uid, $cid ) {
        if ( ! $cid || ! $uid ) {
            return false;
        }
        $prac = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT practitioner_user_id FROM {$wpdb->prefix}hdlv2_consultation_notes WHERE id = %d LIMIT 1",
            $cid
        ) );
        return ( $prac && $prac === $uid );
    }

    /** Creator-bound: payload _owner_user (logged-in) or _owner_token_hash (HMAC of the client token). */
    private static function caller_is_job_creator( $payload, $uid, $token ) {
        $owner_user = isset( $payload['_owner_user'] ) ? (int) $payload['_owner_user'] : 0;
        $owner_hash = isset( $payload['_owner_token_hash'] ) ? (string) $payload['_owner_token_hash'] : '';
        if ( $uid && $owner_user && $uid === $owner_user ) {
            return true;
        }
        if ( $token && $owner_hash ) {
            $calc = hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
            if ( hash_equals( $owner_hash, $calc ) ) {
                return true;
            }
        }
        return false;
    }

    /** Practitioner owner of a form_progress row (direct owner OR linked client). */
    private static function practitioner_owns_progress( $wpdb, $uid, $progress_id ) {
        if ( ! $progress_id || ! $uid ) {
            return false;
        }
        $fp = $wpdb->get_row( $wpdb->prepare(
            "SELECT practitioner_user_id, client_user_id FROM {$wpdb->prefix}hdlv2_form_progress WHERE id = %d LIMIT 1",
            $progress_id
        ) );
        if ( ! $fp ) {
            return false;
        }
        if ( (int) $fp->practitioner_user_id === $uid ) {
            return true;
        }
        return HDLV2_Compatibility::practitioner_owns_client( $uid, (int) $fp->client_user_id );
    }

    /** Draft-report owner: the client (token or client_user_id) OR the practitioner. */
    private static function owns_progress_client_or_practitioner( $wpdb, $uid, $token, $progress_id ) {
        if ( ! $progress_id ) {
            return false;
        }
        $fp = $wpdb->get_row( $wpdb->prepare(
            "SELECT practitioner_user_id, client_user_id, token FROM {$wpdb->prefix}hdlv2_form_progress WHERE id = %d LIMIT 1",
            $progress_id
        ) );
        if ( ! $fp ) {
            return false;
        }
        // Client polling with their own token (no logged-in session) — the
        // Stage-3 draft poll path. form_progress.token is plaintext + UNIQUE.
        if ( $token && ! empty( $fp->token ) && hash_equals( (string) $fp->token, $token ) ) {
            return true;
        }
        if ( $uid && (int) $fp->client_user_id === $uid ) {
            return true;
        }
        if ( $uid && (int) $fp->practitioner_user_id === $uid ) {
            return true;
        }
        if ( $uid ) {
            return HDLV2_Compatibility::practitioner_owns_client( $uid, (int) $fp->client_user_id );
        }
        return false;
    }

    /**
     * Internal worker-tick endpoint. Triggered by trigger_worker_async() so
     * the queue processes a freshly-enqueued job without waiting for the
     * next wp-cron minute tick.
     *
     * Why this exists: sites commonly set DISABLE_WP_CRON=true and rely on
     * a system-cron wp-cli call to run events. In that setup wp-cron.php
     * itself is a no-op, so POSTing to it does nothing. This endpoint is
     * a plain REST route unaffected by DISABLE_WP_CRON, protected by an
     * HMAC so only a WP request with wp_salt() access can fire it.
     *
     * @since 0.20.11
     */
    public function rest_worker_tick( $request ) {
        $supplied = (string) ( $request->get_param( 'key' ) ?: '' );
        $expected = hash_hmac( 'sha256', 'hdlv2-worker-tick', wp_salt( 'auth' ) );
        if ( ! hash_equals( $expected, $supplied ) ) {
            return new WP_Error( 'forbidden', 'Invalid key.', array( 'status' => 403 ) );
        }
        $this->run_worker_tick();
        $response = rest_ensure_response( array( 'ok' => true ) );
        $response->header( 'Cache-Control', 'no-store' );
        return $response;
    }
}

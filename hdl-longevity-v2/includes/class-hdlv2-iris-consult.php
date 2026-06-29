<?php
/**
 * Iridology Phase 2 — embedded consult flow (WP-coupled wiring).
 *
 * SCOPE (this build): upload → analyse → result shown in the Consultation tab.
 * The longevity-PDF iridology page is a LATER build (this class persists the
 * data + photos it will need, but renders no PDF).
 *
 * Architecture (see /Volumes/Media/irismapper-live/docs/IRIS-PHASE2-*.md):
 *   1. Browser POST /iris/analyse {client_id, progress_id} → HDL mints jobId,
 *      inserts a queued row, makes ONE short (≤5 s, breaker-guarded) call to
 *      IrisMapper iris-analyse-upload-url, returns {jobId, uploadUrls}. PHP
 *      NEVER carries image bytes and NEVER blocks on the analysis.
 *   2. Browser PUTs L+R DIRECT to Supabase signed URLs.
 *   3. Browser POST /iris/start {job} → HDL calls iris-analyse (confirm+enqueue)
 *      → 202 queued. Row → running.
 *   4. Browser polls GET /iris/analysis-status?job=… — a PURE local MySQL read,
 *      ZERO outbound IrisMapper call on the request path.
 *   5. IrisMapper PUSHes the result to POST /iris/analyse/callback (HMAC +
 *      jobId-idempotent UPSERT). A real-OS-cron reconcile is the backstop.
 *   6. Browser render → editable areas-to-check (POST /iris/areas-edit).
 *
 * Dark behind hdlv2_ff_iris_consult (layered ON TOP of hdlv2_ff_iris_addon):
 * when off, register_routes() returns early ⇒ every /iris/analyse* 404s, no
 * REST-index trace, no serve route, no cron work (Rule-0). Entitlement is
 * re-checked fail-closed inside every handler.
 *
 * @package HDL_Longevity_V2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HDLV2_Iris_Consult {

    private static $instance = null;

    const MAP_DEFAULT  = 'Jensen';
    const HTTP_TIMEOUT = 5;          // ≤5 s — never near PHP max_execution_time (§7.6)
    const POLL_MIN_MS  = 3000;       // server-side poll throttle (Retry-After to fast pollers)
    const RECONCILE_GRACE_S = 180;   // > p99 read; reconcile only acts past this (§8)
    const REVISIONS_CAP = 20;        // capped _revisions archive

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function register_hooks() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
        add_action( 'init', array( $this, 'maybe_serve_image' ) );      // cookie-auth image serve
        add_action( 'hdlv2_iris_reconcile', array( $this, 'cron_reconcile' ) ); // OS-cron backstop
    }

    // ── Flag (Rule-0): BOTH the v1 add-on flag AND the Phase-2 flag ──
    public static function enabled() {
        return HDLV2_Iris_Addon::enabled() && (bool) get_option( 'hdlv2_ff_iris_consult', false );
    }

    // ── Config readers (wp-config constants; server-side only) ──
    private static function base() {
        return defined( 'IRISMAPPER_BASE' ) ? rtrim( IRISMAPPER_BASE, '/' ) : '';
    }
    private static function secret() {
        return defined( 'HDL_SHARED_SECRET' ) ? HDL_SHARED_SECRET : '';
    }
    private static function callback_secret() {
        return defined( 'HDL_CALLBACK_SECRET' ) ? HDL_CALLBACK_SECRET : '';
    }
    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'hdlv2_iris_results';
    }

    // ── Private image dir (OUTSIDE docroot — OLS ignores .htaccess) ──
    private static function private_dir() {
        $base = defined( 'HDLV2_PRIVATE_DIR' )
            ? rtrim( HDLV2_PRIVATE_DIR, '/' )
            : dirname( rtrim( ABSPATH, '/' ) ) . '/hdlv2-private';
        return $base . '/hdlv2-iris/';
    }

    // ─────────────────────────────────────────────────────────────────────
    //  REST routes
    // ─────────────────────────────────────────────────────────────────────

    public function register_routes() {
        if ( ! self::enabled() ) {
            return; // Rule-0: no routes registered ⇒ 404, no REST-index trace
        }
        $ns   = 'hdl-v2/v1';
        $perm = array( $this, 'require_practitioner' );

        register_rest_route( $ns, '/iris/analyse', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_analyse' ),
            'permission_callback' => $perm,
        ) );
        register_rest_route( $ns, '/iris/start', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_start' ),
            'permission_callback' => $perm,
        ) );
        register_rest_route( $ns, '/iris/analysis-status', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'rest_status' ),
            'permission_callback' => $perm,
        ) );
        register_rest_route( $ns, '/iris/areas-edit', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_areas_edit' ),
            'permission_callback' => $perm,
        ) );
        // Callback: IrisMapper → HDL (server-to-server). The shared cookie/nonce
        // auth does not apply; the SEPARATE callback secret + HMAC are checked
        // inside the handler (hash_equals), mirroring /reports/pdf-callback.
        register_rest_route( $ns, '/iris/analyse/callback', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_callback' ),
            'permission_callback' => '__return_true',
        ) );
    }

    /**
     * Permission gate for the practitioner-facing routes: flag (Rule-0) + role +
     * a FAIL-CLOSED add-on entitlement re-check (mirrors the UI gate, so a
     * role=practitioner user WITHOUT the add-on never reaches the outbound
     * IrisMapper call). Per-client IDOR is enforced separately inside handlers.
     */
    public function require_practitioner() {
        if ( ! self::enabled() ) {
            return false;
        }
        $uid = get_current_user_id();
        if ( ! $uid || ! HDLV2_Compatibility::is_practitioner( $uid ) ) {
            return false;
        }
        return HDLV2_Compatibility::practitioner_has_iridology_addon( $uid );
    }

    /** Confirm the caller owns (client_id, progress_id) and they are linked. */
    private function authorise_consult( $client_id, $progress_id ) {
        $pid = get_current_user_id();
        if ( ! HDLV2_Compatibility::practitioner_owns_client( $pid, $client_id ) ) {
            return false;
        }
        if ( $progress_id <= 0 ) {
            return false;
        }
        global $wpdb;
        // Admins bypass ownership (precedent) but the progress row must still
        // link this client so jobId encodes a real consultation.
        if ( user_can( $pid, 'manage_options' ) ) {
            $linked = $wpdb->get_var( $wpdb->prepare(
                "SELECT 1 FROM {$wpdb->prefix}hdlv2_form_progress WHERE id = %d AND client_user_id = %d LIMIT 1",
                $progress_id, $client_id ) );
            return (bool) $linked;
        }
        $linked = $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$wpdb->prefix}hdlv2_form_progress
              WHERE id = %d AND practitioner_user_id = %d AND client_user_id = %d AND deleted_at IS NULL LIMIT 1",
            $progress_id, $pid, $client_id ) );
        return (bool) $linked;
    }

    // ── POST /iris/analyse — mint job, ONE upload-url call, return signed URLs ──
    public function rest_analyse( $request ) {
        $client_id   = absint( $request->get_param( 'client_id' ) );
        $progress_id = absint( $request->get_param( 'progress_id' ) );
        $map_name    = sanitize_text_field( (string) $request->get_param( 'map_name' ) );
        if ( $map_name === '' ) { $map_name = self::MAP_DEFAULT; }

        if ( ! $this->authorise_consult( $client_id, $progress_id ) ) {
            return new WP_Error( 'forbidden', 'Not your client.', array( 'status' => 403 ) );
        }
        $email = $this->current_email();
        if ( ! is_email( $email ) ) {
            return new WP_Error( 'no_email', 'No practitioner email.', array( 'status' => 400 ) );
        }

        $job_id = HDLV2_Iris_Support::build_job_id( $client_id, $progress_id, wp_generate_uuid4() );
        if ( null === $job_id ) {
            return new WP_Error( 'bad_job', 'Could not mint a job id.', array( 'status' => 500 ) );
        }

        // Never-delete: a fresh analysis archives any prior live row for this
        // (client, consultation) before inserting the new one.
        $this->archive_prior( $client_id, $progress_id );

        global $wpdb;
        $wpdb->insert( self::table(), array(
            'client_user_id'       => $client_id,
            'practitioner_user_id' => get_current_user_id(),
            'form_progress_id'     => $progress_id,
            'job_id'               => $job_id,
            'idempotency_key'      => $job_id,
            'status'               => 'queued',
            'map_name'             => $map_name,
            'eyes_label'           => 'Left + Right',
        ), array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s' ) );

        // ONE short, breaker-guarded call. Reserves NO usage on IrisMapper.
        $res = $this->post_iris( 'iris-analyse-upload-url', array(
            'email'     => $email,
            'jobId'     => $job_id,
            'eyes'      => array( 'L', 'R' ),
            'mediaType' => 'image/jpeg',
        ) );

        if ( is_wp_error( $res ) ) {
            $state = $this->classify_upstream_error( $res );
            $this->set_status( $job_id, $state );
            // Fail-closed: 200 with a soft state so the consult save + UI proceed.
            return rest_ensure_response( array( 'jobId' => $job_id, 'state' => $state ) );
        }

        $urls = isset( $res['uploadUrls'] ) && is_array( $res['uploadUrls'] ) ? $res['uploadUrls'] : array();
        // Persist the Supabase keys (refs only — never bytes) for reconcile/debug.
        $wpdb->update( self::table(), array(
            'supabase_key_l' => isset( $urls['L']['key'] ) ? (string) $urls['L']['key'] : null,
            'supabase_key_r' => isset( $urls['R']['key'] ) ? (string) $urls['R']['key'] : null,
        ), array( 'job_id' => $job_id ), array( '%s', '%s' ), array( '%s' ) );

        return rest_ensure_response( array(
            'jobId'      => $job_id,
            'state'      => 'queued',
            'uploadUrls' => $urls, // {L:{uploadUrl,token,key}, R:{...}} — browser PUTs direct
        ) );
    }

    // ── POST /iris/start — confirm upload + enqueue the analysis (202) ──
    public function rest_start( $request ) {
        $job_id = (string) $request->get_param( 'job' );
        $row    = $this->own_job_or_null( $job_id );
        if ( ! $row ) {
            return new WP_Error( 'forbidden', 'Unknown or not-your job.', array( 'status' => 403 ) );
        }

        $callback = rest_url( 'hdl-v2/v1/iris/analyse/callback' );
        $res = $this->post_iris( 'iris-analyse', array(
            'jobId'       => $job_id,
            'callbackUrl' => $callback,
            'mapName'     => $row->map_name ? $row->map_name : self::MAP_DEFAULT,
        ) );

        if ( is_wp_error( $res ) ) {
            $state = $this->classify_upstream_error( $res );
            $this->set_status( $job_id, $state );
            return rest_ensure_response( array( 'jobId' => $job_id, 'state' => $state ) );
        }

        // 202 queued / 200 deduped — either way the analysis is running upstream.
        $this->set_status( $job_id, 'running' );
        return rest_ensure_response( array( 'jobId' => $job_id, 'state' => 'running' ) );
    }

    // ── GET /iris/analysis-status — PURE local read (no IrisMapper call) ──
    // Accepts EITHER ?job=<jobId> (the active poll) OR ?progress_id&client_id
    // (mount-time discovery of the latest analysis for this consultation).
    public function rest_status( $request ) {
        $job_id = (string) $request->get_param( 'job' );
        $row    = null;
        if ( $job_id !== '' ) {
            $row = $this->own_job_or_null( $job_id );
            if ( ! $row ) {
                return new WP_Error( 'forbidden', 'Unknown or not-your job.', array( 'status' => 403 ) );
            }
        } else {
            $client_id   = absint( $request->get_param( 'client_id' ) );
            $progress_id = absint( $request->get_param( 'progress_id' ) );
            if ( ! $this->authorise_consult( $client_id, $progress_id ) ) {
                return new WP_Error( 'forbidden', 'Not your client.', array( 'status' => 403 ) );
            }
            $row = $this->latest_row( $client_id, $progress_id );
            if ( ! $row ) {
                $resp = rest_ensure_response( array( 'state' => 'none' ) );
                $resp->header( 'Cache-Control', 'no-store' );
                return $resp;
            }
            $job_id = $row->job_id;
        }
        $mapped = HDLV2_Iris_Support::map_poll_state( array(
            'status'            => $row->status,
            'result_json'       => $row->result_json,
            'areas_edited_json' => $row->areas_edited_json,
            'error'             => $row->error_message,
            'refused'           => $row->refused,
        ) );
        $mapped['jobId']         = $job_id;
        // Native-capture rows are addressed by capture_id; legacy embedded rows
        // fall back to the job_id (so the browser always has a stable handle).
        $mapped['captureId']     = ! empty( $row->capture_id ) ? $row->capture_id : $job_id;
        $mapped['include_in_pdf'] = (bool) $row->include_in_pdf;
        // Image thumbnails (only once persisted to the private dir).
        if ( $row->image_l_path ) { $mapped['image_l'] = home_url( '/?hdlv2_iris_img=' . (int) $row->id . '&eye=L' ); }
        if ( $row->image_r_path ) { $mapped['image_r'] = home_url( '/?hdlv2_iris_img=' . (int) $row->id . '&eye=R' ); }

        $resp = rest_ensure_response( $mapped );
        $resp->header( 'Cache-Control', 'no-store' );
        return $resp;
    }

    // ── POST /iris/analyse/callback — IrisMapper → HDL (HMAC, idempotent) ──
    public function rest_callback( $request ) {
        // Flag guard is implicit (route not registered when off), but re-assert.
        if ( ! self::enabled() ) {
            return new WP_Error( 'not_found', 'Not found.', array( 'status' => 404 ) );
        }
        $raw = $request->get_body();
        $sig = $request->get_header( 'x-hdl-signature' );
        $ts  = $request->get_header( 'x-hdl-timestamp' );
        $given_secret = (string) $request->get_header( 'x-hdl-callback-secret' );
        $secret = self::callback_secret();

        // 1) static callback secret (hash_equals) + 2) HMAC over the raw body.
        if ( '' === $secret || ! hash_equals( $secret, $given_secret ) ) {
            return new WP_Error( 'forbidden', 'Invalid callback secret.', array( 'status' => 403 ) );
        }
        $v = HDLV2_Iris_Support::verify_callback( $secret, $ts, $sig, $raw );
        if ( empty( $v['ok'] ) ) {
            return new WP_Error( 'forbidden', 'Bad signature.', array( 'status' => 403 ) );
        }

        $parsed = HDLV2_Iris_Support::parse_callback_body( json_decode( $raw, true ) );
        if ( empty( $parsed['ok'] ) ) {
            return new WP_Error( 'invalid', 'Bad callback body.', array( 'status' => 400 ) );
        }

        $applied = $this->apply_terminal( $parsed );
        if ( is_wp_error( $applied ) ) {
            return $applied;
        }
        // Always ack 200 so at-least-once delivery stops retrying. Echo whichever
        // key the push carried (native captureId or legacy jobId).
        $ack = array( 'received' => true );
        if ( isset( $parsed['captureId'] ) ) { $ack['captureId'] = $parsed['captureId']; }
        if ( isset( $parsed['jobId'] ) ) { $ack['jobId'] = $parsed['jobId']; }
        return rest_ensure_response( $ack );
    }

    // ── POST /iris/areas-edit — additive practitioner overlay (never overwrite AI) ──
    public function rest_areas_edit( $request ) {
        $job_id = (string) $request->get_param( 'job' );
        $row    = $this->own_job_or_null( $job_id );
        if ( ! $row ) {
            return new WP_Error( 'forbidden', 'Unknown or not-your job.', array( 'status' => 403 ) );
        }
        if ( $row->status !== 'done' ) {
            return new WP_Error( 'not_ready', 'Nothing to edit yet.', array( 'status' => 409 ) );
        }

        global $wpdb;
        $fields = array();
        $formats = array();

        // include_in_pdf toggle (opt-in per report — Decision 15).
        $inc = $request->get_param( 'include_in_pdf' );
        if ( null !== $inc ) {
            $fields['include_in_pdf'] = $inc ? 1 : 0;
            $formats[] = '%d';
        }

        $edited = $request->get_param( 'areas' ); // full overlay (seed-from-shown pattern)
        if ( null !== $edited ) {
            $json = wp_json_encode( $edited );
            if ( false === $json ) {
                return new WP_Error( 'bad_json', 'Could not encode edit.', array( 'status' => 400 ) );
            }
            // Archive the prior copy (AI original on first edit, else the last
            // edited overlay) into _revisions — never overwrite result_json.
            $prior = ( $row->areas_edited_json !== null && $row->areas_edited_json !== '' )
                ? $row->areas_edited_json : $row->result_json;
            $this->push_revision( $row, $prior );
            $fields['areas_edited_json'] = $json;
            $formats[] = '%s';
        }

        if ( $fields ) {
            $wpdb->update( self::table(), $fields, array( 'job_id' => $job_id ), $formats, array( '%s' ) );
        }
        return rest_ensure_response( array( 'saved' => true ) );
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Terminal apply (callback + reconcile share this)
    //   • NATIVE-CAPTURE (captureId): insert-on-callback + terminal-wins UPSERT.
    //   • LEGACY EMBEDDED (jobId): HDL minted the row first → find-or-404 update.
    // ─────────────────────────────────────────────────────────────────────

    private function apply_terminal( $parsed ) {
        // Native-capture push carries a deterministic captureId HDL never minted.
        if ( isset( $parsed['captureId'] ) ) {
            return $this->apply_terminal_capture( $parsed );
        }

        global $wpdb;
        $job_id = $parsed['jobId'];
        $row    = $this->row_by_job( $job_id );
        if ( ! $row ) {
            // Never minted here (or a stray) — 404 so a misrouted push is visible,
            // but a re-push for an existing-but-archived job still finds its row.
            return new WP_Error( 'not_found', 'Unknown job.', array( 'status' => 404 ) );
        }
        // Terminal status wins: a 'done' row is never downgraded by a later error.
        if ( $row->status === 'done' && $parsed['status'] === 'error' ) {
            return true;
        }
        if ( $row->status === 'archived' ) {
            return true; // superseded by a newer analysis — ignore late delivery
        }

        if ( $parsed['status'] === 'done' ) {
            $result = $parsed['result'];
            $update = array(
                'status'      => 'done',
                'result_json' => wp_json_encode( $result ),
                'cost'        => isset( $parsed['cost'] ) ? (float) $parsed['cost'] : null,
                'refused'     => 0,
                'eyes_label'  => $this->eyes_label_from_result( $result ),
                'analysed_at' => current_time( 'mysql' ),
            );
            $wpdb->update( self::table(), $update, array( 'job_id' => $job_id ),
                array( '%s', '%s', '%f', '%d', '%s', '%s' ), array( '%s' ) );

            // Persist photos to the private dir IF the callback carried them
            // (forward-compat — see the photo-persistence note at the foot of
            // this file). Best-effort, never fatal.
            if ( ! empty( $parsed['images'] ) ) {
                $this->persist_images( $job_id, $parsed['images'] );
            }
            return true;
        }

        // error / refused
        $wpdb->update( self::table(), array(
            'status'        => 'error',
            'refused'       => ! empty( $parsed['refused'] ) ? 1 : 0,
            'error_message' => isset( $parsed['error'] ) ? substr( (string) $parsed['error'], 0, 500 ) : 'Analysis failed',
            'analysed_at'   => current_time( 'mysql' ),
        ), array( 'job_id' => $job_id ), array( '%s', '%d', '%s', '%s' ), array( '%s' ) );
        return true;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  NATIVE-CAPTURE terminal apply — INSERT-ON-CALLBACK + terminal-wins UPSERT
    //
    //  HDL never minted the capture_id, so an UNSEEN capture_id is a true CREATE
    //  (client/consultation read off the deterministic key). The auto safety-net
    //  DRAFT (finalized=false → status 'draft') and the "Send to HealthDataLab"
    //  FINAL (finalized=true → status 'done') collapse to ONE row: the FINAL /
    //  terminal write wins and is never downgraded by a late draft or error.
    //  Same capture_id re-arrival = idempotent in-place update (one row, one
    //  charge). A NEW capture_id (genuine re-shoot / explicit :vN) archives the
    //  prior live row for the consultation (never-delete) then inserts.
    // ─────────────────────────────────────────────────────────────────────

    private function apply_terminal_capture( $parsed ) {
        $cap     = $parsed['captureId'];
        $client  = HDLV2_Iris_Support::client_id_from_capture_id( $cap );
        $consult = HDLV2_Iris_Support::consultation_id_from_capture_id( $cap );
        if ( $client < 1 || $consult < 1 ) {
            return new WP_Error( 'invalid', 'Bad captureId.', array( 'status' => 400 ) );
        }

        $row = $this->row_by_capture( $cap );
        if ( $row ) {
            return $this->capture_upsert_existing( $row, $parsed );
        }

        // ── Unseen capture_id → INSERT-ON-CALLBACK ──
        // terminal-wins ACROSS captureIds: an ERROR carries no result, so a failed
        // (re-)analysis must NEVER supersede a captured done/draft result for this
        // consultation. Only a result-bearing push may archive a prior live row —
        // and we archive ONLY AFTER the new row is safely inserted, so a failed
        // insert can never strand the consultation with its prior result archived
        // and nothing live.
        if ( $parsed['status'] === 'error' ) {
            if ( $this->has_live_result( $client, $consult ) ) {
                return true; // keep the captured result; drop the failed re-analysis
            }
            // No result to protect → record the failure (nothing to archive).
            if ( $this->capture_insert_new( $cap, $client, $consult, $parsed ) ) {
                return true;
            }
            $row = $this->row_by_capture( $cap );
            return $row ? $this->capture_upsert_existing( $row, $parsed )
                        : new WP_Error( 'db_error', 'Could not persist capture.', array( 'status' => 500 ) );
        }

        // Result-bearing push (draft|final): INSERT FIRST, then archive the prior
        // live rows (everything except this new capture). Insert-before-archive
        // makes the supersede atomic from the practitioner's view — a failed
        // insert leaves the prior result live instead of archiving into a void.
        if ( $this->capture_insert_new( $cap, $client, $consult, $parsed ) ) {
            $this->archive_prior_except( $client, $consult, $cap );
            return true;
        }
        // A concurrent push raced us to the UNIQUE(capture_id) key — re-read and
        // fold this delivery into the now-existing row (terminal-wins).
        $row = $this->row_by_capture( $cap );
        if ( $row ) {
            return $this->capture_upsert_existing( $row, $parsed );
        }
        return new WP_Error( 'db_error', 'Could not persist capture.', array( 'status' => 500 ) );
    }

    /** Create the first row for an unseen capture_id (native row: job_id = capture_id). */
    private function capture_insert_new( $cap, $client, $consult, $parsed ) {
        global $wpdb;
        // Resolve the practitioner from the consultation link so display + the
        // per-client IDOR gate work; a missing link still inserts (durable store)
        // but stays invisible to any practitioner (fail-closed on display).
        $prac = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT practitioner_user_id FROM {$wpdb->prefix}hdlv2_form_progress
              WHERE id = %d AND client_user_id = %d AND deleted_at IS NULL LIMIT 1",
            $consult, $client ) );

        $is_error  = ( $parsed['status'] === 'error' );
        $finalized = ( ! $is_error && ! empty( $parsed['finalized'] ) );
        $result    = ( ! $is_error && isset( $parsed['result'] ) ) ? $parsed['result'] : null;
        $status    = $is_error ? 'error' : ( $finalized ? 'done' : 'draft' );

        $data = array(
            'client_user_id'       => $client,
            'practitioner_user_id' => $prac,
            'form_progress_id'     => $consult,
            'job_id'               => $cap,   // native row reuses the NOT-NULL job_id = capture_id
            'capture_id'           => $cap,
            'idempotency_key'      => $cap,
            'source'               => 'native',
            'status'               => $status,
            'finalized'            => $finalized ? 1 : 0,
            'eyes_label'           => $result ? $this->eyes_label_from_result( $result ) : 'Left + Right',
            'result_json'          => $result ? wp_json_encode( $result ) : null,
            'cost'                 => isset( $parsed['cost'] ) ? (float) $parsed['cost'] : null,
            'refused'              => ( $is_error && ! empty( $parsed['refused'] ) ) ? 1 : 0,
            'error_message'        => $is_error ? substr( (string) ( isset( $parsed['error'] ) ? $parsed['error'] : 'Analysis failed' ), 0, 500 ) : null,
            'analysed_at'          => current_time( 'mysql' ),
            'captured_at'          => $finalized ? current_time( 'mysql' ) : null,
        );
        $formats = array( '%d','%d','%d','%s','%s','%s','%s','%s','%d','%s','%s','%f','%d','%s','%s','%s' );

        // Suppress so a UNIQUE(capture_id) race surfaces as false (folded by caller),
        // not a PHP notice / fatal on the server-to-server callback path.
        $prev = $wpdb->suppress_errors( true );
        $ok = $wpdb->insert( self::table(), $data, $formats );
        $wpdb->suppress_errors( $prev );
        if ( false === $ok ) {
            return false;
        }
        if ( $result && ! empty( $parsed['images'] ) ) {
            $this->persist_images( $cap, $parsed['images'] ); // job_id == capture_id
        }
        return true;
    }

    /** Fold a re-arriving push into the existing capture row (terminal-wins). */
    private function capture_upsert_existing( $row, $parsed ) {
        global $wpdb;
        if ( $row->status === 'archived' ) {
            return true; // superseded by a newer capture — ignore late delivery
        }
        $incoming_error     = ( $parsed['status'] === 'error' );
        $incoming_finalized = ! empty( $parsed['finalized'] );
        $row_has_result     = in_array( $row->status, array( 'draft', 'done' ), true );
        $row_is_final       = ( (int) $row->finalized === 1 || $row->status === 'done' );

        if ( $incoming_error ) {
            if ( $row_has_result ) {
                return true; // a captured result (draft|done) is never downgraded to error
            }
            $wpdb->update( self::table(), array(
                'status'        => 'error',
                'refused'       => ! empty( $parsed['refused'] ) ? 1 : 0,
                'error_message' => substr( (string) ( isset( $parsed['error'] ) ? $parsed['error'] : 'Analysis failed' ), 0, 500 ),
                'analysed_at'   => current_time( 'mysql' ),
            ), array( 'id' => (int) $row->id ), array( '%s', '%d', '%s', '%s' ), array( '%d' ) );
            return true;
        }

        if ( $row_is_final && ! $incoming_finalized ) {
            return true; // a late auto-safety-net DRAFT must not un-finalise a FINAL row
        }

        // Apply the done/draft result (idempotent for a same-state re-push).
        $result  = isset( $parsed['result'] ) ? $parsed['result'] : array();
        $update  = array(
            'status'      => $incoming_finalized ? 'done' : 'draft',
            'finalized'   => $incoming_finalized ? 1 : 0,
            'result_json' => wp_json_encode( $result ),
            'cost'        => isset( $parsed['cost'] ) ? (float) $parsed['cost'] : null,
            'refused'     => 0,
            'eyes_label'  => $this->eyes_label_from_result( $result ),
            'analysed_at' => current_time( 'mysql' ),
        );
        $formats = array( '%s', '%d', '%s', '%f', '%d', '%s', '%s' );
        if ( $incoming_finalized && empty( $row->captured_at ) ) {
            $update['captured_at'] = current_time( 'mysql' );
            $formats[] = '%s';
        }
        $wpdb->update( self::table(), $update, array( 'id' => (int) $row->id ), $formats, array( '%d' ) );

        if ( ! empty( $parsed['images'] ) ) {
            $this->persist_images( $row->job_id, $parsed['images'] );
        }
        return true;
    }

    private function eyes_label_from_result( $result ) {
        $eyes = ( is_array( $result ) && isset( $result['eyes'] ) && is_array( $result['eyes'] ) ) ? $result['eyes'] : array();
        $codes = array();
        foreach ( $eyes as $e ) {
            if ( isset( $e['eye'] ) ) { $codes[] = $e['eye']; }
        }
        $has_l = in_array( 'L', $codes, true );
        $has_r = in_array( 'R', $codes, true );
        if ( $has_l && $has_r ) { return 'Left + Right'; }
        if ( $has_l ) { return 'Left'; }
        if ( $has_r ) { return 'Right'; }
        return 'Left + Right';
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Photo persistence (raw downscaled photos → private dir; PDF source)
    //  DECISION: RAW photos, not a map composite — HDL has no map-overlay
    //  renderer and the IrisMapper callback returns JSON only. The brief needs
    //  only "two small photos". See the foot-of-file note for the contract gap.
    // ─────────────────────────────────────────────────────────────────────

    private function persist_images( $job_id, $images ) {
        global $wpdb;
        $row = $this->row_by_job( $job_id );
        if ( ! $row ) { return; }
        $dir = self::private_dir();
        if ( ! file_exists( $dir ) ) { wp_mkdir_p( $dir ); @chmod( dirname( rtrim( $dir, '/' ) ), 0700 ); @chmod( rtrim( $dir, '/' ), 0700 ); }
        if ( ! is_writable( $dir ) ) { return; }

        $map = array( 'L' => 'image_l_path', 'R' => 'image_r_path' );
        $fields = array();
        $formats = array();
        foreach ( $map as $eye => $col ) {
            if ( empty( $images[ $eye ] ) || ! is_string( $images[ $eye ] ) ) { continue; }
            $b64 = preg_replace( '#^data:image/[a-z]+;base64,#i', '', $images[ $eye ] );
            $bytes = base64_decode( $b64, true );
            if ( false === $bytes || strlen( $bytes ) < 64 ) { continue; }
            $fname = sanitize_file_name( 'iris-' . (int) $row->id . '-' . strtolower( $eye ) . '-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false ) . '.jpg' );
            if ( file_put_contents( $dir . $fname, $bytes ) !== false ) {
                @chmod( $dir . $fname, 0640 );
                $fields[ $col ] = $fname;
                $formats[] = '%s';
            }
        }
        if ( $fields ) {
            $wpdb->update( self::table(), $fields, array( 'job_id' => $job_id ), $formats, array( '%s' ) );
        }
    }

    /** ?hdlv2_iris_img=<row id>&eye=L|R — cookie-auth, ownership-checked stream. */
    public function maybe_serve_image() {
        if ( ! isset( $_GET['hdlv2_iris_img'] ) ) { return; }
        if ( ! self::enabled() ) { status_header( 404 ); exit; }
        if ( ! is_user_logged_in() ) { auth_redirect(); exit; }
        $id  = absint( $_GET['hdlv2_iris_img'] );
        $eye = ( isset( $_GET['eye'] ) && strtoupper( $_GET['eye'] ) === 'R' ) ? 'R' : 'L';

        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, client_user_id, image_l_path, image_r_path FROM " . self::table() . " WHERE id = %d LIMIT 1", $id ) );
        if ( ! $row ) { status_header( 404 ); exit; }
        $uid = get_current_user_id();
        $ok  = ( (int) $row->client_user_id === $uid )
            || ( class_exists( 'HDLV2_Compatibility' ) && HDLV2_Compatibility::practitioner_owns_client( $uid, (int) $row->client_user_id ) );
        if ( ! $ok ) { status_header( 403 ); exit; }

        $rel = ( $eye === 'R' ) ? $row->image_r_path : $row->image_l_path;
        if ( ! $rel ) { status_header( 404 ); exit; }
        $base = realpath( rtrim( self::private_dir(), '/' ) );
        $real = realpath( self::private_dir() . $rel );
        if ( ! $real || ! $base || strpos( $real, $base ) !== 0 || ! is_file( $real ) ) { status_header( 404 ); exit; }
        while ( ob_get_level() > 0 ) { ob_end_clean(); }
        nocache_headers();
        header( 'Content-Type: image/jpeg' );
        header( 'Content-Length: ' . filesize( $real ) );
        readfile( $real );
        exit;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Reconcile cron — backstop for a lost callback (off the request path)
    // ─────────────────────────────────────────────────────────────────────

    public function cron_reconcile() {
        if ( ! self::enabled() ) { return; }
        if ( '' === self::base() || '' === self::secret() ) { return; }
        global $wpdb;
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - self::RECONCILE_GRACE_S );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT job_id, practitioner_user_id FROM " . self::table() . "
              WHERE status IN ('queued','running') AND updated_at < %s
              ORDER BY updated_at ASC LIMIT 10", $cutoff ) );
        if ( ! $rows ) { return; }
        foreach ( $rows as $r ) {
            // iris-analyse-status now enforces ownership: pass the owning
            // practitioner's email (the join key) that this row already records.
            $email = '';
            if ( ! empty( $r->practitioner_user_id ) ) {
                $u = get_userdata( (int) $r->practitioner_user_id );
                if ( $u ) { $email = $u->user_email; }
            }
            $res = $this->get_iris( 'iris-analyse-status', HDLV2_Iris_Support::build_status_query( $r->job_id, $email ) );
            if ( is_wp_error( $res ) ) { continue; } // breaker/timeout — try next tick
            $status = isset( $res['status'] ) ? $res['status'] : '';
            if ( $status === 'done' || $status === 'error' ) {
                $this->apply_terminal( HDLV2_Iris_Support::parse_callback_body( array(
                    'jobId'   => $r->job_id,
                    'status'  => $status,
                    'result'  => isset( $res['result'] ) ? $res['result'] : array(),
                    'error'   => isset( $res['message'] ) ? $res['message'] : 'Analysis failed',
                    'refused' => ! empty( $res['refused'] ),
                    'cost'    => isset( $res['cost'] ) ? $res['cost'] : null,
                ) ) );
            } elseif ( $status === 'expired' ) {
                $this->set_status( $r->job_id, 'unavailable' );
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Outbound HTTP — breaker-guarded, ≤5 s (protects the OLS LSAPI pool)
    // ─────────────────────────────────────────────────────────────────────

    private function post_iris( $fn, $body ) {
        $base = self::base();
        if ( '' === $base ) {
            return new WP_Error( 'iris_no_base', 'IRISMAPPER_BASE not set', array( 'status' => 500 ) );
        }
        if ( ! $this->breaker_allow() ) {
            return new WP_Error( 'iris_breaker_open', 'Iris analysis temporarily unavailable', array( 'status' => 503 ) );
        }
        $raw = wp_json_encode( $body );
        $headers = array( 'Content-Type' => 'application/json', 'x-hdl-secret' => self::secret() );
        // Replay hardening (optional on staging; harmless to always send — the
        // IrisMapper gate verifies only when IRIS_HMAC_REQUIRED or a sig is set).
        foreach ( HDLV2_Iris_Support::sign_headers( self::secret(), $raw ) as $k => $v ) {
            $headers[ $k ] = $v;
        }
        $resp = wp_remote_post( $base . '/.netlify/functions/' . $fn, array(
            'timeout'     => self::HTTP_TIMEOUT,
            'redirection' => 0,
            'headers'     => $headers,
            'body'        => $raw,
        ) );
        return $this->finish_call( $fn, $resp );
    }

    private function get_iris( $fn, $query ) {
        $base = self::base();
        if ( '' === $base ) { return new WP_Error( 'iris_no_base', 'IRISMAPPER_BASE not set', array( 'status' => 500 ) ); }
        if ( ! $this->breaker_allow() ) {
            return new WP_Error( 'iris_breaker_open', 'unavailable', array( 'status' => 503 ) );
        }
        $url = $base . '/.netlify/functions/' . $fn . '?' . http_build_query( $query );
        $resp = wp_remote_get( $url, array(
            'timeout'     => self::HTTP_TIMEOUT,
            'redirection' => 0,
            'headers'     => array( 'x-hdl-secret' => self::secret() ),
        ) );
        return $this->finish_call( $fn, $resp );
    }

    /** Map a wp_remote_* result to decoded JSON|WP_Error AND update the breaker. */
    private function finish_call( $fn, $resp ) {
        if ( is_wp_error( $resp ) ) {
            $this->breaker_record( false ); // network error / timeout = unreachable
            return $resp;
        }
        $code = (int) wp_remote_retrieve_response_code( $resp );
        $json = json_decode( wp_remote_retrieve_body( $resp ), true );
        // Reachability breaker: a timeout (is_wp_error, handled above) or a 5xx
        // counts as a failure; any other HTTP response (incl. 4xx business codes)
        // proves the upstream is alive → close the breaker (§7.6: 401/404/429 do
        // NOT count). EXCEPT 503 — IrisMapper returns 503 BUSY_GLOBAL as a
        // deliberate, fast self-throttle; the upstream is healthy, so it must not
        // trip the pool-protection breaker during a legitimate burst.
        if ( $code >= 500 && $code !== 503 ) {
            $this->breaker_record( false );
        } else {
            $this->breaker_record( true );
        }
        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'iris_http', 'IrisMapper ' . $fn . ' ' . $code,
                array( 'status' => $code, 'body' => $json ) );
        }
        return is_array( $json ) ? $json : array();
    }

    /** Soft-state classifier for a failed upstream call (fail-closed). */
    private function classify_upstream_error( $err ) {
        $data = $err->get_error_data();
        $status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
        $body   = is_array( $data ) && isset( $data['body'] ) ? $data['body'] : array();
        $code   = is_array( $body ) && isset( $body['code'] ) ? $body['code'] : '';
        if ( $status === 429 && $code === 'LIMIT_REACHED' ) {
            return 'limit';
        }
        return 'unavailable';
    }

    // ── Circuit breaker — shared single MySQL row, atomic UPDATE…WHERE ──
    private function breaker_row() {
        global $wpdb;
        $t = $wpdb->prefix . 'hdlv2_iris_breaker';
        $row = $wpdb->get_row( "SELECT state, failures, opened_at FROM $t WHERE id = 1", ARRAY_A );
        if ( ! $row ) {
            $wpdb->query( "INSERT IGNORE INTO $t (id, state, failures, opened_at) VALUES (1, 'closed', 0, 0)" );
            $row = array( 'state' => 'closed', 'failures' => 0, 'opened_at' => 0 );
        }
        return $row;
    }
    private function breaker_allow() {
        $row = $this->breaker_row();
        $d = HDLV2_Iris_Support::breaker_decide( array(
            'state' => $row['state'], 'failures' => (int) $row['failures'], 'opened_at' => (int) $row['opened_at'],
        ), $this->now_ms() );
        return ! empty( $d['allow'] );
    }
    private function breaker_record( $reachable ) {
        global $wpdb;
        $t = $wpdb->prefix . 'hdlv2_iris_breaker';
        $this->breaker_row(); // ensure the row exists
        if ( $reachable ) {
            $wpdb->query( "UPDATE $t SET state='closed', failures=0, opened_at=0 WHERE id=1" );
            return;
        }
        $now  = $this->now_ms();
        $thr  = HDLV2_Iris_Support::BREAKER_THRESHOLD;
        // Atomic increment + conditional trip in ONE statement (no read race).
        $wpdb->query( $wpdb->prepare(
            "UPDATE $t SET
                opened_at = IF(failures + 1 >= %d, %d, opened_at),
                state     = IF(failures + 1 >= %d, 'open', state),
                failures  = failures + 1
             WHERE id = 1", $thr, $now, $thr ) );
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Small helpers
    // ─────────────────────────────────────────────────────────────────────

    private function current_email() {
        $u = wp_get_current_user();
        return ( $u && $u->ID ) ? $u->user_email : '';
    }
    private function now_ms() {
        return (int) round( microtime( true ) * 1000 );
    }
    private function row_by_job( $job_id ) {
        global $wpdb;
        if ( ! HDLV2_Iris_Support::is_valid_job_id( $job_id ) ) { return null; }
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE job_id = %s LIMIT 1", $job_id ) );
    }
    /** Native-capture dedupe lookup — the row keyed on the deterministic capture_id. */
    private function row_by_capture( $capture_id ) {
        global $wpdb;
        if ( ! HDLV2_Iris_Support::is_valid_capture_id( $capture_id ) ) { return null; }
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE capture_id = %s LIMIT 1", $capture_id ) );
    }
    /** Latest non-archived row for a consultation (mount-time discovery). */
    private function latest_row( $client_id, $progress_id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::table() . "
              WHERE client_user_id = %d AND form_progress_id = %d AND status <> 'archived'
              ORDER BY id DESC LIMIT 1", $client_id, $progress_id ) );
    }
    /** Row by job_id, but only if the current user owns the client (IDOR gate). */
    private function own_job_or_null( $job_id ) {
        $row = $this->row_by_job( $job_id );
        if ( ! $row ) { return null; }
        $uid = get_current_user_id();
        if ( ! HDLV2_Compatibility::practitioner_owns_client( $uid, (int) $row->client_user_id ) ) {
            return null;
        }
        return $row;
    }
    private function set_status( $job_id, $status ) {
        global $wpdb;
        // Never move a row OUT of a terminal/archived state — a late /start
        // replay or a deduped upstream response must not clobber a result the
        // callback already wrote (apply_terminal owns terminal transitions).
        $wpdb->query( $wpdb->prepare(
            "UPDATE " . self::table() . " SET status = %s
              WHERE job_id = %s AND status NOT IN ('done','error','archived')",
            $status, $job_id ) );
    }
    private function archive_prior( $client_id, $progress_id ) {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "UPDATE " . self::table() . " SET status='archived'
              WHERE client_user_id = %d AND form_progress_id = %d AND status <> 'archived'",
            $client_id, $progress_id ) );
    }
    /**
     * Archive every live row for the consultation EXCEPT the given capture_id
     * (the row just inserted). Used by the native-capture insert-then-archive
     * path so a new result supersedes prior rows WITHOUT a window where the
     * consult has nothing live. NULL-capture (legacy embedded) rows are archived
     * too — a new native result supersedes any prior delivery model.
     */
    private function archive_prior_except( $client_id, $progress_id, $keep_capture_id ) {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "UPDATE " . self::table() . " SET status='archived'
              WHERE client_user_id = %d AND form_progress_id = %d AND status <> 'archived'
                AND ( capture_id IS NULL OR capture_id <> %s )",
            $client_id, $progress_id, $keep_capture_id ) );
    }
    /** True if a live (non-archived) captured result exists for this consultation. */
    private function has_live_result( $client_id, $progress_id ) {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM " . self::table() . "
              WHERE client_user_id = %d AND form_progress_id = %d
                AND status IN ('draft','done') LIMIT 1",
            $client_id, $progress_id ) );
    }
    private function push_revision( $row, $prior_json ) {
        global $wpdb;
        if ( $prior_json === null || $prior_json === '' ) { return; }
        $revs = array();
        if ( $row->_revisions ) {
            $decoded = json_decode( $row->_revisions, true );
            if ( is_array( $decoded ) ) { $revs = $decoded; }
        }
        $revs[] = array( 'at' => current_time( 'mysql' ), 'by' => get_current_user_id(), 'json' => $prior_json );
        if ( count( $revs ) > self::REVISIONS_CAP ) {
            $revs = array_slice( $revs, -self::REVISIONS_CAP );
        }
        $wpdb->update( self::table(), array( '_revisions' => wp_json_encode( $revs ) ),
            array( 'id' => (int) $row->id ), array( '%s' ), array( '%d' ) );
    }
}

/*
 * ── PHOTO-PERSISTENCE CONTRACT GAP (flagged for the PDF session) ──
 * The PDF iridology page needs "two small photos". HDL cannot render the
 * IrisMapper map overlay, so the DECISION is to persist the RAW downscaled
 * photos. BUT the real IrisMapper worker DELETES the Supabase blobs on the
 * terminal transition and the callback (buildCallbackBody) carries JSON only —
 * no image bytes, no download URL. So at callback time the photos are already
 * gone and HDL has no way to fetch them. persist_images() above is wired +
 * tested against a callback that DOES carry base64 photos (forward-compat), so
 * the moment IrisMapper adds one of:
 *   (a) photos (base64) in the callback body, OR
 *   (b) signed DOWNLOAD URLs in the callback, OR
 *   (c) retains the blobs until HDL acks,
 * persistence works with no HDL change. Until then the consult tab shows the
 * browser's in-memory copies (this session's scope is the consult E2E; the PDF
 * is the next session). This is the single E2E prerequisite on the photo path.
 */

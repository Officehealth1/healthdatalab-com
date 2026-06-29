<?php
/**
 * Iridology Phase 2 — CAPTURE-ONLY consult flow (WP-coupled wiring).
 *
 * ALL iris work (upload, mapping, analysis) happens on irismapper.com. HDL NEVER
 * triggers an analysis and NEVER calls Opus — it only CAPTURES, RECORDS and
 * DISPLAYS the result IrisMapper pushes, and feeds the Final-Report PDF.
 *
 * Architecture (see /Volumes/Media/irismapper-live/docs/IRIS-PHASE2-NATIVE-CAPTURE-DESIGN.md):
 *   1. The practitioner runs the analysis inside IrisMapper for an HDL client
 *      (bound to clientId:consultationId via the picker / launch context).
 *   2. IrisMapper PUSHes the finished result to POST /iris/analyse/callback
 *      (separate callback secret + HMAC over the raw body), keyed on the
 *      deterministic captureId — insert-on-callback, terminal-wins UPSERT. An
 *      optional `images` object carries short-lived signed download URLs for the
 *      iris photo(s) + map composite, which HDL downloads to its private dir.
 *   3. Browser GET /iris/analysis-status — a PURE local MySQL read (no outbound
 *      IrisMapper call) — drives the consult display + a manual Refresh.
 *   4. Browser render → editable areas overlay (POST /iris/areas-edit); the
 *      original AI text stays immutable. "Include in report PDF" opt-in.
 *   5. GET /iris/clients — IrisMapper's picker pulls the calling practitioner's
 *      OWN clients (signed shared-secret + HMAC).
 *
 * Dark behind hdlv2_ff_iris_consult (layered ON TOP of hdlv2_ff_iris_addon):
 * when off, register_routes() returns early ⇒ every /iris/* route 404s, no
 * REST-index trace, no serve route (Rule-0). Entitlement is re-checked
 * fail-closed inside every handler. HDL makes ZERO outbound *analysis* calls.
 *
 * @package HDL_Longevity_V2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HDLV2_Iris_Consult {

    private static $instance = null;

    const REVISIONS_CAP = 20;        // capped _revisions archive

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function register_hooks() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
        add_action( 'init', array( $this, 'maybe_serve_image' ) );      // cookie-auth / signed image serve
    }

    // ── Flag (Rule-0): BOTH the v1 add-on flag AND the Phase-2 flag ──
    public static function enabled() {
        return HDLV2_Iris_Addon::enabled() && (bool) get_option( 'hdlv2_ff_iris_consult', false );
    }

    // ── Config readers (wp-config constants; server-side only) ──
    // The shared secret guards the inbound GET /iris/clients (picker pull). HDL
    // makes NO outbound *analysis* call, so there is no IRISMAPPER_BASE reader here.
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
        // Clients picker: IrisMapper → HDL (server-to-server). Shared secret
        // (HDL_SHARED_SECRET) + HMAC over the email, checked inside the handler.
        // Returns ONLY the calling practitioner's own clients (no health data).
        register_rest_route( $ns, '/iris/clients', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'rest_clients' ),
            'permission_callback' => '__return_true',
        ) );
    }

    // ── GET /iris/clients — IrisMapper picker pulls the practitioner's clients ──
    //  Auth: x-hdl-secret == HDL_SHARED_SECRET (constant-time) + HMAC over the
    //  email (binds the request to ONE practitioner — no cross-practitioner
    //  replay). Returns ONLY that practitioner's own clients, name+email+the
    //  consultation id to attach to + a coarse stage status. NO health data.
    public function rest_clients( $request ) {
        if ( ! self::enabled() ) {
            return new WP_Error( 'not_found', 'Not found.', array( 'status' => 404 ) );
        }
        $secret = self::secret(); // HDL_SHARED_SECRET (shared with IrisMapper)
        // 1) shared secret — x-hdl-secret, or Authorization: Bearer <secret>.
        $given = (string) $request->get_header( 'x-hdl-secret' );
        if ( '' === $given ) {
            $auth = (string) $request->get_header( 'authorization' );
            if ( 0 === stripos( $auth, 'Bearer ' ) ) { $given = trim( substr( $auth, 7 ) ); }
        }
        if ( '' === $given ) {
            return new WP_Error( 'unauthorized', 'Missing credentials.', array( 'status' => 401 ) );
        }
        if ( '' === $secret || ! hash_equals( $secret, $given ) ) {
            return new WP_Error( 'forbidden', 'Invalid credentials.', array( 'status' => 403 ) );
        }
        // 2) the email being requested (signed over, so it cannot be swapped).
        $email_raw = (string) $request->get_param( 'email' );
        if ( '' === trim( $email_raw ) ) {
            return new WP_Error( 'bad_request', 'email is required.', array( 'status' => 400 ) );
        }
        // 3) HMAC over the raw email param (replay-hardened, ±5 min).
        $v = HDLV2_Iris_Support::verify_clients_request(
            $secret,
            $request->get_header( 'x-hdl-timestamp' ),
            $request->get_header( 'x-hdl-signature' ),
            $email_raw
        );
        if ( empty( $v['ok'] ) ) {
            return new WP_Error( 'forbidden', 'Bad signature.', array( 'status' => 403 ) );
        }
        // 4) resolve the practitioner. Don't leak which emails exist / are
        //    practitioners — a non-practitioner (or unknown) email is a 404.
        $email = strtolower( sanitize_email( $email_raw ) );
        $user  = $email ? get_user_by( 'email', $email ) : false;
        if ( ! $user || ! HDLV2_Compatibility::is_practitioner( (int) $user->ID ) ) {
            return new WP_Error( 'not_found', 'No such practitioner.', array( 'status' => 404 ) );
        }
        // 5) own clients only — strictly scoped to this practitioner_user_id.
        $rows = HDLV2_Compatibility::get_client_rows_for_practitioner( (int) $user->ID );
        $resp = rest_ensure_response( array_values( $rows ) );
        $resp->header( 'Cache-Control', 'no-store' );
        return $resp;
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
    //  Terminal apply (callback path) — CAPTURE-ONLY
    //   • NATIVE-CAPTURE (captureId): insert-on-callback + terminal-wins UPSERT.
    //   • A legacy jobId-only push has no embedded row (HDL no longer mints
    //     them) → 404 (visible misroute). parse_callback_body still understands
    //     the jobId shape for back-compat, but only a captureId is ever applied.
    // ─────────────────────────────────────────────────────────────────────

    private function apply_terminal( $parsed ) {
        // Native-capture push carries a deterministic captureId HDL never minted.
        if ( isset( $parsed['captureId'] ) ) {
            return $this->apply_terminal_capture( $parsed );
        }
        // Capture-only: HDL never mints a jobId row, so a jobId-keyed push has
        // nothing to update. Surface it as a 404 (misrouted/legacy delivery).
        return new WP_Error( 'not_found', 'Unknown capture (capture-only).', array( 'status' => 404 ) );
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
    //  Image persistence — download the iris photo(s) + map composite to the
    //  PRIVATE dir (outside the webroot) and record the per-eye relpaths.
    //
    //  Capture-only contract: the IrisMapper callback's optional `images` object
    //  carries SHORT-LIVED SIGNED DOWNLOAD URLs — { iris:{L,R}, map:{L,R} } —
    //  which HDL fetches server-side (SSRF-guarded, size-capped) at capture, so
    //  the practitioner + the report PDF always render HDL's own copy (the
    //  upstream URLs expire). Back-compat: a base64 `data:` value OR a flat
    //  { L, R } base64 shape is still accepted. Best-effort — a failed image
    //  NEVER fails the callback (the result row is the durable record).
    // ─────────────────────────────────────────────────────────────────────

    private function persist_images( $job_id, $images ) {
        global $wpdb;
        if ( ! is_array( $images ) ) { return; }
        $row = $this->row_by_job( $job_id );
        if ( ! $row ) { return; }
        $dir = self::private_dir();
        if ( ! file_exists( $dir ) ) {
            wp_mkdir_p( $dir );
            @chmod( dirname( rtrim( $dir, '/' ) ), 0750 );
            @chmod( rtrim( $dir, '/' ), 0750 );
        }
        if ( ! is_writable( $dir ) ) {
            // Diagnostic (NOT a silent return): on a fresh VPS the iris dir is
            // often root:root — chown it to the web user (see the deploy runbook).
            error_log( '[HDLV2][iris] image dir not writable, skipping persist: ' . $dir );
            return;
        }

        $work = $this->normalise_images( $images ); // { db_column => source-string }
        if ( ! $work ) { return; }

        $tags = array(
            'image_l_path' => 'iris-l', 'image_r_path' => 'iris-r',
            'map_l_path'   => 'map-l',  'map_r_path'   => 'map-r',
        );
        $fields = array();
        $formats = array();
        foreach ( $work as $col => $src ) {
            $img = $this->fetch_image_bytes( $src );
            if ( ! $img ) { continue; }
            $tag   = isset( $tags[ $col ] ) ? $tags[ $col ] : 'img';
            $fname = sanitize_file_name( 'iris-' . (int) $row->id . '-' . $tag . '-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false ) . '.' . $img['ext'] );
            if ( false !== file_put_contents( $dir . $fname, $img['bytes'] ) ) {
                @chmod( $dir . $fname, 0640 );
                $fields[ $col ] = $fname;
                $formats[] = '%s';
            }
        }
        if ( $fields ) {
            $wpdb->update( self::table(), $fields, array( 'job_id' => $job_id ), $formats, array( '%s' ) );
        }
    }

    /**
     * Map the callback `images` object to { db_column => source }. Accepts the
     * capture-only nested shape { iris:{L,R}, map:{L,R} } and the legacy flat
     * base64 shape { L, R } (raw iris photos only).
     */
    private function normalise_images( $images ) {
        $work   = array();
        $nested = ( isset( $images['iris'] ) && is_array( $images['iris'] ) )
               || ( isset( $images['map'] )  && is_array( $images['map'] ) );
        if ( $nested ) {
            $iris = ( isset( $images['iris'] ) && is_array( $images['iris'] ) ) ? $images['iris'] : array();
            $mapc = ( isset( $images['map'] )  && is_array( $images['map'] ) )  ? $images['map']  : array();
            if ( ! empty( $iris['L'] ) ) { $work['image_l_path'] = $iris['L']; }
            if ( ! empty( $iris['R'] ) ) { $work['image_r_path'] = $iris['R']; }
            if ( ! empty( $mapc['L'] ) ) { $work['map_l_path']   = $mapc['L']; }
            if ( ! empty( $mapc['R'] ) ) { $work['map_r_path']   = $mapc['R']; }
            return $work;
        }
        if ( ! empty( $images['L'] ) ) { $work['image_l_path'] = $images['L']; }
        if ( ! empty( $images['R'] ) ) { $work['image_r_path'] = $images['R']; }
        return $work;
    }

    /** Resolve a source (signed https URL OR base64) → { bytes, ext } | false. */
    private function fetch_image_bytes( $src ) {
        if ( ! is_string( $src ) || '' === $src ) { return false; }
        if ( preg_match( '#^https?://#i', $src ) ) {
            return $this->download_image( $src );
        }
        $b64   = preg_replace( '#^data:image/[a-z0-9.+-]+;base64,#i', '', $src );
        $bytes = base64_decode( $b64, true );
        if ( false === $bytes || strlen( $bytes ) < 64 ) { return false; }
        $ext = $this->image_ext( $bytes );
        return $ext ? array( 'bytes' => $bytes, 'ext' => $ext ) : false;
    }

    /** Download a signed image URL server-side (SSRF-guarded, ≤8 MB). */
    private function download_image( $url ) {
        // SSRF: reject non-http(s) + private/reserved/link-local/metadata hosts
        // (the report-pdf guard catches what wp_safe_remote_get's check misses).
        if ( ! class_exists( 'HDLV2_Report_PDF' ) || HDLV2_Report_PDF::url_targets_reserved_ip( $url ) ) {
            error_log( '[HDLV2][iris] refused unsafe image URL: ' . substr( (string) $url, 0, 120 ) );
            return false;
        }
        $resp = wp_safe_remote_get( $url, array(
            'timeout'             => 15,
            'redirection'         => 2,
            'limit_response_size' => 8 * 1024 * 1024,
        ) );
        if ( is_wp_error( $resp ) ) { return false; }
        $code = (int) wp_remote_retrieve_response_code( $resp );
        if ( $code < 200 || $code >= 300 ) { return false; }
        $ct = strtolower( (string) wp_remote_retrieve_header( $resp, 'content-type' ) );
        if ( '' !== $ct && 0 !== strpos( $ct, 'image/' ) ) { return false; }
        $bytes = wp_remote_retrieve_body( $resp );
        if ( ! is_string( $bytes ) || strlen( $bytes ) < 64 || strlen( $bytes ) > 8 * 1024 * 1024 ) { return false; }
        $ext = $this->image_ext( $bytes );
        return $ext ? array( 'bytes' => $bytes, 'ext' => $ext ) : false;
    }

    /** Magic-byte sniff → jpg|png|webp, or '' if not a supported image. */
    private function image_ext( $bytes ) {
        $sig = substr( (string) $bytes, 0, 12 );
        if ( 0 === strncmp( $sig, "\xFF\xD8\xFF", 3 ) ) { return 'jpg'; }
        if ( 0 === strncmp( $sig, "\x89PNG\r\n\x1a\n", 8 ) ) { return 'png'; }
        if ( 0 === strncmp( $sig, 'RIFF', 4 ) && 'WEBP' === substr( $sig, 8, 4 ) ) { return 'webp'; }
        return '';
    }

    /** ?hdlv2_iris_img=<row id>&eye=L|R&kind=iris|map — cookie-auth, IDOR-checked stream. */
    public function maybe_serve_image() {
        if ( ! isset( $_GET['hdlv2_iris_img'] ) ) { return; }
        if ( ! self::enabled() ) { status_header( 404 ); exit; }
        $id   = absint( $_GET['hdlv2_iris_img'] );
        $eye  = ( isset( $_GET['eye'] ) && strtoupper( $_GET['eye'] ) === 'R' ) ? 'R' : 'L';
        $kind = ( isset( $_GET['kind'] ) && strtolower( $_GET['kind'] ) === 'map' ) ? 'map' : 'iris';

        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, client_user_id, image_l_path, image_r_path, map_l_path, map_r_path
               FROM " . self::table() . " WHERE id = %d LIMIT 1", $id ) );
        if ( ! $row ) { status_header( 404 ); exit; }

        // AUTH: a logged-in owning practitioner or the client (cookie). [Part 5
        // adds a signed, login-less URL for the external PDF fetcher.]
        if ( ! is_user_logged_in() ) { auth_redirect(); exit; }
        $uid = get_current_user_id();
        $ok  = ( (int) $row->client_user_id === $uid )
            || ( class_exists( 'HDLV2_Compatibility' ) && HDLV2_Compatibility::practitioner_owns_client( $uid, (int) $row->client_user_id ) );
        if ( ! $ok ) { status_header( 403 ); exit; }

        $rel = ( 'map' === $kind )
            ? ( $eye === 'R' ? $row->map_r_path   : $row->map_l_path )
            : ( $eye === 'R' ? $row->image_r_path : $row->image_l_path );
        if ( ! $rel ) { status_header( 404 ); exit; }
        $this->stream_private_image( $rel );
    }

    /** Realpath-validate the relpath is inside the private dir and stream it. */
    private function stream_private_image( $rel ) {
        $base = realpath( rtrim( self::private_dir(), '/' ) );
        $real = realpath( self::private_dir() . $rel );
        if ( ! $real || ! $base || strpos( $real, $base ) !== 0 || ! is_file( $real ) ) { status_header( 404 ); exit; }
        $ext = strtolower( pathinfo( $real, PATHINFO_EXTENSION ) );
        $ct  = ( 'png' === $ext ) ? 'image/png' : ( 'webp' === $ext ? 'image/webp' : 'image/jpeg' );
        while ( ob_get_level() > 0 ) { ob_end_clean(); }
        nocache_headers();
        header( 'Content-Type: ' . $ct );
        header( 'Content-Length: ' . filesize( $real ) );
        readfile( $real );
        exit;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Small helpers
    // ─────────────────────────────────────────────────────────────────────

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
 * ── IMAGE PERSISTENCE CONTRACT (capture-only) ──
 * The callback's optional `images` object carries SHORT-LIVED SIGNED DOWNLOAD
 * URLs for the iris photo(s) + the map composite:
 *     images: { iris: { L: <url>, R: <url> }, map: { L: <url>, R: <url> } }
 * persist_images() downloads them server-side (SSRF-guarded, ≤8 MB, magic-byte
 * checked) into the PRIVATE dir (outside the webroot) and records the per-eye
 * relpaths (image_l/r_path = raw iris, map_l/r_path = overlay). Back-compat: a
 * base64 `data:` value OR the legacy flat { L, R } base64 shape still works.
 *
 * HDL-side is fully built + tested (against a pre_http_request mock). The
 * end-to-end IMAGE render still depends on IrisMapper's emit side actually
 * SENDING the `images` object (its worker historically deleted the blobs on the
 * terminal transition); until then the row/result text is the durable record
 * and the consult/PDF render the text. The dir must be web-user-writable
 * (chown the hdlv2-iris dir off root:root — see the deploy runbook).
 */

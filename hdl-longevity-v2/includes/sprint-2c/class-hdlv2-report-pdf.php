<?php
/**
 * HDL V2 — Report PDF: Make→WP callback, file persistence, authenticated serve (D-2).
 *
 * PDFMonkey download URLs are presigned S3 links that expire ~1h (then 403), so
 * we DOWNLOAD + self-host the file on callback and serve it through an
 * ownership-checked route. Mirrors the flight-plan callback
 * (Bearer HDLV2_MAKE_CALLBACK_SECRET, hash_equals).
 *
 * Storage:
 *   - draft / final  → wp_hdlv2_reports     (pdf_stored_path, pdf_url, pdf_generated_at)
 *   - stage-2 WHY    → wp_hdlv2_why_profiles (same cols) — practitioner-only download
 *
 * @since 0.46.32 (D-2)
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class HDLV2_Report_PDF {

    const SUBDIR = 'hdlv2-reports';

    /**
     * Private storage base — OUTSIDE the web root so the PDFs are never
     * URL-addressable. (OpenLiteSpeed ignores Apache .htaccess deny rules, so a
     * file under wp-content/uploads would be publicly downloadable regardless of
     * an unguessable name — unacceptable for health PDFs.) Files are served ONLY
     * through the ownership-checked serve route below. Override with the
     * HDLV2_PRIVATE_DIR constant in wp-config if the server layout differs.
     * Default: a sibling of the WP root (e.g. /var/www/hdlv2-private/hdlv2-reports/).
     */
    private static function private_dir() {
        $base = defined( 'HDLV2_PRIVATE_DIR' )
            ? rtrim( HDLV2_PRIVATE_DIR, '/' )
            : dirname( rtrim( ABSPATH, '/' ) ) . '/hdlv2-private';
        return $base . '/' . self::SUBDIR . '/';
    }

    private static $instance = null;
    public static function get_instance() {
        if ( self::$instance === null ) self::$instance = new self();
        return self::$instance;
    }
    private function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
        add_action( 'init', array( $this, 'maybe_serve_pdf' ) ); // non-REST cookie-auth download
    }

    public function register_routes() {
        register_rest_route( 'hdl-v2/v1', '/reports/pdf-callback', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_pdf_callback' ),
            'permission_callback' => '__return_true', // Bearer secret checked inside (hash_equals)
        ) );
        register_rest_route( 'hdl-v2/v1', '/form/stage1-callback', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_stage1_callback' ),
            'permission_callback' => '__return_true', // Bearer secret checked inside (hash_equals)
        ) );
    }

    private function valid_secret( $request ) {
        $expected = defined( 'HDLV2_MAKE_CALLBACK_SECRET' ) ? HDLV2_MAKE_CALLBACK_SECRET : '';
        $provided = '';
        $auth = $request->get_header( 'authorization' );
        if ( $auth && stripos( $auth, 'Bearer ' ) === 0 ) $provided = trim( substr( $auth, 7 ) );
        return ( ! empty( $expected ) && hash_equals( $expected, $provided ) );
    }

    /** POST /reports/pdf-callback  Body: { report_id, stage:'draft'|'final', pdf_url, token, generated_at } */
    public function rest_pdf_callback( $request ) {
        if ( ! $this->valid_secret( $request ) ) {
            return new WP_Error( 'forbidden', 'Invalid callback secret.', array( 'status' => 403 ) );
        }
        $p         = $request->get_json_params();
        $report_id = absint( $p['report_id'] ?? 0 );
        $stage     = sanitize_key( $p['stage'] ?? '' );
        $src_url   = esc_url_raw( $p['pdf_url'] ?? '' );
        if ( ! $report_id || ! $src_url || ! in_array( $stage, array( 'draft', 'final' ), true ) ) {
            return new WP_Error( 'invalid', 'report_id, stage(draft|final), pdf_url required.', array( 'status' => 400 ) );
        }

        global $wpdb;
        // Resolve + active-guard (reports has no deleted_at; join form_progress).
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT r.id FROM {$wpdb->prefix}hdlv2_reports r
               JOIN {$wpdb->prefix}hdlv2_form_progress fp ON fp.id = r.form_progress_id
              WHERE r.id = %d AND r.report_type = %s AND fp.deleted_at IS NULL LIMIT 1",
            $report_id, $stage
        ) );
        if ( ! $row ) return new WP_Error( 'not_found', 'Report not found or inactive.', array( 'status' => 404 ) );

        $stored = self::fetch_and_store( $src_url, 'report-' . $stage . '-' . $report_id );
        if ( is_wp_error( $stored ) ) {
            // NON-CLOBBER: leave any existing pdf intact. Soft error → Make ignores (non-blocking).
            return new WP_Error( 'fetch_failed', $stored->get_error_message(), array( 'status' => 502 ) );
        }
        // Additive: only the pdf_* columns; never touch report_content / status.
        $wpdb->update(
            $wpdb->prefix . 'hdlv2_reports',
            array(
                'pdf_stored_path'  => $stored['relpath'],
                'pdf_url'          => home_url( '/?hdlv2_report_pdf=' . $report_id ),
                'pdf_generated_at' => current_time( 'mysql' ),
            ),
            array( 'id' => $report_id ),
            array( '%s', '%s', '%s' ), array( '%d' )
        );
        return rest_ensure_response( array( 'success' => true, 'report_id' => $report_id ) );
    }

    /** POST /form/stage1-callback  Body: { stage:'stage1', token, pdf_url, generated_at } — keyed on TOKEN, not report_id. */
    public function rest_stage1_callback( $request ) {
        if ( ! $this->valid_secret( $request ) ) {
            return new WP_Error( 'forbidden', 'Invalid callback secret.', array( 'status' => 403 ) );
        }
        $p       = $request->get_json_params();
        $token   = sanitize_text_field( $p['token'] ?? '' );
        $src_url = esc_url_raw( $p['pdf_url'] ?? '' );
        if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
            return new WP_Error( 'invalid', 'token (64 hex) required.', array( 'status' => 400 ) );
        }
        if ( ! $src_url ) {
            return new WP_Error( 'invalid', 'pdf_url required.', array( 'status' => 400 ) );
        }

        global $wpdb;

        // STEP 1 (existing): confirmed client — form_progress keyed by its real token.
        $pid = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}hdlv2_form_progress WHERE token = %s AND deleted_at IS NULL LIMIT 1",
            $token
        ) );
        if ( $pid ) {
            $ok = self::store_stage1_pdf( $pid, $src_url );
            if ( ! $ok ) {
                // NON-CLOBBER: leave any existing pdf intact. Soft error → Make ignores (non-blocking).
                return new WP_Error( 'fetch_failed', 'Could not fetch/store Stage-1 PDF.', array( 'status' => 502 ) );
            }
            return rest_ensure_response( array( 'success' => true, 'form_progress_id' => $pid ) );
        }

        // STEP 2 (fallback): public widget signup fires the Stage-1 PDF at SUBMIT with a
        // synthetic cache token, BEFORE any form_progress row exists (that row is created
        // later on Confirm, which does NOT re-fire). Capture onto the widget_lead, then
        // forward to form_progress if Confirm has already landed.
        $lead = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, practitioner_user_id, visitor_email
               FROM {$wpdb->prefix}hdlv2_widget_leads WHERE stage1_cache_token = %s ORDER BY id DESC LIMIT 1",
            $token
        ) );
        if ( ! $lead ) return new WP_Error( 'not_found', 'Lead not found or inactive.', array( 'status' => 404 ) );

        $stored = self::fetch_and_store( $src_url, 'stage1lead-' . absint( $lead->id ) );
        if ( is_wp_error( $stored ) ) {
            // NON-CLOBBER: leave any existing pdf intact. Soft error → Make ignores (non-blocking).
            return new WP_Error( 'fetch_failed', $stored->get_error_message(), array( 'status' => 502 ) );
        }
        $rel = $stored['relpath'];
        $now = current_time( 'mysql', true );

        $wpdb->update(
            $wpdb->prefix . 'hdlv2_widget_leads',
            array(
                'stage1_pdf_stored_path'  => $rel,
                'stage1_pdf_generated_at' => $now,
            ),
            array( 'id' => (int) $lead->id ),
            array( '%s', '%s' ), array( '%d' )
        );

        // FORWARD (covers callback-arrives-after-Confirm): reuse the SAME stored file —
        // never re-download. Serve stays form_progress-only, so populate the served URL.
        $fp_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}hdlv2_form_progress
              WHERE client_email = %s AND practitioner_user_id = %d AND deleted_at IS NULL
              ORDER BY id DESC LIMIT 1",
            $lead->visitor_email, (int) $lead->practitioner_user_id
        ) );
        if ( $fp_id ) {
            $wpdb->update(
                $wpdb->prefix . 'hdlv2_form_progress',
                array(
                    'stage1_pdf_stored_path'  => $rel,
                    'stage1_pdf_url'          => home_url( '/?hdlv2_stage1_pdf=' . $fp_id ),
                    'stage1_pdf_generated_at' => $now,
                ),
                array( 'id' => $fp_id ),
                array( '%s', '%s', '%s' ), array( '%d' )
            );
        }

        return rest_ensure_response( array(
            'success'          => true,
            'widget_lead_id'   => (int) $lead->id,
            'form_progress_id' => $fp_id ?: null,
        ) );
    }

    /** Download a remote PDF into the protected uploads dir. Timestamped filename → additive, never overwrites prior files. */
    public static function fetch_and_store( $src_url, $slug ) {
        $resp = wp_remote_get( $src_url, array( 'timeout' => 20, 'redirection' => 3 ) );
        if ( is_wp_error( $resp ) ) return $resp;
        $code  = wp_remote_retrieve_response_code( $resp );
        $body  = wp_remote_retrieve_body( $resp );
        $ctype = (string) wp_remote_retrieve_header( $resp, 'content-type' );
        if ( $code !== 200 || $body === '' )                                            return new WP_Error( 'bad_fetch', "HTTP $code from PDF source" );
        if ( stripos( $ctype, 'pdf' ) === false && substr( $body, 0, 5 ) !== '%PDF-' )   return new WP_Error( 'not_pdf', 'Source is not a PDF' );

        $dir = self::private_dir();
        if ( ! file_exists( $dir ) ) { wp_mkdir_p( $dir ); self::protect_dir( $dir ); }
        if ( ! is_writable( $dir ) ) return new WP_Error( 'no_dir', 'Private PDF dir not writable: ' . $dir );
        $fname = sanitize_file_name( $slug . '-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 8, false ) . '.pdf' );
        $abs   = $dir . $fname;
        if ( file_put_contents( $abs, $body ) === false ) return new WP_Error( 'write_failed', 'Could not write PDF' );
        @chmod( $abs, 0640 );
        return array( 'relpath' => $fname, 'abspath' => $abs );
    }

    private static function protect_dir( $dir ) {
        // The dir is already outside the web root; these are belt-and-braces in
        // case the layout ever changes or HDLV2_PRIVATE_DIR points inside docroot.
        @chmod( $dir, 0700 );
        @file_put_contents( trailingslashit( $dir ) . '.htaccess',
            "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
        @file_put_contents( trailingslashit( $dir ) . 'index.html', '' );
    }

    /** Persist a Stage-2 WHY PDF against why_profiles (called by the stage-2 callback). Non-fatal. */
    public static function store_why_pdf( $form_progress_id, $src_url ) {
        $stored = self::fetch_and_store( $src_url, 'why-' . absint( $form_progress_id ) );
        if ( is_wp_error( $stored ) ) return $stored;
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'hdlv2_why_profiles',
            array(
                'pdf_stored_path'  => $stored['relpath'],
                'pdf_url'          => home_url( '/?hdlv2_why_pdf=' . absint( $form_progress_id ) ),
                'pdf_generated_at' => current_time( 'mysql' ),
            ),
            array( 'form_progress_id' => absint( $form_progress_id ) ),
            array( '%s', '%s', '%s' ), array( '%d' )
        );
        return $stored;
    }

    /** Persist a Stage-1 quick-insight PDF against form_progress (called by the stage-1 callback). Non-fatal. Returns bool. */
    public static function store_stage1_pdf( $form_progress_id, $src_url ) {
        $stored = self::fetch_and_store( $src_url, 'stage1-' . absint( $form_progress_id ) );
        if ( is_wp_error( $stored ) ) return false; // NON-CLOBBER: leave columns untouched.
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'hdlv2_form_progress',
            array(
                'stage1_pdf_stored_path'  => $stored['relpath'],
                'stage1_pdf_url'          => home_url( '/?hdlv2_stage1_pdf=' . absint( $form_progress_id ) ),
                'stage1_pdf_generated_at' => current_time( 'mysql', true ),
            ),
            array( 'id' => absint( $form_progress_id ) ),
            array( '%s', '%s', '%s' ), array( '%d' )
        );
        return true;
    }

    /** Authenticated, ownership-checked streaming download (cookie auth — works from a bare <a href>). */
    public function maybe_serve_pdf() {
        $rid = isset( $_GET['hdlv2_report_pdf'] ) ? absint( $_GET['hdlv2_report_pdf'] ) : 0;
        $wid = isset( $_GET['hdlv2_why_pdf'] )    ? absint( $_GET['hdlv2_why_pdf'] )    : 0;
        $fid = isset( $_GET['hdlv2_fp_pdf'] )     ? absint( $_GET['hdlv2_fp_pdf'] )     : 0;
        $sid = isset( $_GET['hdlv2_stage1_pdf'] ) ? absint( $_GET['hdlv2_stage1_pdf'] ) : 0;
        if ( ! $rid && ! $wid && ! $fid && ! $sid ) return;
        if ( ! is_user_logged_in() ) { auth_redirect(); exit; }
        global $wpdb;
        $uid = get_current_user_id();

        // v0.46.58 — Weekly Flight Plan PDF (direct-render, self-hosted):
        // owning client OR owning practitioner.
        if ( $fid ) {
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT client_id, week_number, pdf_stored_path
                   FROM {$wpdb->prefix}hdlv2_flight_plans
                  WHERE id = %d AND deleted_at IS NULL LIMIT 1", $fid ) );
            if ( ! $row || empty( $row->pdf_stored_path ) ) { status_header( 404 ); exit; }
            $ok = ( (int) $row->client_id === $uid )
               || ( class_exists( 'HDLV2_Compatibility' ) && HDLV2_Compatibility::practitioner_owns_client( $uid, (int) $row->client_id ) );
            if ( ! $ok ) { status_header( 403 ); exit; }
            self::stream( $row->pdf_stored_path, 'HDL-flight-plan-week-' . (int) $row->week_number . '.pdf' );
        }

        if ( $rid ) { // reports: client (owner) OR owning practitioner
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT r.client_user_id, r.report_type, r.pdf_stored_path
                   FROM {$wpdb->prefix}hdlv2_reports r
                   JOIN {$wpdb->prefix}hdlv2_form_progress fp ON fp.id = r.form_progress_id
                  WHERE r.id = %d AND fp.deleted_at IS NULL LIMIT 1", $rid ) );
            if ( ! $row || empty( $row->pdf_stored_path ) ) { status_header( 404 ); exit; }
            $ok = ( (int) $row->client_user_id === $uid )
               || ( class_exists( 'HDLV2_Compatibility' ) && HDLV2_Compatibility::practitioner_owns_client( $uid, (int) $row->client_user_id ) );
            if ( ! $ok ) { status_header( 403 ); exit; }
            self::stream( $row->pdf_stored_path, 'HDL-' . $row->report_type . '-report.pdf' );
        }

        // Stage-1 quick-insight PDF (self-hosted): owning client OR owning practitioner.
        if ( $sid ) {
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT client_user_id, stage1_pdf_stored_path
                   FROM {$wpdb->prefix}hdlv2_form_progress
                  WHERE id = %d AND deleted_at IS NULL LIMIT 1", $sid ) );
            if ( ! $row || empty( $row->stage1_pdf_stored_path ) ) { status_header( 404 ); exit; }
            $ok = ( (int) $row->client_user_id === $uid )
               || ( class_exists( 'HDLV2_Compatibility' ) && HDLV2_Compatibility::practitioner_owns_client( $uid, (int) $row->client_user_id ) );
            if ( ! $ok ) { status_header( 403 ); exit; }
            self::stream( $row->stage1_pdf_stored_path, 'HDL-stage1-quick-insight.pdf' );
        }

        // Stage-2 WHY: PRACTITIONER ONLY (not client-safe — carries practitioner_brief).
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT wp.client_user_id, wp.pdf_stored_path
               FROM {$wpdb->prefix}hdlv2_why_profiles wp
               JOIN {$wpdb->prefix}hdlv2_form_progress fp ON fp.id = wp.form_progress_id
              WHERE wp.form_progress_id = %d AND fp.deleted_at IS NULL LIMIT 1", $wid ) );
        if ( ! $row || empty( $row->pdf_stored_path ) ) { status_header( 404 ); exit; }
        $is_prac = class_exists( 'HDLV2_Compatibility' ) && HDLV2_Compatibility::practitioner_owns_client( $uid, (int) $row->client_user_id );
        if ( ! $is_prac ) { status_header( 403 ); exit; } // clients explicitly rejected
        self::stream( $row->pdf_stored_path, 'HDL-WHY-profile.pdf' );
    }

    public static function stream( $relpath, $download_name ) {
        $base = realpath( rtrim( self::private_dir(), '/' ) );
        $real = realpath( self::private_dir() . $relpath );
        if ( ! $real || ! $base || strpos( $real, $base ) !== 0 || ! is_file( $real ) ) { status_header( 404 ); exit; }
        // Discard any buffered output (stray whitespace from loaded PHP files /
        // closing-tag newlines) so the binary PDF stream is byte-clean.
        while ( ob_get_level() > 0 ) { ob_end_clean(); }
        nocache_headers();
        header( 'Content-Type: application/pdf' );
        header( 'Content-Disposition: inline; filename="' . sanitize_file_name( $download_name ) . '"' );
        header( 'Content-Length: ' . filesize( $real ) );
        readfile( $real );
        exit;
    }
}
HDLV2_Report_PDF::get_instance();

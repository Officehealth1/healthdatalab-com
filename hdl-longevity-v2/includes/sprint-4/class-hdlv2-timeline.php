<?php
/**
 * Client Timeline — Unified chronological view of all interactions.
 *
 * @package HDL_Longevity_V2
 * @since 0.7.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class HDLV2_Timeline {

    private static $instance = null;
    public static function get_instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }
    private function __construct() {}

    public function register_hooks() {
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
        add_shortcode( 'hdlv2_timeline', array( $this, 'render_shortcode' ) );
    }

    public function register_rest_routes() {
        // Practitioner view
        register_rest_route( 'hdl-v2/v1', '/timeline/(?P<client_id>\d+)', array(
            'methods' => 'GET', 'callback' => array( $this, 'rest_load_practitioner' ),
            'permission_callback' => array( $this, 'check_practitioner' ),
        ) );
        // Client view
        register_rest_route( 'hdl-v2/v1', '/timeline/(?P<client_id>\d+)/client', array(
            'methods' => 'GET', 'callback' => array( $this, 'rest_load_client' ),
            'permission_callback' => '__return_true',
        ) );
        // Add note
        register_rest_route( 'hdl-v2/v1', '/timeline/add-note', array(
            'methods' => 'POST', 'callback' => array( $this, 'rest_add_note' ),
            'permission_callback' => array( $this, 'check_practitioner' ),
        ) );
        // Export
        register_rest_route( 'hdl-v2/v1', '/timeline/(?P<client_id>\d+)/export', array(
            'methods' => 'GET', 'callback' => array( $this, 'rest_export' ),
            'permission_callback' => '__return_true',
        ) );
    }

    public function check_practitioner() {
        return HDLV2_Compatibility::is_practitioner( get_current_user_id() );
    }

    // ── Static feed writer ──
    public static function add_entry( $client_id, $practitioner_id, $entry_type, $title, $summary = '', $detail = null, $source_table = '', $source_id = null, $has_flags = false, $is_private = false ) {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'hdlv2_timeline', array(
            'client_id'       => $client_id,
            'practitioner_id' => $practitioner_id,
            'entry_type'      => $entry_type,
            'title'           => sanitize_text_field( $title ),
            'summary'         => sanitize_textarea_field( $summary ),
            'detail'          => $detail ? wp_json_encode( $detail ) : null,
            'source_table'    => sanitize_text_field( $source_table ),
            'source_id'       => $source_id ? absint( $source_id ) : null,
            'has_flags'       => $has_flags ? 1 : 0,
            'is_private'      => $is_private ? 1 : 0,
        ) );
        return (int) $wpdb->insert_id;
    }

    // ── REST: Practitioner timeline ──
    public function rest_load_practitioner( $request ) {
        $client_id = absint( $request['client_id'] );
        $page      = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
        $per_page  = min( 50, max( 1, (int) $request->get_param( 'per_page' ) ?: 20 ) );
        $type      = sanitize_text_field( $request->get_param( 'type' ) ?: '' );
        $search    = sanitize_text_field( $request->get_param( 'search' ) ?: '' );

        return $this->query_timeline( $client_id, $page, $per_page, $type, $search, false );
    }

    // ── REST: Client timeline (no private entries) ──
    public function rest_load_client( $request ) {
        $client_id = absint( $request['client_id'] );

        // Auth: token-based client validation or practitioner override
        $auth = $this->validate_client_token( $request, $client_id );
        if ( is_wp_error( $auth ) ) return $auth;

        $page      = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
        $per_page  = min( 50, max( 1, (int) $request->get_param( 'per_page' ) ?: 20 ) );
        $type      = sanitize_text_field( $request->get_param( 'type' ) ?: '' );

        return $this->query_timeline( $client_id, $page, $per_page, $type, '', true );
    }

    /**
     * Validate client access via token or practitioner session.
     */
    private function validate_client_token( $request, $client_id ) {
        // Practitioner override
        $user_id = get_current_user_id();
        if ( $user_id && HDLV2_Compatibility::is_practitioner( $user_id ) ) {
            return true;
        }

        $token = $request->get_param( 'token' ) ?: '';
        if ( ! $token || ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
            return new WP_Error( 'unauthorized', 'Authentication required.', array( 'status' => 403 ) );
        }

        global $wpdb;
        $progress = $wpdb->get_row( $wpdb->prepare(
            "SELECT client_user_id FROM {$wpdb->prefix}hdlv2_form_progress WHERE token = %s LIMIT 1", $token
        ) );
        if ( ! $progress || (int) $progress->client_user_id !== (int) $client_id ) {
            return new WP_Error( 'forbidden', 'Access denied.', array( 'status' => 403 ) );
        }

        return true;
    }

    private function query_timeline( $client_id, $page, $per_page, $type, $search, $exclude_private ) {
        global $wpdb;
        $table  = $wpdb->prefix . 'hdlv2_timeline';
        $where  = $wpdb->prepare( "WHERE client_id = %d", $client_id );

        if ( $exclude_private ) $where .= " AND is_private = 0";
        if ( $type ) $where .= $wpdb->prepare( " AND entry_type = %s", $type );
        if ( $search ) $where .= $wpdb->prepare( " AND (title LIKE %s OR summary LIKE %s)", '%' . $wpdb->esc_like( $search ) . '%', '%' . $wpdb->esc_like( $search ) . '%' );

        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table $where" );
        $offset = ( $page - 1 ) * $per_page;
        $rows = $wpdb->get_results( "SELECT * FROM $table $where ORDER BY created_at DESC LIMIT $per_page OFFSET $offset" );

        $items = array();
        foreach ( $rows as $r ) {
            $items[] = array(
                'id'            => (int) $r->id,
                'type'          => $r->entry_type,
                'title'         => $r->title,
                'date'          => $r->date ?? $r->created_at,
                'end_date'      => $r->end_date ?? null,
                'temporal_type' => $r->temporal_type ?? 'instant',
                'category'      => $r->category ?? 'system',
                'source'        => $r->source ?? 'system',
                'severity'      => $r->severity ? (int) $r->severity : null,
                'summary'       => $r->summary,
                'detail'        => json_decode( $r->detail, true ),
                'has_flags'     => (bool) $r->has_flags,
                'flags'         => isset( $r->flags ) ? json_decode( $r->flags, true ) : null,
                'is_private'    => (bool) $r->is_private,
                'created_at'    => $r->created_at,
            );
        }

        $response = rest_ensure_response( $items );
        $response->header( 'X-WP-Total', $total );
        $response->header( 'X-WP-TotalPages', ceil( $total / $per_page ) );
        return $response;
    }

    // ── REST: Add practitioner note ──
    public function rest_add_note( $request ) {
        $params    = $request->get_json_params();
        $client_id = absint( $params['client_id'] ?? 0 );
        $title     = sanitize_text_field( $params['title'] ?? 'Practitioner note' );
        $summary   = sanitize_textarea_field( $params['summary'] ?? '' );
        $private   = ! empty( $params['is_private'] );

        if ( ! $client_id || ! $summary ) {
            return new WP_Error( 'missing_data', 'Client ID and summary required.', array( 'status' => 400 ) );
        }

        $prac_id = get_current_user_id();
        $entry_id = self::add_entry( $client_id, $prac_id, 'note', $title, $summary, null, '', null, false, $private );

        return rest_ensure_response( array( 'success' => true, 'entry_id' => $entry_id ) );
    }

    // ── REST: Export all timeline entries ──
    public function rest_export( $request ) {
        $client_id = absint( $request['client_id'] );

        // Auth: practitioner or client token
        $auth = $this->validate_client_token( $request, $client_id );
        if ( is_wp_error( $auth ) ) return $auth;

        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hdlv2_timeline WHERE client_id = %d ORDER BY COALESCE(date, created_at) ASC, created_at ASC",
            $client_id
        ) );

        $entries = array();
        foreach ( $rows as $r ) {
            $entries[] = array(
                'id'          => (int) $r->id,
                'entry_type'  => $r->entry_type,
                'title'       => $r->title,
                'date'        => $r->date ?? $r->created_at,
                'end_date'    => $r->end_date ?? null,
                'temporal_type' => $r->temporal_type ?? 'instant',
                'category'    => $r->category ?? 'system',
                'source'      => $r->source ?? 'system',
                'summary'     => $r->summary,
                'detail'      => json_decode( $r->detail, true ),
                'severity'    => $r->severity ? (int) $r->severity : null,
                'has_flags'   => (bool) $r->has_flags,
                'flags'       => isset( $r->flags ) ? json_decode( $r->flags, true ) : null,
                'created_at'  => $r->created_at,
            );
        }

        return rest_ensure_response( $entries );
    }

    // ── Shortcode ──
    public function render_shortcode( $atts ) {
        wp_enqueue_script( 'hdlv2-audio-component', HDLV2_PLUGIN_URL . 'assets/js/hdlv2-audio-component.js', array(), HDLV2_VERSION, true );
        wp_enqueue_script( 'hdlv2-timeline', HDLV2_PLUGIN_URL . 'assets/js/hdlv2-timeline.js', array( 'hdlv2-audio-component' ), HDLV2_VERSION, true );
        wp_enqueue_style( 'hdlv2-form', HDLV2_PLUGIN_URL . 'assets/css/hdlv2-form.css', array(), HDLV2_VERSION );
        wp_localize_script( 'hdlv2-timeline', 'hdlv2_timeline', array(
            'api_base'        => rest_url( 'hdl-v2/v1/timeline' ),
            'nonce'           => wp_create_nonce( 'wp_rest' ),
            'is_practitioner' => HDLV2_Compatibility::is_practitioner( get_current_user_id() ),
        ) );
        return '<div id="hdlv2-timeline" class="hdlv2-assessment-root"></div>';
    }
}

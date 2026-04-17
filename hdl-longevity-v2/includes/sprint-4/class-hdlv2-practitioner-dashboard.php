<?php
/**
 * V2 Practitioner Dashboard — shortcode wrapper.
 *
 * Hydrates the practitioner's V2 client roster via the existing
 * /dashboard/clients REST endpoint. This class only wires the shortcode
 * and asset enqueue; all list/status/sort logic lives in JS + the REST
 * endpoint in class-hdlv2-client-status.php.
 *
 * Usage: [hdlv2_practitioner_dashboard]
 *
 * @package HDL_Longevity_V2
 * @since 0.9.6
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class HDLV2_Practitioner_Dashboard {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {}

    public function register_hooks() {
        add_shortcode( 'hdlv2_practitioner_dashboard', array( $this, 'render_shortcode' ) );
    }

    public function render_shortcode( $atts ) {
        if ( ! is_user_logged_in() || ! HDLV2_Compatibility::is_practitioner( get_current_user_id() ) ) {
            return '<p style="text-align:center;color:#888;padding:40px;font-family:Inter,sans-serif;">You must be signed in as a practitioner to view this dashboard.</p>';
        }

        wp_enqueue_script(
            'hdlv2-practitioner-dashboard',
            HDLV2_PLUGIN_URL . 'assets/js/hdlv2-practitioner-dashboard.js',
            array(),
            HDLV2_VERSION,
            true
        );

        // Per-page slugs — filterable so renamed pages keep working.
        $consultation_slug = apply_filters( 'hdlv2_consultation_slug', 'consultation' );
        $flight_plan_slug  = apply_filters( 'hdlv2_flight_plan_slug', 'my-flight-plan' );

        wp_localize_script( 'hdlv2-practitioner-dashboard', 'hdlv2_prac_dashboard', array(
            'api_base'         => rest_url( 'hdl-v2/v1' ),
            'nonce'            => wp_create_nonce( 'wp_rest' ),
            'consultation_url' => home_url( '/' . trim( $consultation_slug, '/' ) . '/' ),
            'flight_plan_url'  => home_url( '/' . trim( $flight_plan_slug, '/' ) . '/' ),
        ) );

        return '<div id="hdlv2-practitioner-dashboard"></div>';
    }
}

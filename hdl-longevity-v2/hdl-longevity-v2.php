<?php
/**
 * Plugin Name: HDL Longevity V2 — Staged Workflow
 * Plugin URI: https://healthdatalab.net
 * Description: V2 longevity workflow: staged intake, WHY profiling, practitioner consultations, weekly flight plans, and AI coaching. Runs alongside the existing Health Data Lab plugin.
 * Version: 0.6.0
 * Author: Health Data Lab
 * Author URI: https://healthdatalab.net
 * License: Proprietary
 * Text Domain: hdl-longevity-v2
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * @package HDL_Longevity_V2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Boot at plugins_loaded priority 20 — V1 loads at default priority (10),
 * so HDL_VERSION is guaranteed to exist by the time we check.
 *
 * This MUST NOT check HDL_VERSION at file include time because WordPress
 * may load V2 before V1 depending on active_plugins array order.
 */
/**
 * Magic link auto-login for V2 Assessment Links.
 *
 * Runs at 'init' — BEFORE the main WP query — because UM's content restriction
 * fires from the_posts filter during the main query, which exits with wp_redirect()
 * before template_redirect ever fires.
 *
 * By logging the user in at init, they're authenticated before UM checks access.
 */
add_action( 'init', function () {
    if ( is_admin() || is_user_logged_in() ) return;

    // ── ?invite=TOKEN → auto-login for Assessment Links ──
    if ( ! empty( $_GET['invite'] ) ) {
        $token = sanitize_text_field( $_GET['invite'] );
        if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) return;

        global $wpdb;
        $invite = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hdlv2_widget_invites WHERE token = %s AND status IN ('pending','opened') LIMIT 1",
            $token
        ) );

        if ( ! $invite ) return;
        if ( ! empty( $invite->expires_at ) && strtotime( $invite->expires_at ) < time() ) return;

        $email = sanitize_email( $invite->client_email );
        $name  = sanitize_text_field( $invite->client_name );
        if ( ! $email ) return;

        // Create or get existing user
        $user_id = email_exists( $email );
        if ( ! $user_id ) {
            $username = sanitize_user( strtolower( preg_replace( '/[^a-zA-Z0-9]/', '', $name ) ) . '_' . wp_rand( 1000, 9999 ), true );
            if ( username_exists( $username ) ) {
                $username .= wp_rand( 100, 999 );
            }

            $user_id = wp_insert_user( array(
                'user_login'   => $username,
                'user_email'   => $email,
                'user_pass'    => wp_generate_password( 24, true, true ),
                'first_name'   => $name,
                'display_name' => $name,
                'role'         => 'um_practitioner-invite',
            ) );

            if ( is_wp_error( $user_id ) ) return;

            // UM auto-approve — bypass admin approval
            update_user_meta( $user_id, 'account_status', 'approved' );
            update_user_meta( $user_id, 'um_user_status', 'approved' );
            update_user_meta( $user_id, 'um_member_directory_data', 'a:1:{s:14:"account_status";s:8:"approved";}' );
            update_user_meta( $user_id, 'hdl_source', 'assessment_link' );
            update_user_meta( $user_id, 'hdl_consumer_user', true );
            update_user_meta( $user_id, 'hdl_invited_by_practitioner', $invite->practitioner_id );
        }

        // Auto-login BEFORE the main query runs
        wp_set_current_user( $user_id );
        wp_set_auth_cookie( $user_id, false );

        // Link client to practitioner in V1 table for dashboard visibility
        if ( class_exists( 'HDLV2_Compatibility' ) ) {
            HDLV2_Compatibility::create_practitioner_client_link( $invite->practitioner_id, $user_id );
        }

        // Mark invite as opened
        if ( $invite->status === 'pending' ) {
            $wpdb->update(
                $wpdb->prefix . 'hdlv2_widget_invites',
                array( 'status' => 'opened', 'opened_at' => current_time( 'mysql' ) ),
                array( 'id' => $invite->id )
            );
        }

        return;
    }

    // ── ?token=TOKEN → auto-login for assessment/checkin pages ──
    if ( ! empty( $_GET['token'] ) ) {
        $token = sanitize_text_field( $_GET['token'] );
        if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) return;

        global $wpdb;
        $progress = $wpdb->get_row( $wpdb->prepare(
            "SELECT client_user_id FROM {$wpdb->prefix}hdlv2_form_progress WHERE token = %s LIMIT 1", $token
        ) );

        if ( $progress && $progress->client_user_id ) {
            wp_set_current_user( $progress->client_user_id );
            wp_set_auth_cookie( $progress->client_user_id, false );
        }
    }

} );

add_action( 'plugins_loaded', function () {

    // V1 plugin must be active
    if ( ! defined( 'HDL_VERSION' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>HDL Longevity V2</strong> requires the Health Data Lab plugin to be active.</p></div>';
        } );
        return;
    }

    // Constants — all prefixed HDLV2_ to avoid collision with V1's HDL_*
    define( 'HDLV2_VERSION', '0.9.3' );
    define( 'HDLV2_DB_VERSION', '2.0' );
    define( 'HDLV2_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
    define( 'HDLV2_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
    define( 'HDLV2_PLUGIN_FILE', __FILE__ );

    // DB upgrade check — creates new tables without requiring deactivate/reactivate
    if ( get_option( 'hdlv2_db_version' ) !== HDLV2_DB_VERSION ) {
        require_once plugin_dir_path( __FILE__ ) . 'includes/class-hdlv2-activator.php';
        HDLV2_Activator::activate();
    }

    // Load classes and boot
    HDL_Longevity_V2::get_instance();

    // Cache-Control headers for V2 REST API — prevents LSCache and Cloudflare caching
    add_filter( 'rest_post_dispatch', function ( $response, $server, $request ) {
        $route = $request->get_route();
        if ( strpos( $route, '/hdl-v2/v1/' ) === 0 ) {
            $response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
            $response->header( 'X-LiteSpeed-Cache-Control', 'no-cache' );
            $response->header( 'Pragma', 'no-cache' );
        }
        return $response;
    }, 10, 3 );

}, 20 );

// Activator / Deactivator — registered outside plugins_loaded so activation hook fires
// even if V1 isn't loaded yet (tables are safe to create regardless).
require_once plugin_dir_path( __FILE__ ) . 'includes/class-hdlv2-activator.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-hdlv2-deactivator.php';

register_activation_hook( __FILE__, array( 'HDLV2_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'HDLV2_Deactivator', 'deactivate' ) );

/**
 * Main V2 plugin class — Singleton
 */
final class HDL_Longevity_V2 {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_dependencies();
        $this->init();
    }

    private function load_dependencies() {
        // Compatibility bridge (read-only V1 access)
        require_once HDLV2_PLUGIN_DIR . 'includes/class-hdlv2-compatibility.php';

        // Sprint 1: Lead Magnet Widget
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-1/class-hdlv2-widget-config.php';
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-1/class-hdlv2-widget-renderer.php';

        // Sprint 2: Staged Form + Rate Calculator + AI + Email
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-2/class-hdlv2-rate-calculator.php';
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-2/class-hdlv2-ai-service.php';
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-2/class-hdlv2-email-templates.php';
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-2/class-hdlv2-staged-form.php';

        // Sprint 2C: Consultation Interface + Final Report
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-2c/class-hdlv2-consultation.php';
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-2c/class-hdlv2-final-report.php';

        // Sprint 3: Shared Audio Component
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-3/class-hdlv2-audio-service.php';

        // Sprint 4: Check-in + Timeline + Client Status
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-4/class-hdlv2-checkin.php';
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-4/class-hdlv2-timeline.php';
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-4/class-hdlv2-client-status.php';

        // Sprint 5: Flight Plan
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-5/class-hdlv2-flight-plan.php';
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-5/class-hdlv2-flight-plan-renderer.php';

        // Context Builder + Monthly Summaries
        require_once HDLV2_PLUGIN_DIR . 'includes/class-hdlv2-context-builder.php';
    }

    private function init() {
        // Sprint 1: Widget config dashboard + REST API + shortcode
        $widget_config = new HDLV2_Widget_Config();
        $widget_config->register_hooks();

        // Sprint 2: Staged assessment form
        $staged_form = new HDLV2_Staged_Form();
        $staged_form->register_hooks();

        // Sprint 2C: Practitioner consultation interface
        $consultation = new HDLV2_Consultation();
        $consultation->register_hooks();

        // Sprint 3: Audio service
        HDLV2_Audio_Service::get_instance()->register_hooks();

        // Sprint 4: Check-in, Timeline, Client Status
        HDLV2_Checkin::get_instance()->register_hooks();
        HDLV2_Timeline::get_instance()->register_hooks();
        HDLV2_Client_Status::get_instance()->register_hooks();

        // Sprint 5: Flight Plan
        HDLV2_Flight_Plan::get_instance()->register_hooks();

        // Context Builder (monthly summary cron)
        HDLV2_Context_Builder::get_instance()->register_hooks();
    }
}

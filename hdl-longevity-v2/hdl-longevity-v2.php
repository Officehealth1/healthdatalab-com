<?php
/**
 * Plugin Name: HDL Longevity V2 — Staged Workflow
 * Plugin URI: https://healthdatalab.net
 * Description: V2 longevity workflow: staged intake, WHY profiling, practitioner consultations, weekly flight plans, and AI coaching. Runs alongside the existing Health Data Lab plugin.
 * Version: 0.9.11
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
    // Priority 1 (see add_action below) so we run before any plugin that might
    // short-circuit via wp_redirect/exit on anonymous access (e.g. UM's
    // content restriction).
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

        // Ensure a form_progress row exists so the assessment page has a token
        // to load with. If the client already started via the widget we reuse
        // their existing row; otherwise we create a fresh empty one.
        $progress = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, token FROM {$wpdb->prefix}hdlv2_form_progress WHERE client_user_id = %d ORDER BY id DESC LIMIT 1",
            $user_id
        ) );
        if ( ! $progress ) {
            $form_token = bin2hex( random_bytes( 32 ) );
            $wpdb->insert(
                $wpdb->prefix . 'hdlv2_form_progress',
                array(
                    'client_user_id'       => $user_id,
                    'client_email'         => $email,
                    'client_name'          => $name,
                    'practitioner_user_id' => (int) $invite->practitioner_id,
                    'token'                => $form_token,
                    'current_stage'        => 1,
                )
            );
        } else {
            $form_token = $progress->token;
        }

        // Redirect straight to the assessment with the form token so the
        // client lands on the right stage with their session auth + token
        // already resolved — no guessing which URL to go to next.
        $assessment_slug = apply_filters( 'hdlv2_assessment_slug', 'assessment' );
        wp_safe_redirect( home_url( '/' . trim( $assessment_slug, '/' ) . '/?token=' . $form_token ) );
        exit;
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

}, 1 );

add_action( 'plugins_loaded', function () {

    // V1 plugin must be active
    if ( ! defined( 'HDL_VERSION' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>HDL Longevity V2</strong> requires the Health Data Lab plugin to be active.</p></div>';
        } );
        return;
    }

    // Constants — all prefixed HDLV2_ to avoid collision with V1's HDL_*
    define( 'HDLV2_VERSION', '0.12.3' );
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

    // Frontend rate-limit awareness module (loaded globally; tiny, cached)
    add_action( 'wp_enqueue_scripts', function () {
        wp_enqueue_script(
            'hdlv2-rate-limit',
            HDLV2_PLUGIN_URL . 'assets/js/hdlv2-rate-limit.js',
            array(),
            HDLV2_VERSION,
            true
        );
    }, 5 );

    // ── Transcriber (in-browser Whisper) ──
    // Register the ES module globally at priority 5 so it's available as a
    // dependency by the time shortcodes render their own audio enqueues.
    // Each of the four audio-component consumers adds 'hdlv2-transcriber' to
    // its audio-component dep array, and WP auto-enqueues the transcriber
    // alongside. This replaces the earlier wp_script_is() auto-enqueue —
    // which ran at wp_enqueue_scripts priority 100 (before shortcode render)
    // and therefore never saw the audio-component as enqueued.
    add_action( 'wp_enqueue_scripts', 'hdlv2_register_transcriber', 5 );
    add_action( 'admin_enqueue_scripts', 'hdlv2_register_transcriber', 5 );

    // Emit type="module" on the transcriber script tag so static imports resolve.
    add_filter( 'script_loader_tag', 'hdlv2_transcriber_module_tag', 10, 3 );

}, 20 );

/**
 * Register (do not enqueue) the transcriber ES module + its localized config.
 * Called at wp_enqueue_scripts priority 5 on both frontend and admin so that
 * any subsequent enqueue naming 'hdlv2-transcriber' as a dep finds it.
 */
function hdlv2_register_transcriber() {
    if ( wp_script_is( 'hdlv2-transcriber', 'registered' ) ) {
        return;
    }

    wp_register_script(
        'hdlv2-transcriber',
        HDLV2_PLUGIN_URL . 'assets/js/hdlv2-transcriber.js',
        array(),
        HDLV2_VERSION,
        true
    );

    // preloadMaster is a tri-state master override consulted by each consumer:
    //   'on'   → force preload everywhere, regardless of what the audio
    //            component was asked to do.
    //   'off'  → disable preload everywhere, regardless of consumer opt-in.
    //   null   → no override. Per-consumer preloadOnIdle option wins.
    // Filter default is null so admins can opt in/out without touching JS.
    $preload_filter = apply_filters( 'hdlv2_whisper_preload_on_idle', null );
    $preload_master = null;
    if ( $preload_filter === true )  { $preload_master = 'on';  }
    if ( $preload_filter === false ) { $preload_master = 'off'; }

    wp_localize_script( 'hdlv2-transcriber', 'HDLV2_TRANSCRIBER_CFG', array(
        'modelName'     => apply_filters( 'hdlv2_whisper_model', 'Xenova/whisper-base' ),
        'workerUrl'     => HDLV2_PLUGIN_URL . 'assets/js/hdlv2-transcriber.worker.js?ver=' . HDLV2_VERSION,
        'remoteHost'    => apply_filters( 'hdlv2_whisper_remote_host', null ),
        'errorEndpoint' => esc_url_raw( rest_url( 'hdl-v2/v1/audio/client-error' ) ),
        'nonce'         => wp_create_nonce( 'wp_rest' ),
        'preloadMaster' => $preload_master,
    ) );
}

/**
 * Add type="module" to the transcriber <script> tag so the browser parses it
 * as an ES module (required for the static import of transformers.min.js).
 */
function hdlv2_transcriber_module_tag( $tag, $handle, $src ) {
    if ( 'hdlv2-transcriber' !== $handle ) {
        return $tag;
    }
    if ( false !== strpos( $tag, 'type="module"' ) ) {
        return $tag;
    }
    return str_replace( '<script ', '<script type="module" ', $tag );
}

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

        // Security: rate limiter + idempotency (loaded BEFORE feature classes
        // so the middleware can wrap every REST route registered downstream).
        require_once HDLV2_PLUGIN_DIR . 'includes/security/class-hdlv2-rate-limiter.php';
        require_once HDLV2_PLUGIN_DIR . 'includes/security/class-hdlv2-rate-limit-policy.php';
        require_once HDLV2_PLUGIN_DIR . 'includes/security/class-hdlv2-rate-limit-middleware.php';
        require_once HDLV2_PLUGIN_DIR . 'includes/security/class-hdlv2-rate-limit-status.php';
        require_once HDLV2_PLUGIN_DIR . 'includes/security/class-hdlv2-idempotency.php';

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

        // Sprint 4: Check-in + Timeline + Client Status + Practitioner Dashboard
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-4/class-hdlv2-checkin.php';
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-4/class-hdlv2-timeline.php';
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-4/class-hdlv2-client-status.php';
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-4/class-hdlv2-practitioner-dashboard.php';

        // Sprint 5: Flight Plan
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-5/class-hdlv2-flight-plan.php';
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-5/class-hdlv2-flight-plan-renderer.php';

        // Context Builder + Monthly Summaries
        require_once HDLV2_PLUGIN_DIR . 'includes/class-hdlv2-context-builder.php';
    }

    private function init() {
        // Security middleware — must run first so it wraps every V2 REST route
        HDLV2_Rate_Limit_Middleware::init();
        ( new HDLV2_Rate_Limit_Status() )->register_hooks();

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

        // Sprint 4: Check-in, Timeline, Client Status, Practitioner Dashboard
        HDLV2_Checkin::get_instance()->register_hooks();
        HDLV2_Timeline::get_instance()->register_hooks();
        HDLV2_Client_Status::get_instance()->register_hooks();
        HDLV2_Practitioner_Dashboard::get_instance()->register_hooks();

        // Sprint 5: Flight Plan
        HDLV2_Flight_Plan::get_instance()->register_hooks();

        // Context Builder (monthly summary cron)
        HDLV2_Context_Builder::get_instance()->register_hooks();
    }
}

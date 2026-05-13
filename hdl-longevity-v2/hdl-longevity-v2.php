<?php
/**
 * Plugin Name: HDL Longevity V2 — Staged Workflow
 * Plugin URI: https://healthdatalab.net
 * Description: V2 longevity workflow: staged intake, WHY profiling, practitioner consultations, weekly flight plans, and AI coaching. Runs alongside the existing Health Data Lab plugin.
 * Version: 0.40.13
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

// Core constants — defined at file scope (not inside plugins_loaded) so
// register_activation_hook callbacks, which fire synchronously during
// `wp plugin activate`, can reference them. The activator needs
// HDLV2_DB_VERSION / HDLV2_VERSION at call time to update version options.
define( 'HDLV2_VERSION', '0.40.13' );
define( 'HDLV2_DB_VERSION', '3.7' );
define( 'HDLV2_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'HDLV2_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'HDLV2_PLUGIN_FILE', __FILE__ );

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

            // NUA ("New User Approve") auto-approve — magic-link clients are
            // pre-vetted (the token came from a practitioner invite) so they
            // must not be blocked by NUA's wp_authenticate_user filter on
            // subsequent logins. Without this, the first auto-login worked
            // (it bypasses wp_authenticate via wp_set_auth_cookie) but the
            // user could never log back in after logout — pw_user_status
            // stayed 'pending' and NUA returned "Invalid username or password".
            // Mirrors V1's pattern at class-health-tracker-practitioner.php:2971.
            // Added 2026-04-27 (v0.22.26).
            if ( class_exists( 'pw_new_user_approve' ) || function_exists( 'pw_new_user_approve' ) ) {
                update_user_meta( $user_id, 'pw_user_status', 'approved' );
                update_user_meta( $user_id, 'pw_user_status_time', gmdate( 'Y-m-d H:i:s' ) );
                delete_user_meta( $user_id, 'pending' );
            }
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

        // Look up existing form_progress so we know whether Stage 1 is done.
        $progress = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, token, stage1_completed_at FROM {$wpdb->prefix}hdlv2_form_progress WHERE client_user_id = %d ORDER BY id DESC LIMIT 1",
            $user_id
        ) );

        // Stage 1 already complete → redirect to /assessment/ so the client
        // resumes at Stage 2/3. This is the "returning user" path.
        if ( $progress && ! empty( $progress->stage1_completed_at ) ) {
            $assessment_slug = apply_filters( 'hdlv2_assessment_slug', 'assessment' );
            wp_safe_redirect( home_url( '/' . trim( $assessment_slug, '/' ) . '/?token=' . $progress->token ) );
            exit;
        }

        // v0.19.2 — Stage 1 NOT done → no redirect. The autologin already
        // authenticated the invite user so UM's access gate is satisfied;
        // the widget page will now render naturally. Stage 1 submission
        // hits rest_capture_lead which:
        //   - runs complete_invite()       → widget_invites.status = completed
        //   - inserts widget_leads row     → analytics stays accurate
        //   - fires Make.com PDF webhook   → Stage 1 PDF generated
        //   - notifies the practitioner    → they learn Stage 1 finished
        //
        // Previously we unconditionally redirected to /assessment/, skipping
        // the widget entirely → invite stuck at 'opened' + no side-effects.
        //
        // If form_progress doesn't exist yet, leave that for complete_signup()
        // to create with widget_invite_id properly linked. Don't pre-insert
        // here; it produced orphan rows with widget_invite_id = NULL.
        return;
    }

    // ── ?prac_login=TOKEN → one-shot practitioner magic-link auto-login ──
    //
    // v0.40.7 — Mirrors the ?invite= flow above but for the practitioner
    // side. The Stage 2 "Release Stage 3" email button (Make.com Module 81)
    // lands here with a 64-hex token; we wp_set_auth_cookie the practitioner
    // then redirect to a clean /clients/?release_progress_id=N URL so the
    // dashboard's deep-link JS (hdlv2-client-list-enhance.js:846
    // applyReleaseDeepLink) scrolls and pulse-highlights the right client row.
    //
    // Token shape    : 64-hex (same regex as client tokens; safe URL char set)
    // Storage        : 30-minute transient `hdlv2_prac_login_<token>`
    // Payload        : { practitioner_id, progress_id, created_at }
    // One-shot       : transient deleted on first read; replay-safe
    // Threat model   : email bearer credential. 30-min TTL keeps the leak
    //                  window small. Validates the practitioner role at use
    //                  time so a deleted/demoted practitioner can't auth.
    //
    // No DB writes other than the transient delete; the slug filter still
    // applies so a future override can move the dashboard without forking
    // this handler.
    if ( ! empty( $_GET['prac_login'] ) ) {
        $prac_token = sanitize_text_field( $_GET['prac_login'] );
        if ( ! preg_match( '/^[a-f0-9]{64}$/', $prac_token ) ) return;

        $transient_key = 'hdlv2_prac_login_' . $prac_token;
        $payload       = get_transient( $transient_key );
        if ( ! is_array( $payload ) ) return; // expired or already consumed

        $prac_id     = (int) ( $payload['practitioner_id'] ?? 0 );
        $progress_id = (int) ( $payload['progress_id'] ?? 0 );

        if ( $prac_id <= 0 || ! class_exists( 'HDLV2_Compatibility' ) ) return;
        if ( ! HDLV2_Compatibility::is_practitioner( $prac_id ) ) return;

        // Delete BEFORE setting cookie — failed redirect must not leave a
        // replayable token behind. The cookie write below is synchronous so
        // the user is fully authenticated regardless of the transient state.
        delete_transient( $transient_key );

        wp_set_current_user( $prac_id );
        wp_set_auth_cookie( $prac_id, false );

        // Strip the prac_login param from the URL — leaks the consumed token
        // into browser history otherwise. release_progress_id is preserved
        // so applyReleaseDeepLink() can scroll+pulse the right client row.
        $slug     = apply_filters( 'hdlv2_practitioner_dashboard_slug', 'clients' );
        $redirect = home_url( '/' . trim( $slug, '/' ) . '/' );
        if ( $progress_id > 0 ) {
            $redirect = add_query_arg( 'release_progress_id', $progress_id, $redirect );
        }
        wp_safe_redirect( $redirect );
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

    // ── ?hdlv2_verify=TOKEN → confirm widget signup → create user → continue to Stage 2 ──
    // Promotes a wp_hdlv2_pending_leads row into a real account + form_progress + widget_leads
    // entry, links to practitioner, notifies the practitioner (only verified leads), and
    // redirects to the assessment page already authenticated.
    if ( ! empty( $_GET['hdlv2_verify'] ) ) {
        $vtoken = sanitize_text_field( $_GET['hdlv2_verify'] );
        if ( preg_match( '/^[a-f0-9]{64}$/', $vtoken )
             && class_exists( 'HDLV2_Widget_Config' )
             && method_exists( 'HDLV2_Widget_Config', 'handle_verification_click' ) ) {
            HDLV2_Widget_Config::handle_verification_click( $vtoken );
            // handle_verification_click() either redirects-and-exits or returns
            // (when token is invalid/expired/already-used), in which case we
            // fall through to the normal page render.
        }
    }

    // ── ?hdlv2_reject=TOKEN → "this wasn't me" link in the verification email ──
    // Marks the pending row as rejected (no account, no further emails for this token).
    if ( ! empty( $_GET['hdlv2_reject'] ) ) {
        $rtoken = sanitize_text_field( $_GET['hdlv2_reject'] );
        if ( preg_match( '/^[a-f0-9]{64}$/', $rtoken )
             && class_exists( 'HDLV2_Widget_Config' )
             && method_exists( 'HDLV2_Widget_Config', 'handle_rejection_click' ) ) {
            HDLV2_Widget_Config::handle_rejection_click( $rtoken );
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

    // ──────────────────────────────────────────────────────────────
    //  v0.31.2 — Token auth bypasses WP cookie/nonce check
    // ──────────────────────────────────────────────────────────────
    //
    // Why this exists: V2 client routes (check-in, draft report, flight plan,
    // audio upload from those pages) all authenticate via a 64-hex token
    // that the route handler validates against `wp_hdlv2_form_progress.token`.
    // They do NOT depend on the WP login cookie.
    //
    // BUT — when a client visits one of those pages, our auto-login at
    // `init` priority 1 calls wp_set_auth_cookie(). The browser then has
    // a WP cookie. WordPress's default rest_cookie_check_errors filter
    // (priority 100 on rest_authentication_errors) sees the cookie, marks
    // the request as "authenticated", and demands a valid X-WP-Nonce. If
    // the page was served from LiteSpeed cache (stale nonce), or the user
    // has a session for a DIFFERENT user than the one wp_create_nonce() saw
    // when the page rendered, the nonce mismatches and every V2 REST call
    // returns 403 "Cookie check failed".
    //
    // Concrete reproduction (kim / progress 50, 2026-05-05):
    //   - Client visits /weekly-check-in/?token=…
    //   - LiteSpeed serves a cached HTML with nonce-for-user-A
    //   - Auto-login fires (or didn't, depending on cache state) → cookie-for-B
    //   - JS fetch /checkin/load → cookie B + nonce A → 403
    //   - V1's credit-balance jQuery call also fails (same wp_rest nonce)
    //
    // Fix: when the request is to an hdl-v2/v1 route AND a 64-hex token is
    // present in the query, body, or multipart payload, return `true` from
    // rest_authentication_errors. That short-circuits subsequent auth filters
    // (the cookie check at priority 100 sees a non-empty $result and bails).
    //
    // Security:
    //   - The token itself is opaque (64 hex chars from random_bytes) and
    //     scoped to exactly one client+practitioner via form_progress.
    //   - Each route handler still validates the token against the DB.
    //   - Routes that REQUIRE a logged-in practitioner (e.g. /clients/list,
    //     /flight-plan/{id}/generate) have their own permission_callback that
    //     returns false for non-practitioners — the token bypass doesn't grant
    //     them practitioner access. We only bypass the COOKIE check; the
    //     ROUTE check is unchanged.
    //   - Token regex matches exactly /^[a-f0-9]{64}$/ — no globs, no looser
    //     fallback, no Authorization header trickery.
    //   - Rate limiting (per-token / per-IP) still applies via the V2
    //     middleware on rest_pre_dispatch.
    add_filter( 'rest_authentication_errors', function ( $result ) {
        // Don't override an existing error from another auth provider.
        if ( is_wp_error( $result ) ) return $result;
        if ( $result === true )       return $result; // already authenticated upstream

        // Only V2 routes care about this bypass.
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
        if ( strpos( $request_uri, '/wp-json/hdl-v2/v1/' ) === false
             && strpos( $request_uri, '/?rest_route=/hdl-v2/v1/' ) === false ) {
            return $result;
        }

        // Look for a token in query / body / multipart.
        $token = '';
        if ( isset( $_GET['token'] ) && is_string( $_GET['token'] ) ) {
            $token = $_GET['token'];
        } elseif ( isset( $_POST['token'] ) && is_string( $_POST['token'] ) ) {
            $token = $_POST['token'];
        } elseif ( isset( $_REQUEST['token'] ) && is_string( $_REQUEST['token'] ) ) {
            $token = $_REQUEST['token'];
        } else {
            // JSON body — read raw input. wp_get_request_data is not
            // available this early, so parse the body ourselves. Cap at
            // 64KB to avoid pathological reads on large multipart uploads
            // that don't carry a token (those will fall through and fail
            // route-level auth, which is correct).
            $ctype = isset( $_SERVER['CONTENT_TYPE'] ) ? (string) $_SERVER['CONTENT_TYPE'] : '';
            if ( stripos( $ctype, 'application/json' ) !== false ) {
                $raw = file_get_contents( 'php://input', false, null, 0, 65536 );
                if ( $raw ) {
                    $decoded = json_decode( $raw, true );
                    if ( is_array( $decoded ) && isset( $decoded['token'] ) && is_string( $decoded['token'] ) ) {
                        $token = $decoded['token'];
                    }
                }
            }
        }

        if ( $token && preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
            // Token-shape valid. Route handler will verify it against
            // wp_hdlv2_form_progress and reject if it doesn't exist.
            return true;
        }

        return $result;
    }, 5 ); // priority 5 — runs BEFORE WP's default cookie check (priority 100)

    // v0.31.2 — prevent LiteSpeed / WP supercache / Cloudflare from caching
    // any client-facing page accessed with a `?token=…` magic-link query
    // string. The page embeds a fresh wp_rest nonce (tied to the
    // auto-logged-in user); a cached version served to a different visitor
    // would carry the wrong nonce and 403 every REST call after page load
    // (the original "Cookie check failed" symptom).
    //
    // Cheap — just emits HTTP headers + a LiteSpeed-specific X-header.
    // Only fires on frontend page loads carrying a 64-hex token, so admin
    // and non-token traffic is untouched.
    add_action( 'template_redirect', function () {
        if ( is_admin() ) return;
        $token = isset( $_GET['token'] )  ? $_GET['token']  : ( isset( $_GET['invite'] ) ? $_GET['invite'] : '' );
        if ( ! $token || ! is_string( $token ) ) return;
        if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) return;
        nocache_headers();
        // LiteSpeed-specific opt-out (header replaces an earlier 'public,...'
        // if a default cache plugin tried to mark the page cacheable).
        if ( ! headers_sent() ) {
            header( 'X-LiteSpeed-Cache-Control: no-cache, esi=on' );
            header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
        }
    }, 1 );

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

    // ── Shared loading primitives (skeletons + progress illusion + optimistic save) ──
    // Registered (not enqueued) at priority 5 so consumers can add
    // 'hdlv2-loading' / 'hdlv2-loading-css' to their dep arrays and WP
    // auto-enqueues them alongside. Tiny — ~140 lines JS + ~120 lines CSS.
    // Loading-system-wide consumers: draft-report, flight-plan, consultation,
    // staged-form, checkin, practitioner-dashboard.
    add_action( 'wp_enqueue_scripts', 'hdlv2_register_loading_helpers', 5 );
    add_action( 'admin_enqueue_scripts', 'hdlv2_register_loading_helpers', 5 );

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
 * Register (do not enqueue) the shared loading-system CSS + JS so consumers
 * (draft-report, flight-plan, consultation, staged-form, checkin, practitioner-
 * dashboard) can depend on 'hdlv2-loading' / 'hdlv2-loading-css' and WP
 * auto-enqueues them. Keeps the loading helpers out of pages that don't
 * need them.
 *
 * @since 0.37.0
 */
function hdlv2_register_loading_helpers() {
    if ( ! wp_style_is( 'hdlv2-loading-css', 'registered' ) ) {
        wp_register_style(
            'hdlv2-loading-css',
            HDLV2_PLUGIN_URL . 'assets/css/hdlv2-loading.css',
            array(),
            HDLV2_VERSION
        );
    }
    if ( ! wp_script_is( 'hdlv2-loading', 'registered' ) ) {
        wp_register_script(
            'hdlv2-loading',
            HDLV2_PLUGIN_URL . 'assets/js/hdlv2-loading.js',
            array(),
            HDLV2_VERSION,
            true
        );
    }
}

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
        // whisper-small (~240MB quantised) is the accuracy target for clinical
        // vocabulary after Matthew flagged whisper-base as too lossy on terms
        // like SIBO / cortisol / mitochondrial. Override via filter if the
        // accuracy diff shows we need to upgrade to whisper-large-v3-turbo.
        'modelName'     => apply_filters( 'hdlv2_whisper_model', 'Xenova/whisper-small' ),
        // Beam search over greedy decoding — material accuracy gain on
        // long-form speech with minimal compute overhead at num_beams=5.
        'numBeams'      => (int) apply_filters( 'hdlv2_whisper_num_beams', 5 ),
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

// Custom cron schedule for the background job worker. Registered at file
// scope (not inside plugins_loaded) so it's available on activation AND on
// every subsequent request — wp_schedule_event validates the recurrence
// name against active cron_schedules at registration time.
add_filter( 'cron_schedules', function ( $schedules ) {
    if ( ! isset( $schedules['hdlv2_five_minutes'] ) ) {
        $schedules['hdlv2_five_minutes'] = array(
            'interval' => 300,
            'display'  => 'HDLV2 — every 5 minutes (audio cleanup, etc.)',
        );
    }
    // v0.30.0 — faster recurrence for the job-queue worker so a queued
    // transcription job doesn't sit pending for up to 5 minutes when the
    // loopback kick (trigger_worker_async) silently fails on slow VPS SSL
    // handshakes. Worst-case latency from enqueue to run drops 5 min → 1 min.
    if ( ! isset( $schedules['hdlv2_one_minute'] ) ) {
        $schedules['hdlv2_one_minute'] = array(
            'interval' => 60,
            'display'  => 'HDLV2 — every 1 minute (job queue worker)',
        );
    }
    return $schedules;
} );

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
        $this->maybe_upgrade_db();
        $this->init();
    }

    /**
     * Idempotent runtime upgrade check.
     *
     * Activator::activate() only runs on plugin activation. On a file-only
     * deploy (scp the new PHP onto LIVE/STBY without clicking Deactivate/
     * Reactivate), any new tables or migrations wouldn't land — which is
     * exactly how we deploy (per the ops runbook).
     *
     * This hook compares the stored hdlv2_db_version option to the
     * compiled HDLV2_DB_VERSION constant on every boot. If they differ,
     * it runs create_tables() + run_migrations() to close the gap, then
     * writes the new version. Cheap when in sync (one option read);
     * only expensive after a real upgrade.
     */
    private function maybe_upgrade_db() {
        $installed = get_option( 'hdlv2_db_version', '0' );
        if ( version_compare( $installed, HDLV2_DB_VERSION, '<' ) ) {
            HDLV2_Activator::upgrade();
        }
        // v0.30.0 — independent of DB-version upgrade. Reconciles the
        // job-queue worker recurrence so existing installs (currently on
        // hdlv2_five_minutes) migrate to hdlv2_one_minute on next boot
        // without requiring a DB schema bump. Cheap (1 option read +
        // 1 cron lookup); idempotent.
        HDLV2_Activator::ensure_worker_schedule_current();
    }

    private function load_dependencies() {
        // Compatibility bridge (read-only V1 access)
        require_once HDLV2_PLUGIN_DIR . 'includes/class-hdlv2-compatibility.php';

        // Practitioner-level reads (canonical logo URL resolver — used by
        // every consumer that surfaces the practitioner's logo)
        require_once HDLV2_PLUGIN_DIR . 'includes/class-hdlv2-practitioner.php';

        // Security: rate limiter + idempotency (loaded BEFORE feature classes
        // so the middleware can wrap every REST route registered downstream).
        require_once HDLV2_PLUGIN_DIR . 'includes/security/class-hdlv2-rate-limiter.php';
        require_once HDLV2_PLUGIN_DIR . 'includes/security/class-hdlv2-rate-limit-policy.php';
        require_once HDLV2_PLUGIN_DIR . 'includes/security/class-hdlv2-rate-limit-middleware.php';
        require_once HDLV2_PLUGIN_DIR . 'includes/security/class-hdlv2-rate-limit-status.php';
        require_once HDLV2_PLUGIN_DIR . 'includes/security/class-hdlv2-idempotency.php';
        require_once HDLV2_PLUGIN_DIR . 'includes/security/class-hdlv2-webhook-monitor.php';

        // Sprint 1: Lead Magnet Widget
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-1/class-hdlv2-widget-config.php';
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-1/class-hdlv2-widget-renderer.php';

        // Sprint 2: Staged Form + Rate Calculator + AI + Email
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-2/class-hdlv2-rate-calculator.php';
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-2/class-hdlv2-ai-service.php';
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-2/class-hdlv2-stage1-commentary.php';
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-2/class-hdlv2-stage2-insight.php';
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-2/class-hdlv2-email-templates.php';
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-2/class-hdlv2-staged-form.php';

        // Sprint 2C: Consultation Interface + Final Report
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-2c/class-hdlv2-consultation.php';
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-2c/class-hdlv2-final-report.php';

        // Phase 5 (v0.18.0) — Client DRAFT Report interactive view
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-2c/class-hdlv2-client-draft-view.php';

        // v0.21.3 — /my-report/ resolver: redirects logged-in clients to
        // their /longevity-draft-report/?t=<token>. Powers the "My Report"
        // menu item for Consumer / Invited Client roles.
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-2c/class-hdlv2-my-report.php';

        // v0.20.17 — V1 trajectory chart ported to PHP/SVG for PDF embedding
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-2c/class-hdlv2-trajectory-svg.php';

        // v0.17.0 — Background job queue (foundation for async work).
        // Loaded before sprint-3 so the audio service's transcribe-async
        // endpoint can reference HDLV2_Job_Queue at route registration.
        require_once HDLV2_PLUGIN_DIR . 'includes/class-hdlv2-job-queue.php';

        // Sprint 3: Shared Audio Component
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-3/class-hdlv2-audio-service.php';
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-3/class-hdlv2-deepgram-service.php';
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-3/class-hdlv2-transcription-jobs.php';

        // Sprint 4: Check-in + Timeline + Client Status + Practitioner Dashboard
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-4/class-hdlv2-checkin.php';
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-4/class-hdlv2-timeline.php';
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-4/class-hdlv2-client-status.php';
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-4/class-hdlv2-practitioner-dashboard.php';
        // v0.32.7 — client-side dashboard (/my-dashboard/) + login_redirect + role guards
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-4/class-hdlv2-client-dashboard.php';

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
        HDLV2_Webhook_Monitor::register_hooks();

        // Sprint 1: Widget config dashboard + REST API + shortcode
        $widget_config = new HDLV2_Widget_Config();
        $widget_config->register_hooks();

        // Sprint 2: Staged assessment form
        $staged_form = new HDLV2_Staged_Form();
        $staged_form->register_hooks();

        // Sprint 2C: Practitioner consultation interface
        $consultation = new HDLV2_Consultation();
        $consultation->register_hooks();

        // v0.21.3 — /my-report/ resolver shortcode + template_redirect
        $my_report = new HDLV2_My_Report();
        $my_report->register_hooks();

        // v0.17.0 — Job queue (worker cron + /jobs/{id}/status REST)
        HDLV2_Job_Queue::get_instance()->register_hooks();
        // Handlers must register AFTER the queue class is loaded so
        // HDLV2_Job_Queue::register_handler() is available.
        HDLV2_Transcription_Jobs::register();

        // Sprint 3: Audio service
        HDLV2_Audio_Service::get_instance()->register_hooks();

        // Sprint 4: Check-in, Timeline, Client Status, Practitioner Dashboard
        HDLV2_Checkin::get_instance()->register_hooks();
        HDLV2_Timeline::get_instance()->register_hooks();
        HDLV2_Client_Status::get_instance()->register_hooks();
        HDLV2_Practitioner_Dashboard::get_instance()->register_hooks();
        HDLV2_Client_Dashboard::get_instance()->register_hooks();

        // Sprint 5: Flight Plan
        HDLV2_Flight_Plan::get_instance()->register_hooks();

        // Context Builder (monthly summary cron)
        HDLV2_Context_Builder::get_instance()->register_hooks();
    }
}

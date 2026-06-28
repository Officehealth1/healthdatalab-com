<?php
/**
 * Plugin Name: HDL Longevity V2 — Staged Workflow
 * Plugin URI: https://healthdatalab.net
 * Description: V2 longevity workflow: staged intake, WHY profiling, practitioner consultations, weekly flight plans, and AI coaching. Runs alongside the existing Health Data Lab plugin.
 * Version: 0.47.30
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
define( 'HDLV2_VERSION', '0.47.30' );
define( 'HDLV2_DB_VERSION', '3.23' );
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

    // v0.41.3 — Skip REST API requests. The handlers below call
    // hdlv2_render_link_card() which sets status_header(410) and echoes an
    // HTML page on stale/invalid magic-link tokens. That's correct for page
    // loads (replaces silent failures with a friendly card), but disastrous
    // for REST callers: client JS does `.then(r => r.json())` and the HTML
    // body makes JSON.parse() throw → `.catch()` fires → user sees the
    // generic "Connection error" string on every button.
    //
    // REST handlers self-authenticate from the ?token= query param — they
    // don't need the init auto-login. All client-facing routes use
    // `permission_callback => '__return_true'` and validate the token in
    // their own handler (see staged-form / checkin / draft-report / flight-plan).
    //
    // Note: WP only defines the REST_REQUEST constant on `parse_request`,
    // which fires AFTER `init`. At our priority-1 init, the constant is
    // not yet set, so we also sniff the request URI for `/wp-json/`. The
    // defined() check stays for forward-compat with any plugin that sets
    // it earlier.
    if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST )
         || ( isset( $_SERVER['REQUEST_URI'] ) && false !== strpos( $_SERVER['REQUEST_URI'], '/wp-json/' ) ) ) {
        return;
    }

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

        if ( ! $invite ) {
            // v0.40.15 — surfaced from silent return. Invite row missing
            // (deleted, never existed, or token forged). Friendly card with
            // clear next step instead of letting the page render anonymously.
            hdlv2_render_link_card(
                'This invitation is no longer valid',
                'The invitation link you clicked could not be found. It may have already been used or your practitioner may have replaced it. Please ask your practitioner to send you a new invitation.',
                '',
                ''
            );
        }
        if ( ! empty( $invite->expires_at ) && strtotime( $invite->expires_at ) < time() ) {
            // v0.40.15 — surfaced from silent return. Invites expire after
            // 72 hours by default; tell the user clearly and point them at
            // their practitioner.
            hdlv2_render_link_card(
                'This invitation has expired',
                'For your security, invitation links expire after a short time. Please ask your practitioner to send you a fresh invitation.',
                '',
                ''
            );
        }

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
        //
        // v0.41.17 — `AND deleted_at IS NULL`. Without it, a soft-deleted
        // client whose old invite/magic-link is still in their inbox could
        // click it, get auto-logged-in, and resume on the archived
        // form_progress row (Stage 2/3, draft report, etc.) — defeating
        // the soft-delete. Now: archived rows are invisible; if there's no
        // active row, we fall through to render the widget page (Stage 1
        // path), which is harmless because the practitioner won't see the
        // result until they create a new invite.
        $progress = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, token, stage1_completed_at FROM {$wpdb->prefix}hdlv2_form_progress
             WHERE client_user_id = %d AND deleted_at IS NULL
             ORDER BY id DESC LIMIT 1",
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
        if ( ! is_array( $payload ) ) {
            // v0.40.15 — surfaced from silent return. Practitioner one-shot
            // tokens are 30-min transients consumed on first use. Second
            // click (forwarded email, browser back) used to land anonymously
            // with no signal. Now show a clear "use normal sign-in" message.
            hdlv2_render_link_card(
                'Sign-in link already used',
                'This one-time sign-in link has already been used or has expired. Please sign in to your practitioner account normally.',
                'Sign in',
                wp_login_url( home_url( '/' . trim( apply_filters( 'hdlv2_practitioner_dashboard_slug', 'clients' ), '/' ) . '/' ) )
            );
        }

        $prac_id     = (int) ( $payload['practitioner_id'] ?? 0 );
        $progress_id = (int) ( $payload['progress_id'] ?? 0 );
        $dest        = isset( $payload['dest'] ) ? (string) $payload['dest'] : 'release';

        if ( $prac_id <= 0 || ! class_exists( 'HDLV2_Compatibility' ) ) return;
        if ( ! HDLV2_Compatibility::is_practitioner( $prac_id ) ) return;

        // Delete BEFORE setting cookie — failed redirect must not leave a
        // replayable token behind. The cookie write below is synchronous so
        // the user is fully authenticated regardless of the transient state.
        delete_transient( $transient_key );

        wp_set_current_user( $prac_id );
        wp_set_auth_cookie( $prac_id, false );

        // Strip the prac_login param from the URL — leaks the consumed token
        // into browser history otherwise. Destination per the token payload:
        //   dest=pending_leads → Pending Leads inbox (#hdlv2-pending-leads),
        //     used for an unconfirmed public red-flag lead (no record yet);
        //   otherwise → release_progress_id so the dashboard scrolls+pulses the
        //     client's row (Stage 2 release + confirmed/AI red-flag records).
        $slug     = apply_filters( 'hdlv2_practitioner_dashboard_slug', 'clients' );
        $base     = home_url( '/' . trim( $slug, '/' ) . '/' );
        if ( 'pending_leads' === $dest ) {
            $redirect = $base . '#hdlv2-pending-leads';
        } elseif ( $progress_id > 0 ) {
            $redirect = add_query_arg( 'release_progress_id', $progress_id, $base );
        } else {
            $redirect = $base;
        }
        wp_safe_redirect( $redirect );
        exit;
    }

    // ── ?token=TOKEN → auto-login for assessment/checkin pages ──
    if ( ! empty( $_GET['token'] ) ) {
        $token = sanitize_text_field( $_GET['token'] );
        if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) return;

        global $wpdb;
        // v0.40.20 — also fetch token_expires_at so we can show a specific
        // "link expired" card (different copy than "not found"). Legacy
        // rows have NULL expiry → treated as no expiry (back-compat).
        //
        // v0.41.17 — `AND deleted_at IS NULL`. Soft-deleted form_progress
        // rows must not resolve, otherwise the client could click an old
        // emailed link and auto-log into an archived assessment.
        $progress = $wpdb->get_row( $wpdb->prepare(
            "SELECT client_user_id, token_expires_at FROM {$wpdb->prefix}hdlv2_form_progress
             WHERE token = %s AND deleted_at IS NULL
             LIMIT 1",
            $token
        ) );

        if ( $progress && ! empty( $progress->token_expires_at )
             && strtotime( $progress->token_expires_at ) < time() ) {
            // v0.40.20 — explicit "expired" card. Distinct from the
            // "not found" branch below so the practitioner / support
            // can tell the user the right next step (ask for a fresh
            // link rather than re-checking their inbox). The
            // hdlv2_render_link_card helper sets 410 Gone + nocache
            // + noindex headers and exits the request.
            hdlv2_render_link_card(
                'This assessment link has expired',
                'For your security, assessment links expire after a period of time. Please ask your practitioner to send you a fresh link, or contact them if you need help continuing your assessment.',
                '',
                ''
            );
        }

        if ( $progress && $progress->client_user_id ) {
            wp_set_current_user( $progress->client_user_id );
            wp_set_auth_cookie( $progress->client_user_id, false );
        } else {
            // v0.40.15 — surfaced from silent fall-through. Token regex was
            // valid (64-hex) but no form_progress row matched, meaning the
            // assessment record was deleted or the token is wrong. Previously
            // we let the page render anonymously and the JS would call
            // /form/load with an invalid token, producing a 404 the user
            // never saw clearly. Render a friendly card instead.
            //
            // Note: we already passed the `is_user_logged_in()` guard at the
            // top of this init callback, so we know the user is anonymous here.
            hdlv2_render_link_card(
                'Link not found',
                'We could not find this assessment. The link may have expired or been replaced. Please use the link in your most recent email from your practitioner.',
                '',
                ''
            );
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

    // E4 (v0.46.47) — the in-browser Whisper transcriber tier was removed:
    // every audio surface routes to server-side Deepgram via
    // /audio/transcribe-async. The hdlv2-transcriber registration, its
    // ES-module loader filter, and the vendor blobs (transformers.min.js +
    // ort wasm, ~22.5MB) are gone.

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
 * Render a self-contained "link expired / invalid" card and exit (v0.40.15).
 *
 * Called from the init-priority-1 magic-link handler when a token has expired
 * or been consumed, replacing the previous bare `return` which left the user
 * looking at a blank or anonymous page with no context. Output is intentionally
 * theme-independent (init runs before theme hooks) — inline-styled card on a
 * neutral background, no nav/footer.
 *
 * Always exits. Always 410 Gone + nocache headers + noindex.
 *
 * @param string $title      Card headline (escaped)
 * @param string $body       Card body text (escaped)
 * @param string $cta_label  Optional CTA button label (escaped)
 * @param string $cta_url    Optional CTA button URL (escaped)
 * @return void              Exits
 */
function hdlv2_render_link_card( $title, $body, $cta_label = '', $cta_url = '' ) {
    if ( ! headers_sent() ) {
        nocache_headers();
        status_header( 410 );
    }
    $cta_html = '';
    if ( $cta_label && $cta_url ) {
        $cta_html = '<a href="' . esc_url( $cta_url ) . '" style="display:inline-block;background:#3d8da0;color:#fff;padding:11px 24px;border-radius:48px;font-size:14px;font-weight:600;text-decoration:none;font-family:Poppins,Inter,sans-serif;">' . esc_html( $cta_label ) . ' &rarr;</a>';
    }
    $html  = '<!DOCTYPE html><html lang="en"><head>';
    $html .= '<meta charset="utf-8">';
    $html .= '<meta name="viewport" content="width=device-width, initial-scale=1">';
    $html .= '<meta name="robots" content="noindex">';
    $html .= '<title>' . esc_html( $title ) . '</title>';
    $html .= '</head><body style="margin:0;background:#fafbfc;font-family:Inter,-apple-system,BlinkMacSystemFont,sans-serif;min-height:100vh;">';
    $html .= '<div style="max-width:560px;margin:80px auto;padding:40px 32px;background:#fff;border:1px solid #e4e6ea;border-radius:14px;box-shadow:0 4px 24px rgba(0,0,0,0.04);text-align:center;">';
    $html .= '<h2 style="font-family:Poppins,Inter,sans-serif;font-size:22px;font-weight:600;color:#111;margin:0 0 10px;">' . esc_html( $title ) . '</h2>';
    $html .= '<p style="font-size:14px;color:#666;margin:0 0 24px;line-height:1.5;">' . esc_html( $body ) . '</p>';
    $html .= $cta_html;
    $html .= '</div></body></html>';
    echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — variables escaped above
    exit;
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
        // v0.40.17 — register the stuck-release nudge cron on every boot
        // so existing installs pick it up without a DB-version bump.
        HDLV2_Activator::ensure_stuck_release_reminder_scheduled();
        // v0.40.19 — register Stage 2 extraction retry cron on every boot.
        HDLV2_Activator::ensure_stage2_extraction_retry_scheduled();
        // v0.41.0 — register the weekly flight-plan cron on every boot. The
        // LIVE plugin pre-dates this cron's existence; without this it would
        // never auto-generate Saturday plans for existing clients.
        HDLV2_Activator::ensure_weekly_flight_plan_scheduled();
        // v0.41.8 (Bug-3) — register attention-digest cron on every boot.
        HDLV2_Activator::ensure_attention_email_cron_scheduled();
    }

    private function load_dependencies() {
        // Compatibility bridge (read-only V1 access)
        require_once HDLV2_PLUGIN_DIR . 'includes/class-hdlv2-compatibility.php';

        // Flags notifier — single notification path for the safety screen + AI scan
        // (Make.com if HDLV2_MAKE_REDFLAG is set, else wp_mail fallback).
        require_once HDLV2_PLUGIN_DIR . 'includes/class-hdlv2-flags-store.php';

        // Practitioner-level reads (canonical logo URL resolver — used by
        // every consumer that surfaces the practitioner's logo)
        require_once HDLV2_PLUGIN_DIR . 'includes/class-hdlv2-practitioner.php';

        // Iridology add-on (IrisMapper) — backend-to-backend HTTP client + 3
        // REST routes (/iris/checkout|status|login). Dark behind the
        // hdlv2_ff_iris_addon feature flag (Rule-0). v1 = purchase + launch.
        require_once HDLV2_PLUGIN_DIR . 'includes/class-hdlv2-iris-addon.php';

        // Iridology Phase 2 (embedded consult) — pure contract helpers + the
        // WP-coupled consult flow (upload/analyse/poll/callback/edit). Layered
        // behind a SECOND flag hdlv2_ff_iris_consult (default OFF) ON TOP of
        // hdlv2_ff_iris_addon, so Phase 1 stays byte-identical when off (Rule-0).
        require_once HDLV2_PLUGIN_DIR . 'includes/class-hdlv2-iris-support.php';
        require_once HDLV2_PLUGIN_DIR . 'includes/class-hdlv2-iris-consult.php';

        // Security: rate limiter + idempotency (loaded BEFORE feature classes
        // so the middleware can wrap every REST route registered downstream).
        require_once HDLV2_PLUGIN_DIR . 'includes/security/class-hdlv2-rate-limiter.php';
        require_once HDLV2_PLUGIN_DIR . 'includes/security/class-hdlv2-rate-limit-policy.php';
        require_once HDLV2_PLUGIN_DIR . 'includes/security/class-hdlv2-rate-limit-middleware.php';
        require_once HDLV2_PLUGIN_DIR . 'includes/security/class-hdlv2-rate-limit-status.php';
        require_once HDLV2_PLUGIN_DIR . 'includes/security/class-hdlv2-idempotency.php';
        require_once HDLV2_PLUGIN_DIR . 'includes/security/class-hdlv2-webhook-monitor.php';
        require_once HDLV2_PLUGIN_DIR . 'includes/security/class-hdlv2-webhook-retry.php';
        // v0.41.26 (W4) — Altituding Stripe → HDL automation tier endpoint.
        // V1-style class name (HDL_*) because it registers under V1's
        // hdl/v1 REST namespace; file lives here because the downstream
        // pipeline (token, widget invite pre-fill, magic link) is V2-owned.
        // Dark behind hdlv2_automation_tier_enabled feature flag.
        require_once HDLV2_PLUGIN_DIR . 'includes/security/class-hdl-paid-report-provisioner.php';

        // Sprint 1: Lead Magnet Widget
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-1/class-hdlv2-widget-config.php';
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-1/class-hdlv2-widget-renderer.php';
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-1/class-hdlv2-safety-screen.php';

        // Sprint 2: Staged Form + Rate Calculator + AI + Email
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-2/class-hdlv2-rate-calculator.php';
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-2/class-hdlv2-ai-service.php';
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-2/class-hdlv2-stage1-commentary.php';
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-2/class-hdlv2-stage2-insight.php';
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-2/class-hdlv2-email-templates.php';
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-2/class-hdlv2-staged-form.php';
        // v0.46.x — async client draft-report generation (queue-backed).
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-2/class-hdlv2-draft-report-jobs.php';

        // Sprint 2C: Consultation Interface + Final Report
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-2c/class-hdlv2-consultation.php';
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-2c/class-hdlv2-final-report.php';
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-2c/class-hdlv2-report-pdf.php'; // D-2: report-PDF callback + authenticated serve
        // v0.46.0 — async Final Report job handlers (queue-backed generation).
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-2c/class-hdlv2-report-jobs.php';

        // W8 (v0.41.30) — Automation-tier self-reported consultation shortcode
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-2c/class-hdlv2-auto-consultation.php';

        // Phase 5 (v0.18.0) — Client DRAFT Report interactive view
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-2c/class-hdlv2-client-draft-view.php';

        // v0.21.3 — /my-report/ resolver: redirects logged-in clients to
        // their /longevity-draft-report/?t=<token>. Powers the "My Report"
        // menu item for Consumer / Invited Client roles.
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-2c/class-hdlv2-my-report.php';

        // v0.20.17 — V1 trajectory chart ported to PHP/SVG for PDF embedding
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-2c/class-hdlv2-trajectory-svg.php';
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-2c/class-hdlv2-flight-notes.php';
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-2c/class-hdlv2-flight-notes-service.php';
        // v0.46.58 — direct WP→PDFMonkey renderers (no Make): weekly Flight
        // Plan PDF + Final Report re-render on milestone edits.
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-5/class-hdlv2-flight-plan-pdf-service.php';
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-2c/class-hdlv2-final-report-pdf-service.php';
        // v0.46.x — async Flight Notes render job handler (queue-backed, like reports).
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-2c/class-hdlv2-flight-notes-jobs.php';

        // v0.17.0 — Background job queue (foundation for async work).
        // Loaded before sprint-3 so the audio service's transcribe-async
        // endpoint can reference HDLV2_Job_Queue at route registration.
        require_once HDLV2_PLUGIN_DIR . 'includes/class-hdlv2-job-queue.php';

        // Sprint 3: Shared Audio Component
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-3/class-hdlv2-audio-service.php';
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-3/class-hdlv2-deepgram-service.php';
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-3/class-hdlv2-transcription-jobs.php';
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-2/class-hdlv2-redflag-jobs.php';

        // Sprint 4: Check-in + Timeline + Client Status + Practitioner Dashboard
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-4/class-hdlv2-checkin.php';
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-4/class-hdlv2-timeline.php';
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-4/class-hdlv2-client-status.php';
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-4/class-hdlv2-practitioner-dashboard.php';
        // v0.32.7 — client-side dashboard (/my-dashboard/) + login_redirect + role guards
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-4/class-hdlv2-client-dashboard.php';
        // v0.41.8 (Bug-3) — daily digest cron for needs_attention clients
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-4/class-hdlv2-attention-cron.php';
        // v0.41.16 — Tools → V2 Restore admin page for soft-deleted form_progress rows
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-4/class-hdlv2-admin-restore.php';

        // W13 (v0.41.34) — Automation Tier admin page (top-level menu)
        require_once HDLV2_PLUGIN_DIR . 'includes/class-hdlv2-admin-automation-tier.php';

        // Sprint 5: Flight Plan
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-5/class-hdlv2-flight-plan.php';
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-5/class-hdlv2-flight-plan-renderer.php';
        // v0.46.x — async manual Flight Plan generation (queue-backed, like reports).
        require_once HDLV2_PLUGIN_DIR . 'includes/sprint-5/class-hdlv2-flight-plan-jobs.php';

        // Context Builder + Monthly Summaries
        require_once HDLV2_PLUGIN_DIR . 'includes/class-hdlv2-context-builder.php';
    }

    private function init() {
        // Security middleware — must run first so it wraps every V2 REST route
        HDLV2_Rate_Limit_Middleware::init();
        ( new HDLV2_Rate_Limit_Status() )->register_hooks();
        HDLV2_Webhook_Monitor::register_hooks();

        // v0.40.20 — Webhook retry mechanism. Each fire helper that has a
        // re-fire path (Draft Report / Final Report / Flight Plan PDF)
        // registers itself below so HDLV2_Webhook_Retry::run_scheduled_retry
        // can dispatch to it. Cron hooks are registered eagerly so the
        // scheduled events fire even if the registering class hasn't been
        // instantiated yet on the cron-tick request.
        HDLV2_Webhook_Retry::register_cron_hooks();
        HDLV2_Webhook_Retry::register_handler( 'draft', array( 'HDLV2_Staged_Form', 'refire_draft_webhook' ) );
        HDLV2_Webhook_Retry::register_handler( 'final', array( 'HDLV2_Final_Report', 'refire_final_webhook' ) );
        HDLV2_Webhook_Retry::register_handler( 'flight_pdf', array( 'HDLV2_Flight_Plan', 'refire_flight_plan_webhook' ) );

        // v0.41.26 (W4) — Paid-flow REST endpoint (Altituding Stripe →
        // automation tier). Dark behind hdlv2_automation_tier_enabled
        // flag; with flag false every request returns 503.
        HDL_Paid_Report_Provisioner::get_instance()->register_hooks();

        // Sprint 1: Widget config dashboard + REST API + shortcode
        $widget_config = new HDLV2_Widget_Config();
        $widget_config->register_hooks();

        // Sprint 2: Staged assessment form
        $staged_form = new HDLV2_Staged_Form();
        $staged_form->register_hooks();

        // Sprint 2C: Practitioner consultation interface
        $consultation = new HDLV2_Consultation();
        $consultation->register_hooks();

        // W8 (v0.41.30) — Automation-tier self-reported consultation
        $auto_consultation = new HDLV2_Auto_Consultation();
        $auto_consultation->register_hooks();

        // v0.21.3 — /my-report/ resolver shortcode + template_redirect
        $my_report = new HDLV2_My_Report();
        $my_report->register_hooks();

        // v0.17.0 — Job queue (worker cron + /jobs/{id}/status REST)
        HDLV2_Job_Queue::get_instance()->register_hooks();
        // Handlers must register AFTER the queue class is loaded so
        // HDLV2_Job_Queue::register_handler() is available.
        HDLV2_Transcription_Jobs::register();
        HDLV2_Redflag_Jobs::register();
        // v0.46.0 — Final Report generation now runs on the job queue.
        HDLV2_Report_Jobs::register();
        // v0.46.x — Flight Notes PDF render now runs on the job queue too.
        HDLV2_Flight_Notes_Jobs::register();
        // v0.46.x — manual Flight Plan regeneration now runs on the job queue.
        HDLV2_Flight_Plan_Jobs::register();
        // v0.46.66 — auto / check-in / finalise generation now runs on the queue
        // too (robust under DISABLE_WP_CRON on STBY + LIVE; retry + alert).
        HDLV2_Flight_Plan_Auto_Jobs::register();
        HDLV2_Flight_Plan_PDF_Service::register();   // v0.46.58 — render_flight_plan_pdf
        HDLV2_Final_Report_PDF_Service::register();  // v0.46.58 — render_final_report_pdf
        // v0.46.x — client draft-report generation now runs on the job queue.
        HDLV2_Draft_Report_Jobs::register();

        // Sprint 3: Audio service
        HDLV2_Audio_Service::get_instance()->register_hooks();

        // Sprint 4: Check-in, Timeline, Client Status, Practitioner Dashboard
        HDLV2_Checkin::get_instance()->register_hooks();
        HDLV2_Timeline::get_instance()->register_hooks();
        HDLV2_Client_Status::get_instance()->register_hooks();
        HDLV2_Practitioner_Dashboard::get_instance()->register_hooks();
        HDLV2_Client_Dashboard::get_instance()->register_hooks();
        // Iridology add-on (IrisMapper) — REST routes for checkout/status/login.
        // Dark behind hdlv2_ff_iris_addon (require_practitioner() returns false
        // when the flag is off, so the routes 401 — Rule-0).
        HDLV2_Iris_Addon::get_instance()->register_hooks();
        // Iridology Phase 2 (embedded consult) — REST routes (analyse/start/
        // analysis-status/callback/areas-edit), the ?hdlv2_iris_img serve route,
        // and the hdlv2_iris_reconcile cron handler. Dark behind
        // hdlv2_ff_iris_consult (register_routes() returns early when off, so
        // /iris/analyse* 404s — Rule-0). The callback route registers even when
        // off? No: it self-guards on the flag too (no flag ⇒ no callback route).
        HDLV2_Iris_Consult::get_instance()->register_hooks();
        // v0.41.8 (Bug-3) — daily attention digest cron handler.
        HDLV2_Attention_Cron::get_instance()->register_hooks();
        // v0.41.16 — admin restore page (Tools → V2 Restore).
        HDLV2_Admin_Restore::get_instance()->register_hooks();

        // W13 (v0.41.34) — Automation Tier admin page (top-level menu).
        HDLV2_Admin_Automation_Tier::get_instance()->register_hooks();

        // Sprint 5: Flight Plan
        HDLV2_Flight_Plan::get_instance()->register_hooks();
        HDLV2_Flight_Notes_Service::get_instance()->register_hooks();

        // Context Builder (monthly summary cron)
        HDLV2_Context_Builder::get_instance()->register_hooks();
    }
}

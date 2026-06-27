<?php
/**
 * Iridology add-on (IrisMapper) — HTTP client + 3 REST routes.
 *
 * v1 scope: PURCHASE + LAUNCH only. A practitioner can buy the IrisMapper
 * iridology add-on and launch into IrisMapper /app signed-in. NOT the
 * per-consultation capture, NOT the report-PDF iridology page (later builds).
 *
 * Architecture (see docs/plans/IRIS-ADDON-WIRING-PLAN-2026-06-23.md):
 *   - All three IrisMapper calls are backend-to-backend (the shared secret
 *     never reaches the browser).
 *   - Join key = the logged-in PRACTITIONER's WP email, derived server-side
 *     only (wp_get_current_user()->user_email). NEVER a client email.
 *   - Everything is dark until get_option('hdlv2_ff_iris_addon', false) is
 *     true (Rule-0: flag off ⇒ require_practitioner() returns false ⇒ 401,
 *     no card, no tab, no exposed routes).
 *   - FAIL-CLOSED: any error/timeout/non-200 ⇒ treated as "not entitled".
 *
 * Config (wp-config.php, STBY → IrisMapper staging; live → irismapper.com):
 *   define( 'HDL_SHARED_SECRET', '<same value as IrisMapper env>' );
 *   define( 'IRISMAPPER_BASE',   'https://irismapper-staging.netlify.app' );
 *
 * @package HDL_Longevity_V2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HDLV2_Iris_Addon {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function register_hooks() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    // ── Config readers (wp-config constants; mirrors the defined()?:'' pattern) ──

    private static function base() {
        return defined( 'IRISMAPPER_BASE' ) ? rtrim( IRISMAPPER_BASE, '/' ) : '';
    }
    private static function secret() {
        return defined( 'HDL_SHARED_SECRET' ) ? HDL_SHARED_SECRET : '';
    }
    public static function enabled() {
        return (bool) get_option( 'hdlv2_ff_iris_addon', false );
    }

    // ── HTTP client (one place; WP HTTP API) ──

    /**
     * POST a JSON body to an IrisMapper Netlify function.
     *
     * @param string $fn          Function slug under /.netlify/functions/.
     * @param array  $body        Request payload (json-encoded).
     * @param bool   $with_secret Send the x-hdl-secret header (#1 + #3 only).
     * @return array|WP_Error     Decoded JSON on 2xx, else WP_Error w/ ['status'].
     */
    private static function call( $fn, $body, $with_secret ) {
        $base = self::base();
        if ( '' === $base ) {
            return new WP_Error( 'iris_no_base', 'IRISMAPPER_BASE not set', array( 'status' => 500 ) );
        }
        $headers = array( 'Content-Type' => 'application/json' );
        if ( $with_secret ) {
            $headers['x-hdl-secret'] = self::secret();
        }
        $resp = wp_remote_post( $base . '/.netlify/functions/' . $fn, array(
            'timeout'     => 8,
            'redirection' => 0,
            'headers'     => $headers,
            'body'        => wp_json_encode( $body ),
        ) );
        if ( is_wp_error( $resp ) ) {
            return $resp; // network error / timeout → caller fails closed
        }
        $code = (int) wp_remote_retrieve_response_code( $resp );
        $json = json_decode( wp_remote_retrieve_body( $resp ), true );
        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'iris_http', 'IrisMapper ' . $fn . ' ' . $code, array( 'status' => $code, 'body' => $json ) );
        }
        return is_array( $json ) ? $json : array();
    }

    /** Contract #1 — entitlement lookup (carries the shared secret). */
    public static function check_entitlement( $email ) {
        return self::call( 'hdl-check-entitlement', array( 'email' => $email ), true );
    }

    /**
     * Contract #2 — create a Stripe Checkout session (NO secret).
     *
     * IMPORTANT (verified contract): create-checkout-simple appends
     * `?email=…&session_id=…` to the successUrl with a literal "?", so the
     * successUrl passed here MUST be query-string-free. The browser detects
     * the post-checkout return via the appended `session_id` param — there is
     * no `?iris=success` flag. A plain cancel returns to the bare dashboard
     * URL, which the JS reads as a normal load (upsell stays).
     *
     * @return string|WP_Error  Stripe Checkout URL, or WP_Error.
     */
    public static function create_checkout( $email, $success, $cancel ) {
        $r = self::call( 'create-checkout-simple', array(
            'email'      => $email,
            'addon'      => true,
            'tier'       => 'practitioner',
            'plan'       => 'monthly',
            'successUrl' => $success,
            'cancelUrl'  => $cancel,
        ), false );
        if ( is_wp_error( $r ) ) {
            return $r;
        }
        return isset( $r['url'] ) ? $r['url'] : new WP_Error( 'iris_no_url', 'no checkout url', array( 'status' => 502 ) );
    }

    /** Contract #3 — single-use auto-login URL into /app (carries the shared secret). */
    public static function auto_login( $email ) {
        return self::call( 'hdl-auto-login', array( 'email' => $email ), true ); // → { loginUrl, expiresAt }
    }

    // ── REST routes (namespace hdl-v2/v1; permission = practitioner + flag) ──

    public function register_routes() {
        // Rule-0: with the master flag off the routes are not even registered,
        // so /iris/* returns 404 (no trace in the REST index), not a 401.
        // require_practitioner() below is belt-and-braces for the flag-on case.
        if ( ! self::enabled() ) {
            return;
        }
        $ns   = 'hdl-v2/v1';
        $perm = array( $this, 'require_practitioner' );
        register_rest_route( $ns, '/iris/checkout', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_checkout' ),
            'permission_callback' => $perm,
        ) );
        register_rest_route( $ns, '/iris/status', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'rest_status' ),
            'permission_callback' => $perm,
        ) );
        register_rest_route( $ns, '/iris/login', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_login' ),
            'permission_callback' => $perm,
        ) );
    }

    /**
     * Master guard + practitioner check. Flag off ⇒ false ⇒ 401 (Rule-0:
     * the routes are invisible/unusable until the feature flag is on).
     */
    public function require_practitioner() {
        if ( ! self::enabled() ) {
            return false;
        }
        $uid = get_current_user_id();
        return $uid && HDLV2_Compatibility::is_practitioner( $uid );
    }

    /** The logged-in practitioner's WP email — the only join key. */
    private function current_email() {
        $u = wp_get_current_user();
        return ( $u && $u->ID ) ? $u->user_email : '';
    }

    /**
     * POST /iris/checkout — create a Stripe Checkout session and hand the URL
     * back to the browser, which redirects there. Success/cancel URLs are
     * HDL-owned and query-string-free so the buyer always lands back on the
     * practitioner dashboard (never stranded on irismapper.com).
     */
    public function rest_checkout( $request ) {
        $email = $this->current_email();
        if ( ! is_email( $email ) ) {
            return new WP_Error( 'iris_no_email', 'No practitioner email', array( 'status' => 400 ) );
        }
        $slug = apply_filters( 'hdlv2_practitioner_dashboard_slug', 'clients' );
        $dash = home_url( '/' . trim( $slug, '/' ) . '/' ); // query-string-free (see create_checkout note)
        $url  = self::create_checkout( $email, $dash, $dash );
        if ( is_wp_error( $url ) ) {
            return $url;
        }
        return rest_ensure_response( array( 'url' => $url ) );
    }

    /**
     * GET /iris/status — entitlement booleans for the current practitioner.
     * Returns only the practitioner's own booleans + tier/status (their own
     * data); never an email, never the secret. `?fresh=1` busts the 5-min
     * transient first so the post-checkout poll sees the freshly-provisioned
     * state immediately.
     */
    public function rest_status( $request ) {
        $uid = get_current_user_id();
        if ( $request && '1' === (string) $request->get_param( 'fresh' ) ) {
            delete_transient( 'hdlv2_irido_addon_' . (int) $uid );
        }
        $e = HDLV2_Compatibility::iris_entitlement( $uid );
        return rest_ensure_response( array(
            'entitled'           => (bool) $e['iridologyAddon'],
            'found'              => (bool) $e['found'],
            'subscriptionTier'   => $e['subscriptionTier'],
            'subscriptionStatus' => $e['subscriptionStatus'],
        ) );
    }

    /**
     * POST /iris/login — mint a single-use IrisMapper auto-login URL for the
     * current practitioner. The JS opens it in a new tab. 404 ⇒ no IrisMapper
     * account yet; 403 ⇒ add-on not active.
     */
    public function rest_login( $request ) {
        $email = $this->current_email();
        if ( ! is_email( $email ) ) {
            return new WP_Error( 'iris_no_email', 'No practitioner email', array( 'status' => 400 ) );
        }
        $r = self::auto_login( $email );
        if ( is_wp_error( $r ) ) {
            $status = (int) ( $r->get_error_data()['status'] ?? 502 );
            if ( 404 === $status ) {
                return new WP_Error( 'iris_no_account', 'No IrisMapper account for this email yet — check your email to finish setup.', array( 'status' => 404 ) );
            }
            if ( 403 === $status ) {
                return new WP_Error( 'iris_inactive', 'Your IrisMapper add-on is not active.', array( 'status' => 403 ) );
            }
            return new WP_Error( 'iris_login_failed', 'Could not sign you in to IrisMapper. Please try again.', array( 'status' => $status ) );
        }
        if ( empty( $r['loginUrl'] ) ) {
            return new WP_Error( 'iris_no_loginurl', 'No login URL returned', array( 'status' => 502 ) );
        }
        return rest_ensure_response( array( 'loginUrl' => $r['loginUrl'] ) );
    }
}

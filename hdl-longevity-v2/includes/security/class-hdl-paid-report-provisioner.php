<?php
/**
 * Paid Report Provisioner — Altituding Stripe webhook → HDL automation tier.
 *
 * Sibling of V1's HDL_Consumer_Provisioner (mirrors the request/auth/user-
 * creation pattern; does NOT extend or modify V1's class). Class name uses
 * V1's HDL_* prefix because the route lives under V1's hdl/v1 REST namespace
 * (Altituding's existing webhook plumbing posts there). File lives under V2's
 * plugin directory because the downstream pipeline — automation token,
 * widget invite pre-fill, paid-flow magic link — is entirely V2-owned.
 *
 * Endpoint shape (POST /wp-json/hdl/v1/paid-report-provision):
 *   Headers
 *     X-HDL-Paid-Report-Key: <HMAC matching HDL_PAID_REPORT_PROVISION_KEY>
 *   Body (JSON)
 *     email             string  required  sanitize_email
 *     name              string  required  sanitize_text_field, max 255
 *     stripe_session_id string  required  sanitize_text_field, max 128
 *     programme         string  required  sanitize_text_field, max 64
 *     tier              string  required  enum: automation | practitioner
 *     source            string  optional  sanitize_text_field, max 32, default 'altituding_paid'
 *
 * Response 200
 *   { token, magic_link_url, status, user_id, idempotent, email_sent, request_id }
 * Response 503  — feature flag disabled (the endpoint is dark)
 * Response 401  — bad/missing HMAC
 * Response 429  — rate-limited (per-IP)
 * Response 400  — body validation failure
 * Response 500  — internal error (user creation, DB insert, etc.)
 *
 * Order of operations in handle_request:
 *   1. Flag check FIRST. If hdlv2_automation_tier_enabled !== true, return
 *      503 immediately. No HMAC, no DB read, no logging of body content.
 *   2. HMAC verification.
 *   3. Per-IP rate limit (V1's HDL_Rate_Limiter, 30/hour bucket
 *      'paid_report_provision').
 *   4. Body sanitisation + validation.
 *   5. Idempotency: check wp_hdlv2_automation_tokens for matching
 *      stripe_session_id. If found, return existing token + URL.
 *   6. User: get_user_by(email) reuse existing OR wp_create_user new with
 *      role um_client (tier=automation) or um_practitioner-invite
 *      (tier=practitioner). Mirror V1's create_consumer_user UM-approval
 *      meta dance so wp-admin / UM don't show pending state.
 *   7. User_meta: hdlv2_tier, hdlv2_purchased_via, hdlv2_stripe_session_id
 *      (always written, even for existing users — paid-flow specific).
 *   8. Token: 64-char hex bin2hex(random_bytes(32)).
 *   9. Pre-fill snapshot: SELECT stage1_data FROM hdlv2_widget_leads WHERE
 *      visitor_email = email AND status = 'confirmed' ORDER BY created_at
 *      DESC LIMIT 1. If found, copy JSON to a new wp_hdlv2_widget_invites
 *      row keyed by the token. practitioner_id = 0 (no practitioner yet —
 *      automation tier client is unassigned at provisioning time).
 *  10. Insert wp_hdlv2_automation_tokens row, capture id, write back to
 *      user_meta hdlv2_automation_token_id.
 *  11. wp_mail magic-link email (on STBY caught by mu-plugin mail-redirect
 *      when in capture mode; on LIVE V2 delivers to real recipient).
 *  12. Log request_id + ip + body field names (NOT values — no PII in log)
 *      + response status + outcome label.
 *
 * Rollback: every commit referencing this class can be reverted independently.
 *           The wp_hdlv2_automation_tokens table is W3 schema (separate
 *           commit); rolling back this file disables the endpoint without
 *           dropping the table. Existing token rows remain queryable.
 *
 * @package HDL_Longevity_V2
 * @since   0.41.26
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HDL_Paid_Report_Provisioner {

    /** @var self|null */
    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    public function register_hooks() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    public function register_routes() {
        register_rest_route( 'hdl/v1', '/paid-report-provision', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_request' ),
            // permission_callback is __return_true so we always reach the
            // handler: the spec requires the flag check to be the FIRST
            // gate (before HMAC). Doing auth in the handler lets us return
            // 503 from a flagged-off endpoint regardless of HMAC validity.
            'permission_callback' => '__return_true',
        ) );
    }

    public function handle_request( $request ) {
        $request_id = wp_generate_uuid4();
        $ip         = $this->get_ip();

        // ── 1. FLAG CHECK FIRST. Endpoint is dark when flag is false.
        if ( get_option( 'hdlv2_automation_tier_enabled', false ) !== true ) {
            return new WP_REST_Response( array(
                'error'      => 'automation_tier_disabled',
                'message'    => 'Automation tier is not enabled on this server.',
                'request_id' => $request_id,
            ), 503 );
        }

        $user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';

        // ── 2. HMAC verification.
        $expected = defined( 'HDL_PAID_REPORT_PROVISION_KEY' ) ? HDL_PAID_REPORT_PROVISION_KEY : '';
        $provided = $request->get_header( 'X-HDL-Paid-Report-Key' );
        if ( empty( $expected ) || empty( $provided ) || ! hash_equals( (string) $expected, (string) $provided ) ) {
            $this->log_request( $request_id, $ip, $user_agent, $request, 401, 'invalid_hmac' );
            return new WP_REST_Response( array(
                'error'      => 'unauthorized',
                'request_id' => $request_id,
            ), 401 );
        }

        // ── 3. Per-IP rate limit (V1's rate limiter; this endpoint is
        //       Altituding-webhook-triggered so volume is naturally low —
        //       30/hour is plenty of headroom for retry storms).
        if ( class_exists( 'HDL_Rate_Limiter' ) ) {
            $rl = new HDL_Rate_Limiter();
            if ( ! $rl->check_limit( 'paid_report_provision', $ip, 30, HOUR_IN_SECONDS ) ) {
                $this->log_request( $request_id, $ip, $user_agent, $request, 429, 'rate_limited' );
                return new WP_REST_Response( array(
                    'error'      => 'rate_limited',
                    'request_id' => $request_id,
                ), 429 );
            }
        }

        // ── 4. Body sanitisation + validation.
        $email             = sanitize_email( (string) $request->get_param( 'email' ) );
        $name              = $this->trim_to( (string) $request->get_param( 'name' ), 255 );
        $stripe_session_id = $this->trim_to( (string) $request->get_param( 'stripe_session_id' ), 128 );
        $programme         = $this->trim_to( (string) $request->get_param( 'programme' ), 64 );
        $tier              = sanitize_text_field( (string) $request->get_param( 'tier' ) );
        $source_raw        = (string) ( $request->get_param( 'source' ) !== null ? $request->get_param( 'source' ) : 'altituding_paid' );
        $source            = $this->trim_to( $source_raw, 32 );
        if ( '' === $source ) {
            $source = 'altituding_paid';
        }

        $missing = array();
        if ( ! is_email( $email ) ) {
            $missing[] = 'email';
        }
        if ( '' === $name ) {
            $missing[] = 'name';
        }
        if ( '' === $stripe_session_id ) {
            $missing[] = 'stripe_session_id';
        }
        if ( '' === $programme ) {
            $missing[] = 'programme';
        }
        if ( ! in_array( $tier, array( 'automation', 'practitioner' ), true ) ) {
            $missing[] = 'tier';
        }
        if ( ! empty( $missing ) ) {
            $this->log_request( $request_id, $ip, $user_agent, $request, 400, 'invalid_body:' . implode( ',', $missing ) );
            return new WP_REST_Response( array(
                'error'           => 'invalid_body',
                'invalid_fields'  => $missing,
                'message'         => 'One or more required fields failed validation.',
                'request_id'      => $request_id,
            ), 400 );
        }

        // ── 5. Idempotency check on stripe_session_id.
        global $wpdb;
        $tokens_table = $wpdb->prefix . 'hdlv2_automation_tokens';
        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, token, status FROM $tokens_table WHERE stripe_session_id = %s LIMIT 1",
            $stripe_session_id
        ) );
        if ( $existing ) {
            $this->log_request( $request_id, $ip, $user_agent, $request, 200, 'idempotent_reuse' );
            return new WP_REST_Response( array(
                'token'          => $existing->token,
                'magic_link_url' => $this->build_magic_link_url( $existing->token ),
                'status'         => $existing->status,
                'idempotent'     => true,
                'request_id'     => $request_id,
            ), 200 );
        }

        // ── 6. User: reuse or create.
        $existing_user = get_user_by( 'email', $email );
        if ( $existing_user ) {
            $user_id     = (int) $existing_user->ID;
            $created_new = false;
        } else {
            $role        = ( 'automation' === $tier ) ? 'um_client' : 'um_practitioner-invite';
            $user_id     = $this->create_user( $email, $name, $role );
            $created_new = true;
            if ( is_wp_error( $user_id ) ) {
                $this->log_request( $request_id, $ip, $user_agent, $request, 500, 'user_creation_failed' );
                return new WP_REST_Response( array(
                    'error'      => 'user_creation_failed',
                    'message'    => $user_id->get_error_message(),
                    'request_id' => $request_id,
                ), 500 );
            }
        }

        // ── 7. User meta (always — paid-flow markers, harmless to overwrite).
        update_user_meta( $user_id, 'hdlv2_tier', $tier );
        update_user_meta( $user_id, 'hdlv2_purchased_via', 'altituding' );
        update_user_meta( $user_id, 'hdlv2_stripe_session_id', $stripe_session_id );

        // ── 8. Token (64-char hex).
        $token = bin2hex( random_bytes( 32 ) );

        // ── 9. Pre-fill snapshot widget_leads → widget_invites.
        $prefill_json = $this->snapshot_widget_lead_prefill( $email );
        $invite_id    = $this->create_widget_invite( $token, $email, $name, $prefill_json );
        if ( 0 === $invite_id ) {
            $this->log_request( $request_id, $ip, $user_agent, $request, 500, 'invite_insert_failed:' . $wpdb->last_error );
            return new WP_REST_Response( array(
                'error'      => 'invite_insert_failed',
                'request_id' => $request_id,
            ), 500 );
        }

        // ── 10. Insert automation token row.
        $insert_ok = $wpdb->insert(
            $tokens_table,
            array(
                'token'             => $token,
                'client_email'      => $email,
                'client_name'       => $name,
                'programme'         => $programme,
                'tier'              => $tier,
                'stripe_session_id' => $stripe_session_id,
                'status'            => 'issued',
                'source'            => $source,
                // issued_at + created_at fall to CURRENT_TIMESTAMP defaults.
            ),
            array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
        );
        if ( false === $insert_ok ) {
            $this->log_request( $request_id, $ip, $user_agent, $request, 500, 'token_insert_failed:' . $wpdb->last_error );
            return new WP_REST_Response( array(
                'error'      => 'token_insert_failed',
                'message'    => $wpdb->last_error,
                'request_id' => $request_id,
            ), 500 );
        }
        $token_id = (int) $wpdb->insert_id;
        update_user_meta( $user_id, 'hdlv2_automation_token_id', $token_id );

        // ── 11. Magic-link email.
        $magic_link_url = $this->build_magic_link_url( $token );
        $email_sent     = $this->send_magic_link_email( $email, $name, $magic_link_url );

        // ── 12. Log + respond.
        $outcome = $created_new ? 'provisioned_new_user' : 'provisioned_existing_user';
        $this->log_request( $request_id, $ip, $user_agent, $request, 200, $outcome );

        return new WP_REST_Response( array(
            'token'          => $token,
            'magic_link_url' => $magic_link_url,
            'status'         => 'issued',
            'user_id'        => $user_id,
            'idempotent'     => false,
            'email_sent'     => (bool) $email_sent,
            'request_id'     => $request_id,
        ), 200 );
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────

    /**
     * sanitize_text_field + length cap. Empty if input is whitespace-only.
     */
    private function trim_to( $value, $max_len ) {
        $clean = sanitize_text_field( $value );
        if ( strlen( $clean ) > $max_len ) {
            $clean = substr( $clean, 0, $max_len );
        }
        return trim( $clean );
    }

    /**
     * Create a WP user with UM-approval flags pre-set. Mirrors V1's
     * HDL_Consumer_Provisioner::create_consumer_user() approach.
     */
    private function create_user( $email, $name, $role ) {
        $base = sanitize_user( substr( $email, 0, strpos( $email, '@' ) ?: strlen( $email ) ), true );
        if ( '' === $base ) {
            $base = 'hdlclient';
        }
        $username = $base;
        $counter  = 1;
        while ( username_exists( $username ) ) {
            $username = $base . $counter;
            $counter++;
            if ( $counter > 999 ) {
                return new WP_Error( 'username_clash', 'Could not derive a unique username from email.' );
            }
        }

        $password = wp_generate_password( 32, true, true );

        // Suppress UM's own welcome emails — we send our own magic-link.
        $transient_key = 'hdl_consumer_email_' . md5( strtolower( trim( $email ) ) );
        set_transient( $transient_key, array(
            'email'                => $email,
            'skip_external_emails' => true,
        ), 300 );

        $user_id = wp_create_user( $username, $password, $email );
        if ( is_wp_error( $user_id ) ) {
            return $user_id;
        }

        if ( '' !== $name ) {
            $parts = explode( ' ', $name, 2 );
            wp_update_user( array(
                'ID'           => $user_id,
                'display_name' => $name,
                'first_name'   => $parts[0],
                'last_name'    => isset( $parts[1] ) ? $parts[1] : '',
            ) );
        }

        // Guard flags so V1 / UM don't fire their own approval emails.
        update_user_meta( $user_id, 'hdl_auto_approved', true );
        update_user_meta( $user_id, 'hdl_invite_approval_done', 'yes' );
        update_user_meta( $user_id, 'hdl_welcome_credits_sent', 'yes' );

        if ( function_exists( 'UM' ) ) {
            UM()->roles()->set_role( $user_id, $role );
            // UM-approval meta (mirror V1's exact list).
            update_user_meta( $user_id, 'account_status', 'approved' );
            update_user_meta( $user_id, 'um_account_status', 'approved' );
            update_user_meta( $user_id, '_um_account_status', 'approved' );
            update_user_meta( $user_id, 'approved', 1 );
            update_user_meta( $user_id, '_um_approved', 1 );
            update_user_meta( $user_id, 'um_approved', 1 );
            update_user_meta( $user_id, '_um_email_verified', 1 );
            update_user_meta( $user_id, 'um_email_verified', 1 );
        } else {
            $user = new WP_User( $user_id );
            $user->set_role( $role );
        }

        // "New User Approve" plugin compatibility (V1 sets these too).
        update_user_meta( $user_id, 'pw_user_status', 'approved' );
        update_user_meta( $user_id, 'pw_user_status_time', gmdate( 'Y-m-d H:i:s' ) );
        delete_user_meta( $user_id, 'pending' );

        return (int) $user_id;
    }

    /**
     * Look up the most recent widget_leads.stage1_data for this email,
     * across all practitioners. Returns the JSON string (or null).
     */
    private function snapshot_widget_lead_prefill( $email ) {
        global $wpdb;
        $leads_table = $wpdb->prefix . 'hdlv2_widget_leads';
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT stage1_data FROM $leads_table
             WHERE visitor_email = %s AND status = 'confirmed'
             ORDER BY created_at DESC
             LIMIT 1",
            $email
        ) );
        return $row ? $row->stage1_data : null;
    }

    /**
     * Insert a wp_hdlv2_widget_invites row tied to the new token.
     *
     * practitioner_id = 0 sentinel because automation-tier paid clients
     * arrive without a practitioner assignment. Matthew is the only V2
     * practitioner today and will message provisioned clients post-launch;
     * formal assignment can be back-filled in a later phase if needed.
     *
     * Expiry: 90 days. Paid clients may not open the link immediately
     * (long enough to survive holidays / forgotten purchases).
     */
    private function create_widget_invite( $token, $email, $name, $prefill_json ) {
        global $wpdb;
        $invites_table = $wpdb->prefix . 'hdlv2_widget_invites';
        $expires_at    = gmdate( 'Y-m-d H:i:s', time() + ( 90 * DAY_IN_SECONDS ) );

        $ok = $wpdb->insert(
            $invites_table,
            array(
                'practitioner_id' => 0,
                'token'           => $token,
                'client_name'     => $name,
                'client_email'    => $email,
                'status'          => 'pending',
                'expires_at'      => $expires_at,
                'prefill_stage1'  => $prefill_json,
                'source'          => 'automation',
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
        );
        return $ok ? (int) $wpdb->insert_id : 0;
    }

    /**
     * Magic-link URL. Filter override available for non-Altituding flows.
     */
    private function build_magic_link_url( $token ) {
        return apply_filters(
            'hdl_paid_report_magic_link_url',
            'https://altituding.com/longevity-assessment?t=' . rawurlencode( $token ),
            $token
        );
    }

    /**
     * Send the magic-link email. Returns wp_mail's bool.
     *
     * Subject + body are placeholder copy — Matthew refines later. Filter
     * hooks let downstream wire a richer template (e.g., HDLV2_Email_Templates
     * style co-branded HTML) without modifying this class.
     */
    private function send_magic_link_email( $email, $name, $magic_link_url ) {
        $subject = apply_filters(
            'hdl_paid_report_magic_link_subject',
            'Your longevity assessment is ready'
        );
        $body = apply_filters(
            'hdl_paid_report_magic_link_body',
            $this->render_default_email_body( $name, $magic_link_url ),
            $name,
            $magic_link_url
        );
        $headers = apply_filters(
            'hdl_paid_report_magic_link_headers',
            array(
                'Content-Type: text/html; charset=UTF-8',
            )
        );
        return wp_mail( $email, $subject, $body, $headers );
    }

    private function render_default_email_body( $name, $magic_link_url ) {
        $greeting = esc_html( '' !== trim( (string) $name ) ? trim( (string) $name ) : 'there' );
        $url      = esc_url( $magic_link_url );
        return <<<HTML
<!DOCTYPE html>
<html><body style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Inter,sans-serif;color:#2c3e50;background:#fafbfc;margin:0;padding:32px;">
  <div style="max-width:560px;margin:0 auto;background:#ffffff;border:1px solid #e4e6ea;border-radius:8px;padding:32px;">
    <h1 style="font-size:22px;margin:0 0 16px;color:#004F59;">Hi {$greeting},</h1>
    <p style="font-size:15px;line-height:1.6;margin:0 0 16px;">
      Your longevity assessment is ready. Click below to start.
    </p>
    <p style="margin:24px 0;">
      <a href="{$url}" style="display:inline-block;background:#3d8da0;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:6px;font-weight:600;">Start your assessment</a>
    </p>
    <p style="font-size:13px;color:#888;line-height:1.5;margin:24px 0 0;">
      If the button doesn't work, copy and paste this link into your browser:<br>
      <span style="word-break:break-all;color:#3d8da0;">{$url}</span>
    </p>
  </div>
</body></html>
HTML;
    }

    /**
     * Resolve client IP through the CF/Vultr proxy chain. Reuses V2's
     * middleware helper when available; falls back to REMOTE_ADDR.
     */
    private function get_ip() {
        if ( class_exists( 'HDLV2_Rate_Limit_Middleware' ) && method_exists( 'HDLV2_Rate_Limit_Middleware', 'get_ip' ) ) {
            return HDLV2_Rate_Limit_Middleware::get_ip();
        }
        return isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
    }

    /**
     * Audit log: request_id, ip, ua, status, outcome, body field NAMES
     * (NEVER values — no PII in log).
     */
    private function log_request( $request_id, $ip, $user_agent, $request, $status, $outcome ) {
        $body         = $request->get_json_params();
        $field_names  = is_array( $body ) ? array_keys( $body ) : array();
        $ua_short     = substr( (string) $user_agent, 0, 120 );
        error_log( sprintf(
            '[HDL Paid Report Provisioner] request_id=%s ip=%s ua=%s status=%d outcome=%s body_fields=[%s]',
            $request_id,
            $ip,
            $ua_short,
            (int) $status,
            $outcome,
            implode( ',', $field_names )
        ) );
    }
}

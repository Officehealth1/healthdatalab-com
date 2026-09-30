<?php
/**
 * HDL_Stage1_Ticket — POST /wp-json/hdl/v1/stage1-ticket
 *
 * Mints the one-time ticket that opens a practitioner's PAID Stage 1 widget
 * (widget_config.access_mode = 'paid'). The selling site calls this once its
 * payment is confirmed and sends the buyer the link itself:
 *   <page hosting the widget>?invite=<token>
 *
 * Auth: header X-HDL-Stage1-Ticket-Key against the wp-config constant
 * HDL_STAGE1_TICKET_KEY. No constant → 503, the route is dark.
 *
 * Body: practitioner_id, email, name, external_ref (the seller's payment
 * reference, e.g. a Stripe session id), optional expires_days (1–365,
 * default 90).
 * Response: { token, expires_at, idempotent }.
 *
 * The same external_ref always returns the same token. That is enforced by
 * the UNIQUE key on widget_invites.external_ref: insert first, read the
 * existing row on the duplicate-key error. No user, no email, no Stage 2.
 *
 * V1-style class name because the route lives in the hdl/v1 namespace, like
 * HDL_Paid_Report_Provisioner next to it.
 *
 * @package HDL_Longevity_V2
 * @since   0.47.85
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HDL_Stage1_Ticket {

    /** Mints per caller IP per hour. Room for a webinar's worth of buyers. */
    const RATE_LIMIT = 300;

    const DEFAULT_DAYS = 90;
    const MAX_DAYS     = 365;

    public static function register_hooks() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    public static function register_routes() {
        register_rest_route( 'hdl/v1', '/stage1-ticket', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'handle' ),
            // Auth happens in the handler so a server without the key
            // answers 503 whatever the caller sends.
            'permission_callback' => '__return_true',
        ) );
    }

    public static function handle( $request ) {
        $expected = defined( 'HDL_STAGE1_TICKET_KEY' ) ? (string) HDL_STAGE1_TICKET_KEY : '';
        if ( '' === $expected ) {
            return self::refuse( 503, 'not_configured', 'Stage 1 tickets are not enabled on this server.' );
        }

        // hdlv2_db_version only reaches 3.27 once Phase AH has verified the
        // 'paid_stage1' source value and the UNIQUE key. Without them a
        // ticket would be stored as an ordinary invite, or minted twice.
        if ( version_compare( (string) get_option( 'hdlv2_db_version', '0' ), '3.27', '<' ) ) {
            return self::refuse( 503, 'not_ready', 'Stage 1 tickets are not ready on this server.' );
        }

        $provided = (string) $request->get_header( 'X-HDL-Stage1-Ticket-Key' );
        if ( '' === $provided || ! hash_equals( $expected, $provided ) ) {
            return self::refuse( 401, 'unauthorized', 'Unauthorized.' );
        }

        if ( class_exists( 'HDL_Rate_Limiter' ) ) {
            $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( $_SERVER['REMOTE_ADDR'] ) : 'unknown';
            $rl = new HDL_Rate_Limiter();
            if ( ! $rl->check_limit( 'stage1_ticket', $ip, self::RATE_LIMIT, HOUR_IN_SECONDS ) ) {
                return self::refuse( 429, 'rate_limited', 'Too many requests.' );
            }
        }

        $practitioner_id = absint( $request->get_param( 'practitioner_id' ) );
        $email           = sanitize_email( (string) $request->get_param( 'email' ) );
        $name            = substr( sanitize_text_field( (string) $request->get_param( 'name' ) ), 0, 200 );
        $external_ref    = (string) $request->get_param( 'external_ref' );
        $days_raw        = $request->get_param( 'expires_days' );
        $days            = null === $days_raw ? self::DEFAULT_DAYS : (int) $days_raw;

        $invalid = array();
        if ( ! $practitioner_id ) {
            $invalid[] = 'practitioner_id';
        }
        if ( ! is_email( $email ) ) {
            $invalid[] = 'email';
        }
        if ( '' === $name ) {
            $invalid[] = 'name';
        }
        if ( ! preg_match( '/^[A-Za-z0-9_.:\-]{1,128}$/', $external_ref ) ) {
            $invalid[] = 'external_ref';
        }
        if ( ( null !== $days_raw && ! is_numeric( $days_raw ) ) || $days < 1 || $days > self::MAX_DAYS ) {
            $invalid[] = 'expires_days';
        }
        if ( $invalid ) {
            return self::refuse( 400, 'invalid_body', 'One or more fields failed validation.', $invalid );
        }

        global $wpdb;
        $access_mode = $wpdb->get_row( $wpdb->prepare(
            "SELECT access_mode FROM {$wpdb->prefix}hdlv2_widget_config WHERE practitioner_user_id = %d LIMIT 1",
            $practitioner_id
        ) );
        if ( ! $access_mode || 'paid' !== $access_mode->access_mode ) {
            return self::refuse( 409, 'not_paid_mode', 'This practitioner\'s widget is not in paid mode.' );
        }

        $table      = $wpdb->prefix . 'hdlv2_widget_invites';
        $token      = bin2hex( random_bytes( 32 ) );
        $expires_at = gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS );

        // Insert first: the UNIQUE key on external_ref decides, so two
        // deliveries of the same payment can never mint two tickets.
        $was_suppressed = $wpdb->suppress_errors( true );
        $inserted       = $wpdb->insert( $table, array(
            'practitioner_id' => $practitioner_id,
            'token'           => $token,
            'client_name'     => $name,
            'client_email'    => $email,
            'status'          => 'pending',
            'expires_at'      => $expires_at,
            'source'          => 'paid_stage1',
            'external_ref'    => $external_ref,
        ), array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );
        $wpdb->suppress_errors( $was_suppressed );

        if ( $inserted ) {
            return rest_ensure_response( array(
                'token'      => $token,
                'expires_at' => self::iso( $expires_at ),
                'idempotent' => false,
            ) );
        }

        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT token, expires_at, practitioner_id, source FROM $table WHERE external_ref = %s LIMIT 1",
            $external_ref
        ) );
        if ( ! $existing ) {
            return self::refuse( 500, 'mint_failed', 'The ticket could not be saved.' );
        }
        if ( (int) $existing->practitioner_id !== $practitioner_id || 'paid_stage1' !== $existing->source ) {
            return self::refuse( 409, 'external_ref_conflict', 'This external_ref belongs to another ticket.' );
        }
        return rest_ensure_response( array(
            'token'      => (string) $existing->token,
            'expires_at' => self::iso( $existing->expires_at ),
            'idempotent' => true,
        ) );
    }

    /** Stored UTC datetime → ISO 8601. */
    private static function iso( $mysql_utc ) {
        return gmdate( 'c', strtotime( $mysql_utc . ' UTC' ) );
    }

    /** Refusal + one log line: status, code and field NAMES, never values. */
    private static function refuse( $status, $code, $message, $fields = array() ) {
        error_log( '[HDL stage1-ticket] ' . $status . ' ' . $code . ( $fields ? ' fields=' . implode( ',', $fields ) : '' ) );
        $data = array( 'status' => $status );
        if ( $fields ) {
            $data['invalid_fields'] = $fields;
        }
        return new WP_Error( $code, $message, $data );
    }
}

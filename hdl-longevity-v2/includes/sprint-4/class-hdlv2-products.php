<?php
/**
 * HDL V2 — what a client has and could have (v0.48.36).
 *
 * Five tiles, the same for the client on /my-dashboard/ and for their practitioner on /clients/:
 * Stage 1, Full Report, Timeline, Flight Plan, 30 days. Each reads Yours, In progress or Not yet.
 * There is no purchases table: each state is read from the rows the journey already writes
 * (newest live form_progress row, its report, a bought full_report ticket, a live flight plan,
 * the family pack, the hdlv2_challenge user meta). A price shows only when HDLV2_Offer holds one
 * for this client's practitioner; every other locked tile names who to ask.
 *
 * Flag hdlv2_ff_dashboard_products, absent = off: render_strip() returns '' and the route is
 * not registered.
 *
 * @package HDL_Longevity_V2
 * @since   0.48.36
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HDLV2_Products {

    const FLAG = 'hdlv2_ff_dashboard_products';

    const STATE_WORDS = array( 'yours' => 'Yours', 'in-progress' => 'In progress', 'not-yet' => 'Not yet' );

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    public static function enabled() {
        return (bool) get_option( self::FLAG, false );
    }

    public static function register_routes() {
        if ( ! self::enabled() ) {
            return;
        }
        register_rest_route( 'hdl-v2/v1', '/dashboard/client/(?P<id>\d+)/products', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_products' ),
            'permission_callback' => function () {
                return HDLV2_Compatibility::is_practitioner( get_current_user_id() ) || current_user_can( 'manage_options' );
            },
        ) );
    }

    /** The practitioner's copy of the strip. Not their client (or no record): 404, as the pack routes. */
    public static function rest_products( $request ) {
        $client_id = absint( $request['id'] );
        if ( ! $client_id || ! HDLV2_Compatibility::practitioner_owns_client( get_current_user_id(), $client_id ) ) {
            return new WP_Error( 'not_found', 'No such client.', array( 'status' => 404 ) );
        }
        return rest_ensure_response( array( 'products_html' => self::render_strip( self::for_client( $client_id ), true ) ) );
    }

    /**
     * @return array five { key, label, state (yours|in-progress|not-yet), url ('' = no link), note, price (null, or HDLV2_Offer::money()) }
     */
    public static function for_client( $user_id ) {
        global $wpdb;
        $user_id = (int) $user_id;
        $row     = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, practitioner_user_id, token, stage1_completed_at, stage1_pdf_url, stage1_pdf_stored_path,
                    stage2_completed_at, stage3_completed_at
             FROM {$wpdb->prefix}hdlv2_form_progress
             WHERE client_user_id = %d AND deleted_at IS NULL
             ORDER BY id DESC LIMIT 1",
            $user_id
        ) );
        $report    = $row ? HDLV2_Healthspan_Timeline::report_row( (int) $row->id ) : null;
        $user      = get_userdata( $user_id );
        $email     = $user ? (string) $user->user_email : '';
        // A Full Report bought (Altituding, a family seat) whose report is not written yet.
        $ticket    = ! $report && '' !== $email ? $wpdb->get_row( $wpdb->prepare(
            "SELECT practitioner_id FROM {$wpdb->prefix}hdlv2_widget_invites
             WHERE client_email = %s AND source = 'full_report' AND external_ref IS NOT NULL AND status <> 'revoked'
             ORDER BY id DESC LIMIT 1",
            $email
        ) ) : null;
        $plan      = $row ? $wpdb->get_row( $wpdb->prepare(
            "SELECT id, week_number FROM {$wpdb->prefix}hdlv2_flight_plans
             WHERE client_id = %d AND deleted_at IS NULL
             ORDER BY week_start DESC, id DESC LIMIT 1",
            $user_id
        ) ) : null;
        $enrolment = HDLV2_Challenge::enrolment( $user_id );

        $prac_id = $row ? (int) $row->practitioner_user_id
            : ( $enrolment ? (int) $enrolment['practitioner_id'] : ( $ticket ? (int) $ticket->practitioner_id : 0 ) );
        $prac    = $prac_id ? get_userdata( $prac_id ) : null;
        $name    = $prac ? (string) $prac->display_name : '';
        $ask     = 'Ask ' . ( '' !== $name ? $name : 'your practitioner' );
        $who     = '' !== $name ? $name : 'Your practitioner';
        $record  = $row && ( $row->stage1_completed_at || $row->stage2_completed_at || $row->stage3_completed_at || $report );

        // Stage 1
        if ( $row && $row->stage1_completed_at ) {
            $stage1 = self::item( 'stage1', 'Stage 1', 'yours', 'Done ' . self::day( $row->stage1_completed_at ),
                empty( $row->stage1_pdf_stored_path ) ? '' : (string) $row->stage1_pdf_url );
        } elseif ( $enrolment && HDLV2_Challenge::stage1_on_file( $user_id ) ) {
            $stage1 = self::item( 'stage1', 'Stage 1', 'in-progress', $who . ' is confirming it' );
        } else {
            $stage1 = self::item( 'stage1', 'Stage 1', 'not-yet', $ask );
        }

        // Full Report
        if ( $report ) {
            $full = self::item( 'full_report', 'Full Report', 'yours',
                'final' === $report->report_type ? 'Final' : 'Draft · consultation next', home_url( '/my-report/' ) );
        } elseif ( $ticket || ( $row && ( $row->stage2_completed_at || $row->stage3_completed_at ) ) ) {
            $full = self::item( 'full_report', 'Full Report', 'in-progress',
                $row && $row->stage3_completed_at ? $who . ' is writing it' : 'Questions under way' );
        } elseif ( $prac_id && HDLV2_Offer::applies_to( $prac_id ) ) {
            $o    = HDLV2_Offer::get();
            $full = self::item( 'full_report', 'Full Report', 'not-yet', 'Today ' . HDLV2_Offer::money( $o['full_today_price'], $o ),
                add_query_arg( 'code', rawurlencode( $o['code'] ), $o['url'] ), HDLV2_Offer::money( $o['full_today_price'], $o ) );
        } else {
            $full = self::item( 'full_report', 'Full Report', 'not-yet', $ask );
        }

        // Timeline: comes with the Full Report (hdlv2_ht_gate 'addon' is reserved and behaves the same).
        if ( ! HDLV2_Healthspan_Timeline::enabled() ) {
            $timeline = self::item( 'timeline', 'Timeline', 'not-yet', 'Coming soon' );
        } elseif ( $report && ! empty( $row->token ) ) {
            $family   = class_exists( 'HDLV2_Timeline_Links' ) ? HDLV2_Timeline_Links::dashboard_pack( (int) $row->id ) : null;
            $pack     = $family ? $family['pack'] : null;
            $line     = ! $pack ? 'Ready'
                : ( 'active' === $pack['status']
                    ? 'Family pack · ' . $pack['seats_total'] . ' seats · ' . max( 0, $pack['seats_total'] - $pack['seats_used'] ) . ' left'
                    : 'Family pack ended' );
            $timeline = self::item( 'timeline', 'Timeline', 'yours', $line, HDLV2_Healthspan_Timeline::report_url( $row->token ) );
        } else {
            $timeline = self::item( 'timeline', 'Timeline', 'not-yet', 'Comes with your Full Report' );
        }

        // Flight Plan
        $flight = $plan
            ? self::item( 'flight_plan', 'Flight Plan', 'yours', 'Week ' . (int) $plan->week_number, home_url( '/my-flight-plan/' ) )
            : self::item( 'flight_plan', 'Flight Plan', 'not-yet', 'After your consultation' );

        // 30 days: free for any client record (v0.48.27 D6), so a record that has not started reads "Free to start".
        $challenge_url = home_url( '/' . trim( apply_filters( 'hdlv2_client_dashboard_slug', 'my-dashboard' ), '/' ) . '/#challenge' );
        if ( $enrolment ) {
            $challenge = self::item( 'challenge', '30 days', 'yours', 'Started ' . self::day( $enrolment['started_at'] ), $challenge_url );
        } elseif ( $record ) {
            $challenge = self::item( 'challenge', '30 days', 'not-yet', 'Free to start', $challenge_url );
        } else {
            $challenge = self::item( 'challenge', '30 days', 'not-yet', $ask );
        }

        return array( $stage1, $full, $timeline, $flight, $challenge );
    }

    /**
     * The strip. The practitioner's copy carries no link: no buy button, and no client token
     * (the timeline link holds one) reaches the practitioner's page.
     */
    public static function render_strip( array $items, $for_practitioner = false ) {
        if ( ! self::enabled() ) {
            return '';
        }
        $h = '<nav class="cdp-products" aria-label="' . ( $for_practitioner ? 'What this client has' : 'What you have' ) . '">';
        foreach ( $items as $i ) {
            $inner = '<span class="cdp-tile-label">' . esc_html( $i['label'] ) . '</span>'
                   . '<span class="cdp-tile-state">' . esc_html( self::STATE_WORDS[ $i['state'] ] ) . '</span>'
                   . '<span class="cdp-tile-note">' . esc_html( $i['note'] ) . '</span>';
            $class = 'cdp-tile is-' . $i['state'];
            $h    .= ! $for_practitioner && '' !== $i['url']
                ? '<a class="' . $class . '" href="' . esc_url( $i['url'] ) . '">' . $inner . '</a>'
                : '<span class="' . $class . '">' . $inner . '</span>';
        }
        return $h . '</nav>';
    }

    private static function item( $key, $label, $state, $note, $url = '', $price = null ) {
        return array( 'key' => $key, 'label' => $label, 'state' => $state, 'url' => $url, 'note' => $note, 'price' => $price );
    }

    /** "2 Nov" from a stored UTC datetime. */
    private static function day( $mysql_utc ) {
        return wp_date( 'j M', strtotime( $mysql_utc . ' UTC' ) );
    }
}

HDLV2_Products::init();

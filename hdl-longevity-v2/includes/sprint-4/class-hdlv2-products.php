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
 * v0.48.38: with hdlv2_ff_dashboard_one_row also on, /my-dashboard/ shows the products as tabs
 * (render_tabs()) and a product the client lacks as a panel that says how to get it (render_locked()).
 *
 * @package HDL_Longevity_V2
 * @since   0.48.36
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HDLV2_Products {

    const FLAG = 'hdlv2_ff_dashboard_products';

    const STATE_WORDS = array( 'yours' => 'Yours', 'in-progress' => 'In progress', 'not-yet' => 'Not yet', 'open' => 'Free to start' );

    /** v0.48.39 — the one-row tab of a product the client lacks: a 12 px lock (inline SVG, currentColor). */
    const LOCK = '<svg width="12" height="12" viewBox="0 0 12 12" aria-hidden="true" focusable="false"><rect x="2" y="5.25" width="8" height="5.75" rx="1.25" fill="currentColor"/><path d="M3.75 5.25V3.9a2.25 2.25 0 0 1 4.5 0v1.35" fill="none" stroke="currentColor" stroke-width="1.4"/></svg>';

    /** v0.48.38 — /my-dashboard/ as one row of tabs, the products among them. Absent = off = the 0.48.37 page. */
    const FLAG_ONE_ROW = 'hdlv2_ff_dashboard_one_row';

    /** The tabs, in order. 'report' is the Full Report (the key old #tab-report links open). */
    const TAB_ORDER = array( 'today', 'stage1', 'report', 'timeline', 'flight_plan', 'challenge', 'messages' );

    /** What each product is, two lines, on the panel a locked tab opens. Placeholder copy: Matthew's words replace it. */
    const ABOUT = array(
        'stage1'      => array( 'Nine questions, about three minutes.', 'Your pace of ageing, straight away.' ),
        'full_report' => array( 'Your real measurements, reviewed with your practitioner in a consultation.', 'Your personalised Trajectory Plan, as a PDF and on this page.' ),
        'timeline'    => array( 'Your healthspan, year by year, as one picture.', 'It comes with your Full Report. A family pack adds seats for the people you choose.' ),
        'flight_plan' => array( 'A plan for the week, every week, after your consultation.', 'Built from your Sunday check-in.' ),
        'challenge'   => array( 'One small step a day for 30 days.', 'Free once your Stage 1 is in.' ),
    );

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    public static function enabled() {
        return (bool) get_option( self::FLAG, false );
    }

    public static function one_row_enabled() {
        return (bool) get_option( self::FLAG_ONE_ROW, false );
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
        // The Full Report gate as HDLV2_Healthspan_Timeline::report_row(), without reading the report body.
        $report    = $row ? $wpdb->get_row( $wpdb->prepare(
            "SELECT id, report_type FROM {$wpdb->prefix}hdlv2_reports
             WHERE form_progress_id = %d AND report_type IN ('final','draft')
             ORDER BY report_type = 'final' DESC, id DESC LIMIT 1",
            (int) $row->id
        ) ) : null;
        $user      = get_userdata( $user_id );
        $email     = $user ? (string) $user->user_email : '';
        // A Full Report bought (Altituding, a family seat) whose report is not written yet.
        $ticket    = ! $report && '' !== $email ? $wpdb->get_row( $wpdb->prepare(
            "SELECT practitioner_id FROM {$wpdb->prefix}hdlv2_widget_invites
             WHERE LOWER(client_email) = %s AND source = 'full_report' AND external_ref IS NOT NULL AND status <> 'revoked'
             ORDER BY id DESC LIMIT 1",
            strtolower( $email )
        ) ) : null;
        // The current week, as the dashboard and /my-flight-plan/ pick it (a pre-built next week is not current).
        $plan      = $row ? $wpdb->get_row( $wpdb->prepare(
            "SELECT id, week_number FROM {$wpdb->prefix}hdlv2_flight_plans
             WHERE client_id = %d AND week_start <= %s AND deleted_at IS NULL
             ORDER BY week_start DESC, id DESC LIMIT 1",
            $user_id,
            current_time( 'Y-m-d' )
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
                $row && $row->stage3_completed_at ? $who . ' is writing it'
                : ( $row && $row->stage2_completed_at ? 'Questions under way' : 'Bought · your questions next' ) );
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

        $dashboard = home_url( '/' . trim( apply_filters( 'hdlv2_client_dashboard_slug', 'my-dashboard' ), '/' ) . '/' );

        // Flight Plan. v0.48.39, one-row page: after a final report the consultation is done and the first
        // plan is queued by the finalise step (HDLV2_Final_Report, week 'current'), not by a check-in.
        if ( $plan ) {
            $flight = self::item( 'flight_plan', 'Flight Plan', 'yours', 'Week ' . (int) $plan->week_number, home_url( '/my-flight-plan/' ) );
        } elseif ( $report && 'final' === $report->report_type && self::one_row_enabled() ) {
            $flight = self::item( 'flight_plan', 'Flight Plan', 'not-yet', $who . ' is preparing it' );
        } else {
            $flight = self::item( 'flight_plan', 'Flight Plan', 'not-yet', 'After your consultation' );
        }

        // 30 days: free for any client record (v0.48.27 D6), so a record that has not started reads "Free to start".
        $challenge_url = $dashboard . '#challenge';
        if ( $enrolment ) {
            $challenge = self::item( 'challenge', '30 days', 'yours', 'Started ' . self::day( $enrolment['started_at'] ), $challenge_url );
        } elseif ( $record ) {
            // v0.48.39 — on the one-row page it is its own state: the tab opens a challenge they can start.
            $challenge = self::item( 'challenge', '30 days', self::one_row_enabled() ? 'open' : 'not-yet', 'Free to start', $challenge_url );
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
                   . ( self::STATE_WORDS[ $i['state'] ] !== $i['note'] ? '<span class="cdp-tile-note">' . esc_html( $i['note'] ) . '</span>' : '' );
            $class = 'cdp-tile is-' . $i['state'];
            $h    .= ! $for_practitioner && '' !== $i['url']
                ? '<a class="' . $class . '" href="' . esc_url( $i['url'] ) . '">' . $inner . '</a>'
                : '<span class="' . $class . '">' . $inner . '</span>';
        }
        return $h . '</nav>';
    }

    /**
     * v0.48.38 — the one row: Today, the five products, Messages. Buttons only (they open a panel on
     * this page); each product carries its pill (v0.48.39: see pill()). The tab script (hdlv2-client-dashboard.js) reads
     * .cdp-tab[data-tab].
     */
    public static function render_tabs( array $items, $active = 'today' ) {
        $by = array();
        foreach ( $items as $i ) {
            $by[ 'full_report' === $i['key'] ? 'report' : $i['key'] ] = $i;
        }
        $h = '<nav class="cdp-tabs cdp-tabs-products" role="tablist" aria-label="Dashboard sections"><div class="cdp-tabs-inner">';
        foreach ( self::TAB_ORDER as $key ) {
            $on = $key === $active;
            $h .= '<button type="button" class="cdp-tab' . ( $on ? ' active' : '' ) . '" data-tab="' . $key . '" role="tab" aria-selected="' . ( $on ? 'true' : 'false' ) . '">';
            if ( 'today' === $key ) {
                $h .= '<span class="cdp-tab-label">Today</span>';
            } elseif ( 'messages' === $key ) {
                // The badge is filled by hdlv2-inbox.js from the poll's unread_count and stays hidden at zero.
                $h .= '<span class="cdp-tab-label">Messages <span class="hdlv2-inbox-badge cdp-tab-badge" data-inbox-badge hidden>0</span></span>';
            } else {
                $h .= '<span class="cdp-tab-label">' . esc_html( $by[ $key ]['label'] ) . '</span>' . self::pill( $by[ $key ]['state'] );
            }
            $h .= '</button>';
        }
        return $h . '</div></nav>';
    }

    /**
     * v0.48.38 — the panel of a product the client does not have yet (or has bought and waits for):
     * what it is, and one way to get it. A price only where the Full Report item carries one (the
     * Timeline comes with the Full Report, so it borrows that action); otherwise one button that
     * opens the Messages tab (also for an open 30 days whose section did not render, v0.48.39).
     * In progress: no action.
     *
     * @param array  $item              one item of for_client()
     * @param array  $full_report_item  the Full Report item (the Timeline's way in)
     * @param string $practitioner_name '' = "your practitioner"
     * @param bool   $read_only         v0.48.40 — the practitioner's view of the page: the two lines, no button
     */
    public static function render_locked( array $item, array $full_report_item, $practitioner_name, $read_only = false ) {
        $seller = 'timeline' === $item['key'] ? $full_report_item : $item;
        $sells  = ! $read_only && 'not-yet' === $item['state'] && null !== $seller['price'] && '' !== $seller['url'];
        $h      = '<div class="cdp-product-card cdp-locked">' . self::product_head( $item, ! ( $sells && 'timeline' !== $item['key'] ) );
        foreach ( self::ABOUT[ $item['key'] ] as $line ) {
            $h .= '<p class="cdp-product-about">' . esc_html( $line ) . '</p>';
        }
        if ( $sells ) {
            $h .= '<a class="cdp-btn cdp-btn-primary" href="' . esc_url( $seller['url'] ) . '">'
                . esc_html( 'Get the ' . $seller['label'] . ' · ' . $seller['note'] ) . '</a>';
        } elseif ( ! $read_only && ( ( 'not-yet' === $item['state'] && 'not-yet' === $seller['state'] ) || 'open' === $item['state'] ) ) {
            $h .= '<button type="button" class="cdp-btn cdp-btn-secondary" data-open-tab="messages">'
                . esc_html( 'Ask ' . ( '' !== (string) $practitioner_name ? $practitioner_name : 'your practitioner' ) ) . '</button>';
        }
        return $h . '</div>';
    }

    /** The product's name, its pill and (unless a button already carries it) its line. */
    public static function product_head( array $item, $with_note = true ) {
        return '<div class="cdp-product-head"><h2 class="cdp-product-title">' . esc_html( $item['label'] ) . '</h2>' . self::pill( $item['state'] ) . '</div>'
            . ( $with_note ? '<p class="cdp-product-note">' . esc_html( $item['note'] ) . '</p>' : '' );
    }

    /**
     * v0.48.39 — what the client has, or can start, carries nothing (the label says it); what they
     * lack carries the lock, its word for screen readers; in progress keeps its words.
     */
    private static function pill( $state ) {
        if ( 'yours' === $state || 'open' === $state ) {
            return '';
        }
        $word = esc_html( self::STATE_WORDS[ $state ] );
        return 'not-yet' === $state
            ? '<span class="cdp-tab-state is-not-yet" title="' . $word . '">' . self::LOCK . '<span class="cdp-sr">' . $word . '</span></span>'
            : '<span class="cdp-tab-state is-' . $state . '">' . $word . '</span>';
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

<?php
/**
 * HDLV2_Challenge — the 30-day challenge on the client dashboard (v0.48.23).
 *
 * Matthew, 2026-10-27: a buyer gets a video a day for 30 days; they tick the
 * day, may leave a note, and the next day opens. Managed on our page.
 *
 *  - Enrolment: user meta `hdlv2_challenge` (JSON), written once when the
 *    buyer first opens their challenge link (hdl-longevity-v2.php ?invite=).
 *  - Ticks: wp_hdlv2_challenge_days, one row per day, UNIQUE per client+day.
 *  - Unlock (D1): day N opens once day N-1 is ticked AND the UTC date is
 *    later than that tick's date. Day 1 is open from enrolment.
 *  - Videos (D3): option `hdlv2_challenge_videos`, up to 30
 *    { day, title, youtube_id }; a day without one shows "Video coming".
 *  - POST hdl-v2/v1/challenge/tick — the signed-in client only, no ids taken.
 *  - The link: open_link() from the ?invite= login, signed_in_link() when
 *    the browser is already signed in.
 *
 * @package HDL_Longevity_V2
 * @since   0.48.23
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HDLV2_Challenge {

    const DAYS          = 30;
    const META          = 'hdlv2_challenge';
    const VIDEOS_OPTION = 'hdlv2_challenge_videos';
    const NOTE_MAX      = 500;

    /** open_link() already ran in this request (the ?invite= login). */
    private static $opened = false;

    public static function register_hooks() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
        // After the ?invite= login (init priority 1), which skips a browser
        // that is already signed in.
        add_action( 'init', array( __CLASS__, 'signed_in_link' ), 2 );
        // WordPress removes the user's meta itself; the ticked days go here.
        add_action( 'deleted_user', array( __CLASS__, 'forget' ) );
    }

    public static function register_routes() {
        register_rest_route( 'hdl-v2/v1', '/challenge/tick', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_tick' ),
            'permission_callback' => 'is_user_logged_in',
        ) );
    }

    /** First open of a challenge link. A repeat login never resets the start. */
    public static function enrol( $user_id, $invite ) {
        $ref = (string) ( $invite->external_ref ?? '' );
        return add_user_meta( $user_id, self::META, wp_json_encode( array(
            'started_at'      => gmdate( 'Y-m-d H:i:s' ),
            'source'          => 0 === strpos( $ref, 'cs_' ) ? 'stripe' : 'granted',
            'external_ref'    => $ref,
            'invite_id'       => (int) $invite->id,
            'practitioner_id' => (int) $invite->practitioner_id,
        ) ), true );
    }

    /**
     * A challenge link was opened by its own account: enrol once, then the
     * dashboard if Stage 1 is on file.
     *
     * @return string|null the URL to send the buyer to, or null to let the
     *                     widget page render (Stage 1 first).
     */
    public static function open_link( $user_id, $invite ) {
        self::$opened = true;
        self::enrol( $user_id, $invite );
        if ( ! self::stage1_on_file( $user_id ) ) {
            return null;
        }
        return home_url( '/' . trim( apply_filters( 'hdlv2_client_dashboard_slug', 'my-dashboard' ), '/' ) . '/#challenge' );
    }

    /** Stage 1 on file: a client record past Stage 1, or a lead for this email with the seller. */
    public static function stage1_on_file( $user_id ) {
        global $wpdb;
        if ( $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$wpdb->prefix}hdlv2_form_progress
             WHERE client_user_id = %d AND deleted_at IS NULL AND stage1_completed_at IS NOT NULL LIMIT 1",
            $user_id
        ) ) ) {
            return true;
        }
        $enrolment = self::enrolment( $user_id );
        $user      = get_userdata( $user_id );
        return $enrolment && $user && (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$wpdb->prefix}hdlv2_widget_leads
             WHERE practitioner_user_id = %d AND visitor_email = %s LIMIT 1",
            (int) $enrolment['practitioner_id'],
            $user->user_email
        ) );
    }

    /**
     * The ?invite= login (hdl-longevity-v2.php) returns at once for a signed-in
     * browser, so a buyer re-opening their link there is handled here: the
     * live challenge link of the signed-in account's own email. Any other
     * account, a staff account or another kind of link: nothing happens.
     */
    public static function signed_in_link() {
        if ( self::$opened || is_admin() || ! is_user_logged_in() || empty( $_GET['invite'] ) ) {
            return;
        }
        if ( isset( $_SERVER['REQUEST_URI'] ) && false !== strpos( $_SERVER['REQUEST_URI'], '/wp-json/' ) ) {
            return;
        }
        $token = sanitize_text_field( wp_unslash( $_GET['invite'] ) );
        if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
            return;
        }
        $user = wp_get_current_user();
        if ( hdlv2_is_staff_account( $user->ID ) ) {
            return;
        }
        global $wpdb;
        $invite = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, practitioner_id, client_email, external_ref FROM {$wpdb->prefix}hdlv2_widget_invites
             WHERE token = %s AND source = 'challenge' AND status IN ('pending','opened') AND expires_at > UTC_TIMESTAMP() LIMIT 1",
            $token
        ) );
        if ( ! $invite || 0 !== strcasecmp( (string) $user->user_email, (string) $invite->client_email ) ) {
            return;
        }
        $url = self::open_link( (int) $user->ID, $invite );
        if ( $url ) {
            wp_safe_redirect( $url );
            exit;
        }
    }

    /** @return array|null the enrolment, or null when the client never bought one. */
    public static function enrolment( $user_id ) {
        $e = json_decode( (string) get_user_meta( $user_id, self::META, true ), true );
        return is_array( $e ) && ! empty( $e['started_at'] ) ? $e : null;
    }

    /**
     * @return array|null { started_at, days [1..30 => { done_at, note }],
     *   open_day (null when all done), next_opens_at, done_count, streak, complete }
     */
    public static function state( $user_id, $now = null ) {
        $enrolment = self::enrolment( $user_id );
        if ( ! $enrolment ) {
            return null;
        }
        global $wpdb;
        $days = array_fill( 1, self::DAYS, array( 'done_at' => null, 'note' => null ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT day_no, done_at, note FROM {$wpdb->prefix}hdlv2_challenge_days WHERE client_user_id = %d ORDER BY day_no",
            $user_id
        ) );
        foreach ( (array) $rows as $r ) {
            $n = (int) $r->day_no;
            if ( isset( $days[ $n ] ) ) {
                $days[ $n ] = array( 'done_at' => (string) $r->done_at, 'note' => (string) $r->note );
            }
        }

        $now   = null === $now ? time() : $now;
        $done  = self::done_run( $days );
        $open  = self::open_day( $days, $now );
        $next  = null;
        if ( null !== $open && null !== $days[ $open ]['done_at'] ) {
            $next = gmdate( 'Y-m-d 00:00:00', strtotime( substr( $days[ $open ]['done_at'], 0, 10 ) . ' UTC' ) + DAY_IN_SECONDS );
        }
        $streak = 0;
        for ( $n = $done; $n >= 1; $n-- ) {
            $streak++;
            if ( 1 === $n || self::day_before( $days[ $n ]['done_at'] ) !== substr( $days[ $n - 1 ]['done_at'], 0, 10 ) ) {
                break;
            }
        }
        return array(
            'started_at'    => $enrolment['started_at'],
            'days'          => $days,
            'open_day'      => $open,
            'next_opens_at' => $next,
            'done_count'    => $done,
            'streak'        => $streak,
            'complete'      => self::DAYS === $done,
        );
    }

    /**
     * The day the client is on: the first undone day once the day before it
     * was ticked on an earlier UTC date, else the last done day (nothing to
     * tick until tomorrow). 1 with no ticks, null when all 30 are done. D1:
     * dropping the date test here makes a tick alone open the next day.
     */
    public static function open_day( $days, $now ) {
        $done = self::done_run( $days );
        if ( 0 === $done ) {
            return 1;
        }
        if ( self::DAYS === $done ) {
            return null;
        }
        return substr( $days[ $done ]['done_at'], 0, 10 ) < gmdate( 'Y-m-d', $now ) ? $done + 1 : $done;
    }

    /** Days are ticked in order, so the done days are 1..N. */
    private static function done_run( $days ) {
        $n = 0;
        while ( $n < self::DAYS && null !== $days[ $n + 1 ]['done_at'] ) {
            $n++;
        }
        return $n;
    }

    private static function day_before( $datetime ) {
        return gmdate( 'Y-m-d', strtotime( substr( $datetime, 0, 10 ) . ' UTC' ) - DAY_IN_SECONDS );
    }

    /** @return array|WP_Error the new state. A repeat of today's tick is a no-op. */
    public static function tick( $user_id, $day, $note, $now = null ) {
        if ( ! is_numeric( $day ) || (int) $day != $day || $day < 1 || $day > self::DAYS ) {
            return new WP_Error( 'invalid_day', 'That day does not exist.', array( 'status' => 400 ) );
        }
        $day   = (int) $day;
        $now   = null === $now ? time() : $now;
        $state = self::state( $user_id, $now );
        if ( ! $state ) {
            return new WP_Error( 'not_enrolled', 'You are not on the 30-day challenge.', array( 'status' => 403 ) );
        }
        if ( $day !== $state['open_day'] ) {
            $msg = null !== $state['days'][ $day ]['done_at'] ? 'Already done.' : 'This day is not open yet.';
            return new WP_Error( 'day_locked', $msg, array( 'status' => 409 ) );
        }

        global $wpdb;
        $note     = mb_substr( sanitize_textarea_field( (string) $note ), 0, self::NOTE_MAX );
        $inserted = $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->prefix}hdlv2_challenge_days (client_user_id, day_no, done_at, note) VALUES (%d, %d, %s, %s)",
            $user_id, $day, gmdate( 'Y-m-d H:i:s', $now ), $note
        ) );
        if ( false === $inserted ) {
            return new WP_Error( 'save_failed', 'Could not save. Try again.', array( 'status' => 500 ) );
        }
        if ( $inserted ) {
            HDLV2_Timeline::add_entry(
                $user_id,
                (int) ( self::enrolment( $user_id )['practitioner_id'] ?? 0 ),
                'challenge_day',
                sprintf( 'Day %d of 30 done', $day ),
                $note,
                null,
                'hdlv2_challenge_days',
                (int) $wpdb->insert_id
            );
        }
        return self::state( $user_id, $now );
    }

    public static function rest_tick( $request ) {
        $user_id = get_current_user_id();
        $state   = self::tick( $user_id, $request->get_param( 'day' ), (string) $request->get_param( 'note' ) );
        if ( is_wp_error( $state ) ) {
            return $state;
        }
        return rest_ensure_response( array(
            'state' => $state,
            'html'  => self::render_section( $user_id, self::has_full_report( $user_id ) ),
        ) );
    }

    /** Any client record (a confirmed Stage 1 or a Full Report bought) opens the Full Report form already. */
    public static function has_full_report( $user_id ) {
        return 'brand-new' !== HDLV2_Compatibility::get_client_journey_state( $user_id );
    }

    /** The ticked days and the timeline copies of their notes. */
    public static function forget( $user_id ) {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}hdlv2_challenge_days WHERE client_user_id = %d",
            $user_id
        ) );
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}hdlv2_timeline WHERE client_id = %d AND source_table = 'hdlv2_challenge_days'",
            $user_id
        ) );
    }

    /** The videos setting, cleaned: day => { title, youtube_id }. Bad entries are dropped. */
    public static function videos() {
        $raw = get_option( self::VIDEOS_OPTION, array() );
        if ( is_string( $raw ) ) {
            $raw = json_decode( $raw, true );
        }
        $out = array();
        foreach ( is_array( $raw ) ? $raw : array() as $v ) {
            $day = is_array( $v ) && isset( $v['day'] ) && is_numeric( $v['day'] ) ? (int) $v['day'] : 0;
            $id  = is_array( $v ) ? (string) ( $v['youtube_id'] ?? '' ) : '';
            if ( $day < 1 || $day > self::DAYS || ! preg_match( '/^[A-Za-z0-9_-]{11}$/', $id ) ) {
                continue;
            }
            $out[ $day ] = array(
                'title'      => mb_substr( trim( (string) ( $v['title'] ?? '' ) ), 0, 120 ),
                'youtube_id' => $id,
            );
        }
        ksort( $out );
        return $out;
    }

    /** The dashboard section, or '' when the client is not enrolled. */
    public static function render_section( $user_id, $has_full_report ) {
        $state = self::state( $user_id );
        if ( ! $state ) {
            return '';
        }
        $videos = self::videos();
        $day    = null === $state['open_day'] ? self::DAYS : $state['open_day'];
        $done   = $state['done_count'];

        $h  = '<section id="challenge" class="cd30" aria-labelledby="cd30-h">';
        $h .= '<div class="cd30-body">';
        $h .= '<div class="cd30-head"><h2 id="cd30-h" tabindex="-1">Your 30 days</h2>';
        $h .= '<p class="cd30-meta">Day ' . $day . ' of 30 · ' . $done . ' done';
        if ( $state['streak'] >= 2 ) {
            $h .= '<span class="cd30-streak"> · ' . $state['streak'] . ' days in a row</span>';
        }
        $h .= '</p></div>';

        $h .= '<ol class="cd30-grid" aria-label="Day by day">';
        for ( $n = 1; $n <= self::DAYS; $n++ ) {
            $done_at = $state['days'][ $n ]['done_at'];
            if ( null !== $done_at ) {
                $when = wp_date( 'j M', strtotime( $done_at . ' UTC' ) );
                $h   .= '<li class="cd30-day is-done" aria-label="' . esc_attr( 'Day ' . $n . ', done ' . $when ) . '"><span class="cd30-n">' . $n . '</span><span class="cd30-date">' . esc_html( $when ) . '</span></li>';
            } elseif ( $n === $state['open_day'] ) {
                $h .= '<li class="cd30-day is-open" aria-current="step"><span class="cd30-n">' . $n . '</span><span class="cd30-date">Today</span></li>';
            } else {
                $h .= '<li class="cd30-day is-locked" aria-disabled="true"><span class="cd30-n">' . $n . '</span></li>';
            }
        }
        $h .= '</ol>';

        $video = $videos[ $day ] ?? null;
        $title = $video && '' !== $video['title'] ? $video['title'] : 'Day ' . $day;
        $h .= '<div class="cd30-today">';
        $h .= '<h3 class="cd30-today-title">' . esc_html( $title ) . '</h3>';
        if ( $video ) {
            $h .= '<div class="hdlv2-video-embed"><iframe src="https://www.youtube-nocookie.com/embed/' . esc_attr( $video['youtube_id'] ) . '" title="' . esc_attr( $title ) . '" loading="lazy" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe></div>';
        } else {
            $h .= '<div class="cd30-video-coming">Video coming</div>';
        }
        if ( $state['complete'] ) {
            $h .= '<p class="cd30-next">You did all 30 days.</p>';
        } elseif ( null !== $state['next_opens_at'] ) {
            $h .= '<p class="cd30-next">Day ' . $day . ' done. Day ' . ( $day + 1 ) . ' opens tomorrow.</p>';
        } else {
            $h .= '<form class="cd30-tick" data-day="' . $day . '">';
            $h .= '<label for="cd30-note">How did it go? (optional)</label><textarea id="cd30-note" name="note" maxlength="500" rows="3"></textarea>';
            $h .= '<button type="submit" class="cd30-done">Done for today</button>';
            $h .= '<p class="cd30-error" role="alert" hidden></p>';
            $h .= '</form>';
        }
        $h .= '</div>';

        $h .= self::offer_card( $has_full_report, (int) ( self::enrolment( $user_id )['practitioner_id'] ?? 0 ) );
        $h .= '</div>';
        $h .= '<p class="cd30-live" aria-live="polite"></p>';
        $h .= '</section>';
        return $h;
    }

    /** D4: the hook, prices, url and code are the Stage 1 offer's own. */
    private static function offer_card( $has_full_report, $practitioner_id ) {
        if ( $has_full_report || ! HDLV2_Offer::applies_to( $practitioner_id ) ) {
            return '';
        }
        $o = HDLV2_Offer::get();
        return '<aside class="cd30-offer"><h3>' . esc_html( $o['hook'] ) . '</h3>'
             . '<p>Today ' . esc_html( HDLV2_Offer::money( $o['full_today_price'], $o ) ) . ' · ' . esc_html( HDLV2_Offer::money( $o['full_list_price'], $o ) ) . ' before</p>'
             . '<a class="cd30-offer-btn" href="' . esc_url( add_query_arg( 'code', rawurlencode( $o['code'] ), $o['url'] ) ) . '">Go deeper with the Full Report</a></aside>';
    }
}

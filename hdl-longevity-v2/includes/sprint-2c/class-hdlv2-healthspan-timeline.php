<?php
/**
 * HDL V2 — Healthspan Timeline page (v0.48.29).
 *
 * [hdlv2_healthspan_timeline] on /healthspan-timeline/, two modes (DA1, DA2):
 *   ?t=<64-hex> of a client with a report row (draft or final) → 'full', their report numbers;
 *   any other live client token, or none → 'preview': their own lane (or, with no token, the
 *   numbers the page reads from the URL fragment), loved ones blurred behind the Full Report offer.
 * The data is printed into the page as window.HDLV2_HT; there is no REST route.
 *
 * Flag hdlv2_ff_healthspan_timeline, absent = off: the shortcode prints nothing and no
 * surface links here. Options, each one line to change:
 *   hdlv2_ht_title          the page title (DA7), default "Healthspan Timeline"
 *   hdlv2_ht_card           JSON {title, text, button, url}: one card under "Years you depend on
 *                           others" (DA8), url https only; empty = no card
 *   hdlv2_ht_show_science   1 = the future-science slider and its panel (DA5)
 *   hdlv2_ht_gate           'full_report' (default); 'addon' is reserved for a later checkout
 *                           and behaves as full_report
 *
 * @package HDL_Longevity_V2
 * @since   0.48.29
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HDLV2_Healthspan_Timeline {

    const FLAG = 'hdlv2_ff_healthspan_timeline';
    const SLUG = 'healthspan-timeline';

    /** The 12 lifestyle keys of HDLV2_Final_Report::derive_lifestyle_band_label(). */
    const LIFESTYLE_KEYS = array(
        'physicalActivity', 'sleepDuration', 'sleepQuality', 'stressLevels',
        'socialConnections', 'dietQuality', 'alcoholConsumption', 'smokingStatus',
        'cognitiveActivity', 'sunlightExposure', 'supplementIntake', 'dailyHydration',
    );

    /** The report page's own words for a dead link (client-draft-view). */
    const EXPIRED = 'This link has expired. Please ask your practitioner for a fresh link.';

    public static function init() {
        add_shortcode( 'hdlv2_healthspan_timeline', array( __CLASS__, 'render_shortcode' ) );
        add_action( 'template_redirect', array( __CLASS__, 'no_cache_page' ) );
        add_filter( 'um_get_option_filter__access_exclude_uris', array( __CLASS__, 'um_public' ) );
        add_filter( 'body_class', array( __CLASS__, 'wide_body_class' ) );
    }

    public static function enabled() {
        return (bool) get_option( self::FLAG, false );
    }

    /** The page prints a client's numbers into its HTML: never cache it. */
    public static function no_cache_page() {
        if ( ! self::enabled() || ! is_page( self::SLUG ) ) {
            return;
        }
        nocache_headers();
        if ( ! headers_sent() ) {
            header( 'X-LiteSpeed-Cache-Control: no-cache' );
            header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
        }
    }

    /**
     * Ultimate Member runs the site "logged-in only" and matches its exclusions against the full
     * URL without the query. This page is public by design: the token and the fragment are the
     * access. Never in wp-admin, so the UM settings screen does not save the entry.
     */
    public static function um_public( $uris ) {
        if ( is_admin() || ! self::enabled() ) {
            return $uris;
        }
        $uris   = is_array( $uris ) ? $uris : array();
        $uris[] = self::page_url();
        return $uris;
    }

    /**
     * Divi puts the page in its 80 % row (300 px on a 375 px phone). The consultation page's body
     * class lets this page's own CSS widen the row; only on this page, with the shortcode, flag on.
     */
    public static function wide_body_class( $classes ) {
        if ( is_admin() || ! self::enabled() || ! is_page( self::SLUG ) ) {
            return $classes;
        }
        $post = get_post();
        if ( $post && has_shortcode( $post->post_content, 'hdlv2_healthspan_timeline' ) ) {
            $classes[] = 'hdlv2-wide-page';
        }
        return $classes;
    }

    public static function page_url() {
        return home_url( '/' . self::SLUG . '/' );
    }

    public static function report_url( $token ) {
        return add_query_arg( 't', $token, self::page_url() );
    }

    /** Beside the offer fields in /widget/public-config: the widget's teaser shows only when this is present. */
    public static function public_config_fields() {
        return self::enabled() ? array( 'timeline_url' => self::page_url() ) : array();
    }

    /**
     * What the page prints. '' token → preview with no numbers; an unknown, deleted or
     * expired token (without the client's or the owning practitioner's cookie) → array().
     */
    public static function page_data( $token ) {
        $token = (string) $token;
        if ( '' === $token ) {
            return self::frame( 'preview', array(), 0, null );
        }
        if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
            return array();
        }
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, client_user_id, practitioner_user_id, client_name, stage1_data, stage3_data, created_at, token_expires_at
             FROM {$wpdb->prefix}hdlv2_form_progress
             WHERE token = %s AND deleted_at IS NULL
             LIMIT 1",
            $token
        ) );
        if ( ! $row || ( self::expired( $row ) && ! self::may_view_expired( $row ) ) ) {
            return array();
        }
        if ( 'addon' === get_option( 'hdlv2_ht_gate', 'full_report' ) ) {
            self::log_gate_once();
        }
        $report = self::report_row( (int) $row->id );
        return self::frame( $report ? 'full' : 'preview', self::you( $row, $report ), (int) $row->id, (int) $row->practitioner_user_id );
    }

    public static function render_shortcode( $atts = array() ) {
        if ( ! self::enabled() ) {
            return '';
        }
        $token = isset( $_GET['t'] ) ? sanitize_text_field( wp_unslash( $_GET['t'] ) ) : '';
        $data  = self::page_data( $token );

        wp_enqueue_style( 'hdlv2-healthspan-timeline', HDLV2_PLUGIN_URL . 'assets/css/hdlv2-healthspan-timeline.css', array(), HDLV2_VERSION );
        if ( ! $data ) {
            return '<div class="hdlv2-ht-msg" role="status"><p>' . esc_html( self::EXPIRED ) . '</p></div>';
        }
        // Same handles as the report page, so a page carrying both loads each file once.
        wp_enqueue_style( 'hdlv2-draft-fonts', 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@600;700&display=swap', array(), null );
        wp_enqueue_script( 'hdl-trajectory-chart', HDLV2_PLUGIN_URL . 'assets/js/hdl-trajectory-chart-hero.js', array(), HDLV2_VERSION, true );
        wp_enqueue_script( 'hdlv2-healthspan-timeline', HDLV2_PLUGIN_URL . 'assets/js/hdlv2-healthspan-timeline.js', array( 'hdl-trajectory-chart' ), HDLV2_VERSION, true );
        wp_add_inline_script( 'hdlv2-healthspan-timeline', 'window.HDLV2_HT = ' . wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP ) . ';', 'before' );
        return '<div id="hdlv2-ht-root" class="hdlv2-ht"></div>';
    }

    /**
     * The client dashboard's link: a client with a report, or with Stage 3 done, opens the
     * page with their latest token; a Stage-1-only client opens the preview with their own
     * numbers in the fragment. '' when the flag is off or nothing is on file.
     */
    public static function dashboard_url( $user_id ) {
        if ( ! self::enabled() ) {
            return '';
        }
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, client_user_id, practitioner_user_id, client_name, token, stage1_data, stage3_data, created_at, token_expires_at
             FROM {$wpdb->prefix}hdlv2_form_progress
             WHERE client_user_id = %d AND deleted_at IS NULL
             ORDER BY id DESC LIMIT 1",
            (int) $user_id
        ) );
        if ( ! $row ) {
            return '';
        }
        $s3 = json_decode( (string) $row->stage3_data, true );
        if ( self::report_row( (int) $row->id ) || isset( $s3['server_result']['rate'] ) ) {
            return empty( $row->token ) ? '' : self::report_url( $row->token );
        }
        $you = self::you( $row, null );
        if ( ! $you ) {
            return '';
        }
        return self::page_url() . '#a=' . ( (int) gmdate( 'Y' ) - $you['born'] ) . '&s=' . $you['sex']
            . '&r=' . number_format( $you['rate0'], 2, '.', '' ) . '&n=' . rawurlencode( $you['name'] );
    }

    // ── internals ──────────────────────────────────────────────────

    /** As the report page: missing, garbage or past expiry is expired (fail closed). */
    private static function expired( $row ) {
        $ts = empty( $row->token_expires_at ) ? false : strtotime( $row->token_expires_at . ' UTC' );
        return false === $ts || $ts < time();
    }

    /** The report page's bypass: the client themself, or their owning practitioner, by cookie. */
    private static function may_view_expired( $row ) {
        $uid = get_current_user_id();
        return $uid && ( (int) $uid === (int) $row->client_user_id
            || ( class_exists( 'HDLV2_Compatibility' ) && HDLV2_Compatibility::practitioner_owns_client( $uid, (int) $row->client_user_id ) ) );
    }

    private static function report_row( $progress_id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT id, report_type, report_content
             FROM {$wpdb->prefix}hdlv2_reports
             WHERE form_progress_id = %d AND report_type IN ('final','draft')
             ORDER BY report_type = 'final' DESC, id DESC LIMIT 1",
            $progress_id
        ) );
    }

    /**
     * The client's lane. Numbers, in order: the final's frozen calc_snapshot; the draft's calc
     * as the report page reads it (Stage 3 server_result, else the draft's own content); Stage 3
     * alone; Stage 1 (bio = pace × age). No date of birth exists: born = Stage 1 year − age.
     */
    private static function you( $row, $report ) {
        $s1      = json_decode( (string) $row->stage1_data, true ) ?: array();
        $s3      = json_decode( (string) $row->stage3_data, true ) ?: array();
        $content = $report ? ( json_decode( (string) $report->report_content, true ) ?: array() ) : array();
        $age     = (int) ( $s1['q1_age'] ?? $s1['age'] ?? 0 );

        if ( isset( $content['calc_snapshot']['rate'] ) ) {
            $calc = $content['calc_snapshot'];
        } elseif ( isset( $s3['server_result']['rate'] ) ) {
            $calc = $s3['server_result'];
        } elseif ( isset( $content['rate'] ) ) {
            $calc = $content;
        } else {
            $calc = array();
        }

        if ( $calc ) {
            $rate   = (float) $calc['rate'];
            $bio    = isset( $calc['bio_age'] ) ? (float) $calc['bio_age'] : null;
            $scores = is_array( $calc['scores'] ?? null ) ? $calc['scores'] : array();
            $life   = array();
            foreach ( self::LIFESTYLE_KEYS as $k ) {
                if ( isset( $scores[ $k ] ) && is_numeric( $scores[ $k ] ) ) {
                    $life[] = 0 + $scores[ $k ];
                }
            }
            $source = 'report';
        } else {
            $rate = $s1['server_result']['rate'] ?? $s1['rate'] ?? $s1['rate_of_aging'] ?? null;
            if ( ! is_numeric( $rate ) || $age < 1 ) {
                return array();
            }
            $rate   = (float) $rate;
            $bio    = HDLV2_Stage1_Commentary::biological_age( $rate, $age );
            $raw    = is_array( $s1['server_result']['raw'] ?? null ) ? $s1['server_result']['raw'] : array();
            $life   = array_values( array_map( function ( $v ) { return 0 + $v; }, array_filter( $raw, 'is_numeric' ) ) );
            $source = 'stage1';
        }

        $year = (int) substr( (string) $row->created_at, 0, 4 );
        $name = sanitize_text_field( strtok( trim( (string) $row->client_name ), ' ' ) ?: '' );
        return array(
            'name'        => '' !== $name ? substr( $name, 0, 40 ) : 'You',
            'born'        => ( $year > 1900 ? $year : (int) gmdate( 'Y' ) ) - $age,
            'sex'         => 'male' === strtolower( (string) ( $s1['q1_sex'] ?? $s1['sex'] ?? '' ) ) ? 'M' : 'F',
            'bio'         => $bio,
            'rate0'       => $rate,
            'life_scores' => $life,
            'life_source' => $source,
        );
    }

    private static function frame( $mode, $you, $progress_id, $practitioner_id ) {
        $title = sanitize_text_field( (string) get_option( 'hdlv2_ht_title', '' ) );
        $data  = array(
            'mode'         => $mode,
            'lock'         => 'preview' === $mode,
            'you'          => $you,
            'store'        => 'full' === $mode ? 'hs-state-' . $progress_id : 'hs-state-preview',
            'title'        => '' !== $title ? $title : 'Healthspan Timeline',
            'show_science' => (bool) get_option( 'hdlv2_ht_show_science', false ),
            'offer'        => self::offer( $practitioner_id ),
        );
        $card = self::card();
        if ( $card ) {
            $data['card'] = $card;
        }
        return $data;
    }

    /**
     * The lock line and its button, from the one offer setting. The code word goes into the
     * link only for a known client (as the dashboard's offer card), never on the open page.
     *
     * @param int|null $practitioner_id null = no token (an anonymous visitor).
     */
    private static function offer( $practitioner_id ) {
        $o = HDLV2_Offer::get();
        if ( '' === $o['url'] || ( null !== $practitioner_id && ! HDLV2_Offer::applies_to( $practitioner_id ) ) ) {
            return array( 'line' => 'Your loved ones unlock with the full report.' );
        }
        return array(
            'line'   => 'Your loved ones unlock with the full report (today ' . HDLV2_Offer::money( $o['full_today_price'], $o ) . ').',
            'button' => 'Get the full report',
            'url'    => null === $practitioner_id ? $o['url'] : add_query_arg( 'code', rawurlencode( $o['code'] ), $o['url'] ),
        );
    }

    /** DA8 — Matthew's words and link; no card unless title, button and an https url are set. */
    private static function card() {
        $c = get_option( 'hdlv2_ht_card', '' );
        if ( is_string( $c ) ) {
            $c = json_decode( $c, true );
        }
        if ( ! is_array( $c ) ) {
            return null;
        }
        $card = array(
            'title'  => sanitize_text_field( (string) ( $c['title'] ?? '' ) ),
            'text'   => sanitize_text_field( (string) ( $c['text'] ?? '' ) ),
            'button' => sanitize_text_field( (string) ( $c['button'] ?? '' ) ),
            'url'    => esc_url_raw( (string) ( $c['url'] ?? '' ) ),
        );
        return '' !== $card['title'] && '' !== $card['button'] && preg_match( '#^https://#i', $card['url'] ) ? $card : null;
    }

    private static function log_gate_once() {
        static $done = false;
        if ( ! $done ) {
            $done = true;
            error_log( '[HDLV2 timeline] hdlv2_ht_gate=addon is reserved for a later checkout; the page behaves as full_report' );
        }
    }
}

HDLV2_Healthspan_Timeline::init();

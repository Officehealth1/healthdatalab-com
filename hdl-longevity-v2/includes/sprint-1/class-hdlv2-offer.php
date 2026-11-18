<?php
/**
 * HDLV2_Offer — the Full Report offer in ONE setting (v0.48.15).
 *
 * Every figure and line the Stage 1 PDF's page 2 and the widget's locked
 * panel show comes from the option `hdlv2_stage1_offer`, merged over the
 * defaults below (Matthew's call of 2026-10-13; his words replace the
 * placeholders one field at a time). Change one field:
 *
 *   wp option patch update hdlv2_stage1_offer hook "What is ageing you fastest?"
 *
 * (the option must exist first: `wp option add hdlv2_stage1_offer '{}' --format=json`;
 * several fields at once: `wp option update hdlv2_stage1_offer '{"title":"X","hook":"Y"}' --format=json`).
 *
 * Prices are numbers without a symbol; `currency` is printed in front.
 * v0.48.73 — one price, $27 (Matthew, 2026-11-16): no code and no list price. A saved option
 * that still holds `code` or `full_list_price` loses them as unknown keys.
 *
 * @package HDL_Longevity_V2
 * @since   0.48.15
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HDLV2_Offer {

    const OPTION = 'hdlv2_stage1_offer';

    /** v0.48.81 (plan 2h) — how long the inbox pass in a results e-mail works (planner's guess; Quim confirms before live). */
    const INBOX_PASS_DAYS = 3;

    /** v0.48.85 (plan 2k) — how long the browser pass from the lead reply works (Stripe and back fits well inside). */
    const BROWSER_PASS_MINUTES = 60;

    /** One source for the partnership line on every page a buyer reaches. */
    const PARTNER_LINE = 'Powered by HealthDataLab · a partnership focused on longevity';

    const DEFAULTS = array(
        'currency'              => '$',
        'hook'                  => 'Find out more about what is impacting your longevity the most',
        'title'                 => 'Want to go deeper?',
        'stage1_list_price'     => '29',
        'full_today_price'      => '27',
        'url'                   => 'https://altituding.com/full-report',
        'caption'               => 'The abilities your choices need, against where you are today',
        'video_url'             => '',
        'stage1_free_with_code' => false,
        // v0.48.27 — the 30-day challenge videos (D10): the free 30 days on the
        // dashboard carry this card.
        'challenge_url'         => 'https://altituding.com/challenge',
        'challenge_price'       => '27',
        'challenge_hook'        => 'A video for every one of your 30 days',
        // The practitioner whose clients see this offer (Matthew: Altituding).
        // 0 = every practitioner.
        'practitioner_id'       => 122,
        // v0.48.82 (plan 3) — the Healthspan Timeline as an upgrade (HDLV2_Timeline_Upgrade): $10 at
        // checkout or for timeline_offer_days after the form, then $27 on its own. The links are
        // Stripe payment links; empty = that offer is not shown.
        'timeline_upgrade_price' => '10',
        'timeline_alone_price'   => '27',
        'timeline_upgrade_url'   => '',
        'timeline_alone_url'     => '',
        'timeline_video_url'     => '',
        'timeline_offer_days'    => 7,
    );

    /** The defaults with the saved option laid over them, cleaned. Unknown keys are dropped. */
    public static function get() {
        $saved = get_option( self::OPTION, array() );
        if ( is_string( $saved ) ) {
            $saved = json_decode( $saved, true );
        }
        $out = self::DEFAULTS;
        foreach ( is_array( $saved ) ? $saved : array() as $k => $v ) {
            if ( ! array_key_exists( $k, self::DEFAULTS ) ) {
                continue;
            }
            if ( is_int( self::DEFAULTS[ $k ] ) ) {
                $out[ $k ] = absint( $v );
            } elseif ( is_bool( self::DEFAULTS[ $k ] ) ) {
                $out[ $k ] = (bool) $v && 'false' !== $v && '0' !== $v;
            } elseif ( in_array( $k, array( 'url', 'video_url', 'challenge_url', 'timeline_upgrade_url', 'timeline_alone_url', 'timeline_video_url' ), true ) ) {
                $out[ $k ] = esc_url_raw( (string) $v );
            } elseif ( substr( $k, -6 ) === '_price' ) {
                $out[ $k ] = preg_match( '/^\d{1,6}(\.\d{1,2})?$/', (string) $v ) ? (string) $v : self::DEFAULTS[ $k ];
            } else {
                $out[ $k ] = sanitize_text_field( (string) $v );
            }
        }
        return $out;
    }

    /** Whether this practitioner's clients see the offer. */
    public static function applies_to( $practitioner_id ) {
        $owner = self::get()['practitioner_id'];
        return 0 === $owner || (int) $practitioner_id === $owner;
    }

    /** "$27" — the currency always in front of the number. */
    public static function money( $number, $offer = null ) {
        $offer = $offer ?: self::get();
        return $offer['currency'] . $number;
    }

    /**
     * The ten Stage 1 PDF payload keys. Strings only: Make caches a field's
     * type from the first payload it sees (see the note on rate_of_ageing).
     * offer_list, offer_code and offer_code_ends stay as '' since v0.48.73
     * (no list price, no code): Make's 36-key contract keeps the keys.
     */
    public static function payload_keys( $show = true ) {
        $o = self::get();
        return array(
            'offer_show'      => $show ? '1' : '0',
            'offer_title'     => $o['title'],
            'offer_hook'      => $o['hook'],
            'offer_today'     => self::money( $o['full_today_price'], $o ),
            'offer_list'      => '',
            'offer_code'      => '',
            'offer_url'       => $o['url'],
            'offer_caption'   => $o['caption'],
            'offer_video_url' => $o['video_url'],
            'offer_code_ends' => '',
        );
    }

    /**
     * The person's own offer keys for the Stage 1 PDF and e-mail. v0.48.73: no code
     * (flat $27, Matthew 2026-11-16), so nothing is asked of Altituding; the link
     * carries the lead's address in its fragment so the page locks it at Stripe.
     *
     * v0.48.77: the link opens the buying page for the lead's band (band_url).
     *
     * v0.48.81 (plan 2h): with hdlv2_ff_straight_to_form on, the fragment also carries
     * &own=<inbox pass>. The e-mail and the PDF are the only places it goes (this one key,
     * offer_url); the result page's button never gets one. Switch off: today's link.
     *
     * @param string $email           the lead's address; left out of the link when not valid.
     * @param string $band            HDLV2_Stage1_Commentary::band_key() of the lead's rate ('' = none).
     * @param int    $practitioner_id the lead's practitioner; no pass without one.
     * @return array { offer_code, offer_url, offer_code_ends }
     */
    public static function personal( $email = '', $band = '', $practitioner_id = 0 ) {
        $url = self::with_email( self::band_url( $band ), $email );
        if ( get_option( 'hdlv2_ff_straight_to_form', false ) && is_email( $email ) && absint( $practitioner_id ) ) {
            $url .= '&own=' . self::inbox_pass( $email, $practitioner_id );
        }
        return array(
            'offer_code'      => '',
            'offer_url'       => $url,
            'offer_code_ends' => '',
        );
    }

    /**
     * v0.48.81 (plan 2h) — the inbox pass: "<unix time>.<64 hex>", signed with the site's auth salt
     * (the pattern of HDLV2_Timeline_Links::consent_key). Nothing is stored; a changed salt ends
     * every pass. It proves the browser opened what we sent to that inbox, nothing more:
     * HDL_Stage1_Ticket::handle_open() accepts it only with a fresh payment for the same e-mail.
     */
    public static function inbox_pass( $email, $practitioner_id, $time = null ) {
        $time = null === $time ? time() : (int) $time;
        $msg  = 'hdlv2-inbox-pass|' . absint( $practitioner_id ) . '|' . strtolower( trim( (string) $email ) ) . '|' . $time;
        return $time . '.' . hash_hmac( 'sha256', $msg, wp_salt( 'auth' ) );
    }

    /** True for a pass made for this e-mail and practitioner, under INBOX_PASS_DAYS old, not from the future. */
    public static function inbox_pass_ok( $pass, $email, $practitioner_id, $now = null ) {
        if ( ! is_string( $pass ) || ! preg_match( '/^(\d{10})\.[a-f0-9]{64}$/', $pass, $m ) ) {
            return false;
        }
        $now  = null === $now ? time() : (int) $now;
        $made = (int) $m[1];
        if ( $made > $now + MINUTE_IN_SECONDS || $made < $now - self::INBOX_PASS_DAYS * DAY_IN_SECONDS ) {
            return false;
        }
        return hash_equals( self::inbox_pass( $email, $practitioner_id, $made ), $pass );
    }

    /**
     * v0.48.85 (plan 2k) — whether the lead reply hands the browser a pass for the result page's Buy
     * button: the three switches on and the offer shown to this practitioner's leads.
     */
    public static function browser_pass_on( $practitioner_id ) {
        return get_option( 'hdlv2_ff_straight_in_result_page', false ) && get_option( 'hdlv2_ff_straight_to_form', false )
            && get_option( 'hdlv2_ff_result_offer', false ) && self::applies_to( $practitioner_id );
    }

    /**
     * v0.48.85 (plan 2k) — the browser pass: "b<unix time>.<64 hex>", given only in the lead reply to the
     * browser that sent the nine answers. It proves less than the inbox pass (typing, not owning), so
     * HDL_Stage1_Ticket::handle_open() gives a one-day key to the form itself, never a sign-in, and any
     * sign-in of the account ends that key (G4, v0.48.87). Signed over the lead id
     * and the digest of that lead row's answers, so a later re-send of the answers under the same e-mail
     * ends it. The lead id is read on the server, never shown (review F4). Nothing is stored.
     */
    public static function browser_pass( $email, $practitioner_id, $lead_id, $digest, $time = null ) {
        $time = null === $time ? time() : (int) $time;
        $msg  = 'hdlv2-browser-pass|' . absint( $practitioner_id ) . '|' . strtolower( trim( (string) $email ) ) . '|' . absint( $lead_id ) . '|' . (string) $digest . '|' . $time;
        return 'b' . $time . '.' . hash_hmac( 'sha256', $msg, wp_salt( 'auth' ) );
    }

    /** True for a string in the browser pass's shape (the inbox pass has no leading b). */
    public static function is_browser_pass( $pass ) {
        return is_string( $pass ) && (bool) preg_match( '/^b\d{10}\.[a-f0-9]{64}$/', $pass );
    }

    /** True for a pass made for this e-mail, practitioner, lead and digest, under BROWSER_PASS_MINUTES old, not from the future. */
    public static function browser_pass_ok( $pass, $email, $practitioner_id, $lead_id, $digest, $now = null ) {
        if ( '' === (string) $digest || ! self::is_browser_pass( $pass ) ) {
            return false;
        }
        $now  = null === $now ? time() : (int) $now;
        $made = (int) substr( $pass, 1, 10 );
        if ( $made > $now + MINUTE_IN_SECONDS || $made < $now - self::BROWSER_PASS_MINUTES * MINUTE_IN_SECONDS ) {
            return false;
        }
        return hash_equals( self::browser_pass( $email, $practitioner_id, $lead_id, $digest, $made ), $pass );
    }

    /**
     * v0.48.77 (plan 2c) — the buying page for a band: the offer url plus -slower, -average or
     * -faster; an unknown or empty band gets the url unchanged.
     */
    public static function band_url( $band ) {
        // ponytail: the buying page must answer at <url>-<band word>; a different offer url needs
        // the same three rewrites on its own site (Altituding's netlify.toml has them for /full-report).
        $words = array( 'green' => 'slower', 'blue' => 'average', 'red' => 'faster' );
        $url   = self::get()['url'];
        return isset( $words[ $band ] ) && '' !== $url ? rtrim( $url, '/' ) . '-' . $words[ $band ] : $url;
    }

    /**
     * v0.48.77 (plan 2c) — the Stage 1 result page's offer, behind hdlv2_ff_result_offer, for the
     * offer's practitioner only: the price, the three band pages and the offer film for a band
     * with no video. The widget adds the visitor's own e-mail; nothing personal is sent here.
     */
    public static function result_offer_fields( $practitioner_id ) {
        if ( ! get_option( 'hdlv2_ff_result_offer', false ) || ! self::applies_to( $practitioner_id ) ) {
            return array();
        }
        $o = self::get();
        return array( 'result_offer' => array(
            'today'     => self::money( $o['full_today_price'], $o ),
            'band_urls' => array(
                'green' => self::band_url( 'green' ),
                'blue'  => self::band_url( 'blue' ),
                'red'   => self::band_url( 'red' ),
            ),
            'video_url' => $o['video_url'],
        ) );
    }

    /**
     * 0.48.22 — the Full Report page hands a good address to Stripe as prefilled_email.
     * In the fragment (D7): a browser never sends it to a server, a log or a Referer.
     */
    private static function with_email( $url, $email ) {
        return is_email( $email ) ? $url . '#email=' . rawurlencode( $email ) : $url;
    }

    /** What the widget's locked panel needs. The code word itself is never public. */
    public static function public_config_fields() {
        $o = self::get();
        return array(
            'stage1_list_price'     => $o['stage1_list_price'],
            'currency'              => $o['currency'],
            'stage1_free_with_code' => $o['stage1_free_with_code'],
        );
    }
}

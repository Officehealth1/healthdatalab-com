<?php
/**
 * HDLV2_Offer — the Full Report offer in ONE setting (v0.48.15).
 *
 * Every figure and line the Stage 1 PDF's page 2 and the widget's locked
 * panel show comes from the option `hdlv2_stage1_offer`, merged over the
 * defaults below (Matthew's call of 2026-10-13; his words replace the
 * placeholders one field at a time). Change one field:
 *
 *   wp option patch update hdlv2_stage1_offer code SUMMERFREE
 *
 * (the option must exist first: `wp option add hdlv2_stage1_offer '{}' --format=json`;
 * several fields at once: `wp option update hdlv2_stage1_offer '{"code":"X","hook":"Y"}' --format=json`).
 *
 * Prices are numbers without a symbol; `currency` is printed in front.
 *
 * @package HDL_Longevity_V2
 * @since   0.48.15
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HDLV2_Offer {

    const OPTION = 'hdlv2_stage1_offer';

    /** One source for the partnership line on every page a buyer reaches. */
    const PARTNER_LINE = 'Powered by HealthDataLab · a partnership focused on longevity';

    const DEFAULTS = array(
        'currency'              => '$',
        'code'                  => 'YOURCODE',
        'hook'                  => 'Find out more about what is impacting your longevity the most',
        'title'                 => 'Want to go deeper?',
        'stage1_list_price'     => '29',
        'full_list_price'       => '129',
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
            } elseif ( 'url' === $k || 'video_url' === $k || 'challenge_url' === $k ) {
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
     * The nine Stage 1 PDF payload keys. Strings only: Make caches a field's
     * type from the first payload it sees (see the note on rate_of_ageing).
     */
    public static function payload_keys( $show = true ) {
        $o = self::get();
        return array(
            'offer_show'      => $show ? '1' : '0',
            'offer_title'     => $o['title'],
            'offer_hook'      => $o['hook'],
            'offer_today'     => self::money( $o['full_today_price'], $o ),
            'offer_list'      => self::money( $o['full_list_price'], $o ),
            'offer_code'      => $o['code'],
            'offer_url'       => $o['url'],
            'offer_caption'   => $o['caption'],
            'offer_video_url' => $o['video_url'],
        );
    }

    /**
     * v0.48.18 — this person's own one-use Full Report code, asked of Altituding
     * (netlify/functions/full-report-code.js), which has Stripe make it. Called
     * from the drain, never the visitor's request. Any failure, or the two
     * wp-config constants absent: the shared code from the option, in the
     * link too (0.48.22), so the PDF always goes out and its button opens the gate.
     *
     * @param string $ref   lead-<id> or invite-<id>; never the email.
     * @param string $email the lead's address, pre-filled at Stripe; it never goes into the code request.
     * @return array { offer_code, offer_url }
     */
    public static function personal( $ref, $email = '' ) {
        $o      = self::get();
        $shared = array(
            'offer_code' => $o['code'],
            'offer_url'  => self::with_email( add_query_arg( 'code', rawurlencode( $o['code'] ), $o['url'] ), $email ),
        );
        $url    = defined( 'HDLV2_FULL_CODE_URL' ) ? (string) HDLV2_FULL_CODE_URL : '';
        $key    = self::code_key();
        if ( '' === $url || '' === $key || ! preg_match( '/^[A-Za-z0-9_-]{1,64}$/', (string) $ref ) ) {
            return $shared;
        }
        // 3 s: a drain tick sends up to 20 PDFs inside one minute.
        $res  = wp_remote_post( $url, array(
            'body'    => wp_json_encode( array( 'ref' => (string) $ref ) ),
            'headers' => array( 'Content-Type' => 'application/json', 'X-HDL-Full-Code-Key' => $key ),
            'timeout' => 3,
        ) );
        $http = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
        $body = 200 === $http ? json_decode( wp_remote_retrieve_body( $res ), true ) : null;
        $code = is_array( $body ) && is_string( $body['code'] ?? null ) ? $body['code'] : '';
        if ( ! preg_match( '/^[A-Za-z0-9-]{4,40}$/', $code ) ) {
            error_log( '[HDLV2 offer] personal code failed (HTTP ' . $http . ') — shared code used' );
            return $shared;
        }
        return array( 'offer_code' => $code, 'offer_url' => self::with_email( add_query_arg( 'code', rawurlencode( $code ), $o['url'] ), $email ) );
    }

    /**
     * 0.48.22 — the Full Report page hands a good address to Stripe as prefilled_email.
     * In the fragment (D7): a browser never sends it to a server, a log or a Referer.
     */
    private static function with_email( $url, $email ) {
        return is_email( $email ) ? $url . '#email=' . rawurlencode( $email ) : $url;
    }

    /** The key Altituding checks; wp-config only. */
    private static function code_key() {
        return defined( 'HDLV2_FULL_CODE_KEY' ) ? (string) HDLV2_FULL_CODE_KEY : '';
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

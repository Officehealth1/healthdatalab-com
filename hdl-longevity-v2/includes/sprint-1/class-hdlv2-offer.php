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
     * @param string $email the lead's address; left out of the link when not valid.
     * @return array { offer_code, offer_url, offer_code_ends }
     */
    public static function personal( $email = '' ) {
        return array(
            'offer_code'      => '',
            'offer_url'       => self::with_email( self::get()['url'], $email ),
            'offer_code_ends' => '',
        );
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

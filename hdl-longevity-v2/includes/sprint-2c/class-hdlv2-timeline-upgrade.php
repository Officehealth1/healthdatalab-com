<?php
/**
 * HDLV2_Timeline_Upgrade — the Healthspan Timeline as an upgrade to the Full Report (v0.48.82, plan 3).
 *
 * The owner, 2026-11-16: the Full Report is $27, or $37 with the Timeline ("ten dollars more"); a
 * buyer who did not take it is offered it for $10 for a week after their form, then $27 on its own.
 *
 * Who owns it is read from the buyer's Full Report tickets (hdlv2_widget_invites, source
 * 'full_report'): timeline_paid_ref (DB 3.34) holds the Stripe reference that paid for it, or
 * 'included' (a ticket from before the switch, a pack seat, another practitioner's sale). Empty =
 * a Stripe buyer who did not take it. The ticket route writes it (HDL_Stage1_Ticket); this class
 * only reads.
 *
 * Switch hdlv2_ff_timeline_upgrade, absent = off = today's behaviour on every page (the report
 * row alone opens the Timeline). It also needs the Timeline page itself (hdlv2_ff_healthspan_timeline).
 * Prices, links, the film and the days: HDLV2_Offer (timeline_* keys).
 *
 * @package HDL_Longevity_V2
 * @since   0.48.82
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HDLV2_Timeline_Upgrade {

    const FLAG     = 'hdlv2_ff_timeline_upgrade';
    const INCLUDED = 'included';

    public static function enabled() {
        return (bool) get_option( self::FLAG, false ) && HDLV2_Healthspan_Timeline::enabled();
    }

    /** The e-mail's live Full Report tickets, newest first: { id, practitioner_id, external_ref, timeline_paid_ref }. */
    public static function tickets( $email ) {
        $email = strtolower( trim( (string) $email ) );
        if ( '' === $email ) {
            return array();
        }
        global $wpdb;
        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT id, practitioner_id, external_ref, timeline_paid_ref FROM {$wpdb->prefix}hdlv2_widget_invites
             WHERE LOWER(client_email) = %s AND source = 'full_report' AND external_ref IS NOT NULL AND status <> 'revoked'
             ORDER BY id DESC",
            $email
        ) );
    }

    /**
     * Does this client record own the Timeline? Switch off, or no live Full Report ticket (the
     * practitioner's own client): yes, as today. Otherwise: any of their tickets carries a stamp.
     */
    public static function owned( $row ) {
        if ( ! self::enabled() ) {
            return true;
        }
        $tickets = self::tickets( $row->client_email ?? '' );
        foreach ( $tickets as $t ) {
            if ( '' !== (string) $t->timeline_paid_ref ) {
                return true;
            }
        }
        // v0.49.00 (plan 14 A4) — or the practitioner (or an admin) gave it: HDLV2_Access::decide()'s
        // Timeline row (hdlv2_ff_access_gate; off = a stored grant counts for nothing).
        return ! $tickets || ( class_exists( 'HDLV2_Access' ) && HDLV2_Access::timeline_granted( $row ) );
    }

    /**
     * What a buyer without the Timeline is offered at $now (unix time), or null.
     * Form not sent: $10, no date. Up to the end of the London day seven days after the form:
     * $10 and that date. After: $27 with the Timeline-alone link, or null when that link is empty.
     *
     * @return array|null { price, worth, ends ('j F Y' or ''), ends_at (unix, 0 = none), url }
     */
    public static function offer_for( $row, $now ) {
        if ( ! self::enabled() || ! HDLV2_Offer::applies_to( (int) ( $row->practitioner_user_id ?? 0 ) ) || self::owned( $row ) ) {
            return null;
        }
        $o     = HDLV2_Offer::get();
        $email = (string) ( $row->client_email ?? '' );
        $sent  = (string) ( $row->stage3_completed_at ?? '' );
        $ends  = '' === $sent ? 0 : self::ends_at( $sent, $o['timeline_offer_days'] );
        // ponytail: a payment link is one public address and cannot close for one buyer; our pages stop
        // offering it at the date, a late payer is still served and the team is told. A checkout made per
        // buyer by our server is the way up if late payers are ever seen.
        if ( ! $ends || $now <= $ends ) {
            $url = self::link( $o['timeline_upgrade_url'], $email );
            return '' === $url ? null : array(
                'price'   => HDLV2_Offer::money( $o['timeline_upgrade_price'], $o ),
                'worth'   => HDLV2_Offer::money( $o['timeline_alone_price'], $o ),
                'ends'    => $ends ? self::day( $ends, 'j F Y' ) : '',
                'ends_at' => $ends,
                'url'     => $url,
            );
        }
        $url = self::link( $o['timeline_alone_url'], $email );
        return '' === $url ? null : array(
            'price'   => HDLV2_Offer::money( $o['timeline_alone_price'], $o ),
            'worth'   => '',
            'ends'    => '',
            'ends_at' => 0,
            'url'     => $url,
        );
    }

    /** The end of the offer: 23:59:59 site time, $days days after the form (stored in site time). */
    public static function ends_at( $sent, $days ) {
        try {
            $d = new DateTimeImmutable( $sent, wp_timezone() );
        } catch ( Exception $e ) {
            return 0;
        }
        return $d->modify( '+' . max( 1, (int) $days ) . ' days' )->setTime( 23, 59, 59 )->getTimestamp();
    }

    /** "24 November 2026" in site time. */
    public static function day( $ts, $format ) {
        return ( new DateTimeImmutable( '@' . (int) $ts ) )->setTimezone( wp_timezone() )->format( $format );
    }

    /**
     * The five keys of the report e-mail (Make module 63), always present, strings only. "1" only for
     * the $10 offer with its date: the e-mail's words say "until".
     */
    public static function payload_fields( $row, $now ) {
        $o  = self::offer_for( $row, $now );
        $on = $o && '' !== $o['ends'];
        return array(
            'timeline_offer_show'  => $on ? '1' : '0',
            'timeline_offer_price' => $on ? $o['price'] : '',
            'timeline_offer_worth' => $on ? $o['worth'] : '',
            'timeline_offer_url'   => $on ? $o['url'] : '',
            'timeline_offer_ends'  => $on ? $o['ends'] : '',
        );
    }

    /**
     * What the card after the form needs: the offer and the film, or that the Timeline is theirs.
     * Prices, the date and addresses only: the card's words live in the script.
     */
    public static function card_fields( $row, $now ) {
        if ( ! self::enabled() || ! HDLV2_Offer::applies_to( (int) ( $row->practitioner_user_id ?? 0 ) ) ) {
            return array();
        }
        $o = self::offer_for( $row, $now );
        if ( $o ) {
            return array( 'timeline_offer' => array(
                'price'     => $o['price'],
                'worth'     => $o['worth'],
                'ends'      => $o['ends'],
                'url'       => $o['url'],
                'video_url' => HDLV2_Offer::get()['timeline_video_url'],
            ) );
        }
        // The one line is for a buyer (a live Full Report ticket that carries a stamp); the practitioner's
        // own client, who never had a ticket, keeps today's card (plan § 6.1).
        return self::tickets( $row->client_email ?? '' ) && self::owned( $row ) ? array( 'timeline_owned' => true ) : array();
    }

    /** A Stripe payment link with the buyer's address locked; '' unless the link is https. */
    private static function link( $url, $email ) {
        if ( ! preg_match( '#^https://#i', (string) $url ) ) {
            return '';
        }
        return is_email( $email ) ? add_query_arg( 'locked_prefilled_email', rawurlencode( $email ), $url ) : $url;
    }
}

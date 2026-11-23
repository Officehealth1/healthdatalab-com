<?php
/**
 * The report names, in one place (v0.48.96, plan 12 of the $27 path; switch hdlv2_ff_report_names,
 * absent = off).
 *
 * Off, every caller prints its own words of today ("Full Report", "Trajectory Plan", "Final Report"),
 * passed as $today. On, the names below: the $27 report is the "Longevity Report", the report made
 * after a consultation is the "Comprehensive Report". The free report keeps its words until its own
 * plan. Draft names for the owner (2026-11-21: "we could just call it the longevity report";
 * "comprehensive … not bad"; chosen to try on 2026-11-23: "now we can adopt the Comprehensive name").
 *
 * Scripts get the names through their localized config (config()); Make and the PDF templates get
 * them in the payloads (report_name, reviewed_line), only with the switch on.
 *
 * @package HDL_Longevity_V2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HDLV2_Report_Names {

    const OPTION = 'hdlv2_ff_report_names';

    private static $names = array(
        'paid'         => 'Longevity Report',
        'consultation' => 'Comprehensive Report',
    );

    public static function on() {
        return (bool) get_option( self::OPTION, false );
    }

    /** The name for $key ('paid', 'consultation', 'free') with the switch on; $today otherwise. */
    public static function say( $key, $today ) {
        return ( self::on() && isset( self::$names[ $key ] ) ) ? self::$names[ $key ] : $today;
    }

    /** A script's localized config with report_names added when the switch is on; untouched when off. */
    public static function config( array $cfg ) {
        return self::on() ? $cfg + array( 'report_names' => self::$names ) : $cfg;
    }

    /**
     * "Reviewed with you by {name}, {date}": the account that did the consultation (the one that made
     * the report) and the date the report was made, as the PDF cover prints it. Plain text: WordPress
     * stores a display name HTML-encoded, so it is decoded and stripped of tags here and escaped where
     * it is printed. No usable name (empty, or an e-mail address): "Reviewed with your practitioner".
     * No practitioner account (the automation tier makes the report with 0): no line, nobody reviewed it.
     */
    public static function reviewed_line( $practitioner_id, $date ) {
        if ( ! self::on() || (int) $practitioner_id <= 0 ) {
            return '';
        }
        $user = get_userdata( (int) $practitioner_id );
        if ( ! $user ) {
            return '';
        }
        $name = wp_specialchars_decode( (string) $user->display_name, ENT_QUOTES );
        $name = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $name ) ) );
        if ( '' === $name || false !== strpos( $name, '@' ) ) {
            return sprintf( 'Reviewed with your practitioner, %s', $date );
        }
        return sprintf( 'Reviewed with you by %s, %s', $name, $date );
    }
}

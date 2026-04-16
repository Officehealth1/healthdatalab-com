<?php
/**
 * Plugin deactivator.
 *
 * Does NOT drop tables — data is preserved for reactivation.
 * Tables are only removed via uninstall.php (not yet created).
 *
 * @package HDL_Longevity_V2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HDLV2_Deactivator {

    public static function deactivate() {
        // Clean up any scheduled cron events
        wp_clear_scheduled_hook( 'hdlv2_weekly_flight_plan' );
        wp_clear_scheduled_hook( 'hdlv2_monthly_summary' );
        wp_clear_scheduled_hook( 'hdlv2_checkin_reminder' );
        wp_clear_scheduled_hook( 'hdlv2_audio_cleanup' );
        wp_clear_scheduled_hook( 'hdlv2_quarterly_review' );
    }
}

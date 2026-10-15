<?php
/**
 * Read-only STBY baseline for the abilities walk (0.48.11 / 0.48.12):
 * row counts and a hash of each protected row, before and after the walk.
 * Copy to /tmp on STBY, `wp eval-file`, delete. Writes nothing.
 */
global $wpdb;
$p = $wpdb->prefix . 'hdlv2_';
printf( "counts fp_not_deleted/why/reports/leads/users: %d / %d / %d / %d / %d\n",
    $wpdb->get_var( "SELECT COUNT(*) FROM {$p}form_progress WHERE deleted_at IS NULL" ),
    $wpdb->get_var( "SELECT COUNT(*) FROM {$p}why_profiles" ),
    $wpdb->get_var( "SELECT COUNT(*) FROM {$p}reports" ),
    $wpdb->get_var( "SELECT COUNT(*) FROM {$p}widget_leads" ),
    $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ) );
foreach ( array( 251, 252, 253, 255, 256, 259, 270, 275, 276 ) as $id ) {
    $fp  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}form_progress WHERE id = %d", $id ), ARRAY_A );
    $why = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p}why_profiles WHERE form_progress_id = %d ORDER BY id", $id ), ARRAY_A );
    $rep = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p}reports WHERE form_progress_id = %d ORDER BY id", $id ), ARRAY_A );
    printf( "%d fp=%s why=%s reports=%s\n", $id,
        $fp ? substr( md5( json_encode( $fp ) ), 0, 12 ) : 'none',
        $why ? substr( md5( json_encode( $why ) ), 0, 12 ) : 'none',
        $rep ? substr( md5( json_encode( $rep ) ), 0, 12 ) : 'none' );
}
printf( "ff_single_form=%s ff_stage2_pdf_single=%s\n", var_export( get_option( 'hdlv2_ff_single_form', false ), true ), var_export( get_option( 'hdlv2_ff_stage2_pdf_single', false ), true ) );

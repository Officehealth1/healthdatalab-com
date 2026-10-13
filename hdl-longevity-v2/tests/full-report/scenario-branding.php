<?php
/**
 * H6 — /form/load and the report page data carry partner_line from the one
 * source (HDLV2_Offer::PARTNER_LINE) beside the practitioner's name and logo;
 * every base_layout email ends with the same line. (The /form/load key list
 * itself is pinned by tests/single-form L1.)
 *
 * Run:  php scenario-branding.php
 */
error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );
require __DIR__ . '/stubs.php';
load_sources();
$R = __DIR__ . '/../../';
$form  = file_get_contents( $R . 'includes/sprint-2/class-hdlv2-staged-form.php' );
$draft = file_get_contents( $R . 'includes/sprint-2c/class-hdlv2-client-draft-view.php' );
check( 'BR.1 /form/load keeps name + logo and adds partner_line', false !== strpos( $form, "'practitioner_logo_url' => \$practitioner_logo_url," ) && false !== strpos( $form, "'partner_line'          => HDLV2_Offer::PARTNER_LINE," ) );
check( 'BR.2 report page data adds partner_line', false !== strpos( $draft, "'partner_line'           => HDLV2_Offer::PARTNER_LINE," ) );
check( 'BR.3 the line reads as Matthew said it', 'Powered by HealthDataLab · a partnership focused on longevity' === HDLV2_Offer::PARTNER_LINE );
$html = HDLV2_Email_Templates::base_layout( '<p>x</p>', null, 'T' );
check( 'BR.4 every email footer carries the partnership line', false !== strpos( $html, 'a partnership focused on longevity' ) );
done();

<?php
/**
 * v0.48.50 (W39, W40) — the PHP twin of the sun and water answers: HDLV2_Flight_Notes::s3_label()
 * reads the new words with hdlv2_ff_second_form_wording_v2 set and today's without; every other
 * field is the same either way. An old saved value keeps its score and, with the flag on, shows
 * the new words (said in the build report).
 * Run:  php scenario-wording.php   (self-asserting, exit 0/1)
 */
error_reporting( E_ALL & ~E_DEPRECATED );
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['options'] = array();
function get_option( $k, $default = false ) { return array_key_exists( $k, $GLOBALS['options'] ) ? $GLOBALS['options'][ $k ] : $default; }
require __DIR__ . '/../../includes/sprint-2c/class-hdlv2-flight-notes.php';

$pass = 0; $fail = 0;
function check( $label, $ok ) { global $pass, $fail; echo ( $ok ? 'PASS' : 'FAIL' ) . "  $label\n"; $ok ? $pass++ : $fail++; }
$F = 'HDLV2_Flight_Notes';
$FIELDS = array( 'sleepDuration', 'physicalActivity', 'alcoholConsumption', 'smokingStatus', 'stressLevels', 'dailyHydration', 'sitToStand', 'breathHold',
    'balance', 'skinElasticity', 'dietQuality', 'supplementIntake', 'sunlightExposure', 'cognitiveActivity', 'socialConnections' );
$snap = function () use ( $F, $FIELDS ) { $o = array(); foreach ( $FIELDS as $f ) for ( $v = 0; $v <= 5; $v++ ) $o[ "$f.$v" ] = $F::s3_label( $f, $v ); return $o; };

$off = $snap();
check( 'W1 flag off: sun 5 reads "Morning and evening daily"', 'Morning and evening daily' === $off['sunlightExposure.5'] );
check( 'W2 flag off: water 0 reads "Less than 1 litre"', 'Less than 1 litre' === $off['dailyHydration.0'] );

$GLOBALS['options']['hdlv2_ff_second_form_wording_v2'] = '1';
$on = $snap();
$SUN = array( "Hardly ever, I'm indoors most days", 'Now and then, not most days', 'Most days, and I burned two or more times in the past year',
    'Most days, and I burned once in the past year', 'Most days, but less than 20 minutes', 'Most days, 20 minutes or more, and I rarely burn' );
$WATER = array( 'None or one glass a day', '2 to 3 glasses a day', '4 to 5 glasses a day', '6 to 7 glasses a day', '8 to 9 glasses a day', '10 or more glasses a day' );
$sun_ok = true; $water_ok = true;
for ( $v = 0; $v <= 5; $v++ ) { $sun_ok = $sun_ok && $SUN[ $v ] === $on[ "sunlightExposure.$v" ]; $water_ok = $water_ok && $WATER[ $v ] === $on[ "dailyHydration.$v" ]; }
check( 'W3 flag on: the six sun labels by score (5 = "Most days, 20 minutes or more, and I rarely burn")', $sun_ok );
check( 'W4 flag on: the six water labels by score, "glasses a day" so a practitioner reads them alone', $water_ok );
$same = true;
foreach ( $on as $k => $label ) if ( 0 !== strpos( $k, 'sunlightExposure.' ) && 0 !== strpos( $k, 'dailyHydration.' ) ) $same = $same && $label === $off[ $k ];
check( 'W5 flag on: every other field reads as before', $same );
check( 'W6 blank and unknown still read \'\'', '' === $F::s3_label( 'sunlightExposure', '' ) && '' === $F::s3_label( 'nope', 3 ) );
echo "\n" . ( $fail ? "SCENARIO: FAIL ($fail)\n" : "SCENARIO: PASS ($pass)\n" );
exit( $fail ? 1 : 0 );

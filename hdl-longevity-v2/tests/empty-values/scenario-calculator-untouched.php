<?php
/**
 * v0.48.79 (M39) — a pin, green from the start: the REAL HDLV2_Rate_Calculator::calculate_full() already
 * leaves an empty measurement out, and this build does not touch it.
 *   - blood pressure unset, '', '0', 0 or null: no score, and the same rate and biological age each time;
 *     the same for heart rate, for waist with hip, and for height with weight;
 *   - a good reading lowers the rate and a poor one raises it, with empty in between: empty is neither;
 *   - the calculator file is byte for byte the 0.48.77 file.
 * Run:  php scenario-calculator-untouched.php   (self-asserting, exit 0/1)
 */
error_reporting( E_ALL & ~E_DEPRECATED );
define( 'ABSPATH', __DIR__ . '/' );
$file = dirname( __DIR__, 2 ) . '/includes/sprint-2/class-hdlv2-rate-calculator.php';
require $file;

$pass = 0; $fail = 0;
function check( $label, $ok ) { global $pass, $fail; echo ( $ok ? 'PASS' : 'FAIL' ) . "  $label\n"; $ok ? $pass++ : $fail++; }

$full = array(
    'height' => '172', 'weight' => '70', 'waist' => '84', 'hip' => '98', 'bpSystolic' => '118', 'bpDiastolic' => '76', 'restingHeartRate' => '62',
    'skinElasticity' => '3', 'physicalActivity' => '3', 'sleepDuration' => '4', 'sleepQuality' => '3', 'stressLevels' => '3', 'socialConnections' => '4',
    'dietQuality' => '3', 'alcoholConsumption' => '4', 'smokingStatus' => '5', 'cognitiveActivity' => '3', 'sunlightExposure' => '3',
    'supplementIntake' => '2', 'dailyHydration' => '3', 'sitToStand' => '3', 'breathHold' => '3', 'balance' => '4',
);
$run = function ( $data ) { return HDLV2_Rate_Calculator::calculate_full( 52, $data, 'female' ); };
$EMPTY = array( 'unset' => 'UNSET', "''" => '', "'0'" => '0', '0' => 0, 'null' => null );
$with = function ( $keys, $v ) use ( $full ) { $d = $full; foreach ( $keys as $k ) { if ( 'UNSET' === $v ) unset( $d[ $k ] ); else $d[ $k ] = $v; } return $d; };

$groups = array(
    'blood pressure'     => array( array( 'bpSystolic', 'bpDiastolic' ), array( 'bloodPressureScore' ) ),
    'heart rate'         => array( array( 'restingHeartRate' ), array( 'heartRateScore' ) ),
    'waist with hip'     => array( array( 'waist', 'hip' ), array( 'whrScore', 'whtrScore' ) ),
    'height with weight' => array( array( 'height', 'weight' ), array( 'bmiScore', 'whtrScore' ) ),
);
$empty_rate = array();
foreach ( $groups as $name => list( $keys, $score_keys ) ) {
    $results = array();
    foreach ( $EMPTY as $label => $v ) $results[ $label ] = $run( $with( $keys, $v ) );
    $no_score = true;
    foreach ( $results as $r ) foreach ( $score_keys as $sk ) $no_score = $no_score && null === $r['scores'][ $sk ];
    check( "C1 $name left empty (unset, '', '0', 0, null): no score for " . implode( ', ', $score_keys ), $no_score );
    $rates = array_unique( array_map( function ( $r ) { return $r['rate'] . '|' . $r['bio_age']; }, $results ) );
    check( "C2 $name left empty: the same rate and biological age every way (" . reset( $rates ) . ')', 1 === count( $rates ) );
    $empty_rate[ $name ] = $results['unset'];
}

$given = $run( $full );
check( 'C3 a real reading of 118 over 76 gets a score', is_numeric( $given['scores']['bloodPressureScore'] ) );
$poor = $run( array_merge( $full, array( 'bpSystolic' => '170', 'bpDiastolic' => '105' ) ) );
check( 'C4 good reading < left empty < poor reading: empty is neither a good nor a bad reading ('
    . $given['rate'] . ' < ' . $empty_rate['blood pressure']['rate'] . ' < ' . $poor['rate'] . ')',
    $given['rate'] < $empty_rate['blood pressure']['rate'] && $empty_rate['blood pressure']['rate'] < $poor['rate'] );
check( 'C5 an answer that scores 0 stays a score (not empty)', 0 === $run( array_merge( $full, array( 'smokingStatus' => '0' ) ) )['scores']['smokingStatus'] );

// sha256 of includes/sprint-2/class-hdlv2-rate-calculator.php at 8b64a25 (0.48.77, the base of this build).
check( 'C6 the calculator file is the base file, byte for byte', '23a1acbfbeea42f043db76c08aa5231c3aec577b2a3cd6a532b9faf45845cde6' === hash_file( 'sha256', $file ) );
echo "\n" . ( $fail ? "SCENARIO: FAIL ($fail)\n" : "SCENARIO: PASS ($pass)\n" );
exit( $fail ? 1 : 0 );

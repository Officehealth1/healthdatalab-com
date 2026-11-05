<?php
/**
 * v0.48.50 (W29) — the who-for rows, server side: HDLV2_Why_Picks cleans them (draft), validates
 * them (submit), composes the text every old reader keeps using, and hands the timeline its list.
 * The real save path is in tests/single-form/scenario-single-form-flow.php, section P.
 *
 * Run:  php scenario-who-for.php   (self-asserting, exit 0/1)
 */
error_reporting( E_ALL & ~E_DEPRECATED );
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['options'] = array();
function get_option( $k, $default = false ) { return array_key_exists( $k, $GLOBALS['options'] ) ? $GLOBALS['options'][ $k ] : $default; }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_text_field( $s ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $s ) ) ); }
class WP_Error {
    public $code; public $message; public $data;
    public function __construct( $code = '', $message = '', $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
require __DIR__ . '/../../includes/sprint-2/class-hdlv2-why-picks.php';

$pass = 0; $fail = 0;
function check( $label, $ok ) { global $pass, $fail; echo ( $ok ? 'PASS' : 'FAIL' ) . "  $label\n"; $ok ? $pass++ : $fail++; }

$P    = 'HDLV2_Why_Picks';
$FIVE = array( 'floor_grandkids', 'stairs_no_rail', 'wake_rested', 'keep_driving', 'swim_in_sea' );
$ROWS = array( array( 'name' => 'Jenny', 'rel' => 'child' ), array( 'name' => 'Leo', 'rel' => 'friend' ) );

check( 'P1 the seven roles are the timeline\'s', defined( "$P::PEOPLE_RELS" ) && array( 'spouse', 'child', 'grandchild', 'parent', 'sibling', 'friend', 'other' ) === $P::PEOPLE_RELS );
if ( ! method_exists( $P, 'clean_people' ) ) { check( 'P2 clean_people / people_text exist', false ); echo "\nSCENARIO: FAIL\n"; exit( 1 ); }

// Draft: lenient. Names plain, cut at 30, blanks dropped, a role outside the seven reads '', six at most.
$c = $P::clean_people( array( array( 'name' => ' <b>Jenny</b> ', 'rel' => 'child' ), array( 'name' => '', 'rel' => 'friend' ),
    array( 'name' => str_repeat( 'x', 40 ), 'rel' => 'boss' ), 'junk', array( 'name' => array( 'x' ) ) ) );
check( 'P2 clean_people: plain, trimmed, cut at 30, blank and odd rows dropped, an unknown role reads \'\'',
    array( array( 'name' => 'Jenny', 'rel' => 'child' ), array( 'name' => str_repeat( 'x', 30 ), 'rel' => '' ) ) === $c );
check( 'P3 clean_people keeps six at most', 6 === count( $P::clean_people( array_fill( 0, 9, array( 'name' => 'A', 'rel' => 'friend' ) ) ) ) );
check( 'P4 clean_people of nothing / not a list: []', array() === $P::clean_people( null ) && array() === $P::clean_people( 'Jenny' ) );

// Text every old reader keeps (vision text, the WHY prompt, the PDF, Claude's key_people extraction).
check( 'P5 people_text: "Jenny (child), Leo (friend)"', 'Jenny (child), Leo (friend)' === $P::people_text( $ROWS ) );
check( 'P6 people_text: no role, the name alone', 'Mia' === $P::people_text( array( array( 'name' => 'Mia', 'rel' => '' ) ) ) );

// Submit: strict.
$ok = array( 'why_picks' => $FIVE, 'key_people' => $ROWS, 'key_people_text' => 'Jenny (child), Leo (friend)', 'own_words' => '' );
check( 'V1 good rows validate', true === $P::validate( $ok ) );
$bad = array(
    'seven rows'      => array_fill( 0, 7, array( 'name' => 'A', 'rel' => 'friend' ) ),
    'no role'         => array( array( 'name' => 'Jenny', 'rel' => '' ) ),
    'unknown role'    => array( array( 'name' => 'Jenny', 'rel' => 'boss' ) ),
    'name too long'   => array( array( 'name' => str_repeat( 'x', 31 ), 'rel' => 'child' ) ),
    'blank name'      => array( array( 'name' => '  ', 'rel' => 'child' ) ),
    'not a row'       => array( 'Jenny' ),
    'not a list'      => 'Jenny (child)',
);
foreach ( $bad as $label => $rows ) {
    $r = $P::validate( array_merge( $ok, array( 'key_people' => $rows ) ) );
    check( "V2 $label: 400 \"Please check the names\"", is_wp_error( $r ) && 0 === strpos( $r->get_error_message(), 'Please check the names' ) && 400 === ( $r->data['status'] ?? 0 ) );
}
check( 'V3 an old row with only key_people_text still validates', true === $P::validate( array( 'why_picks' => $FIVE, 'key_people_text' => 'Jenny, Leo', 'own_words' => '' ) ) );
check( 'V4 the vision text keeps its shape', 0 === strpos( $P::compose_vision_text( $FIVE, 'Jenny (child), Leo (friend)', '' ), "In my later years, I want to:\n- " )
    && false !== strpos( $P::compose_vision_text( $FIVE, 'Jenny (child), Leo (friend)', '' ), "\nThe people I most want to stay healthy and strong for: Jenny (child), Leo (friend)" ) );

// The timeline's list, from the saved stage 2 data (JSON text or array).
check( 'T1 people_for_timeline from stage2_data JSON', $ROWS === $P::people_for_timeline( json_encode( array( 'key_people' => $ROWS, 'key_people_text' => 'x' ) ) ) );
check( 'T2 people_for_timeline: an old row or nothing gives []', array() === $P::people_for_timeline( '{"key_people_text":"Jenny"}' ) && array() === $P::people_for_timeline( '' ) && array() === $P::people_for_timeline( null ) );

// Source: the timeline page carries it in full mode only.
$tl = file_get_contents( __DIR__ . '/../../includes/sprint-2c/class-hdlv2-healthspan-timeline.php' );
check( 'T3 the timeline reads stage2_data and sends key_people in full mode', false !== strpos( $tl, 'stage1_data, stage2_data, stage3_data' )
    && 1 === preg_match( "/if \\( \\\$report && class_exists\\( 'HDLV2_Why_Picks' \\) \\) \\{\\s*\\\$people = HDLV2_Why_Picks::people_for_timeline\\( \\\$row->stage2_data \\);/", $tl ) );

echo "\n" . ( $fail ? "SCENARIO: FAIL ($fail)\n" : "SCENARIO: PASS ($pass)\n" );
exit( $fail ? 1 : 0 );

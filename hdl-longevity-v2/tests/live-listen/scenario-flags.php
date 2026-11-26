<?php
/**
 * Plan 15 T1: the two switches. "Listen" exists only with hdlv2_ff_live_listen AND hdlv2_ff_live_questions on.
 *   F.1 listen absent, questions on: no live_listen setting, the two new routes answer 404, no new script.
 *   F.2 listen on, questions off: the same.
 *   F.3 both on: the setting (with the piece address), both routes, the script after the audio component.
 *   F.4 the consultation class takes the setting and the script handle from the listen class (one place).
 * Run: php tests/live-listen/scenario-flags.php   Exit: 0 all pass, 1 any fail
 */
require __DIR__ . '/ll-world.php';
$have = class_exists( 'HDLV2_Live_Listen' );
check( 'the class HDLV2_Live_Listen exists', $have );
if ( ! $have ) done();
const R_PIECE = '/hdl-v2/v1/audio/listen-piece';
const R_AUTO  = '/hdl-v2/v1/consultation/live-questions/auto';
ha_world();

function off_state( $label ) {
    ll_boot();
    $GLOBALS['enqueued'] = array();
    HDLV2_Live_Listen::enqueue();
    check( "$label: no setting", array() === HDLV2_Live_Listen::config( array() ) );
    check( "$label: the two routes answer 404", 404 === ll_call( R_PIECE, 3 )[0] && 404 === ll_call( R_AUTO, 3 )[0] );
    check( "$label: the script is not enqueued", ! isset( $GLOBALS['enqueued']['hdlv2-live-listen'] ) );
}
echo "── F.1 listen absent, questions on ──\n";
lq_switch( '1' ); ll_switch( null ); off_state( 'F.1' );
echo "── F.2 listen on, questions off ──\n";
lq_switch( null ); ll_switch( '1' ); off_state( 'F.2' );
check( 'F.2 the manual questions route is absent too', 404 === ll_call( '/hdl-v2/v1/consultation/live-questions', 3 )[0] );

echo "── F.3 both on ──\n";
lq_switch( '1' ); ll_switch( '1' ); ll_boot();
$GLOBALS['enqueued'] = array();
$cfg  = HDLV2_Live_Listen::config( array( 'a' => 1 ) );
HDLV2_Live_Listen::enqueue();
check( 'F.3a the setting says live_listen and carries the piece address', 1 === ( $cfg['live_listen'] ?? 0 ) && 1 === $cfg['a'] && false !== strpos( (string) ( $cfg['listen_url'] ?? '' ), 'hdl-v2/v1/audio/listen-piece' ) );
check( 'F.3b both routes exist, practitioner-only', isset( $GLOBALS['routes'][ R_PIECE ], $GLOBALS['routes'][ R_AUTO ] ) && 401 === ll_call( R_PIECE, 0 )[0] && 403 === ll_call( R_PIECE, 9 )[0] && 403 === ll_call( R_AUTO, 9 )[0] );
check( 'F.3c the script is enqueued after the audio component', in_array( 'hdlv2-audio-component', $GLOBALS['enqueued']['hdlv2-live-listen'] ?? array(), true ) );

echo "── F.4 one place ──\n";
$src = (string) file_get_contents( ha_root() . '/includes/sprint-2c/class-hdlv2-consultation.php' );
check( 'F.4 the screen takes HDLV2_Live_Listen::config() and HDLV2_Live_Listen::enqueue(); its own dependencies are as before', false !== strpos( $src, 'HDLV2_Live_Listen::config( array() )' ) && false !== strpos( $src, 'HDLV2_Live_Listen::enqueue();' )
    && false !== strpos( $src, "'assets/js/hdlv2-consultation.js',\n            array( 'hdlv2-speedometer', 'hdlv2-audio-component', 'hdlv2-ui-modal', 'hdlv2-draft-report', 'hdlv2-loading' )," ) );
done();

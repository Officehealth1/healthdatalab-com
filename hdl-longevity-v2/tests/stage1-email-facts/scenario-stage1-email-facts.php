<?php
/**
 * v0.48.50 — the three facts the Stage 1 email needs (W10, W15): the band, the
 * band's video and the code's end date, on the Make payload (33 → 36 keys).
 *   - band: green / blue / red on the widget's word edges (rate <= 0.95 green,
 *     <= 1.05 blue, else red), one PHP copy pinned equal to the widget's.
 *   - band_video_url: hdlv2_stage1_band_videos[band], or '' (option absent or no URL for that band).
 *     v0.48.51: only for the offer's practitioner (HDLV2_Offer::applies_to); every other practitioner
 *     gets '' in the payload and {} in both public builders, so no partner page loads a video.
 *   - offer_code_ends: '' (v0.48.73, flat $27: no code, so no end date; the key stays for Make's contract).
 *   - no lead asks Altituding for a code (v0.48.73), even with the old wp-config constants still set.
 *
 * Run:  php scenario-stage1-email-facts.php   (self-asserting, exit 0/1)
 */
error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );
require __DIR__ . '/../full-report/stubs.php';
load_sources();

$log = tempnam( sys_get_temp_dir(), 'hdlv2-log' );
ini_set( 'error_log', $log );
$GLOBALS['options'] = array( 'hdlv2_db_version' => '3.30' );
$GLOBALS['options']['hdlv2_stage1_offer'] = array( 'practitioner_id' => 206, 'code' => 'SHAREDCODE', 'url' => 'https://alt.example.test/full-report' );
const CODE_URL = 'https://alt.example.test/.netlify/functions/full-report-code';

/** One Stage 1 dispatch at this rate (the invite path); returns [ make payload, the code call ]. */
function fire_at( $rate, $email = 'ivy@example.test', $offer_show = true, $prac = 206 ) {
    $wpdb = fresh_wpdb( 'open' );
    $wpdb->config->practitioner_user_id = $prac;
    $GLOBALS['captured_posts'] = array();
    $m = new ReflectionMethod( 'HDLV2_Widget_Config', 'dispatch_post_signup_artifacts' );
    $m->setAccessible( true );
    $m->invoke( null, array( 'practitioner_id' => $prac, 'visitor_name' => 'Ivy Lane', 'visitor_email' => $email, 'rate' => $rate,
        'stage1_data' => lead_params( $email ), 'form_token' => str_repeat( 'c', 64 ), 'config' => $wpdb->config,
        'invite_id' => 77, 'send_practitioner_notify' => false, 'send_make_pdf' => true, 'offer_show' => $offer_show ) );
    $make = $call = null;
    foreach ( $GLOBALS['captured_posts'] as $c ) {
        if ( false !== strpos( $c['url'], 'hook.example.test/stage1' ) ) $make = json_decode( $c['args']['body'], true );
        if ( CODE_URL === $c['url'] ) $call = $c;
    }
    return array( $make, $call );
}

echo "── E. the band, one edge, equal to the widget's ──\n";
$widget = file_get_contents( __DIR__ . '/../../widget/hdl-lead-magnet.js' );
check( 'E.1 the widget words still switch at rate <= 0.95 and rate <= 1.05', false !== strpos( $widget, 'if (rate <= 0.95) {' ) && false !== strpos( $widget, '} else if (rate <= 1.05) {' ) );
check( 'E.2 band_key exists', method_exists( 'HDLV2_Stage1_Commentary', 'band_key' ) );
if ( method_exists( 'HDLV2_Stage1_Commentary', 'band_key' ) ) {
    $cases = array( '0.80' => 'green', '0.90' => 'green', '0.95' => 'green', '0.951' => 'blue', '1.00' => 'blue', '1.05' => 'blue', '1.051' => 'red', '1.10' => 'red', '1.40' => 'red' );
    $all = true;
    foreach ( $cases as $r => $want ) { $all = $all && $want === HDLV2_Stage1_Commentary::band_key( (float) $r ); }
    check( 'E.3 band_key on both sides of both edges equals the widget\'s comparison', $all );
    // Review 2026-11-05: no rate (null, 0, '') is no band, never green.
    check( 'E.4 no rate: no band', '' === HDLV2_Stage1_Commentary::band_key( null ) && '' === HDLV2_Stage1_Commentary::band_key( 0 ) && '' === HDLV2_Stage1_Commentary::band_key( '' ) );
}

echo "── F. the payload: 36 keys, constants absent ──\n";
list( $make, $call ) = fire_at( 0.90 );
check( 'F.1 36 keys', is_array( $make ) && 36 === count( $make ) );
check( 'F.2 band green at 0.90', 'green' === ( $make['band'] ?? null ) );
check( 'F.3 band_video_url empty with the option absent', '' === ( $make['band_video_url'] ?? null ) );
check( 'F.4 no code, no end date, no list price; an old saved code never rides', '' === ( $make['offer_code_ends'] ?? null ) && '' === $make['offer_code'] && '' === $make['offer_list'] );
check( 'F.4b offer_url: the offer url, no code, the e-mail in its fragment', 'https://alt.example.test/full-report#email=ivy%40example.test' === $make['offer_url'] );
check( 'F.5 the three keys are strings', is_array( $make ) && is_string( $make['band'] ?? null ) && is_string( $make['band_video_url'] ?? null ) && is_string( $make['offer_code_ends'] ?? null ) );

define( 'HDLV2_FULL_CODE_URL', CODE_URL );
$stub_access = 'stub-full-code-access';
define( 'HDLV2_FULL_CODE_KEY', $stub_access );

echo "── G. no code request, even with the constants set and Altituding answering ──\n";
$GLOBALS['http'][ CODE_URL ] = array( 'response' => array( 'code' => 200 ), 'body' => '{"code":"ABC123","expires_at":' . ( time() + 15 * 86400 ) . '}' );
list( $make, $call ) = fire_at( 1.00 );
check( 'G.1 no call to the code function', null === $call );
check( 'G.2 offer_code and offer_code_ends stay empty', is_array( $make ) && '' === $make['offer_code'] && '' === $make['offer_code_ends'] );
check( 'G.3 band blue at 1.00', 'blue' === ( $make['band'] ?? null ) );
check( 'G.4 still 36 keys', is_array( $make ) && 36 === count( $make ) );
list( $make ) = fire_at( 1.00, 'not an email' );
check( 'G.5 an invalid email: the plain offer url', is_array( $make ) && 'https://alt.example.test/full-report' === $make['offer_url'] );

echo "── H. the band's video ──\n";
$GLOBALS['options']['hdlv2_stage1_band_videos'] = array( 'green' => 'https://youtu.be/K2rQSjwvTws', 'red' => 'https://www.youtube.com/watch?v=abcdefghijk' );
list( $g ) = fire_at( 0.90 );
list( $b ) = fire_at( 1.00 );
list( $r ) = fire_at( 1.10 );
check( 'H.1 green gets the green URL', 'green' === $g['band'] && 'https://youtu.be/K2rQSjwvTws' === $g['band_video_url'] );
check( 'H.2 blue has none: empty', 'blue' === $b['band'] && '' === $b['band_video_url'] );
check( 'H.3 red at 1.10 gets the red URL', 'red' === $r['band'] && 'https://www.youtube.com/watch?v=abcdefghijk' === $r['band_video_url'] );
$GLOBALS['options']['hdlv2_stage1_band_videos'] = '{"blue":"https://youtu.be/K2rQSjwvTws","junk":"https://x.test"}';
check( 'H.4 the option as JSON text reads too; unknown bands dropped', array( 'blue' => 'https://youtu.be/K2rQSjwvTws' ) === HDLV2_Stage1_Commentary::band_videos( 206 ) );
$GLOBALS['options']['hdlv2_stage1_band_videos'] = array( 'green' => 'javascript:alert(1)', 'blue' => '' );
check( 'H.5 a non-http URL or an empty one is dropped', array() === HDLV2_Stage1_Commentary::band_videos( 206 ) );
unset( $GLOBALS['options']['hdlv2_stage1_band_videos'] );
check( 'H.6 absent option: an empty map', array() === HDLV2_Stage1_Commentary::band_videos( 206 ) );

echo "── I. no offer: the facts still ride, no code call ──\n";
list( $make, $call ) = fire_at( 1.10, 'ivy@example.test', false );
check( 'I.1 offer_show "0": 36 keys, band red, offer_code_ends \'\', no code call', is_array( $make ) && 36 === count( $make ) && 'red' === $make['band'] && '' === $make['offer_code_ends'] && null === $call );

echo "── J. the public config carries the map for the widget (the offer's practitioner, 206 here) ──\n";
$GLOBALS['options']['hdlv2_stage1_band_videos'] = array( 'green' => 'https://youtu.be/K2rQSjwvTws' );
/** band_videos as each public builder sends it, JSON-encoded (an object either way). */
function public_map( $prac ) {
    $wpdb = fresh_wpdb( 'open' );
    $wpdb->config->practitioner_user_id = $prac;
    $r = widget_instance()->rest_get_public_config( new FakeRequest( array( 'practitioner_id' => $prac ) ) )->get_data();
    return json_encode( $r['config']['band_videos'] ?? 'missing' );
}
function invite_map( $prac ) {
    $wpdb = fresh_wpdb( 'open' );
    $wpdb->config->practitioner_user_id = $prac;
    $wpdb->invite = invite_row( array( 'practitioner_id' => $prac, 'source' => 'practitioner' ) );
    $link = $wpdb->invite->token;
    $r = widget_instance()->rest_verify_invite( new FakeRequest( array( 'token' => $link ) ) );
    return json_encode( is_array( $r ) ? ( $r['band_videos'] ?? 'missing' ) : 'error' );
}
check( 'J.1 public-config sends the map as an object', '{"green":"https:\\/\\/youtu.be\\/K2rQSjwvTws"}' === public_map( 206 ) );
check( 'J.2 the invite check sends the map as an object', '{"green":"https:\\/\\/youtu.be\\/K2rQSjwvTws"}' === invite_map( 206 ) );
unset( $GLOBALS['options']['hdlv2_stage1_band_videos'] );
check( 'J.3 no option: both send {}', '{}' === public_map( 206 ) && '{}' === invite_map( 206 ) );

echo "── L. only the offer's practitioner gets the videos (v0.48.51, the offer's gate) ──\n";
$GLOBALS['options']['hdlv2_stage1_offer']['practitioner_id'] = 122;
$GLOBALS['options']['hdlv2_stage1_band_videos'] = array( 'green' => 'https://youtu.be/K2rQSjwvTws', 'blue' => 'https://youtu.be/K2rQSjwvTws', 'red' => 'https://youtu.be/K2rQSjwvTws' );
list( $own ) = fire_at( 0.90, 'ivy@example.test', true, 122 );
list( $other ) = fire_at( 0.90, 'ivy@example.test', true, 117 );
check( 'L.1 practitioner 122 (the offer\'s): the payload carries the green video', is_array( $own ) && 'https://youtu.be/K2rQSjwvTws' === $own['band_video_url'] );
check( 'L.2 practitioner 117: band still green, band_video_url \'\', still 36 keys', is_array( $other ) && 'green' === $other['band'] && '' === $other['band_video_url'] && 36 === count( $other ) );
check( 'L.3 public-config: 122 gets the map, 117 gets {}', false !== strpos( public_map( 122 ), 'K2rQSjwvTws' ) && '{}' === public_map( 117 ) );
check( 'L.4 the invite check: 122 gets the map, 117 gets {}', false !== strpos( invite_map( 122 ), 'K2rQSjwvTws' ) && '{}' === invite_map( 117 ) );
check( 'L.5 band_videos( 117 ) is empty, band_videos( 122 ) has the three', array() === HDLV2_Stage1_Commentary::band_videos( 117 ) && 3 === count( HDLV2_Stage1_Commentary::band_videos( 122 ) ) );
$GLOBALS['options']['hdlv2_stage1_offer']['practitioner_id'] = 0;
check( 'L.6 the offer set to every practitioner (0): 117 gets the map too', 3 === count( HDLV2_Stage1_Commentary::band_videos( 117 ) ) && false !== strpos( public_map( 117 ), 'K2rQSjwvTws' ) );
$GLOBALS['options']['hdlv2_stage1_offer']['practitioner_id'] = 206;
unset( $GLOBALS['options']['hdlv2_stage1_band_videos'] );

check( 'K.1 the key never reaches a log line', false === strpos( file_get_contents( $log ), $stub_access ) );
@unlink( $log );
done();

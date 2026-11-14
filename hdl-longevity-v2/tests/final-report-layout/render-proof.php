<?php
/**
 * Final report layout (plan 2026-11-14): renders ONE document from the PROOF copy of
 * the Final template and saves it in /tmp/frl/. The key is read in place and never
 * printed; the signed download link is never printed.
 *
 *   wp eval-file - payload <proof-id> <file.json> <out.pdf>
 *       payload file in /tmp/frl/; the chart and flight-plan addresses are filled here
 *       from the site this runs on, so the payload files hold none of ours
 *   wp eval-file - doc <proof-id> <document-id> <out.pdf>
 *       re-renders that PDFMonkey document's own payload and saves it beside the PDF
 *       as <out>.json, so check 5 can run; the payload travels only with its PDF
 *       (/tmp/frl, then the git-ignored _local/ folder), never into the repository
 *   wp eval-file - cleanup <proof-id>
 *       deletes the documents made from the proof copy: a card of any other template
 *       is skipped, and nothing is deleted when more than 15 are listed
 *   php render-proof.php selftest
 *       checks that rule on made-up cards; needs no WordPress and no key
 *
 * Any template whose name does not start with "PROOF — " is refused. A re-rendered
 * client PDF is personal data: /tmp/frl is kept 0700 and every file in it 0600.
 * Remove /tmp/frl when the run is done.
 */
// What cleanup may delete. The account is the one LIVE prints from, so the server's
// filter is not trusted alone: only cards that name the proof template, and nothing
// at all when the list is longer than a proof run can make.
$cleanup_targets = function ( array $cards, $tid ) {
    if ( count( $cards ) > 15 ) return null;
    $ids = array();
    foreach ( $cards as $d ) if ( ( $d['document_template_id'] ?? '' ) === $tid ) $ids[] = $d['id'];
    return $ids;
};
if ( 'selftest' === ( $args[0] ?? ( $argv[1] ?? '' ) ) ) {
    $proof = 'aaaaaaaa-0000-0000-0000-000000000000';
    $card  = function ( $id, $tid ) { return array( 'id' => $id, 'document_template_id' => $tid ); };
    $mixed = array( $card( 'p1', $proof ), $card( 'x1', 'bbbbbbbb-0000-0000-0000-000000000000' ), array( 'id' => 'x2' ), $card( 'p2', $proof ) );
    $ok    = array( 'p1', 'p2' ) === $cleanup_targets( $mixed, $proof )
        && null === $cleanup_targets( array_fill( 0, 16, $card( 'p', $proof ) ), $proof )
        && 15 === count( $cleanup_targets( array_fill( 0, 15, $card( 'p', $proof ) ), $proof ) );
    echo $ok ? "cleanup selftest: ok (other templates skipped; 16 listed deletes nothing)\n" : "cleanup selftest: FAILED\n";
    return;
}
umask( 077 );
if ( is_link( '/tmp/frl' ) || ( file_exists( '/tmp/frl' ) && fileowner( '/tmp/frl' ) !== posix_geteuid() ) ) { echo "REFUSED: /tmp/frl is a link or not ours\n"; return; }
wp_mkdir_p( '/tmp/frl' );
chmod( '/tmp/frl', 0700 );
$pdfmonkey_key = function () {
    return defined( 'HDLV2_PDFMONKEY_API_KEY' ) ? (string) HDLV2_PDFMONKEY_API_KEY : '';
};
$key = $pdfmonkey_key();
if ( '' === $key ) { echo "no key\n"; return; }
$api  = 'https://api.pdfmonkey.io/api/v1/';
$call = function ( $method, $path, $body = null ) use ( $key, $api ) {
    $r = wp_remote_request( $api . $path, array( 'method' => $method, 'timeout' => 60, 'headers' => array( 'Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json' ), 'body' => $body ? wp_json_encode( $body ) : null ) );
    return array( wp_remote_retrieve_response_code( $r ), json_decode( wp_remote_retrieve_body( $r ), true ) );
};
list( $mode, $tid ) = array( $args[0] ?? '', (string) ( $args[1] ?? '' ) );
if ( ! preg_match( '/^[0-9a-f-]{36}$/', $tid ) ) { echo "REFUSED: id\n"; return; }
list( $c, $t ) = $call( 'GET', "document_templates/$tid" );
if ( 200 !== $c || 0 !== strpos( $t['document_template']['identifier'] ?? '', 'PROOF — ' ) ) { echo "REFUSED: not a PROOF template\n"; return; }

if ( 'cleanup' === $mode ) {
    $cards = array();
    for ( $page = 1; $page <= 100 && count( $cards ) <= 15; $page++ ) {
        list( , $l ) = $call( 'GET', "document_cards?q[document_template_id]=$tid&page=$page" );
        $cards = array_merge( $cards, $l['document_cards'] ?? array() );
        if ( empty( $l['meta']['next_page'] ) ) break;
    }
    $ids = $cleanup_targets( $cards, $tid );
    if ( null === $ids ) { printf( "REFUSED: %d or more documents listed, more than 15: nothing deleted\n", count( $cards ) ); return; }
    if ( count( $ids ) < count( $cards ) ) printf( "skipped %d card(s) of another template\n", count( $cards ) - count( $ids ) );
    $gone = 0;
    foreach ( $ids as $id ) { list( $c ) = $call( 'DELETE', "documents/$id" ); $gone += 204 === $c; printf( "delete %s %d\n", substr( $id, 0, 8 ), $c ); }
    list( , $left ) = $call( 'GET', "document_cards?q[document_template_id]=$tid" );
    printf( "deleted %d of %d, %d left\n", $gone, count( $ids ), count( $left['document_cards'] ?? array() ) );
    return;
}

$out = basename( (string) ( $args[3] ?? '' ) );
if ( ! preg_match( '/^[a-z0-9-]+\.pdf$/', $out ) ) { echo "REFUSED: out name\n"; return; }

if ( 'payload' === $mode ) {
    $p = json_decode( (string) file_get_contents( '/tmp/frl/' . basename( (string) $args[2] ) ), true );
    if ( ! is_array( $p ) ) { echo "payload unreadable\n"; return; }
    $score_keys = array( 'score_activity' => 'physicalActivity', 'score_sit_stand' => 'sitToStand', 'score_breath' => 'breathHold', 'score_balance' => 'balance',
        'score_sleep_dur' => 'sleepDuration', 'score_sleep_qual' => 'sleepQuality', 'score_stress' => 'stressLevels', 'score_social' => 'socialConnections',
        'score_diet' => 'dietQuality', 'score_alcohol' => 'alcoholConsumption', 'score_smoking' => 'smokingStatus', 'score_cognitive' => 'cognitiveActivity',
        'score_sunlight' => 'sunlightExposure', 'score_supplements' => 'supplementIntake', 'score_hydration' => 'dailyHydration', 'score_bmi' => 'bmiScore',
        'score_whr' => 'whrScore', 'score_whtr' => 'whtrScore', 'score_bp' => 'bloodPressureScore', 'score_hr' => 'heartRateScore' );
    $scores = array();
    foreach ( $score_keys as $k => $s ) if ( isset( $p[ $k ] ) && '' !== $p[ $k ] ) $scores[ $s ] = (float) $p[ $k ];
    $p['gauge_url']            = HDLV2_Final_Report::gauge_url( (float) $p['rate_of_ageing'] );
    $p['radar_chart_url']      = HDLV2_Final_Report::build_radar_chart_url( $scores );
    $p['trajectory_chart_url'] = HDLV2_Trajectory_SVG::url_for( (int) $p['chronological_age'], (float) $p['rate_of_ageing'] );
    $p['flight_plan_url']      = home_url( '/my-flight-plan/' );
    if ( ! empty( $p['_why_chart'] ) ) {
        $p['why_chart_url'] = HDLV2_Why_Picks::chart_url( $p['_why_chart'] + array( 'labels' => array_values( HDLV2_Why_Picks::ABILITY_SHORT ), 'scale' => HDLV2_Why_Picks::MAX_NEED ) );
    }
    unset( $p['_why_chart'] );
} elseif ( 'doc' === $mode ) {
    $doc_id = (string) ( $args[2] ?? '' );
    if ( ! preg_match( '/^[0-9a-f-]{36}$/', $doc_id ) ) { echo "REFUSED: document id\n"; return; }
    list( $c, $d ) = $call( 'GET', "documents/$doc_id" );
    $p = $d['document']['payload'] ?? null;
    if ( is_string( $p ) ) $p = json_decode( $p, true );
    if ( 200 !== $c || ! is_array( $p ) ) { echo "document read $c: not available\n"; return; }
    file_put_contents( '/tmp/frl/' . basename( $out, '.pdf' ) . '.json', wp_json_encode( $p ) );
} else {
    echo "usage: payload|doc|cleanup <proof-id> ...\n";
    return;
}

list( $c, $d ) = $call( 'POST', 'documents', array( 'document' => array( 'document_template_id' => $tid, 'status' => 'pending', 'payload' => $p, 'meta' => wp_json_encode( array( '_filename' => 'frl-' . $out ) ) ) ) );
$id = $d['document']['id'] ?? '';
if ( ! $id ) { echo "create $c failed\n"; return; }
$status = ''; $card = array();
for ( $i = 0; $i < 60; $i++ ) {
    sleep( 2 );
    list( , $card ) = $call( 'GET', "document_cards/$id" );
    $status = $card['document_card']['status'] ?? '';
    if ( in_array( $status, array( 'success', 'failure' ), true ) ) break;
}
printf( "document %s status %s\n", substr( $id, 0, 8 ), $status );
if ( 'success' !== $status ) { echo 'cause: ' . ( $card['document_card']['failure_cause'] ?? '?' ) . "\n"; return; }
$r   = wp_remote_get( $card['document_card']['download_url'], array( 'timeout' => 60 ) );
$pdf = wp_remote_retrieve_body( $r );
if ( 200 !== wp_remote_retrieve_response_code( $r ) || 0 !== strpos( $pdf, '%PDF' ) ) { echo "download failed\n"; return; }
file_put_contents( '/tmp/frl/' . $out, $pdf );
printf( "saved %s bytes %d\n", $out, strlen( $pdf ) );

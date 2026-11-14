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
 *       re-renders that PDFMonkey document's own payload; it never leaves the server
 *   wp eval-file - cleanup <proof-id>
 *       deletes every document made from the proof copy
 *
 * Any template whose name does not start with "PROOF — " is refused.
 */
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
    $ids = array();
    for ( $page = 1; $page <= 5; $page++ ) {
        list( , $l ) = $call( 'GET', "document_cards?q[document_template_id]=$tid&page=$page" );
        foreach ( $l['document_cards'] ?? array() as $d ) $ids[] = $d['id'];
        if ( empty( $l['meta']['next_page'] ) ) break;
    }
    foreach ( $ids as $id ) { list( $c ) = $call( 'DELETE', "documents/$id" ); printf( "delete %s %d\n", substr( $id, 0, 8 ), $c ); }
    printf( "deleted %d\n", count( $ids ) );
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
$pdf = wp_remote_retrieve_body( wp_remote_get( $card['document_card']['download_url'], array( 'timeout' => 60 ) ) );
file_put_contents( '/tmp/frl/' . $out, $pdf );
printf( "saved %s bytes %d\n", $out, strlen( $pdf ) );

<?php
/**
 * v0.48.14 (plan 2026-10-10 v2, Task 5): renders ONE document from the Stage 1
 * sales-page DUPLICATE with its own sample data, so the two pages can be seen
 * before the Make import. Any other template id is refused. The key is read in
 * place and never printed; the signed download link is never printed.
 * Writes /tmp/abl-pm/stage1-sales-page.pdf. Copy to /tmp, `wp eval-file`, delete.
 */
$key = defined( 'HDLV2_PDFMONKEY_API_KEY' ) ? HDLV2_PDFMONKEY_API_KEY : '';
$tid = '4b910161-b645-4fbb-ae3b-b8d7dbc5db59';
if ( '' === $key ) { echo "no key\n"; return; }
if ( '4b910161-b645-4fbb-ae3b-b8d7dbc5db59' !== $tid ) { echo "REFUSED\n"; return; }
$api  = 'https://api.pdfmonkey.io/api/v1/';
$call = function ( $method, $path, $body = null ) use ( $key, $api ) {
    $r = wp_remote_request( $api . $path, array( 'method' => $method, 'timeout' => 30, 'headers' => array( 'Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json' ), 'body' => $body ? wp_json_encode( $body ) : null ) );
    return array( wp_remote_retrieve_response_code( $r ), json_decode( wp_remote_retrieve_body( $r ), true ) );
};
list( $c, $t ) = $call( 'GET', "document_templates/$tid" );
$sample = $t['document_template']['sample_data'] ?? '';
$payload = is_string( $sample ) ? json_decode( $sample, true ) : $sample;
if ( 200 !== $c || ! is_array( $payload ) ) { echo "template read $c, sample unusable\n"; return; }
if ( 0 !== strpos( $t['document_template']['identifier'] ?? '', 'DUP — ' ) ) { echo "REFUSED: not a duplicate\n"; return; }
list( $c, $d ) = $call( 'POST', 'documents', array( 'document' => array( 'document_template_id' => $tid, 'status' => 'pending', 'payload' => $payload, 'meta' => array( '_filename' => 'stage1-sales-page-test.pdf' ) ) ) );
$id = $d['document']['id'] ?? '';
if ( ! $id ) { echo "create $c failed\n"; return; }
$status = '';
for ( $i = 0; $i < 30; $i++ ) {
    sleep( 2 );
    list( , $card ) = $call( 'GET', "document_cards/$id" );
    $status = $card['document_card']['status'] ?? '';
    if ( in_array( $status, array( 'success', 'failure' ), true ) ) break;
}
printf( "document %s status %s\n", $id, $status );
if ( 'success' !== $status ) return;
wp_mkdir_p( '/tmp/abl-pm' );
$r = wp_remote_get( $card['document_card']['download_url'], array( 'timeout' => 60 ) );
$pdf = wp_remote_retrieve_body( $r );
file_put_contents( '/tmp/abl-pm/stage1-sales-page.pdf', $pdf );
printf( "saved bytes %d\n", strlen( $pdf ) );

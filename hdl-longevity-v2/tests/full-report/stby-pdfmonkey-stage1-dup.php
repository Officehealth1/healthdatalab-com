<?php
/**
 * v0.48.15 (plan 2026-10-13, Task 4.3/4.4): writes the Full Report upsell
 * body + stylesheet to the Stage 1 DUPLICATE 4b910161 only (full-document
 * PUT), reads it back, and reports — read only — whether the three other
 * duplicates' footers carry the same "Powered by" overline. Any other id is
 * refused; the key is read in place and never printed.
 * Inputs: /tmp/fr-pm/b08e10ad.html + .css. Copy to /tmp, `wp eval-file`, delete.
 */
$key  = defined( 'HDLV2_PDFMONKEY_API_KEY' ) ? HDLV2_PDFMONKEY_API_KEY : '';
$base = 'https://api.pdfmonkey.io/api/v1/document_templates/';
$id   = '4b910161-b645-4fbb-ae3b-b8d7dbc5db59';
if ( '' === $key ) { echo "no key\n"; return; }
$call = function ( $method, $url, $body = null ) use ( $key ) {
    $r = wp_remote_request( $url, array( 'method' => $method, 'timeout' => 30, 'headers' => array( 'Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json' ), 'body' => $body ? wp_json_encode( $body ) : null ) );
    return array( wp_remote_retrieve_response_code( $r ), json_decode( wp_remote_retrieve_body( $r ), true ) );
};
$html = (string) @file_get_contents( '/tmp/fr-pm/b08e10ad.html' );
$css  = (string) @file_get_contents( '/tmp/fr-pm/b08e10ad.css' );
if ( strlen( $html ) < 1000 || strlen( $css ) < 1000 ) { echo "inputs missing\n"; return; }
list( $c, $cur ) = $call( 'GET', $base . $id );
$t = $cur['document_template'] ?? array();
if ( 200 !== $c || 0 !== strpos( $t['identifier'] ?? '', 'DUP — ' ) ) { echo "REFUSED: not the duplicate ($c)\n"; return; }
list( $c2 ) = $call( 'PUT', $base . $id, array( 'document_template' => array( 'body' => $html, 'scss_style' => $css ) ) );
list( , $back ) = $call( 'GET', $base . $id );
$b = $back['document_template'] ?? array();
printf( "4b910161 PUT %d readback body=%s css=%s\n", $c2, ( $b['body'] ?? '' ) === $html ? 'equal' : 'DIFF', ( $b['scss_style'] ?? '' ) === $css ? 'equal' : 'DIFF' );
foreach ( array( '8d4de60c-57da-4a42-a3ff-6f6b1130e396', '57764484-67c6-4232-9e2f-2cfcd241ba64', 'a091f83e-1e92-40f5-aaa6-62f2bb4bb745' ) as $other ) {
    list( , $o ) = $call( 'GET', $base . $other );
    $body = $o['document_template']['body'] ?? '';
    printf( "%s footer-brand__overline=%d 'Powered by'=%d\n", substr( $other, 0, 8 ), substr_count( $body, 'footer-brand__overline' ), substr_count( $body, 'Powered by' ) );
}

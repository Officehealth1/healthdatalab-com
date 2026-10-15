<?php
/**
 * Writes the v0.48.12 candidate bodies to the PDFMonkey DUPLICATES only
 * (plan Task 7). Production ids are refused. The key is read in place and
 * never printed. Inputs in /tmp/abl-pm/: <id8>.html (+ <id8>.css) per duplicate.
 * PUT = body + scss_style in one call; POST (Stage 1) copies sample_data and
 * settings from production read-only. Every write is read back and compared.
 */
$key  = defined( 'HDLV2_PDFMONKEY_API_KEY' ) ? HDLV2_PDFMONKEY_API_KEY : '';
$base = 'https://api.pdfmonkey.io/api/v1/document_templates';
$PROD = array( 'b08e10ad', 'fdc6ba73', '4385d9b5', '1c9f06c5', '3dd65fe4', 'cd402fa9', 'b6541017', '6f3230f0' );
$call = function ( $method, $url, $body = null ) use ( $key ) {
    $r = wp_remote_request( $url, array( 'method' => $method, 'timeout' => 30, 'headers' => array( 'Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json' ), 'body' => $body ? wp_json_encode( $body ) : null ) );
    return array( wp_remote_retrieve_response_code( $r ), json_decode( wp_remote_retrieve_body( $r ), true ) );
};
$dir = '/tmp/abl-pm/';
// Existing duplicates: Stage 2 WHY, Draft, Final — body from candidate, stylesheet = the duplicate's own (unchanged).
$dups = array( '8d4de60c-57da-4a42-a3ff-6f6b1130e396' => 'fdc6ba73', '57764484-67c6-4232-9e2f-2cfcd241ba64' => '4385d9b5', 'a091f83e-1e92-40f5-aaa6-62f2bb4bb745' => '1c9f06c5' );
foreach ( $dups as $id => $src ) {
    if ( in_array( substr( $id, 0, 8 ), $PROD, true ) ) { echo "REFUSED $id\n"; continue; }
    list( $c, $cur ) = $call( 'GET', "$base/$id" );
    $t    = $cur['document_template'] ?? array();
    $body = file_get_contents( $dir . $src . '.html' );
    list( $c2 ) = $call( 'PUT', "$base/$id", array( 'document_template' => array( 'body' => $body, 'scss_style' => $t['scss_style'] ?? '' ) ) );
    list( , $back ) = $call( 'GET', "$base/$id" );
    $b = $back['document_template'] ?? array();
    printf( "%s PUT %d readback body=%s css=%s\n", substr( $id, 0, 8 ), $c2, ( $b['body'] ?? '' ) === $body ? 'equal' : 'DIFF', ( $b['scss_style'] ?? '' ) === ( $t['scss_style'] ?? '' ) ? 'unchanged' : 'DIFF' );
}
// New Stage 1 duplicate (once: an existing one with the same identifier is reused).
$ident = 'DUP — Stage 1 — sales page — 2026-10-09';
list( , $prod ) = $call( 'GET', "$base/b08e10ad-b7be-4010-b7b8-4dcdce217ddf" );
$p     = $prod['document_template'] ?? array();
$body  = file_get_contents( $dir . 'b08e10ad.html' );
$css   = file_get_contents( $dir . 'b08e10ad.css' );
$found = '';
for ( $page = 1; $page <= 5 && ! $found; $page++ ) {
    list( , $l ) = $call( 'GET', "$base?page=$page" );
    foreach ( $l['document_templates'] ?? array() as $t ) if ( ( $t['identifier'] ?? '' ) === $ident ) $found = $t['id'];
    if ( empty( $l['document_templates'] ) ) break;
}
$doc = array( 'body' => $body, 'scss_style' => $css );
if ( $found ) {
    list( $c ) = $call( 'PUT', "$base/$found", array( 'document_template' => $doc ) );
} else {
    list( $c, $new ) = $call( 'POST', $base, array( 'document_template' => $doc + array( 'identifier' => $ident, 'edition_mode' => $p['edition_mode'] ?? 'code',
        'sample_data' => $p['sample_data'] ?? '', 'settings' => $p['settings'] ?? null ) ) );
    $found = $new['document_template']['id'] ?? '';
}
list( , $back ) = $call( 'GET', "$base/$found" );
$b = $back['document_template'] ?? array();
printf( "stage1 dup %s write %d readback body=%s css=%s sample=%s\n", $found, $c, ( $b['body'] ?? '' ) === $body ? 'equal' : 'DIFF', ( $b['scss_style'] ?? '' ) === $css ? 'equal' : 'DIFF', ( $b['sample_data'] ?? '' ) === ( $p['sample_data'] ?? '' ) ? 'equal' : 'DIFF' );

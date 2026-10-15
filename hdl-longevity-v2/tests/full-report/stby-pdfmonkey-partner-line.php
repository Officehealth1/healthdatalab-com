<?php
// v0.48.15 Task 4.4 — partner line into the footer overline of two DUPLICATES only; body-only change, css kept; read back.
$key = defined( 'HDLV2_PDFMONKEY_API_KEY' ) ? HDLV2_PDFMONKEY_API_KEY : '';
$base = 'https://api.pdfmonkey.io/api/v1/document_templates/';
$call = function ( $m, $u, $b = null ) use ( $key ) { $r = wp_remote_request( $u, array( 'method' => $m, 'timeout' => 30, 'headers' => array( 'Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json' ), 'body' => $b ? wp_json_encode( $b ) : null ) ); return array( wp_remote_retrieve_response_code( $r ), json_decode( wp_remote_retrieve_body( $r ), true ) ); };
$old = '<span class="footer-brand__overline">Powered by</span>';
$new = '<span class="footer-brand__overline">Powered by HealthDataLab · a partnership focused on longevity</span>';
foreach ( array( '8d4de60c-57da-4a42-a3ff-6f6b1130e396', '57764484-67c6-4232-9e2f-2cfcd241ba64' ) as $id ) {
    list( , $cur ) = $call( 'GET', $base . $id );
    $t = $cur['document_template'] ?? array();
    if ( 0 !== strpos( $t['identifier'] ?? '', 'DUP — ' ) ) { echo "REFUSED $id\n"; continue; }
    $n = substr_count( $t['body'], $old ); $all = substr_count( $t['body'], 'footer-brand__overline' );
    if ( ! $n || $n !== $all ) { printf( "%s skip: exact=%d overline=%d\n", substr( $id, 0, 8 ), $n, $all ); continue; }
    $body = str_replace( $old, $new, $t['body'] );
    list( $c ) = $call( 'PUT', $base . $id, array( 'document_template' => array( 'body' => $body, 'scss_style' => $t['scss_style'] ) ) );
    list( , $b ) = $call( 'GET', $base . $id );
    printf( "%s PUT %d replaced=%d body=%s css=%s\n", substr( $id, 0, 8 ), $c, $n, ( $b['document_template']['body'] ?? '' ) === $body ? 'equal' : 'DIFF', ( $b['document_template']['scss_style'] ?? '' ) === $t['scss_style'] ? 'unchanged' : 'DIFF' );
}

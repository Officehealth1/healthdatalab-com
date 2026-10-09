<?php
/**
 * Read-only: every PDFMonkey template's id prefix, name and updated_at, so a
 * walk can prove production templates were not written. The key is read in
 * place on the server and never printed. Copy to /tmp, `wp eval-file`, delete.
 */
$key = defined( 'HDLV2_PDFMONKEY_API_KEY' ) ? HDLV2_PDFMONKEY_API_KEY : '';
if ( '' === $key ) { echo "no key\n"; return; }
for ( $page = 1; $page <= 5; $page++ ) {
    $r = wp_remote_get( 'https://api.pdfmonkey.io/api/v1/document_templates?page=' . $page, array( 'headers' => array( 'Authorization' => 'Bearer ' . $key ), 'timeout' => 20 ) );
    $j = json_decode( wp_remote_retrieve_body( $r ), true );
    $list = $j['document_templates'] ?? array();
    foreach ( $list as $t ) printf( "%s %s %s\n", substr( $t['id'], 0, 8 ), $t['updated_at'] ?? '?', $t['identifier'] ?? '' );
    if ( count( $list ) < 1 || empty( $j['meta']['next_page'] ) ) break;
}

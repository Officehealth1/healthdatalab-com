<?php
/**
 * Final report layout (plan 2026-11-14): makes and writes the PROOF copy of the
 * Final template only. The key is read in place and never printed.
 *
 *   wp eval-file - create        copies the production Final template (read only) into a
 *                                new template "PROOF — Final — layout — 2026-11-14"
 *   wp eval-file - put <id>      writes /tmp/frl/candidate.html (+ candidate.css when present)
 *                                to that template: body and stylesheet, each with its draft,
 *                                in one call, so the editor holds no older draft to publish
 *
 * Any template whose name does not start with "PROOF — " is refused for writing.
 * Every write is read back and compared; a refused write prints the API's errors.
 */
$pdfmonkey_key = function () {
    return defined( 'HDLV2_PDFMONKEY_API_KEY' ) ? (string) HDLV2_PDFMONKEY_API_KEY : '';
};
$key = $pdfmonkey_key();
if ( '' === $key ) { echo "no key\n"; return; }
$base   = 'https://api.pdfmonkey.io/api/v1/document_templates';
$source = 'a091f83e-1e92-40f5-aaa6-62f2bb4bb745';
$ident  = 'PROOF — Final — layout — 2026-11-14';
$call   = function ( $method, $url, $body = null ) use ( $key ) {
    $r = wp_remote_request( $url, array( 'method' => $method, 'timeout' => 60, 'headers' => array( 'Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json' ), 'body' => $body ? wp_json_encode( $body ) : null ) );
    return array( wp_remote_retrieve_response_code( $r ), json_decode( wp_remote_retrieve_body( $r ), true ) );
};
$mode = $args[0] ?? '';

if ( 'create' === $mode ) {
    for ( $page = 1; $page <= 5; $page++ ) {
        list( , $l ) = $call( 'GET', "$base?page=$page" );
        foreach ( $l['document_templates'] ?? array() as $t ) {
            if ( ( $t['identifier'] ?? '' ) === $ident ) { echo "exists {$t['id']}\n"; return; }
        }
        if ( empty( $l['meta']['next_page'] ) ) break;
    }
    list( $c, $src ) = $call( 'GET', "$base/$source" );
    $p = $src['document_template'] ?? array();
    if ( 200 !== $c || empty( $p['body'] ) ) { echo "source read $c\n"; return; }
    $doc = array(
        'identifier'   => $ident,
        'edition_mode' => $p['edition_mode'] ?? 'code',
        'body'         => $p['body'],
        'scss_style'   => $p['scss_style'] ?? '',
        'sample_data'  => $p['sample_data'] ?? '',
        'settings'     => $p['settings'] ?? null,
    );
    list( $c, $new ) = $call( 'POST', $base, array( 'document_template' => $doc ) );
    $id = $new['document_template']['id'] ?? '';
    if ( ! $id ) { echo "create $c failed\n"; return; }
    list( , $back ) = $call( 'GET', "$base/$id" );
    $b = $back['document_template'] ?? array();
    printf( "created %s (%d) body=%s css=%s sample=%s settings=%s\n", $id, $c,
        $b['body'] === $doc['body'] ? 'equal' : 'DIFF', ( $b['scss_style'] ?? '' ) === $doc['scss_style'] ? 'equal' : 'DIFF',
        ( $b['sample_data'] ?? '' ) === $doc['sample_data'] ? 'equal' : 'DIFF', ( $b['settings'] ?? null ) == $doc['settings'] ? 'equal' : 'DIFF' );
    return;
}

if ( 'put' === $mode ) {
    $id = (string) ( $args[1] ?? '' );
    if ( ! preg_match( '/^[0-9a-f-]{36}$/', $id ) || 0 === strpos( $id, substr( $source, 0, 8 ) ) ) { echo "REFUSED: id\n"; return; }
    list( $c, $cur ) = $call( 'GET', "$base/$id" );
    $t = $cur['document_template'] ?? array();
    if ( 0 !== strpos( $t['identifier'] ?? '', 'PROOF — ' ) ) { echo "REFUSED: not a PROOF template\n"; return; }
    $body = (string) file_get_contents( '/tmp/frl/candidate.html' );
    $css  = is_readable( '/tmp/frl/candidate.css' ) ? (string) file_get_contents( '/tmp/frl/candidate.css' ) : (string) ( $t['scss_style'] ?? '' );
    if ( '' === $body ) { echo "no candidate\n"; return; }
    list( $c, $put ) = $call( 'PUT', "$base/$id", array( 'document_template' => array( 'body' => $body, 'body_draft' => $body, 'scss_style' => $css, 'scss_style_draft' => $css ) ) );
    list( , $back ) = $call( 'GET', "$base/$id" );
    $b    = $back['document_template'] ?? array();
    $same = function ( $field, $want ) use ( $b ) { return array_key_exists( $field, $b ) && (string) $b[ $field ] === $want ? 'equal' : 'DIFF'; };
    printf( "put %d body=%s body_draft=%s css=%s css_draft=%s sha=%s\n", $c, $same( 'body', $body ), $same( 'body_draft', $body ),
        $same( 'scss_style', $css ), $same( 'scss_style_draft', $css ), substr( hash( 'sha256', $body ), 0, 12 ) );
    if ( $c < 200 || $c > 299 ) echo 'answer: ' . wp_json_encode( $put['errors'] ?? null ) . "\n";
    return;
}

echo "usage: create | put <proof-id>\n";

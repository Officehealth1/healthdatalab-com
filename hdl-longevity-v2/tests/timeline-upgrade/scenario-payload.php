<?php
/**
 * Plan 3 (v0.48.82), P4/P5 — where the staged form hands the offer on. The five e-mail keys
 * themselves (payload_fields) and the card's keys (card_fields) are driven in scenario-offer.php;
 * this file holds the wiring in class-hdlv2-staged-form.php against the base (8b64a25):
 *   - the draft webhook payload's own keys and values are byte for byte the base's, and the five
 *     keys are added after them, before the Make-safe pass;
 *   - /form/load and the Stage 3 answer of /complete-stage carry card_fields(), nothing else new.
 *
 * Run:  php scenario-payload.php   (self-asserting, exit 0/1)
 */
error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );
$PASS = 0; $FAIL = 0;
function check( $label, $cond ) {
    global $PASS, $FAIL;
    if ( $cond ) { $PASS++; echo "  PASS  $label\n"; } else { $FAIL++; echo "  FAIL  $label\n"; }
}
$src = file_get_contents( __DIR__ . '/../../includes/sprint-2/class-hdlv2-staged-form.php' );
/** The body of method $name, from its signature to the next method's doc comment or signature. */
function method_body( $src, $name ) {
    $at = strpos( $src, 'function ' . $name . '(' );
    if ( false === $at ) return '';
    $next = preg_match( '/\n    (?:\/\*\*|(?:public|private|protected)(?: static)? function )/', $src, $m, PREG_OFFSET_CAPTURE, $at + 10 ) ? $m[0][1] : strlen( $src );
    return substr( $src, $at, $next - $at );
}

echo "── K1. the draft webhook payload ──\n";
$fire = method_body( $src, 'fire_make_webhook' );
$a = strpos( $fire, "        \$payload = array(\n            'client_name'" );
$b = false === $a ? false : strpos( $fire, "\n        );\n", $a );
check( 'K1.1 the payload array is found', false !== $a && false !== $b );
check( 'K1.2 every key and value of the base\'s payload array unchanged (md5 of the block at 8b64a25)', false !== $b && '903a6628abb7f2e219c2dce1b11be8b2' === md5( substr( $fire, $a, $b - $a ) ) );
$add  = strpos( $fire, '$payload += HDLV2_Timeline_Upgrade::payload_fields( $progress, time() );' );
$why  = strpos( $fire, '$payload += HDLV2_Why_Picks::payload_fields(' );
$safe = strpos( $fire, '$html_safe_fields' );
check( 'K1.3 the five keys are added after the WHY keys and before the Make-safe pass', false !== $add && false !== $why && false !== $safe && $why < $add && $add < $safe );
check( 'K1.4 guarded: an older install without the class still fires', (bool) preg_match( "/if \( class_exists\( 'HDLV2_Timeline_Upgrade' \) \) \{\s*\\\$payload \+= HDLV2_Timeline_Upgrade::payload_fields/", $fire ) );

echo "── K2. the card's keys ──\n";
$load = method_body( $src, 'rest_load_form' );
$done = method_body( $src, 'rest_complete_stage' );
check( 'K2.1 /form/load adds card_fields() to its answer', false !== strpos( $load, '$response += HDLV2_Timeline_Upgrade::card_fields( $progress, time() );' ) );
check( 'K2.2 the Stage 3 answer adds card_fields(), for stage 3 only', (bool) preg_match( "/if \( 3 === \\\$stage && class_exists\( 'HDLV2_Timeline_Upgrade' \) \) \{\s*\\\$response \+= HDLV2_Timeline_Upgrade::card_fields\( \\\$progress, time\(\) \);/", $done ) );

echo "\nPASS=$PASS FAIL=$FAIL\n";
exit( $FAIL ? 1 : 0 );

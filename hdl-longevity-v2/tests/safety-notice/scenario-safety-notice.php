<?php
/**
 * Safety notice by country (v0.48.66, flag hdlv2_ff_safety_notice_v2).
 *
 * Drives the REAL classes: HDLV2_Safety_Content (the one source for the words,
 * the country table and the notice choice), HDLV2_Safety_Screen, HDLV2_Flags_Store,
 * HDLV2_Email_Templates and HDLV2_Widget_Config::rest_capture_lead().
 * "Unchanged" checks compare against the files as they were at 9e1d631 (0.48.65),
 * loaded under renamed classes.
 *
 * Run:  php scenario-safety-notice.php   (self-asserting, exit 0/1)
 */

require __DIR__ . '/../full-report/stubs.php';
if ( ! function_exists( 'sanitize_key' ) ) {
    function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
}

$ROOT = __DIR__ . '/../../';
const BASE = '9e1d631';
load_sources();
foreach ( array( 'includes/sprint-1/class-hdlv2-safety-content.php', 'includes/sprint-1/class-hdlv2-safety-screen.php', 'includes/class-hdlv2-flags-store.php' ) as $f ) {
    if ( file_exists( $ROOT . $f ) ) require_once $ROOT . $f;
}
/** The file as it was at BASE, loaded with its class renamed to <Class>_Old. */
function load_old( $path, $class ) {
    $src = shell_exec( 'git -C ' . escapeshellarg( __DIR__ ) . ' show ' . BASE . ':hdl-longevity-v2/' . $path . ' 2>/dev/null' );
    $tmp = sys_get_temp_dir() . '/hdlv2-old-' . md5( $path ) . '.php';
    file_put_contents( $tmp, preg_replace( '/\bclass ' . $class . '\b/', 'class ' . $class . '_Old', (string) $src, 1 ) );
    require_once $tmp;
}
load_old( 'includes/sprint-1/class-hdlv2-safety-screen.php', 'HDLV2_Safety_Screen' );
load_old( 'includes/sprint-2/class-hdlv2-email-templates.php', 'HDLV2_Email_Templates' );

$HAS = class_exists( 'HDLV2_Safety_Content' );
check( 'S0 HDLV2_Safety_Content exists', $HAS );
if ( ! $HAS ) { done(); }
$C = 'HDLV2_Safety_Content';

function flags_on( $notice = true ) {
    $GLOBALS['options'] = array( 'hdlv2_ff_safety_screen' => 1 );
    if ( $notice ) $GLOBALS['options']['hdlv2_ff_safety_notice_v2'] = 1;
}
function mails_to( $addr ) {
    return array_values( array_filter( $GLOBALS['mails'], function ( $m ) use ( $addr ) { return $m[0] === $addr; } ) );
}

echo "── T-country: the table and resolve() ──\n";
$rows_ok = true; $src_ok = true; $date_ok = true;
foreach ( $C::COUNTRIES as $code => $r ) {
    $rows_ok = $rows_ok && preg_match( '/^[A-Z]{2}$/', $code ) && preg_match( '/^\d+$/', (string) $r['emergency'] );
    $src_ok  = $src_ok && ! empty( $r['sources'] ) && count( array_filter( $r['sources'], function ( $u ) { return 0 === strpos( $u, 'https://' ); } ) ) === count( $r['sources'] );
    $date_ok = $date_ok && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $r['checked'] );
}
check( 'C1 every country row: ISO code + emergency number of digits only', $rows_ok );
check( 'C2 every country row: https source links only', $src_ok );
check( 'C3 every country row: a checked date', $date_ok );
check( 'C4 resolve(PH) is the PH row (911)', 'PH' === $C::resolve( 'PH' )['code'] && '911' === $C::resolve( 'PH' )['emergency'] );
check( 'C5 resolve(GB) is the GB row (999, NHS 111 or your GP)', '999' === $C::resolve( 'GB' )['emergency'] && 'NHS 111 or your GP' === $C::resolve( 'GB' )['doctor'] );
check( 'C6 resolve(gb) lower case still GB', 'GB' === $C::resolve( 'gb' )['code'] );
foreach ( array( '' => 'empty', 'zz' => 'zz', '<script>' => '<script>' ) as $in => $name ) {
    check( "C7 resolve($name) is the generic row", '' === $C::resolve( $in )['code'] && 'your local emergency number' === $C::resolve( $in )['emergency_text'] );
}
check( 'C8 resolve(array) is the generic row', '' === $C::resolve( array( 'GB' ) )['code'] );
check( 'C9 no Australian nurse line anywhere in the table', false === strpos( json_encode( $C::COUNTRIES ), '1800' ) );
check( 'C10 no findahelpline link', false === stripos( json_encode( $C::COUNTRIES ), 'findahelpline' ) );

echo "── T-tier: one notice per key, from urgency / is_crisis only ──\n";
$rule = function ( $def ) { return ! empty( $def['is_crisis'] ) ? 'c' : ( 'TODAY' === $def['urgency'] ? 'a' : ( 'THIS_WEEK' === $def['urgency'] ? 'b' : 'none' ) ); };
$all_ok = true; $count = array( 'a' => 0, 'b' => 0, 'c' => 0, 'none' => 0 );
foreach ( array( 'symptoms' => HDLV2_Safety_Screen::SYMPTOM_FLAGS, 'mh' => HDLV2_Safety_Screen::MH_FLAGS ) as $g => $list ) {
    foreach ( $list as $k => $def ) {
        $t = $C::tier_for( $g, $k );
        $all_ok = $all_ok && in_array( $t, array( 'a', 'b', 'c', 'none' ), true ) && $t === $rule( $def );
        $count[ $t ] = ( $count[ $t ] ?? 0 ) + 1;
    }
}
check( 'T1 every key maps to exactly the notice its own urgency / is_crisis gives', $all_ok );
check( 'T2 counts: A×4, B×10, C×2, none×2', array( 'a' => 4, 'b' => 10, 'c' => 2, 'none' => 2 ) == $count );
check( 'T3 unknown key → none', 'none' === $C::tier_for( 'symptoms', 'made_up' ) );
$n = $C::notice_for( array( 'symptoms' => array( 'stroke_speech', 'new_lump' ) ) );
check( 'T4 TODAY + THIS_WEEK → A with the "also" line', 'a' === $n['notice'] && true === $n['also'] && false === $n['crisis'] );
$n = $C::notice_for( array( 'mh' => array( 'self_harm' ), 'symptoms' => array( 'new_lump' ) ) );
check( 'T5 crisis tick → C first, then B', true === $n['crisis'] && 'b' === $n['notice'] && false === $n['also'] );
$n = $C::notice_for( array( 'mh' => array( 'low_mood' ) ) );
check( 'T6 low mood only → no notice (as today)', false === $n['crisis'] && '' === $n['notice'] );
check( 'T7 notice_for takes the ticks and nothing else', 1 === ( new ReflectionMethod( $C, 'notice_for' ) )->getNumberOfParameters() );
check( 'T8 notice_for(junk) → nothing', array( 'crisis' => false, 'notice' => '', 'also' => false ) === $C::notice_for( 'junk' ) );

echo "── T-gb-same: a UK reader's sentences do not change ──\n";
$same = true; $sig = true;
$safety_all = array( 'symptoms' => array_keys( HDLV2_Safety_Screen::SYMPTOM_FLAGS ), 'mh' => array_keys( HDLV2_Safety_Screen::MH_FLAGS ) );
$old = HDLV2_Safety_Screen_Old::map_flags( $safety_all );
foreach ( array( HDLV2_Safety_Screen::map_flags( $safety_all ), HDLV2_Safety_Screen::map_flags( $safety_all, $C::resolve( 'GB' ) ) ) as $new ) {
    foreach ( $old as $i => $f ) {
        $same = $same && $f['client_facing_wording'] === $new[ $i ]['client_facing_wording'] && $f['label'] === $new[ $i ]['label'];
        $sig  = $sig && $f['signature'] === $new[ $i ]['signature'];
    }
}
check( 'G1 all eighteen sentences + labels byte-identical to 0.48.65 (default and GB row)', $same && count( $old ) === 18 );
check( 'G2 signatures unchanged (dedup across the deploy holds)', $sig );
$ph = HDLV2_Safety_Screen::map_flags( array( 'symptoms' => array( 'stroke_speech' ) ), $C::resolve( 'PH' ) );
check( 'G3 PH row: the stroke sentence carries 911 and a doctor or clinic', false !== strpos( $ph[0]['client_facing_wording'], 'call 911' ) && false !== strpos( $ph[0]['client_facing_wording'], 'a doctor or clinic' ) && false === strpos( $ph[0]['client_facing_wording'], '999' ) );
$tok = true;
foreach ( HDLV2_Safety_Screen::map_flags( $safety_all, $C::resolve( 'US' ) ) as $f ) { $tok = $tok && false === strpos( $f['client_facing_wording'], '{' ) && false === strpos( $f['client_facing_wording'], '999' ) && false === strpos( $f['client_facing_wording'], 'NHS' ); }
check( 'G4 US row: no token left, no 999, no NHS in any sentence', $tok );

echo "── T-flag-off: nothing changes while the switch is off ──\n";
fresh_wpdb();
$GLOBALS['options'] = array( 'hdlv2_ff_safety_screen' => 1 ); // today's LIVE: screen on, new switch absent
$cfg = widget_instance()->rest_get_public_config( new FakeRequest( array(), array(), 'GET', '', array( 'practitioner_id' => 206 ) ) );
$cfgd = $cfg instanceof WP_REST_Response ? $cfg->get_data() : $cfg;
check( 'F1 public config has no safety_notice key', is_array( $cfgd['config'] ?? null ) && ! array_key_exists( 'safety_notice', $cfgd['config'] ) );
flags_on();
$cfg = widget_instance()->rest_get_public_config( new FakeRequest( array(), array(), 'GET', '', array( 'practitioner_id' => 206 ) ) );
$cfgd = $cfg instanceof WP_REST_Response ? $cfg->get_data() : $cfg;
check( 'F2 both switches on: public config carries safety_notice', is_array( $cfgd['config']['safety_notice'] ?? null ) );
$GLOBALS['options'] = array( 'hdlv2_ff_safety_notice_v2' => 1 );
check( 'F3 new switch on but the screen off: not enabled', false === $C::enabled() );
$src = file_get_contents( $ROOT . 'includes/sprint-1/class-hdlv2-widget-config.php' );
check( 'F4 the invite payload carries the same guarded key', 2 === substr_count( $src, "HDLV2_Safety_Content::enabled() ? array( 'safety_notice' => HDLV2_Safety_Content::public_payload() )" ) );

$finding = HDLV2_Safety_Screen_Old::map_flags( array( 'symptoms' => array( 'stroke_speech', 'new_lump' ) ) );
$cases = array(
    'findings' => array( 'client_email' => 'k@example.test', 'client_name' => 'Kim Test', 'practitioner_id' => 206, 'crisis' => false, 'findings' => $finding ),
    'crisis'   => array( 'client_email' => 'k@example.test', 'client_name' => 'Kim Test', 'practitioner_id' => 206, 'crisis' => true, 'findings' => $finding ),
);
foreach ( $cases as $name => $d ) {
    $GLOBALS['mails'] = array(); HDLV2_Email_Templates_Old::client_safety_alert( $d ); $o = $GLOBALS['mails'];
    $GLOBALS['mails'] = array(); HDLV2_Email_Templates::client_safety_alert( $d );     $n2 = $GLOBALS['mails'];
    check( "F5 client email ($name) identical to 0.48.65 without a notice context", $o && $o === $n2 );
}
$pd = array( 'practitioner_email' => 'p@example.test', 'practitioner_id' => 206, 'client_name' => 'Kim Test', 'dashboard_url' => 'https://stby.example.test/clients/', 'flags_html' => HDLV2_Flags_Store::practitioner_flags_html( $finding ) );
$GLOBALS['mails'] = array(); HDLV2_Email_Templates_Old::practitioner_redflag_alert( $pd ); $o = $GLOBALS['mails'];
$GLOBALS['mails'] = array(); HDLV2_Email_Templates::practitioner_redflag_alert( $pd );     $n2 = $GLOBALS['mails'];
check( 'F6 practitioner alert identical to 0.48.65 without a notice context', $o && $o === $n2 );

// End to end with the new switch absent: today's emails, no ack stored.
fresh_wpdb(); $GLOBALS['options'] = array( 'hdlv2_ff_safety_screen' => 1 );
$p = lead_params( 'off@example.test' );
$p['safety'] = array( 'symptoms' => array( 'stroke_speech' ) );
$p['visitor_country'] = 'PH';
$p['safety_ack'] = array( 'notice' => 'a', 'version' => $C::VERSION, 'country' => 'PH' );
$r = post_lead( $p );
$lead = $wpdb->inserts_into( 'hdlv2_widget_leads' );
$s1 = json_decode( (string) ( end( $lead )['data']['stage1_data'] ?? '' ), true );
check( 'F7 switch off: lead accepted, _safety kept, no _safety_ack', is_array( $r ) && ! empty( $r['success'] ) && ! empty( $s1['_safety'] ) && ! array_key_exists( '_safety_ack', (array) $s1 ) );
$cm = mails_to( 'off@example.test' );
check( 'F8 switch off: client email still says 999 (British, as today) even for a PH visitor', 1 === count( $cm ) && false !== strpos( $cm[0][2], '999' ) && false === strpos( $cm[0][2], 'sent automatically' ) );

echo "── T-auto / T-email: the notice in the email, by country ──\n";
$banned_subject = array( 'self-harm', 'suicide', 'stroke', 'cancer' );
foreach ( array_merge( array_column( HDLV2_Safety_Screen::SYMPTOM_FLAGS, 'label' ), array_column( HDLV2_Safety_Screen::MH_FLAGS, 'label' ) ) as $l ) { $banned_subject[] = $l; }
$no_offer = function ( $html ) { return ! preg_match( '/upgrade|£|€|\$\d|price|checkout|buy now|full report/i', strip_tags( $html ) ) && false === stripos( $html, 'buy' ); };
foreach ( array( 'PH' => '911', 'GB' => '999', '' => 'your local emergency number' ) as $cc => $want ) {
    foreach ( array( 'a' => array( 'symptoms' => array( 'stroke_speech' ) ), 'b' => array( 'symptoms' => array( 'new_lump' ) ), 'c' => array( 'mh' => array( 'self_harm' ) ) ) as $kind => $safety ) {
        flags_on();
        $ctx = $C::email_context( array( '_safety' => $safety ), $cc );
        $flags = HDLV2_Safety_Screen::map_flags( $safety, $ctx['row'] );
        $GLOBALS['mails'] = array();
        HDLV2_Email_Templates::client_safety_alert( array(
            'client_email' => 'k@example.test', 'client_name' => 'Kim Test', 'practitioner_id' => 206,
            'crisis' => 'c' === $kind, 'findings' => 'c' === $kind ? array() : $flags, 'notice' => $ctx,
        ) );
        $m = $GLOBALS['mails'][0] ?? array( '', '', '' );
        $label = ( $cc ?: 'unknown' ) . " $kind";
        $subj_ok = true; foreach ( $banned_subject as $w ) { $subj_ok = $subj_ok && false === stripos( $m[1], $w ); }
        check( "E1 $label: number matches the row", false !== strpos( $m[2], $want ) );
        check( "E2 $label: subject carries no symptom word", '' !== $m[1] && $subj_ok );
        check( "E3 $label: no price, no upgrade, no buy link", $no_offer( $m[2] ) );
        check( "E4 $label: automatic line + do not wait + the two closing lines", false !== strpos( $m[2], 'This check is automatic.' ) && false !== strpos( $m[2], 'Please do not wait to hear from us.' )
            && false !== strpos( $m[2], 'This email was sent automatically. Please do not reply to it: replies are not read.' )
            && false !== strpos( $m[2], 'If you are a client, your practitioner will see these answers before your first session.' ) );
        $txt = strip_tags( $m[2] ); // the visible text: the shared layout's #999 colour is not a number
        if ( 'GB' !== $cc ) check( "E5 $label: no British number or service in the visible text or links", false === strpos( $txt, '999' ) && false === strpos( $m[2], 'tel:999' ) && false === strpos( $txt, 'NHS' ) && false === strpos( $txt, 'Samaritans' ) );
    }
}
flags_on();
$GLOBALS['mails'] = array();
HDLV2_Email_Templates::client_safety_alert( array( 'client_email' => 'k@example.test', 'client_name' => 'Kim', 'practitioner_id' => 206, 'crisis' => true, 'findings' => array(), 'notice' => $C::email_context( array( '_safety' => array( 'mh' => array( 'self_harm' ) ) ), 'GB' ) ) );
$h = $GLOBALS['mails'][0][2];
check( 'E6 GB crisis keeps today\'s five lines (NHS 111, Samaritans, Shout, Papyrus, 999)', false !== strpos( $h, '111.nhs.uk' ) && false !== strpos( $h, 'samaritans.org' ) && false !== strpos( $h, '85258' ) && false !== strpos( $h, '0800 068 4141' ) && false !== strpos( $h, 'tel:999' ) );
$GLOBALS['mails'] = array();
HDLV2_Email_Templates::client_safety_alert( array( 'client_email' => 'k@example.test', 'client_name' => 'Kim', 'practitioner_id' => 206, 'crisis' => true, 'findings' => array(), 'notice' => $C::email_context( array( '_safety' => array( 'mh' => array( 'self_harm' ) ) ), 'US' ) ) );
$h = $GLOBALS['mails'][0][2];
check( 'E7 US crisis: 911 + 988, no British lines', false !== strpos( $h, 'tel:911' ) && false !== strpos( $h, '988' ) && false === strpos( $h, 'Papyrus' ) );

// The practitioner alert: three lines (4.F).
$ack = array( 'notice' => 'a', 'version' => $C::VERSION, 'country' => 'PH', 'at' => '2026-11-13 03:29:00' );
$ctx = $C::email_context( array( '_safety' => array( 'symptoms' => array( 'stroke_speech', 'new_lump' ) ), '_safety_ack' => $ack ), 'GB' );
check( 'E8 the ack country wins over the geo country', 'PH' === $ctx['row']['code'] );
$GLOBALS['mails'] = array();
HDLV2_Email_Templates::practitioner_redflag_alert( $pd + array( 'notice' => $ctx ) );
$h = $GLOBALS['mails'][0][2];
check( 'E9 alert: what they were shown (Notice A)', false !== strpos( $h, 'Notice A' ) );
check( 'E10 alert: which country\'s numbers (Philippines)', false !== strpos( $h, 'Philippines' ) );
check( 'E11 alert: pressed "I have read this" with the time', false !== strpos( $h, 'I have read this' ) && false !== strpos( $h, '13 Nov 2026' ) );
$ctx2 = $C::email_context( array( '_safety' => array( 'mh' => array( 'life_not_worth' ), 'symptoms' => array( 'new_lump' ) ) ), '' );
$GLOBALS['mails'] = array();
HDLV2_Email_Templates::practitioner_redflag_alert( $pd + array( 'notice' => $ctx2 ) );
$h = $GLOBALS['mails'][0][2];
check( 'E12 alert without an ack: C then B, country not known, not pressed', false !== strpos( $h, 'Notice C' ) && false !== strpos( $h, 'Notice B' ) && false !== strpos( $h, 'Country not known' ) && false !== strpos( $h, 'No' ) );

echo "── T-ack: kept with the lead, junk dropped ──\n";
$GLOBALS['options'] = array();
$a = $C::sanitize_ack( array( 'notice' => 'b', 'version' => $C::VERSION, 'country' => 'ph', 'at' => '1999-01-01 00:00:00', 'extra' => 'x' ) );
check( 'K1 clean ack keeps notice, version, country; server sets at', is_array( $a ) && 'b' === $a['notice'] && $C::VERSION === $a['version'] && 'PH' === $a['country'] && '1999-01-01 00:00:00' !== $a['at'] && preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $a['at'] ) );
check( 'K2 only four keys', is_array( $a ) && array( 'notice', 'version', 'country', 'at' ) === array_keys( $a ) );
check( 'K3 unknown country → empty, ack kept', '' === $C::sanitize_ack( array( 'notice' => 'a', 'version' => $C::VERSION, 'country' => '<b>' ) )['country'] );
check( 'K4 bad notice → dropped', null === $C::sanitize_ack( array( 'notice' => 'z', 'version' => $C::VERSION ) ) );
check( 'K5 bad version → dropped', null === $C::sanitize_ack( array( 'notice' => 'a', 'version' => '<script>' ) ) );
check( 'K6 not an array → dropped', null === $C::sanitize_ack( 'a' ) && null === $C::sanitize_ack( null ) );

fresh_wpdb(); flags_on();
$p = lead_params( 'ack@example.test' );
$p['safety'] = array( 'symptoms' => array( 'stroke_speech' ) );
$p['visitor_country'] = 'PH';
$p['safety_ack'] = array( 'notice' => 'a', 'version' => $C::VERSION, 'country' => 'PH' );
$r = post_lead( $p );
$lead = $wpdb->inserts_into( 'hdlv2_widget_leads' );
$s1 = json_decode( (string) ( end( $lead )['data']['stage1_data'] ?? '' ), true );
check( 'K7 switch on: lead accepted, _safety_ack stored with the server time', is_array( $r ) && ! empty( $r['success'] ) && 'a' === ( $s1['_safety_ack']['notice'] ?? '' ) && 'PH' === ( $s1['_safety_ack']['country'] ?? '' ) && ! empty( $s1['_safety_ack']['at'] ) );
$cm = mails_to( 'ack@example.test' ); $pm = mails_to( 'prac206@example.test' );
check( 'K8 the client email carries 911 (PH), the practitioner alert says Philippines + pressed', 1 === count( $cm ) && false !== strpos( $cm[0][2], 'tel:911' ) && 1 === count( $pm ) && false !== strpos( $pm[0][2], 'Philippines' ) && false !== strpos( $pm[0][2], 'I have read this' ) );

fresh_wpdb(); flags_on();
$p = lead_params( 'junk@example.test' );
$p['safety'] = array( 'symptoms' => array( 'new_lump' ) );
$p['safety_ack'] = array( 'notice' => 'drop table', 'version' => 1 );
$r = post_lead( $p );
$lead = $wpdb->inserts_into( 'hdlv2_widget_leads' );
$s1 = json_decode( (string) ( end( $lead )['data']['stage1_data'] ?? '' ), true );
check( 'K9 junk ack: lead still accepted, no _safety_ack', is_array( $r ) && ! empty( $r['success'] ) && ! array_key_exists( '_safety_ack', (array) $s1 ) && ! empty( $s1['_safety'] ) );

fresh_wpdb(); flags_on();
$p = lead_params( 'noticks@example.test' );
$p['safety_ack'] = array( 'notice' => 'a', 'version' => $C::VERSION, 'country' => 'GB' );
post_lead( $p );
$lead = $wpdb->inserts_into( 'hdlv2_widget_leads' );
$s1 = json_decode( (string) ( end( $lead )['data']['stage1_data'] ?? '' ), true );
check( 'K10 an ack without ticks is not stored', ! array_key_exists( '_safety_ack', (array) $s1 ) );

echo "── T-make: nothing new goes to Make ──\n";
fresh_wpdb(); flags_on();
post_lead( lead_params( 'plain@example.test' ) );
drain_last_lead( $wpdb );
$plain = null; foreach ( $GLOBALS['captured_posts'] as $cp ) { if ( false !== strpos( $cp['url'], 'hook.example.test/stage1' ) ) $plain = json_decode( $cp['args']['body'], true ); }
fresh_wpdb(); flags_on();
$p = lead_params( 'make@example.test' );
$p['safety'] = array( 'symptoms' => array( 'stroke_speech' ) );
$p['safety_ack'] = array( 'notice' => 'a', 'version' => $C::VERSION, 'country' => 'GB' );
post_lead( $p );
drain_last_lead( $wpdb );
$make = null; foreach ( $GLOBALS['captured_posts'] as $cp ) { if ( false !== strpos( $cp['url'], 'hook.example.test/stage1' ) ) $make = json_decode( $cp['args']['body'], true ); }
check( 'M1 Stage 1 payload keeps its 36 keys (same as a lead with no ticks)', is_array( $make ) && is_array( $plain ) && 36 === count( $make ) && count( $plain ) === count( $make ) );
check( 'M2 outbound stage1_data has no _safety_ack, _safety or _safety_flags', is_array( $make['stage1_data'] ?? null ) && ! array_key_exists( '_safety_ack', $make['stage1_data'] ) && ! array_key_exists( '_safety', $make['stage1_data'] ) && ! array_key_exists( '_safety_flags', $make['stage1_data'] ) );

echo "── T-no-branch: signposting only ──\n";
$pp = $C::public_payload();
check( 'N1 the tick rows are the eighteen server rows, in order', 14 === count( $pp['rows']['symptoms'] ) && 4 === count( $pp['rows']['mh'] ) && array_keys( HDLV2_Safety_Screen::SYMPTOM_FLAGS ) === array_column( $pp['rows']['symptoms'], 'k' ) );
check( 'N2 each row carries only k, t, tier', array( 'k', 't', 'tier' ) === array_keys( $pp['rows']['symptoms'][0] ) );
$csrc = file_get_contents( $ROOT . 'includes/sprint-1/class-hdlv2-safety-content.php' );
check( 'N3 no AI, no network, no database in the content class', ! preg_match( '/AI_Service|wp_remote|\$wpdb|curl_|score/i', $csrc ) );
check( 'N4 no public word "triage", "screening", "red flag" or "detect" in the notices', ! preg_match( '/triage|screening|red flag|detect/i', json_encode( $C::NOTICES ) ) );

echo "── T-lang-key ──\n";
check( 'L1 notices(en) is the English set', $C::NOTICES['en'] === $C::notices( 'en' ) );
check( 'L2 an absent language returns English', $C::NOTICES['en'] === $C::notices( 'fr' ) && $C::NOTICES['en'] === $C::notices( '' ) );
check( 'L3 the payload is keyed by language', isset( $pp['notices']['en']['a']['title'] ) );

echo "── Size ──\n";
$bytes = strlen( json_encode( $pp ) );
echo "  (public payload: $bytes bytes)\n";
check( 'Z1 the added config is under 6 KB', $bytes < 6144 );

done();

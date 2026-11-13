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
/** The file as it was at $rev (BASE by default), loaded with its class renamed to <Class><suffix>. */
function load_old( $path, $class, $rev = BASE, $suffix = '_Old' ) {
    $src = shell_exec( 'git -C ' . escapeshellarg( __DIR__ ) . ' show ' . $rev . ':hdl-longevity-v2/' . $path . ' 2>/dev/null' );
    $tmp = sys_get_temp_dir() . '/hdlv2-old-' . md5( $rev . $path ) . '.php';
    file_put_contents( $tmp, preg_replace( '/\bclass ' . $class . '\b/', 'class ' . $class . $suffix, (string) $src, 1 ) );
    require_once $tmp;
}
load_old( 'includes/sprint-1/class-hdlv2-safety-screen.php', 'HDLV2_Safety_Screen' );
load_old( 'includes/sprint-2/class-hdlv2-email-templates.php', 'HDLV2_Email_Templates' );
load_old( 'includes/sprint-2/class-hdlv2-email-templates.php', 'HDLV2_Email_Templates', '5fe95b9', '_0486' ); // 0.48.66, for the gate fixes' UK snapshot

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

echo "── T-gb-same: a UK reader's sentences do not change (except the two of sheet 10.2) ──\n";
$same = true; $sig = true; $two = true;
// Sheet 10.2 (0.48.67): these two read the same in every country, so the UK sentence changes too.
$S102 = array(
    'sym_breathless' => 'You mentioned unusual breathlessness. Please contact NHS 111 or your GP soon. If it comes on suddenly or severely, call 999.',
    'sym_fainting'   => 'You mentioned fainting or near-blackouts. Please contact NHS 111 or your GP soon, and avoid driving until you have been seen if it happens without warning.',
);
$safety_all = array( 'symptoms' => array_keys( HDLV2_Safety_Screen::SYMPTOM_FLAGS ), 'mh' => array_keys( HDLV2_Safety_Screen::MH_FLAGS ) );
$old = HDLV2_Safety_Screen_Old::map_flags( $safety_all );
foreach ( array( HDLV2_Safety_Screen::map_flags( $safety_all ), HDLV2_Safety_Screen::map_flags( $safety_all, $C::resolve( 'GB' ) ) ) as $new ) {
    foreach ( $old as $i => $f ) {
        if ( isset( $S102[ $f['id'] ] ) ) {
            $two = $two && $S102[ $f['id'] ] === $new[ $i ]['client_facing_wording'];
        } else {
            $same = $same && $f['client_facing_wording'] === $new[ $i ]['client_facing_wording'];
        }
        $same = $same && $f['label'] === $new[ $i ]['label'];
        $sig  = $sig && $f['signature'] === $new[ $i ]['signature'];
    }
}
check( 'G1 the other sixteen sentences + all labels byte-identical to 0.48.65 (default and GB row)', $same && count( $old ) === 18 );
check( 'G1b UK: breathless and fainting read as sheet 10.2 (the only two UK sentences that change)', $two );
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
$v = strip_tags( $h );
check( 'E6 GB crisis (sheet 10.7): 999 and Samaritans once each, then NHS 111, Shout and Papyrus', 1 === substr_count( $v, '999' ) && 1 === substr_count( $h, 'tel:999' ) && 1 === substr_count( $v, 'Samaritans' ) && false === strpos( $h, 'samaritans.org' )
    && false !== strpos( $h, '111.nhs.uk' ) && false !== strpos( $h, '85258' ) && false !== strpos( $h, '0800 068 4141' )
    && strpos( $v, 'If you are in danger right now' ) < strpos( $v, 'NHS 111' ) );
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
$B_TICKS = array( 'symptoms' => array( 'new_lump' ) ); $A_TICKS = array( 'symptoms' => array( 'stroke_speech' ) );
$a = $C::sanitize_ack( array( 'notice' => 'b', 'version' => $C::VERSION, 'country' => 'ph', 'at' => '1999-01-01 00:00:00', 'extra' => 'x' ), $B_TICKS );
check( 'K1 clean ack keeps notice, version, country; server sets at', is_array( $a ) && 'b' === $a['notice'] && $C::VERSION === $a['version'] && 'PH' === $a['country'] && '1999-01-01 00:00:00' !== $a['at'] && preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $a['at'] ) );
check( 'K2 only four keys', is_array( $a ) && array( 'notice', 'version', 'country', 'at' ) === array_keys( $a ) );
check( 'K3 unknown country → empty, ack kept', '' === $C::sanitize_ack( array( 'notice' => 'a', 'version' => $C::VERSION, 'country' => '<b>' ), $A_TICKS )['country'] );
check( 'K4 bad notice → dropped', null === $C::sanitize_ack( array( 'notice' => 'z', 'version' => $C::VERSION ), $A_TICKS ) );
check( 'K5 bad version → dropped', null === $C::sanitize_ack( array( 'notice' => 'a', 'version' => '<script>' ), $A_TICKS ) );
check( 'K6 not an array → dropped', null === $C::sanitize_ack( 'a', $A_TICKS ) && null === $C::sanitize_ack( null, $A_TICKS ) );

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

echo "── Gate fixes (0.48.67) ──\n";
$v = $C::VERSION;

// R2 — the press never reaches an AI prompt.
if ( ! defined( 'ARRAY_A' ) ) define( 'ARRAY_A', 'ARRAY_A' );
if ( ! class_exists( 'HDLV2_AI_Service' ) ) {
    class HDLV2_AI_Service { // records what the scan is handed, then stops the run
        public static $seen = array();
        public static function scan_for_flags( $stage1 ) { self::$seen[] = $stage1; return new WP_Error( 'test_stop', 'stop' ); }
    }
}
class FpRowWpdb extends FakeWpdb {
    public $fp = null;
    public function get_row( $sql, $output = null ) {
        if ( false !== strpos( $sql, 'hdlv2_why_profiles' ) ) return null;
        return false !== strpos( $sql, 'hdlv2_form_progress' ) ? $this->fp : parent::get_row( $sql );
    }
}
require_once $ROOT . 'includes/sprint-2/class-hdlv2-redflag-jobs.php';
$wpdb = new FpRowWpdb();
$wpdb->fp = (object) array( 'id' => 9, 'client_user_id' => 1, 'practitioner_user_id' => 206, 'client_email' => 'k@example.test', 'client_name' => 'Kim',
    'stage1_data' => json_encode( array( 'q1_age' => 44, '_safety' => array( 'symptoms' => array( 'stroke_speech' ) ), '_safety_ack' => array( 'notice' => 'a', 'version' => $v, 'country' => 'GB', 'at' => '2026-11-13 03:29:00' ) ) ),
    'stage3_data' => '{}', 'flags' => '[]' );
HDLV2_Redflag_Jobs::run_now( 9 );
$in = HDLV2_AI_Service::$seen[0] ?? null;
check( 'R2a AI scan input: no _safety_ack, no _safety (as before), the rest kept', is_array( $in ) && ! array_key_exists( '_safety_ack', $in ) && ! array_key_exists( '_safety', $in ) && 44 === ( $in['q1_age'] ?? null ) );
$ac   = file_get_contents( $ROOT . 'includes/sprint-2c/class-hdlv2-auto-consultation.php' );
$at   = strpos( $ac, 'private function generate_ai_inputs(' );
$body = false !== $at ? substr( $ac, $at, strpos( $ac, '$user_prompt = ', $at ) - $at ) : '';
$dec  = strpos( $body, '$s1 = ' );
$un   = strpos( $body, "unset( \$s1['_safety_ack'] );" );
check( 'R2b auto-consultation (source read): _safety_ack is unset from $s1 after it is decoded and before the prompt; _safety is left as it was', false !== $dec && false !== $un && $un > $dec && false === strpos( $body, "\$s1['_safety']" ) );

// R3 — a missing content class (a part-finished deploy) never drops a flag or its email.
$fb = json_decode( (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/dump-flags-without-content.php' ) . ' 2>&1' ), true );
$gb = HDLV2_Safety_Screen::map_flags( $safety_all, $C::resolve( 'GB' ) );
$fb_same = is_array( $fb ) && false === $fb['content_loaded'] && 18 === count( $fb['flags'] );
foreach ( $gb as $i => $f ) {
    $fb_same = $fb_same && ( $fb['flags'][ $i ]['client_facing_wording'] ?? null ) === $f['client_facing_wording'] && ( $fb['flags'][ $i ]['signature'] ?? null ) === $f['signature'];
}
check( 'R3 content class absent: all eighteen rows map, each sentence equals the UK rendering, signatures unchanged', $fb_same );

// R4 — the press is kept only when it matches the notice the ticks show.
$CB = array( 'mh' => array( 'self_harm' ), 'symptoms' => array( 'new_lump' ) );
$AB = array( 'symptoms' => array( 'stroke_speech', 'new_lump' ) );
$CO = array( 'mh' => array( 'self_harm' ) );
check( 'R4a a press for a lead with no ticks → not kept', null === $C::sanitize_ack( array( 'notice' => 'a', 'version' => $v ), array() ) );
check( 'R4b "a" claimed with only B ticks → not kept', null === $C::sanitize_ack( array( 'notice' => 'a', 'version' => $v ), $B_TICKS ) );
check( 'R4c "c" claimed without a crisis tick → not kept', null === $C::sanitize_ack( array( 'notice' => 'c', 'version' => $v ), $A_TICKS ) );
check( 'R4d version "2026-11-13.1\n" (trailing newline) → rejected', null === $C::sanitize_ack( array( 'notice' => 'a', 'version' => "2026-11-13.1\n" ), $A_TICKS ) );
check( 'R4e crisis + B: the widget\'s last button is on B, so "b" is kept and "c" is not', is_array( $C::sanitize_ack( array( 'notice' => 'b', 'version' => $v ), $CB ) ) && null === $C::sanitize_ack( array( 'notice' => 'c', 'version' => $v ), $CB ) );
check( 'R4f crisis only: "c" kept; A + B: "a" kept, "b" not', is_array( $C::sanitize_ack( array( 'notice' => 'c', 'version' => $v ), $CO ) ) && is_array( $C::sanitize_ack( array( 'notice' => 'a', 'version' => $v ), $AB ) ) && null === $C::sanitize_ack( array( 'notice' => 'b', 'version' => $v ), $AB ) );
fresh_wpdb(); flags_on();
$p = lead_params( 'forged@example.test' );
$p['safety'] = $B_TICKS;
$p['safety_ack'] = array( 'notice' => 'a', 'version' => $v, 'country' => 'GB' );
$r = post_lead( $p );
$lead = $wpdb->inserts_into( 'hdlv2_widget_leads' );
$s1 = json_decode( (string) ( end( $lead )['data']['stage1_data'] ?? '' ), true );
check( 'R4g lead POST: "a" claimed with B ticks → lead accepted, no _safety_ack stored', is_array( $r ) && ! empty( $r['success'] ) && ! empty( $s1['_safety'] ) && ! array_key_exists( '_safety_ack', (array) $s1 ) );
flags_on();
$ctx = $C::email_context( array( '_safety' => array( 'mh' => array( 'low_mood' ) ), '_safety_ack' => array( 'notice' => 'a', 'version' => $v, 'country' => 'GB', 'at' => '2026-11-13 03:29:00' ) ), 'GB' );
$GLOBALS['mails'] = array();
HDLV2_Email_Templates::practitioner_redflag_alert( $pd + array( 'notice' => $ctx ) );
$h = $GLOBALS['mails'][0][2];
check( 'R4h alert: "No notice on screen" never sits beside a press line', false !== strpos( $h, 'No notice on screen' ) && false === strpos( $h, 'I have read this' ) && false === strpos( $h, 'Went on to the result' ) );

// R5 — the reminder for notice A carries both actions (sheet 10.5; the widget test renders it).
check( 'R5 notice A\'s reminder line is sheet 10.5, word for word', "if this is happening now, or happened in the last 24 hours, call {EMERGENCY} now. If it has passed, ask for an urgent doctor's appointment today." === ( $C::NOTICES['en']['a']['repeat'] ?? '' ) );

// R6 — sheet 10.2 and 10.3 outside the UK.
$by_id = function ( $cc ) use ( $C, $safety_all ) { $o = array(); foreach ( HDLV2_Safety_Screen::map_flags( $safety_all, $C::resolve( $cc ) ) as $f ) { $o[ $f['id'] ] = $f['client_facing_wording']; } return $o; };
foreach ( array( 'PH' => 'PH', 'US' => 'US', '' => 'unknown' ) as $cc => $name ) {
    $bad = false;
    foreach ( $by_id( $cc ) as $w ) { $bad = $bad || false !== strpos( $w, 'or a doctor' ) || false !== strpos( $w, 'doctor appointment' ); }
    check( "R6a $name: no \"or a doctor\" and no \"doctor appointment\" in any sentence", ! $bad );
}
$ph = $by_id( 'PH' ); $uk = $by_id( 'GB' );
check( 'R6b PH: breathless and fainting read as sheet 10.2', 'You mentioned unusual breathlessness. Please contact a doctor or clinic soon. If it comes on suddenly or severely, call 911.' === $ph['sym_breathless']
    && 'You mentioned fainting or near-blackouts. Please contact a doctor or clinic soon, and avoid driving until you have been seen if it happens without warning.' === $ph['sym_fainting'] );
$seven = true;
foreach ( array( 'weight_loss', 'new_lump', 'bleeding', 'swallowing', 'persistent_cough', 'changing_mole', 'abnormal_bleeding' ) as $k ) {
    $seven = $seven && false !== strpos( $ph[ 'sym_' . $k ], "a doctor's appointment" ) && false !== strpos( $uk[ 'sym_' . $k ], 'a GP appointment' );
}
check( 'R6c the seven (sheet 10.3): "a doctor\'s appointment" outside the UK, "a GP appointment" in the UK', $seven );

// R7 — outside the UK the email says "doctor" where it said "GP" (sheet 10.4); the UK email does not change.
$mk = function ( $cc, $safety ) use ( $C ) {
    flags_on();
    $ctx = $C::email_context( array( '_safety' => $safety ), $cc );
    $findings = array_values( array_filter( HDLV2_Safety_Screen::map_flags( $safety, $ctx['row'] ), function ( $f ) { return empty( $f['is_crisis'] ); } ) );
    return array( 'client_email' => 'k@example.test', 'client_name' => 'Kim Test', 'practitioner_id' => 206, 'crisis' => ! empty( $safety['mh'] ) && in_array( 'self_harm', $safety['mh'], true ), 'findings' => $findings, 'notice' => $ctx );
};
$EVERY = array( 'symptoms' => array( 'stroke_speech', 'new_lump', 'breathless', 'weight_loss' ), 'mh' => array( 'low_mood', 'anxiety' ) );
foreach ( array( 'PH' => 'PH', '' => 'unknown' ) as $cc => $name ) {
    foreach ( array( '' => $EVERY, ' crisis' => array( 'mh' => array( 'low_mood', 'anxiety', 'self_harm' ) ) + $EVERY ) as $tag => $safety ) {
        $GLOBALS['mails'] = array();
        HDLV2_Email_Templates::client_safety_alert( $mk( $cc, $safety ) );
        $vis = strip_tags( $GLOBALS['mails'][0][2] ?? 'GP' );
        check( "R7a $name$tag: no \"GP\" anywhere in the email (chips, heading, mood cards, reasons)", ! preg_match( '/\bGP\b/', $vis ) && false !== strpos( $vis, 'doctor' ) );
    }
}
$d = $mk( 'GB', array( 'symptoms' => array( 'stroke_speech', 'new_lump', 'weight_loss' ), 'mh' => array( 'low_mood', 'anxiety' ) ) );
$GLOBALS['mails'] = array(); HDLV2_Email_Templates_0486::client_safety_alert( $d ); $o = $GLOBALS['mails'];
$GLOBALS['mails'] = array(); HDLV2_Email_Templates::client_safety_alert( $d );      $n2 = $GLOBALS['mails'];
check( 'R7b UK email byte-identical to 0.48.66 (chips, cards and mood lines keep "GP")', $o && $o === $n2 && false !== strpos( $o[0][2], 'Worth a GP visit this week' ) );
$GLOBALS['mails'] = array();
HDLV2_Email_Templates::client_safety_alert( $mk( 'GB', array( 'mh' => array( 'self_harm' ), 'symptoms' => array( 'new_lump' ) ) ) );
check( 'R7c UK crisis keeps the heading "Also worth a word with your GP"', false !== strpos( $GLOBALS['mails'][0][2], 'Also worth a word with your GP' ) );

// R8 — the alert line after notice C (sheet 10.6).
flags_on();
$at_ = '2026-11-13 03:29:00';
$GLOBALS['mails'] = array();
HDLV2_Email_Templates::practitioner_redflag_alert( $pd + array( 'notice' => $C::email_context( array( '_safety' => $CO, '_safety_ack' => array( 'notice' => 'c', 'version' => $v, 'country' => 'GB', 'at' => $at_ ) ), 'GB' ) ) );
$h = $GLOBALS['mails'][0][2];
check( 'R8a alert after notice C: "Went on to the result after the support notice: Yes", no "I have read this"', false !== strpos( $h, 'Went on to the result after the support notice:</strong> Yes' ) && false === strpos( $h, 'I have read this' ) );
$GLOBALS['mails'] = array();
HDLV2_Email_Templates::practitioner_redflag_alert( $pd + array( 'notice' => $C::email_context( array( '_safety' => $CB, '_safety_ack' => array( 'notice' => 'b', 'version' => $v, 'country' => 'GB', 'at' => $at_ ) ), 'GB' ) ) );
$h = $GLOBALS['mails'][0][2];
check( 'R8b alert after C then B: the press is on B ("I have read this: Yes"), no support-notice line', false !== strpos( $h, 'I have read this&rdquo;:</strong> Yes' ) && false === strpos( $h, 'Went on to the result' ) );
$GLOBALS['mails'] = array();
HDLV2_Email_Templates::practitioner_redflag_alert( $pd + array( 'notice' => $C::email_context( array( '_safety' => $CO ), 'GB' ) ) );
check( 'R8c alert after notice C without a press: "Went on to the result after the support notice: No"', false !== strpos( $GLOBALS['mails'][0][2], 'Went on to the result after the support notice:</strong> No' ) );

echo "── Size ──\n";
$bytes = strlen( json_encode( $pp ) );
echo "  (public payload: $bytes bytes)\n";
check( 'Z1 the added config is under 6 KB', $bytes < 6144 );

echo "── R1: with the notice on, the email always follows the screen (runs last: it defines the Make hook) ──\n";
define( 'HDLV2_MAKE_REDFLAG', 'https://hook.example.test/redflag' );
$redflag_posts = function () { return array_values( array_filter( $GLOBALS['captured_posts'], function ( $cp ) { return false !== strpos( $cp['url'], 'hook.example.test/redflag' ); } ) ); };
fresh_wpdb(); flags_on();
$p = lead_params( 'r1on@example.test' );
$p['safety'] = array( 'symptoms' => array( 'stroke_speech' ) );
$p['visitor_country'] = 'PH';
post_lead( $p );
$cm = mails_to( 'r1on@example.test' );
check( 'R1a switch on + Make hook defined: the client email goes by wp_mail with the PH notice, Make is not called', 1 === count( $cm ) && false !== strpos( $cm[0][2], 'tel:911' ) && 0 === count( $redflag_posts() ) );
fresh_wpdb(); $GLOBALS['options'] = array( 'hdlv2_ff_safety_screen' => 1 );
$p = lead_params( 'r1off@example.test' );
$p['safety'] = array( 'symptoms' => array( 'stroke_speech' ) );
post_lead( $p );
check( 'R1b switch off + Make hook defined: Make is called as today, no wp_mail to the client', 1 === count( $redflag_posts() ) && 0 === count( mails_to( 'r1off@example.test' ) ) );

done();

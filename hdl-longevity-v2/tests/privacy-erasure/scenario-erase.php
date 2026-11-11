<?php
/**
 * The eraser: find one person, erase every store, leave everyone else alone (E1–E7, E9, E10).
 *   php tests/privacy-erasure/scenario-erase.php
 */
require __DIR__ . '/stubs.php';
require dirname( __DIR__, 2 ) . '/includes/privacy/class-hdlv2-privacy-eraser.php';

const PRAC_A = 5;
const PRAC_B = 6;

/** One person with a row in every store, two report files, three recordings and the iris images. */
function seed_person( $db, $uid, $email, $tag, $email_case = null ) {
    $e    = $email_case ?? $email;
    $tok  = hash( 'sha256', 'token-' . $tag );
    $pdf  = function ( $n ) use ( $tag ) { hdl_erase_file( "hdlv2-reports/$n-$tag.pdf" ); return "$n-$tag.pdf"; };
    $aud  = function ( $n ) use ( $tag ) { return hdl_erase_file( "hdlv2-audio/2026/11/$n-$tag.webm" ); };
    $s    = array( 'uid' => $uid, 'email' => $email, 'token' => $tok );

    $s['pid']  = $db->insert( 'wp_hdlv2_form_progress', array( 'client_user_id' => $uid ?: null, 'practitioner_user_id' => PRAC_A, 'client_name' => "Name $tag", 'client_email' => $e, 'token' => $tok, 'stage1_pdf_stored_path' => $pdf( 'stage1' ), 'stage1_data' => '{"q1":3}' ) );
    // A second record with another practitioner, found only by the email (no user id on it).
    $s['pid2'] = $db->insert( 'wp_hdlv2_form_progress', array( 'client_user_id' => null, 'practitioner_user_id' => PRAC_B, 'client_name' => "Name $tag", 'client_email' => strtoupper( $email ), 'token' => hash( 'sha256', 'token2-' . $tag ), 'stage1_data' => '{}' ) );
    $s['lid']  = $db->insert( 'wp_hdlv2_widget_leads', array( 'practitioner_user_id' => PRAC_A, 'visitor_name' => "Name $tag", 'visitor_email' => " $e ", 'stage1_pdf_stored_path' => $pdf( 'lead' ), 'stage1_data' => '{}' ) );
    $db->insert( 'wp_hdlv2_pending_leads', array( 'practitioner_id' => PRAC_A, 'visitor_email' => $e, 'verify_token' => "v-$tag" ) );
    $db->insert( 'wp_hdlv2_widget_invites', array( 'practitioner_id' => PRAC_B, 'token' => "i-$tag", 'client_name' => "Name $tag", 'client_email' => $e, 'source' => 'practitioner' ) );
    $db->insert( 'wp_hdlv2_automation_tokens', array( 'token' => "a-$tag", 'client_email' => $e, 'stripe_session_id' => "cs_$tag" ) );
    $db->insert( 'wp_hdlv2_why_profiles', array( 'client_user_id' => $uid ?: null, 'form_progress_id' => $s['pid'], 'pdf_stored_path' => $pdf( 'why' ), 'distilled_why' => 'to walk' ) );
    $s['rid']  = $db->insert( 'wp_hdlv2_reports', array( 'client_user_id' => $uid ?: null, 'practitioner_user_id' => PRAC_A, 'form_progress_id' => $s['pid'], 'pdf_stored_path' => $pdf( 'report' ), 'report_type' => 'final' ) );
    $s['cid']  = $db->insert( 'wp_hdlv2_consultation_notes', array( 'client_user_id' => (int) $uid, 'practitioner_user_id' => PRAC_A, 'form_progress_id' => $s['pid'], 'typed_notes' => 'notes' ) );
    $db->insert( 'wp_hdlv2_consultation_addenda', array( 'consultation_id' => $s['cid'], 'practitioner_user_id' => PRAC_A, 'client_user_id' => (int) $uid, 'form_progress_id' => $s['pid'], 'body' => 'later' ) );
    $db->insert( 'wp_hdlv2_audio_extractions', array( 'client_user_id' => $uid ?: null, 'form_progress_id' => $s['pid'], 'transcript' => 'words' ) );
    hdl_erase_file( "hdlv2-iris/iris-l-$tag.jpg" );
    $db->insert( 'wp_hdlv2_iris_results', array( 'client_user_id' => (int) $uid, 'practitioner_user_id' => PRAC_A, 'form_progress_id' => $s['pid'], 'image_l_path' => "iris-l-$tag.jpg" ) );
    $db->insert( 'wp_hdlv2_widget_invites', array( 'practitioner_id' => PRAC_A, 'token' => "t-$tag", 'client_email' => '', 'source' => 'timeline_link', 'external_ref' => 'placeholder' ) );
    if ( $uid ) {
        $db->insert( 'wp_hdlv2_checkins', array( 'client_id' => $uid, 'practitioner_id' => PRAC_A, 'notes' => 'ok' ) );
        $db->insert( 'wp_hdlv2_timeline', array( 'client_id' => $uid, 'practitioner_id' => PRAC_A, 'source_table' => 'hdlv2_checkins', 'body' => 'x' ) );
        $s['plan'] = $db->insert( 'wp_hdlv2_flight_plans', array( 'client_id' => $uid, 'practitioner_id' => PRAC_A, 'pdf_stored_path' => $pdf( 'fp' ), 'plan' => '{}' ) );
        $db->insert( 'wp_hdlv2_flight_plan_ticks', array( 'flight_plan_id' => $s['plan'], 'client_id' => $uid, 'day' => 'mon' ) );
        $db->insert( 'wp_hdlv2_monthly_summaries', array( 'client_id' => $uid, 'practitioner_id' => PRAC_A, 'summary' => 's' ) );
        $db->insert( 'wp_hdlv2_challenge_days', array( 'client_user_id' => $uid, 'day_no' => 1, 'note' => 'n' ) );
        $db->insert( 'wp_hdl_user_credits', array( 'user_id' => $uid, 'credits' => 2 ) );
        $db->insert( 'wp_hdl_usage_logs', array( 'user_id' => $uid, 'ip' => '203.0.113.9' ) );
        $db->insert( 'wp_hdl_credit_purchases', array( 'user_id' => $uid, 'stripe_payment_id' => "pi_$tag" ) );
        $db->insert( 'wp_hdlv2_jobs', array( 'job_type' => 'generate_flight_plan_auto', 'reference_id' => $uid, 'payload' => '{}' ) );
        $db->insert( 'wp_hdlv2_jobs', array( 'job_type' => 'render_flight_plan_pdf', 'reference_id' => $s['plan'], 'payload' => '{}' ) );
        $s['audio_owner'] = $aud( 'own' );
        $db->insert( 'wp_hdlv2_jobs', array( 'job_type' => 'transcribe_audio', 'reference_id' => 0, 'payload' => json_encode( array( 'file_path' => $s['audio_owner'], 'context_type' => 'weekly_checkin', '_owner_user' => $uid, '_owner_token_hash' => '' ) ), 'result' => 'transcript' ) );
    }
    // The person's own timeline: a link they made, its ticket and their family pack.
    $s['own_link'] = $db->insert( 'wp_hdlv2_timeline_links', array( 'owner_progress_id' => $s['pid'], 'label' => 'Mum', 'status' => 'invited' ) );
    $db->pdo->exec( "UPDATE wp_hdlv2_widget_invites SET external_ref = 'tl:{$s['own_link']}' WHERE token = 't-$tag'" );
    $db->insert( 'wp_hdlv2_timeline_packs', array( 'owner_progress_id' => $s['pid'], 'seats_total' => 5, 'seats_used' => 1, 'status' => 'active' ) );
    // Jobs that point at the person by their record, consultation and report; recordings by consultation and by token.
    $db->insert( 'wp_hdlv2_jobs', array( 'job_type' => 'generate_final_report', 'reference_id' => $s['pid'], 'payload' => '{}', 'result' => 'report text' ) );
    $db->insert( 'wp_hdlv2_jobs', array( 'job_type' => 'organise_consultation', 'reference_id' => $s['cid'], 'payload' => '{}' ) );
    $db->insert( 'wp_hdlv2_jobs', array( 'job_type' => 'render_final_report_pdf', 'reference_id' => $s['rid'], 'payload' => '{}' ) );
    $s['audio_consult'] = $aud( 'consult' );
    $db->insert( 'wp_hdlv2_jobs', array( 'job_type' => 'transcribe_audio', 'reference_id' => $s['cid'], 'payload' => json_encode( array( 'file_path' => $s['audio_consult'], 'context_type' => 'consultation_notes', '_owner_user' => PRAC_A ) ) ) );
    $s['audio_token'] = $aud( 'token' );
    $db->insert( 'wp_hdlv2_jobs', array( 'job_type' => 'transcribe_audio', 'reference_id' => 0, 'payload' => json_encode( array( 'file_path' => $s['audio_token'], 'context_type' => 'why_collection', '_owner_user' => 0, '_owner_token_hash' => hash_hmac( 'sha256', $tok, wp_salt( 'auth' ) ) ) ) ) );
    // V1.
    $eh = hash( 'sha256', strtolower( $email ) );
    $uh = hash( 'sha256', strtolower( $email ) . 'v1-salt' );
    $db->insert( 'wp_health_tracker_submissions', array( 'user_hash' => $uh, 'email_hash' => $eh, 'answers' => '{}' ) );
    $db->insert( 'wp_health_tracker_progress', array( 'user_hash' => $uh, 'current_value' => 1.1 ) );
    $db->insert( 'wp_health_tracker_sessions', array( 'user_hash' => $uh, 'email_hash' => $eh ) );
    $db->insert( 'wp_health_tracker_verification_codes', array( 'email_hash' => $eh, 'code' => '123456' ) );
    $db->insert( 'wp_health_tracker_practitioner_clients', array( 'practitioner_user_id' => 7, 'client_email_hash' => $eh ) );
    $db->insert( 'wp_hdl_messages', array( 'practitioner_id' => 7, 'client_email_hash' => $eh, 'client_email' => $e, 'body' => 'hi' ) );
    $db->insert( 'wp_hdl_messaging_prefs', array( 'client_email_hash' => $eh, 'practitioner_id' => 7 ) );
    $db->insert( 'wp_hdl_invites', array( 'email' => $e ) );
    $db->insert( 'wp_hdl_lab_audit_log', array( 'email' => $e, 'action' => 'view' ) );
    $db->insert( 'wp_post_smtp_logs', array( 'original_to' => $e, 'to_header' => $e, 'original_message' => 'sign-in link' ) );
    return $s;
}

function messages_of( $answers ) {
    $m = array();
    foreach ( $answers as $a ) { if ( is_array( $a ) ) $m = array_merge( $m, $a['messages'] ); }
    return $m;
}
function removed_any( $answers ) { foreach ( $answers as $a ) { if ( is_array( $a ) && $a['items_removed'] ) return true; } return false; }
function retained_any( $answers ) { foreach ( $answers as $a ) { if ( is_array( $a ) && $a['items_retained'] ) return true; } return false; }
function files_of( $s, $tag ) {
    $f = array( $s['audio_consult'], $s['audio_token'], HDLV2_PRIVATE_DIR . "/hdlv2-iris/iris-l-$tag.jpg" );
    foreach ( array( 'stage1', 'lead', 'why', 'report' ) as $n ) $f[] = HDLV2_PRIVATE_DIR . "/hdlv2-reports/$n-$tag.pdf";
    if ( $s['uid'] ) { $f[] = $s['audio_owner']; $f[] = HDLV2_PRIVATE_DIR . "/hdlv2-reports/fp-$tag.pdf"; }
    return $f;
}
/** Rows anywhere that still name the person: by user id, email, hash or record id. */
function rows_left( $db, $tables, $s ) {
    $e  = strtolower( $s['email'] );
    $eh = hash( 'sha256', $e );
    $uh = hash( 'sha256', $e . 'v1-salt' );
    $left = array();
    foreach ( hdl_erase_dump( $db, $tables ) as $t => $rows ) {
        foreach ( $rows as $r ) {
            foreach ( (array) $r as $col => $v ) {
                $v = strtolower( trim( (string) $v ) );
                $hit = in_array( $v, array( $e, $eh, $uh ), true )
                    || ( $s['uid'] && in_array( $col, array( 'client_user_id', 'client_id', 'user_id' ), true ) && (int) $v === $s['uid'] )
                    || ( in_array( $col, array( 'form_progress_id', 'owner_progress_id' ), true ) && in_array( (int) $v, array( $s['pid'], $s['pid2'] ), true ) );
                if ( $hit && 'hdl_credit_purchases' !== $t ) { $left[] = "$t.$col"; }
            }
        }
    }
    return array_unique( $left );
}

$db = new SqliteWpdb();
$GLOBALS['wpdb'] = $db;
$tables = hdl_erase_schema( $db );

$GLOBALS['users'][ PRAC_A ] = (object) array( 'ID' => PRAC_A, 'user_email' => 'prac@example.test', 'roles' => array( 'um_practitioner' ) );
$GLOBALS['users'][1]        = (object) array( 'ID' => 1, 'user_email' => 'boss@example.test', 'roles' => array( 'administrator' ) );
$GLOBALS['users'][10]       = (object) array( 'ID' => 10, 'user_email' => 'alice@example.test', 'roles' => array( 'um_client' ) );
$GLOBALS['users'][20]       = (object) array( 'ID' => 20, 'user_email' => 'bob@example.test', 'roles' => array( 'um_consumer' ) );
$GLOBALS['users'][30]       = (object) array( 'ID' => 30, 'user_email' => 'erin@example.test', 'roles' => array( 'editor' ) );

// The neighbour first, then a row that belongs to nobody (no user, no email): neither may change.
$b = seed_person( $db, 20, 'bob@example.test', 'bob' );
$db->insert( 'wp_hdlv2_form_progress', array( 'client_user_id' => 0, 'practitioner_user_id' => PRAC_A, 'client_email' => '', 'token' => 'nobody', 'stage1_data' => '{}' ) );
$db->insert( 'wp_hdlv2_widget_leads', array( 'practitioner_user_id' => PRAC_A, 'visitor_email' => '', 'stage1_data' => '{}' ) );
// A recording of Bob's whose reference happens to equal Alice's consultation id, outside a consultation: Bob's.
$before_b = hdl_erase_dump( $db, $tables );

$job_floor = (int) $db->get_var( 'SELECT MAX(id) FROM wp_hdlv2_jobs' );
$a = seed_person( $db, 10, 'alice@example.test', 'alice', 'Alice@Example.test' );
$a_jobs = $db->get_col( "SELECT id FROM wp_hdlv2_jobs WHERE id > $job_floor" );
$bob_collision_audio = hdl_erase_file( 'hdlv2-audio/2026/11/collision-bob.webm' );
$db->insert( 'wp_hdlv2_jobs', array( 'job_type' => 'transcribe_audio', 'reference_id' => $a['cid'], 'payload' => json_encode( array( 'file_path' => $bob_collision_audio, 'context_type' => 'why_collection', '_owner_user' => 20 ) ) ) );
$c = seed_person( $db, 0, 'carol@example.test', 'carol' ); // no account: a lead and email-only records

echo "── E7. switch off: no eraser ──\n";
HDLV2_Privacy_Eraser::init();
ok( 'E7.1 off: nothing on wp_privacy_personal_data_erasers', empty( $GLOBALS['filters']['wp_privacy_personal_data_erasers'] ) );
$GLOBALS['options'][ HDLV2_Privacy_Eraser::FLAG ] = '1';
HDLV2_Privacy_Eraser::init();
$erasers = apply_filters( 'wp_privacy_personal_data_erasers', array() );
ok( 'E7.2 on: registered with a callable callback', isset( $erasers['hdl-longevity-v2'] ) && is_callable( $erasers['hdl-longevity-v2']['callback'] ) && '' !== $erasers['hdl-longevity-v2']['eraser_friendly_name'] );

echo "── E1. find one person ──\n";
$k = HDLV2_Privacy_Eraser::find( '  ALICE@example.test ' );
ok( 'E1.1 by user: the account and both records, across two practitioners', 10 === $k['user'] && array( $a['pid'], $a['pid2'] ) == array_map( 'intval', $k['progress'] ) );
ok( 'E1.2 the lead, matched case- and space-blind', array( $a['lid'] ) == array_map( 'intval', $k['leads'] ) );
ok( 'E1.3 V1 hashes: email and salted user hash', hash( 'sha256', 'alice@example.test' ) === $k['email_hash'] && hash( 'sha256', 'alice@example.test' . 'v1-salt' ) === $k['user_hash'] );
ok( 'E1.4 practitioners collected (V2 records, lead, ticket and V1 link)', array( PRAC_A, PRAC_B, 7 ) == $k['practitioners'] );
$kc = HDLV2_Privacy_Eraser::find( 'carol@example.test' );
ok( 'E1.5 no account: found by the email-only lead and records', 0 === $kc['user'] && 1 === count( $kc['leads'] ) && 2 === count( $kc['progress'] ) );
$kx = HDLV2_Privacy_Eraser::find( '' );
ok( 'E1.6 an empty or invalid address finds nothing (never matches the blank rows)', ! $kx['progress'] && ! $kx['leads'] && ! $kx['user'] && ! HDLV2_Privacy_Eraser::find( 'not-an-email' )['progress'] );

echo "── E5. a practitioner's or administrator's address ──\n";
$snap = hdl_erase_dump( $db, $tables );
foreach ( array( 'prac@example.test' => 'practitioner', 'BOSS@example.test' => 'administrator' ) as $addr => $who ) {
    $r = hdl_erase_all( $addr );
    ok( "E5 $who: refused at once, retained, the hand-handling message", 1 === count( $r ) && $r[0]['done'] && ! $r[0]['items_removed'] && $r[0]['items_retained'] && false !== strpos( implode( ' ', $r[0]['messages'] ), 'handled by hand' ) );
}
ok( 'E5.3 nothing touched, no account deleted', $snap == hdl_erase_dump( $db, $tables ) && ! $GLOBALS['deleted_users'] );

echo "── rollback: a failed step on the records page changes nothing on it ──\n";
$db->fail_on = 'wp_hdlv2_form_progress';
$r = HDLV2_Privacy_Eraser::erase( 'alice@example.test', 2 );
$db->fail_on = '';
ok( 'R.1 answered as an error the Tools screen shows', is_wp_error( $r ) && false === strpos( $r->get_error_message(), '@' ) );
ok( 'R.2 every V2 row of the page is back', $snap == hdl_erase_dump( $db, $tables ) );

echo "── E2/E3/E9/E10. erase Alice ──\n";
// E3: one path points outside the private folder; one is absolute and outside. Both stay.
$outside = HDLV2_PRIVATE_DIR . '-outside.txt';
file_put_contents( $outside, 'keep me' );
$db->insert( 'wp_hdlv2_reports', array( 'client_user_id' => 10, 'form_progress_id' => $a['pid'], 'pdf_stored_path' => '../../' . basename( $outside ), 'report_type' => 'draft' ) );
$db->insert( 'wp_hdlv2_reports', array( 'client_user_id' => 10, 'form_progress_id' => $a['pid'], 'pdf_stored_path' => $outside, 'report_type' => 'draft' ) );
$answers = hdl_erase_all( 'Alice@Example.test' );
$msgs    = messages_of( $answers );
ok( 'E2.1 four pages, the last done', 4 === count( $answers ) && $answers[3]['done'] && ! $answers[0]['done'] && ! $answers[2]['done'] );
ok( 'E2.2 no row of Alice left in any store (payment rows apart): ' . implode( ', ', rows_left( $db, $tables, $a ) ), ! rows_left( $db, $tables, $a ) );
ok( 'E2.3 her own timeline: links, the ticket she sent and her pack are gone', ! $db->get_var( "SELECT COUNT(*) FROM wp_hdlv2_timeline_links WHERE id = {$a['own_link']}" ) && ! $db->get_var( "SELECT COUNT(*) FROM wp_hdlv2_widget_invites WHERE external_ref = 'tl:{$a['own_link']}'" ) );
ok( 'E2.4 her eight jobs are gone (record, consultation, report, plan, user and token keyed)', 8 === count( $a_jobs ) && ! $db->get_var( 'SELECT COUNT(*) FROM wp_hdlv2_jobs WHERE id IN (' . implode( ',', $a_jobs ) . ')' ) );
$gone = array_filter( files_of( $a, 'alice' ), 'file_exists' );
ok( 'E2.5 her files are gone: ' . implode( ', ', array_map( 'basename', $gone ) ), ! $gone );
ok( 'E2.6 her sign-in account is deleted', array( 10 ) === $GLOBALS['deleted_users'] );
ok( 'E2.7 Bob and the blank rows untouched, row for row', still_there( $before_b, hdl_erase_dump( $db, $tables ) ) );
ok( 'E2.8 Bob\'s files, and his recording whose reference equals Alice\'s consultation id, are there', ! array_filter( files_of( $b, 'bob' ), function ( $f ) { return ! file_exists( $f ); } ) && file_exists( $bob_collision_audio ) );
ok( 'E3.1 both paths outside the private folder were left alone', 'keep me' === file_get_contents( $outside ) );
ok( 'E3.2 and said so, as retained', retained_any( $answers ) && preg_grep( '/outside the private folder/', $msgs ) );
ok( 'E9.1 the payment row is kept', 1 === (int) $db->get_var( 'SELECT COUNT(*) FROM wp_hdl_credit_purchases WHERE user_id = 10' ) );
ok( 'E9.2 and reported as kept', (bool) preg_grep( '/payment record/i', $msgs ) );
ok( 'E10.1 counts per store in the messages, no address or name', (bool) preg_grep( '/hdlv2_form_progress 2/', $msgs ) && ! preg_grep( '/@|alice|Name /i', $msgs ) );
$log = (string) @file_get_contents( $GLOBALS['log_file'] );
ok( 'E10.2 nothing with an address or name in the error log', ! preg_match( '/@|alice|Name /i', $log ) );

echo "── E4. a second run ──\n";
$snap2   = hdl_erase_dump( $db, $tables );
$answers = hdl_erase_all( 'alice@example.test' );
ok( 'E4.1 removes nothing and reports done', ! removed_any( $answers ) && end( $answers )['done'] && $snap2 == hdl_erase_dump( $db, $tables ) );

echo "── no account: Carol ──\n";
$answers = hdl_erase_all( 'carol@example.test' );
ok( 'N.1 Carol\'s rows and files are gone', ! rows_left( $db, $tables, $c ) && ! array_filter( files_of( $c, 'carol' ), 'file_exists' ) && removed_any( $answers ) );
ok( 'N.2 Bob still untouched', still_there( $before_b, hdl_erase_dump( $db, $tables ) ) );

echo "── an account with another role ──\n";
$db->insert( 'wp_hdlv2_form_progress', array( 'client_user_id' => 30, 'practitioner_user_id' => PRAC_A, 'client_email' => 'erin@example.test', 'token' => 'erin', 'stage1_data' => '{}' ) );
$answers = hdl_erase_all( 'erin@example.test' );
ok( 'O.1 her data goes, the account stays and is reported as kept', ! $db->get_var( "SELECT COUNT(*) FROM wp_hdlv2_form_progress WHERE token = 'erin'" ) && isset( $GLOBALS['users'][30] ) && retained_any( $answers ) );

@unlink( $outside );
done_report();

/** Every row of $before is still in $after, byte for byte. */
function still_there( $before, $after ) {
    foreach ( $before as $t => $rows ) {
        foreach ( $rows as $r ) { if ( ! in_array( $r, $after[ $t ], false ) ) { echo "    missing or changed: $t #{$r->id}\n"; return false; } }
    }
    return true;
}

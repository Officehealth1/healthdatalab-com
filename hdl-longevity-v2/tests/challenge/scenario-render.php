<?php
/**
 * 30-day challenge — the dashboard section: grid, today's card, video or
 * "Video coming", the tick form, the upsell card. Against the REAL
 * HDLV2_Challenge::render_section() and the REAL HDLV2_Offer.
 *
 * Run:  php scenario-render.php   (self-asserting, exit 0/1)
 */
require __DIR__ . '/stubs.php';

// v0.48.27 — a done tile is a <button> inside its <li>; open and locked tiles are the <li>.
function cells( $h, $state ) { return preg_match_all( '/class="cd30-day is-' . $state . '"/', $h ); }
function between( $h, $from, $to ) { $a = strpos( $h, $from ); $b = false === $a ? false : strpos( $h, $to, $a ); return false === $b ? '' : substr( $h, $a, $b - $a ); }
$today     = gmdate( 'Y-m-d' );
$yesterday = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );

echo "── R1. two days done, day 3 open, three videos ──\n";
$w = fresh();
enrol_client( 5 );
$w->seed( 5, 2, $yesterday );
$GLOBALS['options']['hdlv2_challenge_videos'] = array(
    array( 'day' => 1, 'title' => 'Walk', 'youtube_id' => 'aaaaaaaaaaa' ),
    array( 'day' => 2, 'title' => 'Breathe', 'youtube_id' => 'bbbbbbbbbbb' ),
    array( 'day' => 3, 'title' => '<b>x</b> Move', 'youtube_id' => 'abcDEF12345' ),
);
$h = HDLV2_Challenge::render_section( 5, false );
check( 'R1.1 the section: id challenge, labelled by its heading "Your 30 days"', false !== strpos( $h, '<section id="challenge" class="cd30" aria-labelledby="cd30-h">' ) && false !== strpos( $h, '<h2 id="cd30-h" tabindex="-1">Your 30 days</h2>' ) );
check( 'R1.2 30 cells: 2 done, 1 open, 27 locked', 30 === preg_match_all( '/class="cd30-day /', $h ) && 2 === cells( $h, 'done' ) && 1 === cells( $h, 'open' ) && 27 === cells( $h, 'locked' ) );
check( 'R1.3 the header line "Day 3 of 30 · 2 done" and the streak "2 days in a row"', false !== strpos( $h, 'Day 3 of 30 · 2 done' ) && false !== strpos( $h, '2 days in a row' ) );
check( 'R1.4 one form, for day 3, labelled textarea of 500, the Done button', 1 === substr_count( $h, '<form' ) && false !== strpos( $h, '<form class="cd30-tick" data-day="3"' )
    && false !== strpos( $h, '<label for="cd30-note">How did it go? (optional)</label>' ) && false !== strpos( $h, '<textarea id="cd30-note" name="note" maxlength="500" rows="3"></textarea>' )
    && false !== strpos( $h, '<button type="submit" class="cd30-done">Done for today</button>' ) );
check( 'R1.5 day 3 video: youtube-nocookie iframe with a title', false !== strpos( $h, '<iframe src="https://www.youtube-nocookie.com/embed/abcDEF12345"' ) && 1 === preg_match( '/<iframe [^>]*title="&lt;b&gt;x&lt;\/b&gt; Move"/', $h ) );
check( 'R1.6 the day title is escaped, never raw HTML', false === strpos( $h, '<b>x</b>' ) && false !== strpos( $h, '&lt;b&gt;x&lt;/b&gt; Move' ) );
check( 'R1.7 done cells show the date in words', 1 <= substr_count( $h, gmdate( 'j M', strtotime( $yesterday . ' UTC' ) ) ) );
check( 'R1.8 locked cells carry aria-disabled and no form', 27 === substr_count( $h, 'aria-disabled="true"' ) );
check( 'R1.9 only one video on the page (today\'s)', 1 === substr_count( $h, '<iframe' ) );
check( 'R1.10 a polite live region for "Day N saved"', false !== strpos( $h, 'aria-live="polite"' ) );

echo "── R2. the upsell card ──\n";
check( 'R2.1 no Full Report → the card: hook, price line, button to the offer url with the code', false !== strpos( $h, 'class="cd30-offer"' )
    && false !== strpos( $h, esc_html( HDLV2_Offer::DEFAULTS['hook'] ) ) && false !== strpos( $h, 'Today $27 · $129 before' )
    && false !== strpos( $h, 'href="https://altituding.com/full-report?code=YOURCODE"' ) && false !== strpos( $h, 'Go deeper with the Full Report' ) );
$GLOBALS['options']['hdlv2_stage1_offer'] = array( 'code' => 'ALTITUDE27', 'full_today_price' => '29', 'hook' => 'What is ageing you fastest?' );
$h2 = HDLV2_Challenge::render_section( 5, false );
check( 'R2.2 the figures and the code come from hdlv2_stage1_offer (no second copy)', false !== strpos( $h2, 'Today $29 · $129 before' ) && false !== strpos( $h2, '?code=ALTITUDE27' ) && false !== strpos( $h2, 'What is ageing you fastest?' ) );
check( 'R2.3 has the Full Report → no card', false === strpos( HDLV2_Challenge::render_section( 5, true ), 'cd30-offer' ) );
enrol_client( 8, 206 );
check( 'R2.4 another practitioner\'s client (the offer is not theirs) → no card', false === strpos( HDLV2_Challenge::render_section( 8, false ), 'cd30-offer' ) );

echo "── R3. today is done: nothing to tick ──\n";
$w = fresh();
enrol_client( 5 );
$w->seed( 5, 3, $today );
$h = HDLV2_Challenge::render_section( 5, false );
check( 'R3.1 no form, no open cell, 3 done', false === strpos( $h, '<form' ) && 0 === cells( $h, 'open' ) && 3 === cells( $h, 'done' ) );
// v0.48.24 (F4) — the line carries the real opening instant; the JS says it in the buyer's clock.
$opens = gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ) . 'T00:00:00Z';
check( 'R3.2 the next line carries the opening instant (ISO, UTC) and says "after midnight UTC" without JS', false !== strpos( $h, '<p class="cd30-next" data-next-opens-at="' . $opens . '" data-day="3">Day 3 done. Day 4 opens after midnight UTC.</p>' ) );

echo "── R4. the open day has no video yet ──\n";
$w = fresh();
enrol_client( 5 );
$w->seed( 5, 3, $yesterday );
$GLOBALS['options']['hdlv2_challenge_videos'] = array( array( 'day' => 1, 'title' => 'Walk', 'youtube_id' => 'aaaaaaaaaaa' ) );
$h = HDLV2_Challenge::render_section( 5, false );
check( 'R4.1 day 4: "Video coming" card, no iframe, the tick still works', false !== strpos( $h, '<div class="cd30-video-coming">Video coming</div>' ) && false === strpos( $h, '<iframe' ) && false !== strpos( $h, 'data-day="4"' ) );
check( 'R4.2 a day with no title is called "Day 4"', false !== strpos( $h, '<h3 class="cd30-today-title">Day 4</h3>' ) );
check( 'R4.3 no streak line below 2 days in a row … here 3', false !== strpos( $h, '3 days in a row' ) );

echo "── R5. all 30 done ──\n";
$w = fresh();
enrol_client( 5 );
$w->seed( 5, 30, $yesterday );
$GLOBALS['options']['hdlv2_challenge_videos'] = array( array( 'day' => 30, 'title' => 'Well done', 'youtube_id' => 'zzzzzzzzzzz' ) );
$h = HDLV2_Challenge::render_section( 5, false );
check( 'R5.1 "You did all 30 days." and the day-30 video stays', false !== strpos( $h, 'You did all 30 days.' ) && false !== strpos( $h, 'embed/zzzzzzzzzzz' ) && false === strpos( $h, '<form' ) && 30 === cells( $h, 'done' ) );

echo "── R6. the videos setting ──\n";
$GLOBALS['options']['hdlv2_challenge_videos'] = json_encode( array(
    array( 'day' => 2, 'title' => '  Stretch  ', 'youtube_id' => 'dQw4w9WgXcQ' ),
    array( 'day' => 31, 'title' => 'x', 'youtube_id' => 'dQw4w9WgXcQ' ),
    array( 'day' => 3, 'title' => 'bad id', 'youtube_id' => 'short' ),
    array( 'day' => 4, 'title' => 'bad id', 'youtube_id' => 'abc"onload=' ),
    array( 'day' => 5, 'title' => str_repeat( 'a', 200 ), 'youtube_id' => 'eeeeeeeeeee' ),
    'junk',
) );
$v = HDLV2_Challenge::videos();
check( 'R6.1 JSON text is read; only valid entries kept, keyed by day', array( 2, 5 ) === array_keys( $v ) && 'Stretch' === $v[2]['title'] && 'dQw4w9WgXcQ' === $v[2]['youtube_id'] );
check( 'R6.2 a title is cut to 120 characters', 120 === mb_strlen( $v[5]['title'] ) );
$GLOBALS['options']['hdlv2_challenge_videos'] = 'not json';
check( 'R6.3 garbage → no videos', array() === HDLV2_Challenge::videos() );
unset( $GLOBALS['options']['hdlv2_challenge_videos'] );
check( 'R6.4 absent → no videos', array() === HDLV2_Challenge::videos() );

echo "── R7. not enrolled ──\n";
fresh();
check( 'R7.1 not enrolled → empty string', '' === HDLV2_Challenge::render_section( 5, false ) );

echo "── R8. the CSS and JS this section needs ──\n";
$css = file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/hdlv2-client-dashboard.css' );
$js  = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/hdlv2-client-dashboard.js' );
check( 'R8.1 cd30 rules live in the base dashboard CSS', false !== strpos( $css, '.cd30-grid' ) && false !== strpos( $css, '.cd30-day.is-open' ) );
check( 'R8.2 no accent rails in the cd30 rules', 0 === preg_match( '/\.cd30[^{]*\{[^}]*border-left/', $css ) && 0 === preg_match( '/\.cd30[^{]*::before/', $css ) );
check( 'R8.3 phone: 5 cells a row, 44 px targets', false !== strpos( $css, 'repeat(5, minmax(0, 1fr))' ) && false !== strpos( $css, 'min-height: 44px' ) );
check( 'R8.4 JS posts to /challenge/tick with the REST nonce, never a native dialog', false !== strpos( $js, "'/challenge/tick'" ) && false !== strpos( $js, "'X-WP-Nonce'" ) && 0 === preg_match( '/\b(alert|confirm|prompt)\(/', $js ) );
check( 'R8.5 #challenge opens the tab on the full dashboard', false !== strpos( $js, 'replace(/^#(tab-)?/, \'\')' ) );
check( 'R8.8 JS rewrites the next line from data-next-opens-at in the browser\'s clock, at load and after each save', false !== strpos( $js, "'.cd30-next[data-next-opens-at]'" ) && false !== strpos( $js, "toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })" )
    && 2 === substr_count( $js, 'showOpensAt(' ) - 1 );
check( 'R8.9 a timed-out session says so: the nonce error gets its own sentence', false !== strpos( $js, "'rest_cookie_invalid_nonce'" ) && false !== strpos( $js, 'Your session timed out. Copy your note, reload the page, then tick again.' ) );
$widget = file_get_contents( dirname( __DIR__, 2 ) . '/widget/hdl-lead-magnet.js' );
check( 'R8.6 widget: a challenge link gets the public result page (no Full Report promise)', false !== strpos( $widget, "invite.source !== 'paid_stage1' && invite.source !== 'challenge'" ) );
check( 'R8.7 widget: after a challenge Stage 1 is saved, a button to the 30 days', false !== strpos( $widget, "/my-dashboard/#challenge" ) && false !== strpos( $widget, 'Start your 30 days' ) );

echo "── R9. the dashboard around the section: a buyer whose Stage 1 is a pending lead (v0.48.26) ──\n";
// The REAL HDLV2_Client_Dashboard::render_empty_state(); the script is taken as enqueued already.
define( 'HDLV2_PLUGIN_URL', 'https://stby.test/wp-content/plugins/hdl-longevity-v2/' );
define( 'HDLV2_VERSION', 'test' );
function wp_script_is( $h, $list ) { return true; }
function current_time( $f, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
require_once dirname( __DIR__, 2 ) . '/includes/sprint-4/class-hdlv2-message-thread-view.php';
require_once dirname( __DIR__, 2 ) . '/includes/sprint-4/class-hdlv2-client-dashboard.php';
$empty = new ReflectionMethod( 'HDLV2_Client_Dashboard', 'render_empty_state' );
$empty->setAccessible( true );
$dash  = HDLV2_Client_Dashboard::get_instance();
$sub_buyer   = 'Your 30 days are below. Tick each day when it is done.';
$sub_waiting = 'Your practitioner is reviewing your Stage 1 result. They&rsquo;ll email you when the next step is ready.';
$card        = 'Step 1 done &mdash; waiting on your practitioner';

$w = fresh();
enrol_client( 5 );
$GLOBALS['users'][5] = 'sam@example.com';
$w->leads[] = '122|sam@example.com';
$h = $empty->invoke( $dash, 5, 'brand-new' );
check( 'R9.1 a buyer with a pending lead: stage1-done, the gauge title stays, the subline points at the 30 days', false !== strpos( $h, 'data-state="stage1-done"' )
    && false !== strpos( $h, 'Hi Sam &mdash; your gauge is in.' ) && false !== strpos( $h, '<p class="cd-hero-sub">' . $sub_buyer . '</p>' ) && false === strpos( $h, $sub_waiting ) );
check( 'R9.2 no "waiting on your practitioner" card; the 30 days and "How the assessment unfolds" stay', false === stripos( $h, 'waiting on your practitioner' ) && false === strpos( $h, 'cd-paths' )
    && false !== strpos( $h, '<section id="challenge"' ) && false !== strpos( $h, 'How the assessment unfolds' ) );

fresh();
$GLOBALS['users'][6] = 'kim@example.com';
$h = $empty->invoke( $dash, 6, 'stage1-done' );
check( 'R9.3 a plain stage1-done client (no challenge) is unchanged: the waiting subline and the card', false !== strpos( $h, '<p class="cd-hero-sub">' . $sub_waiting . '</p>' )
    && false !== strpos( $h, $card ) && false === strpos( $h, $sub_buyer ) );

$w = fresh();
enrol_client( 5 );
$GLOBALS['users'][5] = 'sam@example.com';
$w->leads[] = '122|sam@example.com';
$w->rejected[] = '122|sam@example.com';
$h = $empty->invoke( $dash, 5, 'brand-new' );
check( 'R9.4 a buyer with no Stage 1 on file stays brand-new: "Take the quick gauge", no 30-days subline', false !== strpos( $h, 'data-state="brand-new"' )
    && false !== strpos( $h, 'Take the quick gauge' ) && false === strpos( $h, $sub_buyer ) );

echo "── R10. what each day is: title, text, the day view (v0.48.27) ──\n";
$w = fresh();
enrol_client( 5 );
$w->seed( 5, 2, $yesterday );
$w->rows[5][1]->note = "Short on time </script> & tired\nbut did it";
$GLOBALS['options']['hdlv2_challenge_videos'] = array(
    array( 'day' => 1, 'title' => 'Walk', 'text' => '  Walk ten minutes.  ', 'youtube_id' => 'aaaaaaaaaaa' ),
    array( 'day' => 2, 'title' => 'Walk and breathe', 'text' => "Breathe in four.\nOut six.", 'youtube_id' => '' ),
    array( 'day' => 3, 'title' => 'Move', 'text' => "Stand up <b>every</b> hour.\nTwice.", 'youtube_id' => 'abcDEF12345' ),
    array( 'day' => 4, 'title' => 'Locked day', 'text' => 'Not yet yours', 'youtube_id' => 'ddddddddddd' ),
    array( 'day' => 5, 'title' => 'Long', 'text' => str_repeat( 'b', 700 ) ),
    array( 'day' => 6, 'title' => 'No text', 'youtube_id' => 'fffffffffff' ),
);
$v = HDLV2_Challenge::videos();
check( 'R10.1 each day\'s text: trimmed, tags stripped, line breaks kept, 600 max; missing text is ""; a day with no video yet is kept', 'Walk ten minutes.' === $v[1]['text']
    && "Breathe in four.\nOut six." === $v[2]['text'] && "Stand up every hour.\nTwice." === $v[3]['text'] && 600 === mb_strlen( $v[5]['text'] ) && '' === $v[6]['text']
    && '' === $v[2]['youtube_id'] && '' === $v[5]['youtube_id'] && array( 1, 2, 3, 4, 5, 6 ) === array_keys( $v ) );
$h    = HDLV2_Challenge::render_section( 5, false );
$when = gmdate( 'j M', strtotime( $yesterday . ' UTC' ) );
check( 'R10.2 done and open tiles name the day (aria-label + .cd30-title); a locked day\'s words are nowhere', false !== strpos( $h, 'aria-label="Day 2, Walk and breathe, done ' . $when . '"' )
    && false !== strpos( $h, 'aria-label="Day 3, Move, today"' ) && false !== strpos( $h, '<span class="cd30-title">Walk and breathe</span>' ) && false !== strpos( $h, '<span class="cd30-title">Move</span>' )
    && false === strpos( $h, 'Locked day' ) && false === strpos( $h, 'Not yet yours' ) && false === strpos( $h, 'ddddddddddd' ) );
check( 'R10.3 a done tile is a button with its day, the open tile an li with aria-current, locked tiles li aria-disabled; 30 tiles', 2 === preg_match_all( '/<li class="cd30-cell"><button type="button" class="cd30-day is-done" data-day="[12]"/', $h )
    && 1 === preg_match_all( '/<li class="cd30-day is-open" aria-current="step"/', $h ) && 27 === preg_match_all( '/<li class="cd30-day is-locked" aria-disabled="true"><span class="cd30-n">\d+<\/span><\/li>/', $h )
    && 30 === preg_match_all( '/class="cd30-day /', $h ) );
preg_match( '#<script type="application/json" class="cd30-days">(.*?)</script>#s', $h, $m );
$json = $m[1] ?? '';
$days = json_decode( $json, true );
check( 'R10.4 one JSON block inside .cd30-body: the done days and the open day only', 1 === substr_count( $h, 'class="cd30-days"' )
    && false !== strpos( between( $h, '<div class="cd30-body">', '<p class="cd30-live"' ), 'class="cd30-days"' ) && is_array( $days ) && array( 1, 2, 3 ) === array_keys( $days ) );
check( 'R10.4b each day: title, text, done_at (UTC) or null, the stored note, youtube_id only when paid and set', 'Walk and breathe' === $days[2]['title'] && "Breathe in four.\nOut six." === $days[2]['text']
    && $yesterday . ' 09:00:00' === $days[2]['done_at'] && null === $days[3]['done_at'] && null === $days[3]['note'] && "Short on time </script> & tired\nbut did it" === $days[1]['note']
    && 'aaaaaaaaaaa' === $days[1]['youtube_id'] && ! array_key_exists( 'youtube_id', $days[2] ) && 'abcDEF12345' === $days[3]['youtube_id'] );
check( 'R10.4c the note cannot leave the script: < and & are escaped in the JSON', '' !== $json && false === strpos( $json, '<' ) && false === strpos( $json, '&' )
    && false !== strpos( $json, trim( json_encode( '</script> & tired', JSON_HEX_TAG | JSON_HEX_AMP ), '"' ) ) );
$today = between( $h, '<div class="cd30-today">', '<div class="cd30-detail"' );
check( 'R10.5 today\'s panel: the title, then what to do with its line breaks', false !== strpos( $today, '<h3 class="cd30-today-title">Move</h3><p class="cd30-text">Stand up every hour.<br />' . "\n" . 'Twice.</p>' ) );
check( 'R10.6 the hidden day view after today\'s panel: title, text, video, when, note, Back to today', false !== strpos( $h, '<div class="cd30-detail" hidden><h3 class="cd30-today-title" tabindex="-1"></h3><p class="cd30-text"></p><div class="cd30-video"></div><p class="cd30-when"></p><blockquote class="cd30-note"></blockquote><button type="button" class="cd30-back">Back to today</button></div>' )
    && '' !== $today );
$w = fresh();
enrol_client( 5 );
$w->seed( 5, 5, $yesterday );
$GLOBALS['options']['hdlv2_challenge_videos'] = array( array( 'day' => 6, 'title' => 'No text', 'youtube_id' => 'fffffffffff' ) );
check( 'R10.5b a day with no text prints no text line', false === strpos( between( HDLV2_Challenge::render_section( 5, false ), '<div class="cd30-today">', '<div class="cd30-detail"' ), 'cd30-text' ) );

echo "── R11. free: every client record gets the 30 days; the \$27 buys the videos (D6) ──\n";
$days_opt = array( array( 'day' => 1, 'title' => 'Walk', 'text' => 'Walk ten minutes.', 'youtube_id' => 'aaaaaaaaaaa' ) );
$w = fresh();
$GLOBALS['state'] = 'stage1-done';
$w->practitioner_of[5] = 122;
$GLOBALS['options']['hdlv2_challenge_videos'] = $days_opt;
$h = HDLV2_Challenge::render_section( 5, true );
check( 'R11.1 a client record, not enrolled: day 1 open with its title and text, no video', false !== strpos( $h, '<section id="challenge"' ) && false !== strpos( $h, 'Day 1 of 30 · 0 done' )
    && false !== strpos( $h, '<h3 class="cd30-today-title">Walk</h3><p class="cd30-text">Walk ten minutes.</p>' ) && false === strpos( $h, '<iframe' ) && false === strpos( $h, 'Video coming' )
    && false !== strpos( $h, '<form class="cd30-tick" data-day="1"' ) && false === strpos( $h, 'aaaaaaaaaaa' ) );
check( 'R11.1b …the videos card: hook, "Today $27", button to altituding.com/challenge', false !== strpos( $h, '<aside class="cd30-videos"><h3>A video for every one of your 30 days</h3><p>Today $27</p><a class="cd30-offer-btn" href="https://altituding.com/challenge">Get the 30 videos</a></aside>' ) );
check( 'R11.1c …and rendering writes nothing (enrolment starts at the first tick, D7)', ! isset( $GLOBALS['usermeta'][5] ) && ! $w->rows );
check( 'R11.1d a client record already opens the Full Report form: no Full Report card (rule unchanged)', false === strpos( $h, 'cd30-offer"' ) );
$GLOBALS['state'] = 'brand-new';
check( 'R11.2 no client record and not enrolled → nothing', '' === HDLV2_Challenge::render_section( 5, false ) );
$w = fresh();
enrol_client( 5 );
$GLOBALS['options']['hdlv2_challenge_videos'] = $days_opt;
$h = HDLV2_Challenge::render_section( 5, true );
check( 'R11.3 a paid enrolment: the video, no videos card', false !== strpos( $h, 'embed/aaaaaaaaaaa' ) && false === strpos( $h, 'cd30-videos' ) );
$w = fresh();
enrol_free_client( 5, 206 );
check( 'R11.4 a free enrolment under another practitioner: the 30 days, no videos card', false !== strpos( $h = HDLV2_Challenge::render_section( 5, true ), '<section id="challenge"' ) && false === strpos( $h, 'cd30-videos' ) );
enrol_free_client( 5, 122 );
$GLOBALS['options']['hdlv2_stage1_offer'] = array( 'code' => 'X1', 'challenge_price' => '29', 'challenge_hook' => 'Thirty short videos', 'challenge_url' => 'https://altituding.com/thirty' );
$h = HDLV2_Challenge::render_section( 5, true );
check( 'R11.5 the price, hook and url come from hdlv2_stage1_offer (no second copy)', false !== strpos( $h, '<h3>Thirty short videos</h3><p>Today $29</p><a class="cd30-offer-btn" href="https://altituding.com/thirty">' ) );
$GLOBALS['options']['hdlv2_stage1_offer'] = array( 'challenge_price' => 'free', 'challenge_url' => 'javascript:alert(1)' );
$o = HDLV2_Offer::get();
$offer_src = file_get_contents( dirname( __DIR__, 2 ) . '/includes/sprint-1/class-hdlv2-offer.php' );
check( 'R11.6 a bad price falls back to the default; a missing hook is the default; challenge_url is cleaned as a url', '27' === $o['challenge_price'] && 'A video for every one of your 30 days' === $o['challenge_hook']
    && 1 === preg_match( "/'url' === \\\$k \\|\\| 'video_url' === \\\$k \\|\\| 'challenge_url' === \\\$k/", $offer_src ) );

echo "── R12. all 30 done: every day in the JSON, day 30 in the panel ──\n";
$w = fresh();
enrol_client( 5 );
$w->seed( 5, 30, $yesterday );
$GLOBALS['options']['hdlv2_challenge_videos'] = array( array( 'day' => 30, 'title' => 'Well done', 'text' => 'Look back at day 1.', 'youtube_id' => 'zzzzzzzzzzz' ) );
$h = HDLV2_Challenge::render_section( 5, false );
preg_match( '#<script type="application/json" class="cd30-days">(.*?)</script>#s', $h, $m );
$days = json_decode( $m[1] ?? '', true );
check( 'R12.1 30 days in the JSON; the panel shows day 30; "You did all 30 days." stays', is_array( $days ) && 30 === count( $days ) && 'zzzzzzzzzzz' === $days[30]['youtube_id']
    && false !== strpos( $h, '<h3 class="cd30-today-title">Well done</h3><p class="cd30-text">Look back at day 1.</p>' ) && false !== strpos( $h, 'You did all 30 days.' ) && 30 === cells( $h, 'done' ) );

echo "── R13. the dashboard shows the free 30 days to a client record (v0.48.27) ──\n";
$w = fresh();
$GLOBALS['users'][6] = 'kim@example.com';
$w->practitioner_of[6] = 122;
$GLOBALS['state'] = 'stage1-done';
$h = $empty->invoke( $dash, 6, 'stage1-done' );
check( 'R13.1 a stage1-done client with no enrolment: the 30 days and the videos card; the hero and the waiting card unchanged', false !== strpos( $h, '<section id="challenge"' ) && false !== strpos( $h, 'class="cd30-videos"' )
    && false !== strpos( $h, '<p class="cd-hero-sub">' . $sub_waiting . '</p>' ) && false !== strpos( $h, $card ) && ! isset( $GLOBALS['usermeta'][6] ) );

echo "── R14. the CSS and JS of the day view (v0.48.27) ──\n";
$css = file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/hdlv2-client-dashboard.css' );
$js  = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/hdlv2-client-dashboard.js' );
$cd30 = substr( $css, strpos( $css, '/* ── 30-day challenge' ) );
check( 'R14.1 a done tile opens its day: one delegated click on button.cd30-day.is-done, Back to today, focus moves', false !== strpos( $js, "closest('button.cd30-day.is-done')" ) && false !== strpos( $js, "closest('.cd30-back')" )
    && false !== strpos( $js, "JSON.parse(" ) && false !== strpos( $js, "'aria-pressed'" ) && 2 <= substr_count( $js, '.focus()' ) - 1 );
check( 'R14.2 the video is built only from an 11-character id, through createElement, never innerHTML', false !== strpos( $js, "document.createElement('iframe')" ) && false === strpos( between( $js, 'function challengeDayView(', "\n  }\n" ), 'innerHTML' ) );
check( 'R14.3 the note box: soft fill, no rail (Divi gives blockquote a left border, so the border is cleared)', 1 === preg_match( '/\.cd30-note \{[^}]*border: 0;[^}]*background: #f8f9fb;/', $cd30 ) && 0 === preg_match( '/border-left/', $cd30 ) );
check( 'R14.4 the tile title: two lines, hidden on phones', 1 === preg_match( '/\.cd30-title \{[^}]*-webkit-line-clamp: 2;/', $cd30 ) && 1 === preg_match( '/@media \(max-width: 640px\) \{[^@]*\.cd30-title \{ display: none; \}/', $cd30 ) );
check( 'R14.5 the done tile is a real button: focus ring, pressed state, the videos card styled as the offer', 1 === preg_match( '/button\.cd30-day:focus-visible/', $cd30 ) && 1 === preg_match( '/button\.cd30-day\[aria-pressed="true"\]/', $cd30 )
    && false !== strpos( $cd30, '.cd30-videos' ) && false === strpos( $cd30, '.cd30-date' ) );

echo "── R15. a buyer who did all 30 days: the hero says so (F12) ──\n";
$w = fresh();
enrol_client( 5 );
$GLOBALS['users'][5] = 'sam@example.com';
$w->leads[] = '122|sam@example.com';
$w->seed( 5, 30, $yesterday );
$h = $empty->invoke( $dash, 5, 'brand-new' );
check( 'R15.1 all 30 done: "You did all 30 days. Your record is below."', false !== strpos( $h, '<p class="cd-hero-sub">You did all 30 days. Your record is below.</p>' ) && false === strpos( $h, $sub_buyer ) );
$w->seed( 5, 29, $yesterday );
unset( $w->rows[5][30] );
check( 'R15.2 29 done: the buyer line stays', false !== strpos( $empty->invoke( $dash, 5, 'brand-new' ), '<p class="cd-hero-sub">' . $sub_buyer . '</p>' ) );

done();

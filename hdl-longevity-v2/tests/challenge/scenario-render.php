<?php
/**
 * 30-day challenge — the dashboard section: grid, today's card, video or
 * "Video coming", the tick form, the upsell card. Against the REAL
 * HDLV2_Challenge::render_section() and the REAL HDLV2_Offer.
 *
 * Run:  php scenario-render.php   (self-asserting, exit 0/1)
 */
require __DIR__ . '/stubs.php';

function cells( $h, $state ) { return preg_match_all( '/<li class="cd30-day is-' . $state . '"/', $h ); }
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
check( 'R1.2 30 cells: 2 done, 1 open, 27 locked', 30 === preg_match_all( '/<li class="cd30-day /', $h ) && 2 === cells( $h, 'done' ) && 1 === cells( $h, 'open' ) && 27 === cells( $h, 'locked' ) );
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
check( 'R3.2 "Day 4 opens tomorrow"', false !== strpos( $h, 'Day 4 opens tomorrow' ) );

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

done();

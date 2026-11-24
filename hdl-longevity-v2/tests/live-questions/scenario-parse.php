<?php
/**
 * Plan 14 part B, B1: the model's answer read into questions, each with why, and a red flag.
 *   A.1 "Q: … | Why: …" lines → question and why, in order.
 *   A.2 a "RED FLAG: " line is kept whole and given first, wherever it stands.
 *   A.3 at most five questions; the red flag is never dropped to make room.
 *   A.4 "Nothing to add yet." → no question, no error.
 *   A.5 list marks and bold (1. / - / **) round a line are taken off.
 *   A.6 a "Q:" line with no "| Why:" keeps its question with an empty why.
 *   A.7 an answer with none of the shapes → a plain error, and the error never carries the model's text.
 *   A.8 lines of other text between the questions are left out.
 * Run: php tests/live-questions/scenario-parse.php   Exit: 0 all pass, 1 any fail
 */
require __DIR__ . '/lq-world.php';
$have = class_exists( 'HDLV2_Live_Questions' ) && method_exists( 'HDLV2_Live_Questions', 'parse' );
check( 'HDLV2_Live_Questions::parse exists', $have );
if ( ! $have ) done();
$r = HDLV2_Live_Questions::parse( "Q: How many nights a week do you wake? | Why: she said she wakes at 3 am\nQ: What do you eat after 8 pm? | Why: late snacks" );
check( 'A.1 two questions, each with its why, in order', ! is_wp_error( $r ) && 2 === count( $r['questions'] ) && 'How many nights a week do you wake?' === $r['questions'][0]['question'] && 'she said she wakes at 3 am' === $r['questions'][0]['why'] && 'late snacks' === $r['questions'][1]['why'] && '' === $r['red_flag'] );
$r = HDLV2_Live_Questions::parse( "Q: Any pain on exertion? | Why: stairs\nRED FLAG: chest pain on the stairs twice this week. Advise her to see her GP today." );
check( 'A.2 the red flag is kept whole and given apart from the questions', ! is_wp_error( $r ) && 'chest pain on the stairs twice this week. Advise her to see her GP today.' === $r['red_flag'] && 1 === count( $r['questions'] ) );
$many = "RED FLAG: a new lump in the neck.\n";
for ( $i = 1; $i <= 7; $i++ ) $many .= "Q: Question $i? | Why: reason $i\n";
$r = HDLV2_Live_Questions::parse( $many );
check( 'A.3 five questions at most, the red flag kept', ! is_wp_error( $r ) && 5 === count( $r['questions'] ) && 'Question 5?' === $r['questions'][4]['question'] && 'a new lump in the neck.' === $r['red_flag'] );
$r = HDLV2_Live_Questions::parse( "Nothing to add yet." );
check( 'A.4 "Nothing to add yet." → no question, no error', ! is_wp_error( $r ) && array() === $r['questions'] && '' === $r['red_flag'] && true === $r['nothing'] );
$r = HDLV2_Live_Questions::parse( "1. **Q: Do you nap?** | Why: tired by 3 pm\n- Q: How much water? | Why: headaches" );
check( 'A.5 list marks and bold are taken off', ! is_wp_error( $r ) && 2 === count( $r['questions'] ) && 'Do you nap?' === $r['questions'][0]['question'] && 'tired by 3 pm' === $r['questions'][0]['why'] && 'How much water?' === $r['questions'][1]['question'] );
$r = HDLV2_Live_Questions::parse( "Q: How is your mood lately?" );
check( 'A.6 a question with no why keeps an empty why', ! is_wp_error( $r ) && 'How is your mood lately?' === $r['questions'][0]['question'] && '' === $r['questions'][0]['why'] );
$r = HDLV2_Live_Questions::parse( "Here are some thoughts about the client: <script>x</script> she seems tired." );
check( 'A.7 none of the shapes → a plain error without the model\'s text', is_wp_error( $r ) && false === strpos( $r->get_error_message(), 'thoughts' ) && false === strpos( $r->get_error_message(), 'script' ) );
$r = HDLV2_Live_Questions::parse( "Here are the questions:\nQ: Do you snore? | Why: wakes tired\nI hope this helps." );
check( 'A.8 other lines are left out', ! is_wp_error( $r ) && 1 === count( $r['questions'] ) && 'Do you snore?' === $r['questions'][0]['question'] );
$r = HDLV2_Live_Questions::parse( '' );
check( 'A.9 an empty answer → the plain error', is_wp_error( $r ) );
done();

<?php
/**
 * Snapshot of the AI prompt lines that name the Full Report. Recorded
 * approvals: 2026-11-17 "Yes, one run under $2 (Recommended)" for three lines;
 * 2026-11-18 "Change it too (Recommended)" for the narrative's opening pair.
 * A prompt changes only with a recorded approval; this fails the moment any
 * of these lines moves again, or the Final report loses its "no draft" line.
 *
 * The lines are compared as they stand in the PHP source, quoting included.
 *
 * Run:  php scenario-prompt-lines.php   (self-asserting, exit 0/1)
 */
$SRC  = (string) file_get_contents( __DIR__ . '/../../includes/sprint-2/class-hdlv2-ai-service.php' );
$PASS = 0; $FAIL = 0;
function check( $label, $cond ) {
    global $PASS, $FAIL;
    if ( $cond ) { $PASS++; echo "  PASS  $label\n"; } else { $FAIL++; echo "  FAIL  $label\n"; }
}
function once( $src, $line ) { return 1 === substr_count( $src, $line ); }

// generate_draft_report(): the user prompt's first line.
check( 'report prompt opens "a personalised full longevity report"',
    once( $SRC, '"Generate a personalised full longevity report.\n\n"' ) );

// generate_client_draft_narrative(): the user prompt's first line; the line that said the
// portal is shown before a consultation is gone (recorded approval, 2026-11-18).
check( 'narrative prompt opens "the interactive Full Report", no "BEFORE their practitioner consultation"',
    once( $SRC, '"You are writing the interactive Full Report for a longevity client.\n"' ) );

// generate_client_draft_narrative(): the capped opening, label and pointer.
check( 'opening teaser: label TEASER, no pointer to the consultation',
    once( $SRC, '? "TEASER. 2 sentences MAX, 220 characters MAX. Sentence 1: name the rate (X.XXx) + bio vs chrono age — the headline finding only. DO NOT enumerate scores, name specific metrics beyond the rate, list strengths/focus areas, or quote goals — that detail lives elsewhere in this JSON (radar_commentary, goals_linkage) and in the final report. Use <strong> for the rate number only."' ) );

// generate_client_draft_narrative(): the system line.
check( 'narrative system line: "a personalised interactive full report"',
    once( $SRC, "\$system = 'You are a longevity coach writing a personalised interactive full report. You ONLY reference the 21 whitelisted metrics in the input — you never invent clinical markers. You respect severity bands (2/5 is a focus area, not an emergency). You ground trajectory commentary in the actual rate + chart status. Return JSON only — no prose outside the JSON object.';" ) );

// generate_final_report(): unchanged, still strips any leftover word.
check( 'Final report prompt still says: Remove all "draft" language.',
    once( $SRC, 'Remove all "draft" language. ' ) );

foreach ( array( 'longevity draft report.', 'interactive DRAFT report', 'BEFORE their practitioner consultation', 'DRAFT TEASER', 'point to the consultation', 'interactive draft report.' ) as $old ) {
    check( "old words gone: $old", false === strpos( $SRC, $old ) );
}

echo "\nPASS=$PASS FAIL=$FAIL\n";
exit( $FAIL === 0 ? 0 : 1 );

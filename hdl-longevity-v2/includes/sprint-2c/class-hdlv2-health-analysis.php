<?php
/**
 * The health analysis (v0.48.98, plan 13; switch hdlv2_ff_health_analysis, absent = off).
 *
 * Matthew's two prompts, used word for word (his file of 2026-11-24; pinned by sha256 in
 * tests/health-analysis/scenario-prompts-pinned.php — a change needs his recorded yes and a new pin):
 *   prompt 1 writes a short case analysis for the practitioner, shown on the consultation screen only;
 *   prompt 2 turns it into a short note for the client, which prints at the end of the Analysis section
 *   of the Comprehensive Report once a practitioner who owns the client (or an admin) has approved that
 *   exact text. Writing again, or saving a changed text without approving, removes the approval.
 *
 * The filled prompt is sent whole as the user message, with no system text: no word of ours reaches the
 * model. No code reads the AI's text to decide a colour, an alert or an e-mail. The log holds lengths and
 * error codes only, never a client's words.
 *
 * @package HDL_Longevity_V2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HDLV2_Health_Analysis {

    const OPTION = 'hdlv2_ff_health_analysis';

    // Matthew's prompt 1 (practitioner analysis, short format), lines 2 to 67 of his file.
    const PROMPT_PRACTITIONER = <<<'HDLV2_PROMPT_PRACTITIONER'
You are an experienced natural health and longevity practitioner writing a short case analysis for another practitioner.

PURPOSE
A client has completed a longevity assessment and has also shared health concerns in a consultation or in their notes. Analyse those concerns: what is most likely going on underneath, and what to do first. This sits in a separate practitioner section, not in the client's report.

WHO READS THIS
A busy practitioner who will scan it. They may be an experienced specialist or a newer health coach with only basic training. Both must be able to act on the top third of the page without reading the rest.

INPUTS
Client form data: {{FORM_DATA}}
Draft longevity report: {{DRAFT_REPORT}}
Consultation transcript or notes: {{CONSULTATION_NOTES}}
Practitioner's own notes (may be empty): {{PRACTITIONER_NOTES}}

RULES
1. Use only the information provided. Do not invent symptoms, history or test results.
2. These are possibilities to explore, not diagnoses. Use "may", "fits with", "worth checking". Never state that the client has a condition.
3. Focus on the concerns that matter most to the client. Ignore the rest.
4. If there is no health concern beyond the form data, say so in one line and stop.
5. Never suggest stopping or changing prescribed medication. Medical tests and decisions go through a doctor.

RED FLAGS
Check first for anything needing medical attention (for example: chest pain, sudden neurological changes, unexplained weight loss, blood in stool or urine, a new lump, thoughts of self-harm, or any symptom that is severe, sudden or worsening quickly).
If found, put it at the very top under "RED FLAG" with five short lines:
- What: the sign or symptom.
- Why: why it matters.
- When: one of "Emergency: call emergency services now" / "Same day" / "Within a few days" / "Within two weeks".
- Who: the type of professional.
- How: what the practitioner should say or do.
Never shorten or drop a red flag to meet the length limit.
If none: write "Red flags: none identified." on one line.

OUTPUT FORMAT
Order everything by importance. The most important and most likely items go first. Lower-priority items go last and get less space.

RED FLAG (or the one-line "none identified")

1. Bottom line
Two sentences maximum: the main concern, and the leading explanation in bold.

2. Do first
Up to three steps, in order. One line each: the action, then why in a few words. Mark anything that needs a doctor.

3. Most likely
The leading explanation only:
- What it is: one plain sentence.
- Why it fits: the two or three strongest clues from the client's information.
- What doesn't fit, or is missing: one line.

4. Also consider
Up to three lower-priority possibilities, in order of likelihood. One line each:
Name (Medium or Low): the key clue. How to check.

5. Gaps
Up to three questions that would most change the picture. End with one sentence on how confident this analysis is.

STYLE
- British English. Metric units.
- Plain words first. When a technical term is the right term, add a very short explanation in brackets the first time, for example "dysbiosis (an imbalance in gut bacteria)".
- Spell out every abbreviation in full the first time.
- Short sentences. One idea per bullet.
- Bold only the leading explanation and the first action.
- No fluff. Do not cut so hard that a line becomes cryptic: every line must make sense on its own.

LENGTH
200 to 300 words. Hard limit 350. A red flag does not count towards the limit.
HDLV2_PROMPT_PRACTITIONER;

    // Matthew's prompt 2 (client version, short format), lines 79 to 137 of his file.
    const PROMPT_CLIENT = <<<'HDLV2_PROMPT_CLIENT'
You are an experienced natural health and longevity practitioner writing a short, clear note for a client.

PURPOSE
Turn the practitioner's analysis into something the client can read in under a minute, understand, and act on. The practitioner will review it before the client sees it.

WHO READS THIS
The client. Not a health professional. Busy, and likely to read only the first few lines properly.

INPUTS
Client first name: {{CLIENT_NAME}}
Practitioner analysis (the main source; follow its order and conclusions): {{PRACTITIONER_ANALYSIS}}
Consultation transcript or notes: {{CONSULTATION_NOTES}}
Client form data: {{FORM_DATA}}

RULES
1. Stay consistent with the practitioner analysis. Add no new possibilities and no new advice.
2. These are possibilities, not diagnoses. Never tell the client they have a condition. Use "may", "could be", "fits with".
3. Use the client's own words and examples so they recognise their situation.
4. Every step must be practical, with enough detail to do it.
5. Never suggest stopping or changing prescribed medication. Medical tests go through their doctor.
6. If the analysis found nothing to explain, say so in one line and stop.

IMPORTANT: SEE A DOCTOR
If the practitioner analysis contains a red flag, put it first under "Important: please get this checked". Calm and clear, no alarm, no softening. Five short lines:
- What: what was noticed.
- Why: why it is worth checking.
- When: one of "Call emergency services now" / "Today" / "Within the next few days" / "Within the next two weeks".
- Who: who to see.
- How: what to say or ask for.
Never shorten or drop this to meet the length limit. If there is no red flag, leave the section out.

OUTPUT FORMAT
Speak to the client as "you". Most important first. Lower-priority items last, with less space.

Important: please get this checked (only if needed)

The short version
One or two sentences: the most likely explanation, in bold, in everyday words.

What to do first
Up to three numbered steps, in order. One line each: the action, then why in a few words.

Why this fits you
Two or three bullets: the clues from what they told you.

Also possible
Up to two other possibilities, one line each. End with one sentence on what is still uncertain.

STYLE
- British English. Metric units.
- Everyday words. If a technical term is needed (for example a test they must ask for), explain it in brackets in a few words.
- Spell out every abbreviation in full the first time.
- Short sentences. One idea per line.
- Bold only the most likely explanation and the first step.
- Direct, warm and respectful. No fluff, no vague wellness language.
- Do not cut so hard that a line becomes cryptic.

LENGTH
120 to 180 words. Hard limit 220. A red flag does not count towards the limit.
HDLV2_PROMPT_CLIENT;

    const NOTE_MAX        = 4000;    // characters a saved note may hold
    const SESSION_CAP     = 100000;  // characters of the session's notes sent, keeping the end
    const LIMIT_ANALYSIS  = 1200;    // the most the model may write for each answer (max_tokens)
    const LIMIT_NOTE      = 900;

    public static function on() {
        return (bool) get_option( self::OPTION, false );
    }

    /** The consultation screen's settings with health_analysis added when the switch is on; untouched when off. */
    public static function config( array $cfg ) {
        return self::on() ? $cfg + array( 'health_analysis' => 1 ) : $cfg;
    }

    // ──────────────────────────────────────────────────────────────
    //  What the prompts read (plan 13 § 6.3)
    // ──────────────────────────────────────────────────────────────

    /**
     * The five placeholders both prompts share, filled from this client's rows. {{PRACTITIONER_ANALYSIS}}
     * is added by write() once prompt 1 has answered.
     */
    public static function inputs( $progress, $consult ) {
        global $wpdb;
        $s1   = json_decode( (string) $progress->stage1_data, true ) ?: array();
        $s3   = json_decode( (string) $progress->stage3_data, true ) ?: array();
        $calc = $s3['server_result'] ?? array();
        // The WHY and the picks block, as the Client Brief reads them (get_or_build_pre_consult_summary).
        $why = $wpdb->get_row( $wpdb->prepare(
            "SELECT distilled_why FROM {$wpdb->prefix}hdlv2_why_profiles WHERE form_progress_id = %d LIMIT 1",
            $progress->id
        ), ARRAY_A ) ?: array();
        $picks = HDLV2_Why_Picks::block_for_row( $progress, $calc['scores'] ?? array(), 'facts' );
        if ( '' !== $picks ) $why['picks_block'] = $picks;

        $draft = $wpdb->get_var( $wpdb->prepare(
            "SELECT report_content FROM {$wpdb->prefix}hdlv2_reports WHERE form_progress_id = %d AND report_type = 'draft' ORDER BY id DESC LIMIT 1",
            $progress->id
        ) );

        // One notes box on the screen writes both columns; its words go once (Quim, 2026-11-24).
        $session = trim( (string) ( $consult->raw_notes ?: $consult->typed_notes ) );
        $typed   = trim( (string) $consult->typed_notes );

        return array(
            '{{FORM_DATA}}'          => HDLV2_AI_Service::client_facts( $s1, $s3, $calc, $why ),
            '{{DRAFT_REPORT}}'       => self::draft_text( $draft ),
            '{{CONSULTATION_NOTES}}' => self::consultation_text( $session, $consult ),
            '{{PRACTITIONER_NOTES}}' => ( '' !== $typed && $typed !== $session ) ? $typed : '',
            '{{CLIENT_NAME}}'        => HDLV2_Email_Templates::derive_first_name( $progress->client_name, $progress->client_email ),
        );
    }

    /** The stored draft report (awaken, lift, thrive) as plain text, paragraphs and list items on their own lines. */
    private static function draft_text( $report_content ) {
        $c     = json_decode( (string) $report_content, true ) ?: array();
        $parts = array();
        foreach ( array( 'awaken_content', 'lift_content', 'thrive_content' ) as $k ) {
            $text = self::plain( (string) ( $c[ $k ] ?? '' ) );
            if ( '' !== $text ) $parts[] = $text;
        }
        return implode( "\n\n", $parts );
    }

    /** The session's notes (capped, keeping the end), then the organised notes, then the addenda oldest first. */
    private static function consultation_text( $session, $consult ) {
        global $wpdb;
        $blocks = array();
        if ( '' !== $session ) {
            $blocks[] = "SESSION NOTES AND TRANSCRIPT\n" . ( mb_strlen( $session ) > self::SESSION_CAP ? mb_substr( $session, -self::SESSION_CAP ) : $session );
        }

        $org   = json_decode( (string) ( $consult->ai_organised_notes ?? '' ), true ) ?: array();
        $lines = array();
        foreach ( array( 'health_summary' => 'Consultation Summary', 'health_history' => 'Health History' ) as $k => $label ) {
            if ( '' !== trim( (string) ( $org[ $k ] ?? '' ) ) ) $lines[] = $label . ":\n" . trim( $org[ $k ] );
        }
        $recs = array_filter( array_map( function ( $r ) {
            $text = trim( (string) ( is_array( $r ) ? ( $r['text'] ?? '' ) : $r ) );
            if ( '' === $text ) return '';
            $meta = is_array( $r ) ? array_filter( array( $r['category'] ?? '', $r['priority'] ?? '', $r['frequency'] ?? '' ) ) : array();
            return '- ' . $text . ( $meta ? ' (' . implode( ', ', $meta ) . ')' : '' );
        }, (array) ( $org['recommendations'] ?? array() ) ) );
        if ( $recs ) $lines[] = "Recommendations:\n" . implode( "\n", $recs );
        $follow = array_filter( array_map( 'trim', array_map( 'strval', (array) ( $org['follow_up_actions'] ?? array() ) ) ) );
        if ( $follow ) $lines[] = "Follow-Up Actions:\n- " . implode( "\n- ", $follow );
        if ( '' !== trim( (string) ( $org['additional_notes'] ?? '' ) ) ) $lines[] = "Additional Notes:\n" . trim( $org['additional_notes'] );
        if ( $lines ) $blocks[] = "ORGANISED NOTES\n" . implode( "\n\n", $lines );

        $addenda = $consult->id ? $wpdb->get_results( $wpdb->prepare(
            "SELECT id, note_text, occurred_at, priority FROM {$wpdb->prefix}hdlv2_consultation_addenda
             WHERE consultation_id = %d ORDER BY occurred_at ASC, id ASC",
            (int) $consult->id
        ), ARRAY_A ) : array();
        $rows = array();
        foreach ( (array) $addenda as $a ) {
            $note = trim( wp_strip_all_tags( (string) ( $a['note_text'] ?? '' ) ) );
            if ( '' === $note ) continue;
            $ts       = ! empty( $a['occurred_at'] ) ? strtotime( $a['occurred_at'] ) : false;
            $priority = in_array( $a['priority'] ?? '', array( 'low', 'medium', 'high' ), true ) ? $a['priority'] : 'medium';
            $rows[]   = sprintf( '[%d] %s · %s priority — %s', count( $rows ) + 1, $ts ? gmdate( 'j M Y · H:i', $ts ) : 'unknown date', strtoupper( $priority ), $note );
        }
        if ( $rows ) $blocks[] = "ADDENDA (oldest first)\n" . implode( "\n", $rows );

        return implode( "\n\n", $blocks );
    }

    /** HTML to plain text: block ends and line breaks become new lines, list items "- ", entities decoded. */
    private static function plain( $html ) {
        $t = preg_replace( '#<br\s*/?>#i', "\n", $html );
        $t = preg_replace( '#<li[^>]*>#i', '- ', $t );
        $t = preg_replace( '#</li>#i', "\n", $t );
        $t = preg_replace( '#</(p|div|h[1-6]|ul|ol)>#i', "\n\n", $t );
        $t = html_entity_decode( strip_tags( $t ), ENT_QUOTES, 'UTF-8' );
        $t = preg_replace( "/[ \t]+\n/", "\n", $t );
        return trim( preg_replace( "/\n{3,}/", "\n\n", $t ) );
    }

    // ──────────────────────────────────────────────────────────────
    //  Writing, showing, printing
    // ──────────────────────────────────────────────────────────────

    /**
     * Run prompt 1, store the analysis and clear the note's stamp in the same write; then run prompt 2 and
     * store the note. Prompt 1 failing changes nothing. Prompt 2 failing keeps the new analysis and the old
     * note text, unapproved. Returns the screen's block plus note_written, or a WP_Error.
     */
    public static function write( $progress, $consult ) {
        global $wpdb;
        $table = $wpdb->prefix . 'hdlv2_consultation_notes';
        $in    = self::inputs( $progress, $consult );

        $analysis = HDLV2_AI_Service::run_prompt( strtr( self::PROMPT_PRACTITIONER, $in ), self::LIMIT_ANALYSIS );
        if ( is_wp_error( $analysis ) || '' === trim( (string) $analysis ) ) {
            error_log( sprintf( '[HDLV2 health] analysis failed: consult=%d code=%s', (int) $consult->id, is_wp_error( $analysis ) ? $analysis->get_error_code() : 'empty' ) );
            return new WP_Error( 'analysis_failed', 'The analysis could not be written. Nothing was changed. Please try again.', array( 'status' => 502 ) );
        }
        $analysis = trim( $analysis );
        $at       = current_time( 'mysql' );
        $wpdb->update( $table, array(
            'health_analysis'         => $analysis,
            'health_analysis_at'      => $at,
            'health_note_approved_at' => null,
            'health_note_approved_by' => null,
        ), array( 'id' => (int) $consult->id ) );

        $note = HDLV2_AI_Service::run_prompt( strtr( self::PROMPT_CLIENT, $in + array( '{{PRACTITIONER_ANALYSIS}}' => $analysis ) ), self::LIMIT_NOTE );
        $note_written = ! is_wp_error( $note ) && '' !== trim( (string) $note );
        if ( $note_written ) {
            $note = mb_substr( trim( $note ), 0, self::NOTE_MAX );
            $wpdb->update( $table, array( 'health_note' => $note ), array( 'id' => (int) $consult->id ) );
        } else {
            error_log( sprintf( '[HDLV2 health] note failed: consult=%d code=%s analysis_len=%d', (int) $consult->id, is_wp_error( $note ) ? $note->get_error_code() : 'empty', strlen( $analysis ) ) );
            $note = (string) $consult->health_note;
        }

        return self::block( $analysis, $at, $note, null ) + array( 'success' => true, 'note_written' => $note_written );
    }

    /** What the screen shows: the analysis as safe HTML, when it was written, the note as plain text, the approval date. */
    public static function block( $analysis, $analysis_at, $note, $approved_at ) {
        return array(
            'analysis_html' => self::to_html( (string) $analysis ),
            'written'       => $analysis_at ? mysql2date( 'j M Y, H:i', $analysis_at ) : '',
            'note'          => (string) $note,
            'approved'      => $approved_at ? mysql2date( 'j M Y', $approved_at ) : '',
        );
    }

    /** The block for the consultation load (an admin's empty stub row has no columns: an empty block). */
    public static function screen_block( $consult ) {
        return self::block( $consult->health_analysis ?? '', $consult->health_analysis_at ?? null, $consult->health_note ?? '', $consult->health_note_approved_at ?? null );
    }

    /**
     * Escape everything, turn **text** into <strong>, keep the lines: a blank line starts a paragraph, a
     * line break stays a line break. Nothing else (no link is made clickable). No raw line break is left,
     * because Make's PDF module turns every one into a space.
     */
    public static function to_html( $text ) {
        $text = trim( str_replace( "\r", '', (string) $text ) );
        if ( '' === $text ) return '';
        $html  = preg_replace( '/\*\*([^*\n]+)\*\*/', '<strong>$1</strong>', htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ) );
        $paras = preg_split( "/\n[ \t]*\n\s*/", $html );
        return '<p>' . implode( '</p><p>', array_map( function ( $p ) { return str_replace( "\n", '<br>', trim( $p ) ); }, $paras ) ) . '</p>';
    }

    /**
     * The client's note for the report: only with the switch on, from the consultation the report is made
     * from (the newest 'report_generated' row of this form, as build_pdf_payload() reads it), when it is
     * not empty and a practitioner has approved it. '' otherwise.
     */
    public static function approved_note( $progress_id ) {
        if ( ! self::on() ) return '';
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT health_note, health_note_approved_at FROM {$wpdb->prefix}hdlv2_consultation_notes
             WHERE form_progress_id = %d AND status = 'report_generated' ORDER BY id DESC LIMIT 1",
            (int) $progress_id
        ) );
        if ( ! $row || empty( $row->health_note_approved_at ) ) return '';
        return trim( (string) $row->health_note );
    }

    // ──────────────────────────────────────────────────────────────
    //  REST (registered by HDLV2_Consultation only with the switch on, behind check_practitioner_auth)
    // ──────────────────────────────────────────────────────────────

    /** POST /consultation/health-analysis { progress_id } — AI burn: both prompts, wrapped so a double click is charged once. */
    public static function rest_write( $request ) {
        $found = self::owned( (int) ( $request->get_json_params()['progress_id'] ?? 0 ) );
        if ( is_wp_error( $found ) ) return $found;
        list( $progress, $consult ) = $found;
        $scope = 'health:' . (int) $consult->id . ':' . get_current_user_id();
        return HDLV2_Idempotency::wrap_ai( $request, $scope, function () use ( $progress, $consult ) {
            $out = self::write( $progress, $consult );
            return is_wp_error( $out ) ? $out : rest_ensure_response( $out );
        } );
    }

    /**
     * POST /consultation/health-note { progress_id, note, approve } — saves the note as plain text (4,000
     * characters at most). approve true stamps who and when (an empty note cannot be approved); anything
     * else saves and clears the stamp.
     */
    public static function rest_note( $request ) {
        $params = $request->get_json_params();
        $found  = self::owned( (int) ( $params['progress_id'] ?? 0 ) );
        if ( is_wp_error( $found ) ) return $found;
        $consult = $found[1];
        $note    = sanitize_textarea_field( (string) ( $params['note'] ?? '' ) );
        $approve = true === ( $params['approve'] ?? false );
        if ( mb_strlen( $note ) > self::NOTE_MAX ) {
            return new WP_Error( 'note_too_long', 'The note is longer than 4,000 characters.', array( 'status' => 400 ) );
        }
        if ( $approve && '' === $note ) {
            return new WP_Error( 'empty_note', 'The note is empty.', array( 'status' => 400 ) );
        }
        global $wpdb;
        $at = $approve ? current_time( 'mysql' ) : null;
        $wpdb->update( $wpdb->prefix . 'hdlv2_consultation_notes', array(
            'health_note'             => $note,
            'health_note_approved_at' => $at,
            'health_note_approved_by' => $approve ? get_current_user_id() : null,
        ), array( 'id' => (int) $consult->id ) );
        $block = self::block( '', null, $note, $at );
        return rest_ensure_response( array( 'success' => true, 'note' => $block['note'], 'approved' => $block['approved'] ) );
    }

    /**
     * The form and its consultation, when the caller owns the client or is an admin (the save-brief rule).
     * Anyone else gets a 404, so another practitioner cannot tell the form exists. The consultation is the
     * owner's newest row for this form: the one the screen shows.
     */
    private static function owned( $progress_id ) {
        if ( ! $progress_id ) {
            return new WP_Error( 'missing_id', 'Progress ID required.', array( 'status' => 400 ) );
        }
        global $wpdb;
        $progress = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hdlv2_form_progress WHERE id = %d AND deleted_at IS NULL LIMIT 1",
            $progress_id
        ) );
        $owner = $progress ? (int) $progress->practitioner_user_id : 0;
        if ( ! $progress || ( $owner !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) ) {
            return new WP_Error( 'not_found', 'Assessment not found.', array( 'status' => 404 ) );
        }
        $consult = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hdlv2_consultation_notes WHERE form_progress_id = %d AND practitioner_user_id = %d ORDER BY id DESC LIMIT 1",
            $progress_id, $owner
        ) );
        if ( ! $consult ) {
            return new WP_Error( 'not_found', 'Consultation not found.', array( 'status' => 404 ) );
        }
        return array( $progress, $consult );
    }
}

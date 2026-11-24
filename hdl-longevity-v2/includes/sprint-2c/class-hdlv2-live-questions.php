<?php
/**
 * Questions to ask now (v0.49.03, plan 14 part B; switch hdlv2_ff_live_questions, absent = off).
 *
 * During a consultation the practitioner presses "Suggest questions": the text of the notes box at that
 * moment (what was typed plus the transcript of every recording already stopped; the recorder sends the
 * sound to our own server after each stop) goes with the client's form data and draft report to the AI,
 * with the prompt below, word for word (plan 14 § 7 B2; pinned by sha256 in
 * tests/live-questions/scenario-prompt-pinned.php). Up to five questions come back, each with why, and a
 * red flag first when there is one. Nothing is stored. There is no speech or microphone code here: the
 * browser's speech service has been off since Matthew's sign-off of 2026-07-15 and stays off.
 *
 * The filled prompt is sent whole as the user message, with no system text, as plan 13 does. The log holds
 * the consultation id, lengths, counts and error codes only, never a client's words.
 *
 * @package HDL_Longevity_V2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HDLV2_Live_Questions {

    const OPTION = 'hdlv2_ff_live_questions';

    // Plan 14 § 7 B2, word for word.
    const PROMPT = <<<'HDLV2_PROMPT_LIVE_QUESTIONS'
You are an experienced natural health and longevity practitioner helping another practitioner during a live consultation.

PURPOSE
The consultation is still going on. From what has been said so far and what the client wrote in their assessment, give the practitioner the questions most worth asking right now.

INPUTS
Client form data: {{FORM_DATA}}
Draft longevity report: {{DRAFT_REPORT}}
Heard or noted so far in this consultation: {{HEARD_SO_FAR}}
Questions already suggested (do not repeat them): {{ALREADY_ASKED}}

RULES
1. Use only the information provided. Do not invent symptoms, history or test results.
2. These are questions to explore possibilities, not diagnoses. Never state that the client has a condition.
3. Ask about what matters most to this client. Use their own words and their own answers.
4. Never suggest stopping or changing prescribed medication. Medical tests and decisions go through a doctor.
5. If nothing useful can be asked yet, write "Nothing to add yet." and stop.

RED FLAGS
Check first for anything needing medical attention (for example: chest pain, sudden neurological changes, unexplained weight loss, blood in stool or urine, a new lump, thoughts of self-harm, or any symptom that is severe, sudden or worsening quickly).
If found, put one line first: "RED FLAG: " then what was said, and what the practitioner should say or do now.
Never drop a red flag to meet the length limit.

OUTPUT FORMAT
Up to five lines, most useful first. Each line exactly:
Q: the question, as the practitioner would say it | Why: the answer or remark of the client it comes from, in a few words

STYLE
British English. Plain words. One question per line. No introduction and no closing line.

LENGTH
150 words at most. A red flag does not count towards the limit.
HDLV2_PROMPT_LIVE_QUESTIONS;

    const HEARD_MAX = 20000; // characters of the notes box sent, keeping the end (the newest words)
    const ASKED_MAX = 40;    // questions already shown that are sent back, the newest
    const ASKED_LEN = 300;   // characters of each
    const QUESTIONS = 5;
    const LIMIT     = 600;   // the most the model may write (max_tokens); the answer is 150 words plus a red flag
    const FAILED    = 'Could not get questions. Try again.';

    public static function on() {
        return (bool) get_option( self::OPTION, false );
    }

    /** The consultation screen's settings with live_questions added when the switch is on; untouched when off. */
    public static function config( array $cfg ) {
        return self::on() ? $cfg + array( 'live_questions' => 1 ) : $cfg;
    }

    /**
     * The filled prompt. $inputs is plan 13's HDLV2_Health_Analysis::inputs(); only its form data and draft
     * report are used. One pass (strtr), so a placeholder typed into the notes is never filled.
     */
    public static function prompt( array $inputs, $heard, array $asked ) {
        return strtr( self::PROMPT, array(
            '{{FORM_DATA}}'     => (string) ( $inputs['{{FORM_DATA}}'] ?? '' ),
            '{{DRAFT_REPORT}}'  => (string) ( $inputs['{{DRAFT_REPORT}}'] ?? '' ),
            '{{HEARD_SO_FAR}}'  => (string) $heard,
            '{{ALREADY_ASKED}}' => implode( "\n", $asked ),
        ) );
    }

    /** Control characters out, except tab and line feed (a carriage return goes too). */
    private static function clean( $text ) {
        return preg_replace( '/[\x00-\x08\x0B-\x1F\x7F]/', '', (string) $text );
    }

    /**
     * The model's answer: "RED FLAG: …" (kept whole, given apart) and up to five "Q: … | Why: …" lines.
     * "Nothing to add yet." is an answer with no question. Anything else is a plain error that never
     * carries the model's text.
     */
    public static function parse( $text ) {
        $out = array( 'questions' => array(), 'red_flag' => '', 'nothing' => false );
        foreach ( preg_split( '/\R/', (string) $text ) as $raw ) {
            // List marks and bold round a line are taken off: "1. **Q: …**" reads as "Q: …".
            $l = trim( str_replace( '**', '', preg_replace( '/^\s*(?:[-*•]|\d+[.)])\s+/u', '', (string) $raw ) ) );
            if ( '' === $l ) {
                continue;
            }
            if ( preg_match( '/^RED FLAG:\s*(.+)$/u', $l, $m ) ) {
                if ( '' === $out['red_flag'] ) {
                    $out['red_flag'] = trim( $m[1] );
                }
            } elseif ( preg_match( '/^Q:\s*(.+?)(?:\s*\|\s*Why:\s*(.*))?$/u', $l, $m ) ) {
                if ( count( $out['questions'] ) < self::QUESTIONS ) {
                    $out['questions'][] = array( 'question' => trim( $m[1] ), 'why' => trim( $m[2] ?? '' ) );
                }
            } elseif ( 'Nothing to add yet.' === $l ) {
                $out['nothing'] = true;
            }
        }
        if ( ! $out['questions'] && '' === $out['red_flag'] && ! $out['nothing'] ) {
            return new WP_Error( 'questions_unreadable', self::FAILED, array( 'status' => 502 ) );
        }
        return $out;
    }

    /**
     * POST /consultation/live-questions { progress_id, heard, asked[] } — registered by HDLV2_Consultation
     * only with the switch on, behind check_practitioner_auth; ownership by plan 13's owned() (owner or
     * admin, anyone else 404). `heard` is the whole notes box at the press, saved or not, and the only
     * consultation text sent: the saved notes are not read, so a save still on its way cannot send old
     * notes. AI burn, one Idempotency-Key per press. Nothing is stored.
     */
    public static function rest_suggest( $request ) {
        $params = (array) $request->get_json_params();
        $found  = HDLV2_Health_Analysis::owned( (int) ( $params['progress_id'] ?? 0 ) );
        if ( is_wp_error( $found ) ) {
            return $found;
        }
        list( $progress, $consult ) = $found;

        $heard = $params['heard'] ?? '';
        if ( ! is_string( $heard ) || ! mb_check_encoding( $heard, 'UTF-8' ) ) {
            return new WP_Error( 'heard_not_text', 'The notes could not be read.', array( 'status' => 400 ) );
        }
        $heard = self::clean( $heard );
        if ( mb_strlen( $heard ) > self::HEARD_MAX ) {
            $heard = mb_substr( $heard, -self::HEARD_MAX );
        }
        $asked = array();
        foreach ( is_array( $params['asked'] ?? null ) ? $params['asked'] : array() as $q ) {
            if ( is_string( $q ) && mb_check_encoding( $q, 'UTF-8' ) ) {
                $q = trim( mb_substr( str_replace( "\n", ' ', self::clean( $q ) ), 0, self::ASKED_LEN ) );
                if ( '' !== $q ) {
                    $asked[] = $q;
                }
            }
        }
        $asked = array_slice( $asked, -self::ASKED_MAX );

        $scope = 'liveq:' . (int) $consult->id . ':' . get_current_user_id();
        return HDLV2_Idempotency::wrap_ai( $request, $scope, function () use ( $progress, $consult, $heard, $asked ) {
            $usage  = null;
            $answer = HDLV2_AI_Service::run_prompt( self::prompt( HDLV2_Health_Analysis::inputs( $progress, $consult ), $heard, $asked ), self::LIMIT, $usage );
            $out    = is_wp_error( $answer ) ? $answer : self::parse( $answer );
            error_log( sprintf(
                '[HDLV2 live-questions] consult=%d heard_len=%d asked=%d tokens_in=%d tokens_out=%d result=%s',
                (int) $consult->id, mb_strlen( $heard ), count( $asked ),
                (int) ( $usage['input_tokens'] ?? 0 ), (int) ( $usage['output_tokens'] ?? 0 ),
                is_wp_error( $out ) ? $out->get_error_code() : count( $out['questions'] ) . ' questions' . ( '' !== $out['red_flag'] ? ', red flag' : '' )
            ) );
            if ( is_wp_error( $out ) ) {
                return new WP_Error( is_wp_error( $answer ) ? 'questions_failed' : $out->get_error_code(), self::FAILED, array( 'status' => 502 ) );
            }
            return rest_ensure_response( $out );
        } );
    }
}

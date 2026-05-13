<?php
/**
 * Stage 2 WHY Insight — deterministic 3-paragraph builder.
 *
 * Replaces HDLV2_AI_Service::generate_stage2_insight() (Haiku 4.5)
 * with templated static text. Same input contract, same output shape,
 * drop-in at the REST endpoint dispatch site.
 *
 * Why static (v0.22.11):
 *   Stage 2 input is free-form text. The Haiku reflected specific
 *   names / ages / relationships back to the client. Static cannot
 *   honestly reproduce that level of relevance, so we don't pretend
 *   to. The immediate insight focuses on three things we CAN say
 *   truthfully:
 *     - Receipt of the reflection (with a deterministic length variant
 *       calculated from strlen — short / substantial / rich).
 *     - The practitioner promise: a human reads every word, not an
 *       algorithm summary.
 *     - The next step: Stage 3 unlocks on practitioner release.
 *
 *   The Make.com → /form/stage2-callback pipeline still populates the
 *   canonical hdlv2_why_profiles row (with key_people, motivations,
 *   verbatim_quotes, etc.) for the practitioner's consultation
 *   interface. That heavier extract is unchanged.
 *
 * Tone: matches Stage 1 (softer/neutral, British English, second
 * person, never alarmist, never preachy).
 *
 * Output shape kept identical to the Haiku version so no JS change:
 *   { distilled_why: '', ai_reformulation: '<p>...</p>...', motivations: [] }
 *
 * Frontend gracefully handles empty distilled_why and empty motivations
 * (chip block hides when array is empty — see hdlv2-staged-form.js
 * `if (res.motivations && res.motivations.length)`).
 *
 * @package HDL_Longevity_V2
 * @since 0.22.11
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HDLV2_Stage2_Insight {

    /**
     * Build the immediate Stage 2 insight payload.
     *
     * @param string $vision_text  The client's free-form WHY text.
     * @param string $client_name  Full name (first name extracted) or empty.
     * @return array {
     *     @type string $distilled_why    Always empty string. JS hides callout when blank.
     *     @type string $ai_reformulation 3-paragraph HTML.
     *     @type array  $motivations      Always empty array. JS hides chip block.
     * }
     */
    public static function build( $vision_text, $client_name = '' ) {
        $vision = trim( (string) $vision_text );
        $len    = strlen( $vision );

        $first_name = '';
        if ( $client_name ) {
            $first_name = strtok( trim( (string) $client_name ), ' ' );
        }

        $name_open = $first_name
            ? sprintf( '%s, your', esc_html( $first_name ) )
            : 'Your';

        // Para 1 — receipt + deterministic length variant.
        // Byte count is fact, so this stays 100% truthful.
        if ( $len < 200 ) {
            $length_line = 'You have given a focused, succinct answer &mdash; practitioners often work better from short clear statements than long ones.';
        } elseif ( $len <= 1000 ) {
            $length_line = 'You have shared a substantial reflection &mdash; enough for your practitioner to work from when shaping the next stage.';
        } else {
            $length_line = 'You have shared a rich, detailed account &mdash; your practitioner will have plenty to work with.';
        }

        $p1 = sprintf(
            '<p><strong>%s WHY has been captured.</strong> %s</p>',
            $name_open,
            $length_line
        );

        // Para 2 — practitioner promise.
        $p2 = '<p>What you wrote will be read carefully &mdash; not skimmed, not summarised by an algorithm. '
            . 'Your practitioner will use the specific words and themes you raised to shape Stage 3 and the longevity plan that comes out of it.</p>';

        // Para 3 — what is next.
        $p3 = '<p>Until Stage 3 unlocks, your WHY sits on your practitioner&apos;s queue. '
            . 'They will release it when they have reviewed &mdash; most clients hear back within a working day. '
            . 'The reflection you wrote is the foundation everything that follows is built on.</p>';

        // Honest fallback for the "Your Distilled WHY" card when no
        // extraction has run yet (Make.com hasn't completed AND user
        // didn't click "Extract Themes" during the form). The JS only
        // replaces the loader when distilled_why is non-empty, so an
        // empty string here would leave the misleading "Distilling
        // your WHY..." loader on screen forever. This static line tells
        // the truth: the reflection is captured; the practitioner-led
        // distillation comes during review.
        // v0.22.52 — fallback text rewritten. Old text said "appears here
        // once your practitioner has reviewed it" which was a lie — the
        // gate is the AI extraction completing (Make.com Sonnet, ~30s-2min),
        // not the practitioner's manual Release WHY click. This class is
        // no longer called by /stage2-insight (frontend handles pending
        // state via extraction_status field), but we keep honest text in
        // case any future caller uses build().
        $distilled_fallback = $first_name
            ? sprintf(
                '%s, your reflection is being analysed. The distilled WHY will appear here within a minute.',
                esc_html( $first_name )
            )
            : 'Your reflection is being analysed. The distilled WHY will appear here within a minute.';

        return array(
            'distilled_why'    => $distilled_fallback,
            'ai_reformulation' => $p1 . $p2 . $p3,
            'motivations'      => array(),
        );
    }
}

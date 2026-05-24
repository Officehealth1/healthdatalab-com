<?php
/**
 * Final Report Generation Pipeline.
 *
 * Triggered after practitioner consultation. Steps:
 * 1. Recalculate rate with practitioner-verified data
 * 2. Generate final report content (Claude Sonnet 4)
 * 3. Generate milestones (separate Claude call)
 * 4. Store final report in wp_hdlv2_reports
 * 5. Write to V1 progress tracker
 * 6. Fire Make.com webhook for PDF
 * 7. Send emails
 *
 * @package HDL_Longevity_V2
 * @since 0.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HDLV2_Final_Report {

    // v0.28.5 — primary source is now the wp-config constant
    // HDLV2_MAKE_FINAL_REPORT, matching the pattern of every other Make.com
    // webhook in V2 (Stage 1 PDF, Stage 2 WHY, Draft Report, Flight Plan
    // PDF). The legacy option key is preserved as a fallback so any
    // environment that already had `wp option update hdlv2_make_webhook_final_report`
    // run by hand continues to work without manual migration.
    const MAKE_WEBHOOK_OPTION = 'hdlv2_make_webhook_final_report';
    const MAKE_WEBHOOK_DEFAULT = '';

    /**
     * Generate the final report. Called from HDLV2_Consultation::rest_finalise().
     *
     * @param int      $progress_id        Form progress row ID.
     * @param int      $consult_id         Consultation notes row ID.
     * @param int      $practitioner_id    Practitioner user ID.
     * @param int|null $update_existing_id Optional existing reports.id to UPDATE
     *                                     in place rather than INSERT a new row.
     *                                     Set by ::regenerate() when refreshing a
     *                                     prior Final report (matches the
     *                                     uniq_progress_report unique key — Phase
     *                                     M, DB v3.3 — which would block a second
     *                                     INSERT for the same (progress, type)).
     *                                     null → INSERT new row (first finalise).
     * @return array|WP_Error Final report data or error.
     */
    public static function generate( $progress_id, $consult_id, $practitioner_id, $update_existing_id = null ) {
        global $wpdb;

        // ── Load all data ──
        // v0.41.17 — `AND deleted_at IS NULL`. Final Report generation must
        // not target an archived assessment (would otherwise burn Claude +
        // ship a Make.com webhook for a deleted client).
        $progress = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hdlv2_form_progress
             WHERE id = %d AND deleted_at IS NULL",
            $progress_id
        ) );
        if ( ! $progress ) {
            return new WP_Error( 'not_found', 'Assessment not found.', array( 'status' => 404 ) );
        }

        // ── Ownership ──
        // Closes the IDOR where any practitioner could trigger Final
        // Report generation (Claude + Make.com burn) on a non-owned
        // assessment, OR read another practitioner's existing final
        // report content via the duplicate guard below.
        // Admin escape hatch (manage_options) preserves support flows.
        if ( (int) $progress->practitioner_user_id !== (int) $practitioner_id
             && ! user_can( (int) $practitioner_id, 'manage_options' ) ) {
            return new WP_Error( 'forbidden', 'You do not have access to this assessment.', array( 'status' => 403 ) );
        }

        // ── Duplicate guard ──
        // If a final report already exists for this assessment, return it
        // instead of regenerating. Prevents duplicate Claude calls, duplicate
        // Make.com webhook fires, duplicate client emails, and duplicate
        // Flight Plan scheduling when the practitioner hits Back/Refresh/etc.
        // v0.24.3 — scoped to status='ready' so the new ::regenerate() path
        // (which archives prior Finals before re-firing) can pass through
        // cleanly. Practitioner edit-as-reset relies on this.
        // v0.34.3 — bypass when called in UPDATE mode by ::regenerate(); the
        // caller has already decided this row is the one to refresh.
        $existing_final = ( $update_existing_id !== null )
            ? null
            : $wpdb->get_row( $wpdb->prepare(
                "SELECT id, report_content, milestones FROM {$wpdb->prefix}hdlv2_reports
                 WHERE form_progress_id = %d AND report_type = 'final' AND status = 'ready'
                 ORDER BY id DESC LIMIT 1",
                $progress_id
            ) );
        if ( $existing_final ) {
            $content = json_decode( $existing_final->report_content, true ) ?: array();
            $ms      = json_decode( $existing_final->milestones, true ) ?: array();

            // Recompute calc_result from stored stage data — pure math, no AI burn.
            $s1 = json_decode( $progress->stage1_data, true ) ?: array();
            $s3 = json_decode( $progress->stage3_data, true ) ?: array();
            $calc_data = array_merge( $s1, $s3 );
            foreach ( $calc_data as $k => $v ) { if ( $v === 'skip' ) $calc_data[ $k ] = null; }
            $age    = (int) ( $calc_data['q1_age'] ?? $calc_data['age'] ?? 0 );
            $gender = $calc_data['q1_sex'] ?? $calc_data['gender'] ?? 'other';
            $calc_result = HDLV2_Rate_Calculator::calculate_full( $age, $calc_data, $gender );

            return array(
                'success'           => true,
                'already_generated' => true,
                'report_id'         => (int) $existing_final->id,
                'report_type'       => 'final',
                'awaken_content'    => $content['awaken_content'] ?? '',
                'lift_content'      => $content['lift_content'] ?? '',
                'thrive_content'    => $content['thrive_content'] ?? '',
                'milestones'        => $ms,
                'calc_result'       => $calc_result,
            );
        }

        $consult = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hdlv2_consultation_notes WHERE id = %d", $consult_id
        ) );
        if ( ! $consult ) {
            return new WP_Error( 'not_found', 'Consultation not found.', array( 'status' => 404 ) );
        }

        $s1_data = json_decode( $progress->stage1_data, true ) ?: array();
        $s3_data = json_decode( $progress->stage3_data, true ) ?: array();

        // Draft report content
        $draft = $wpdb->get_row( $wpdb->prepare(
            "SELECT report_content FROM {$wpdb->prefix}hdlv2_reports WHERE form_progress_id = %d AND report_type = 'draft' ORDER BY id DESC LIMIT 1",
            $progress_id
        ) );
        $draft_content = $draft ? ( json_decode( $draft->report_content, true ) ?: array() ) : array();

        // WHY profile
        $why_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT distilled_why, ai_reformulation, key_people, motivations, fears FROM {$wpdb->prefix}hdlv2_why_profiles WHERE form_progress_id = %d LIMIT 1",
            $progress_id
        ), ARRAY_A );
        $why_profile = $why_row ?: array();
        if ( ! empty( $why_profile['key_people'] ) ) $why_profile['key_people'] = json_decode( $why_profile['key_people'], true );
        if ( ! empty( $why_profile['motivations'] ) ) $why_profile['motivations'] = json_decode( $why_profile['motivations'], true );
        if ( ! empty( $why_profile['fears'] ) ) $why_profile['fears'] = json_decode( $why_profile['fears'], true );

        // Consultation data — prefer new AI-organised output (v0.15.0+), with
        // fallback to the legacy structured recommendations column for any
        // in-flight rows that predate the cutover. Clean cutover path writes
        // only to ai_organised_notes going forward.
        $health_changes  = json_decode( $consult->health_data_changes, true ) ?: array();
        $organised       = ( isset( $consult->ai_organised_notes ) && $consult->ai_organised_notes )
            ? ( json_decode( $consult->ai_organised_notes, true ) ?: null )
            : null;

        // v0.32.5 — Merge ALL recommendation sources: AI-organised notes,
        // legacy `recommendations` column, addenda (added below). The previous
        // if/else either-or path silently dropped manual recs from the legacy
        // column whenever ai_organised_notes had any organised entries, and
        // dropped AI organised when manual entries were added through the
        // (since-revived) /add-recommendation endpoint. Now everything merges
        // with light dedupe by trimmed text, so no source is silently lost.
        $recommendations = array();

        if ( $organised && ! empty( $organised['recommendations'] ) && is_array( $organised['recommendations'] ) ) {
            foreach ( $organised['recommendations'] as $r ) {
                if ( ! is_array( $r ) ) continue;
                $cat = isset( $r['category'] ) ? (string) $r['category'] : '';
                if ( ! empty( $r['secondary_category'] ) ) {
                    $cat .= ' (also ' . (string) $r['secondary_category'] . ')';
                }
                $text = isset( $r['text'] ) ? trim( (string) $r['text'] ) : '';
                if ( $text === '' ) continue;
                $recommendations[] = array(
                    'category'  => $cat,
                    'text'      => $text,
                    'priority'  => isset( $r['priority'] ) ? (string) $r['priority'] : 'Medium',
                    'frequency' => isset( $r['frequency'] ) ? (string) $r['frequency'] : 'Not specified',
                );
            }
        }

        // Legacy `recommendations` column — pre-v0.15.0 manual entries OR
        // any future writes that target this column directly. Merge by
        // case-insensitive trimmed-text dedupe so identical content from
        // both sources only appears once.
        $legacy_recs = json_decode( $consult->recommendations ?? '', true );
        if ( is_array( $legacy_recs ) ) {
            foreach ( $legacy_recs as $r ) {
                if ( ! is_array( $r ) ) continue;
                $text = isset( $r['text'] ) ? trim( (string) $r['text'] ) : '';
                if ( $text === '' ) continue;
                $is_dup = false;
                foreach ( $recommendations as $existing ) {
                    if ( strcasecmp( trim( $existing['text'] ), $text ) === 0 ) {
                        $is_dup = true;
                        break;
                    }
                }
                if ( $is_dup ) continue;
                $recommendations[] = array(
                    'category'  => isset( $r['category'] ) ? (string) $r['category'] : '',
                    'text'      => $text,
                    'priority'  => isset( $r['priority'] ) ? (string) $r['priority'] : 'Medium',
                    'frequency' => isset( $r['frequency'] ) ? (string) $r['frequency'] : 'Not specified',
                );
            }
        }

        // Build the notes string the Final Report prompt sees. If organised JSON
        // exists, feed its structured sections (health_summary, history,
        // follow_up_actions, additional_notes) rather than the raw textarea — so
        // Claude works from the practitioner-approved distillation, not raw audio.
        if ( $organised ) {
            $parts = array();
            if ( ! empty( $organised['health_summary'] ) )    $parts[] = "## Client Health Summary\n" . $organised['health_summary'];
            if ( ! empty( $organised['health_history'] ) )    $parts[] = "## Health History\n" . $organised['health_history'];
            if ( ! empty( $organised['follow_up_actions'] ) ) $parts[] = "## Follow-Up Actions\n- " . implode( "\n- ", (array) $organised['follow_up_actions'] );
            if ( ! empty( $organised['additional_notes'] ) )  $parts[] = "## Additional Notes\n" . $organised['additional_notes'];
            $typed_notes = implode( "\n\n", $parts );
            // Fallback: if organised was empty-section-only, still send raw.
            if ( $typed_notes === '' ) {
                $typed_notes = $consult->raw_notes ?: $consult->typed_notes ?: '';
            }
        } else {
            $typed_notes = $consult->typed_notes ?: '';
        }

        // v0.28.0 — Load any practitioner Addenda for this consultation and
        // merge them into the notes block. No-op when the addenda table is
        // empty (initial /finalise calls always have zero addenda — they only
        // appear post-Final). The merge function returns $typed_notes
        // unchanged in that case, so the legacy generate flow is preserved.
        // See HDLV2_AI_Service::merge_consultation_with_addenda().
        $addenda_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, note_text, occurred_at, priority, source FROM {$wpdb->prefix}hdlv2_consultation_addenda
             WHERE consultation_id = %d
             ORDER BY occurred_at ASC, id ASC",
            $consult_id
        ), ARRAY_A );
        if ( ! empty( $addenda_rows ) ) {
            $typed_notes = HDLV2_AI_Service::merge_consultation_with_addenda( $typed_notes, $addenda_rows );

            // v0.32.2 — Surface each addendum as a structured rec card on Page 13.
            // v0.32.5 — Dedupe against already-merged sources so a practitioner
            // who manually added the same text via /add-recommendation and an
            // addendum doesn't see two identical cards.
            //
            // Background: addenda are stored as free-text + priority + occurred_at
            // in wp_hdlv2_consultation_addenda. Until v0.32.2 they were ONLY
            // text-merged into the Claude prompt; the structured
            // $recommendations[] array stayed unchanged. So when a practitioner's
            // original consultation contained no action items (just praise),
            // the Final Report's Page 13 rendered empty even after they added
            // an addendum that was clearly an action. Kim 2026-05-05 hit this.
            foreach ( $addenda_rows as $a ) {
                $text = trim( wp_strip_all_tags( $a['note_text'] ?? '' ) );
                if ( $text === '' ) continue;
                $is_dup = false;
                foreach ( $recommendations as $existing ) {
                    if ( strcasecmp( trim( $existing['text'] ), $text ) === 0 ) {
                        $is_dup = true;
                        break;
                    }
                }
                if ( $is_dup ) continue;
                $pri_raw = strtolower( (string) ( $a['priority'] ?? 'medium' ) );
                if ( ! in_array( $pri_raw, array( 'low', 'medium', 'high' ), true ) ) {
                    $pri_raw = 'medium';
                }
                $recommendations[] = array(
                    'category'  => 'Practitioner addendum',
                    'text'      => $text,
                    'priority'  => ucfirst( $pri_raw ),
                    'frequency' => 'Ongoing',
                );
            }
        }

        // v0.33.5 — HARD GUARD: do not let an empty Page-13 ship.
        //
        // After merging all 3 rec sources (organised, legacy, addenda), if the
        // total is still zero, we refuse to finalise. The webhook never fires,
        // no fresh PDF, no fresh email — the practitioner gets a clear 422
        // and a structured error_log line so we can diagnose which source
        // dried up.
        //
        // Why a hard block: Kim's 2026-05-05 report shipped to the client with
        // an empty Recommendations page because the system silently accepted
        // ai_organised_notes['recommendations'] = []. Any silent-pass for an
        // empty rec list breaks the report's value proposition. Either
        // Claude extracted recs from the consultation notes, the practitioner
        // added one manually, or there's a non-empty addendum — without at
        // least one of the three, the Final Report has nothing actionable to
        // show on Page 13.
        //
        // Recovery path for the practitioner:
        //   - Pre-Final: click "+ Add a recommendation" or "↻ Re-organise these
        //     notes" on the review panel, then retry.
        //   - Post-Final: add an addendum with action text, then click
        //     "Save & Update Plan" again.
        // Both paths surface in the JS pre-flight check (Layer 2) so the
        // practitioner sees the issue BEFORE this 422 ever fires.
        if ( empty( $recommendations ) ) {
            $organised_count = ( is_array( $organised ) && isset( $organised['recommendations'] ) && is_array( $organised['recommendations'] ) )
                ? count( $organised['recommendations'] )
                : 0;
            $legacy_count    = is_array( $legacy_recs ) ? count( $legacy_recs ) : 0;
            $addenda_count   = is_array( $addenda_rows ) ? count( $addenda_rows ) : 0;
            error_log( sprintf(
                '[HDLV2 empty-recs] progress=%d consult=%d organised_recs=%d legacy_recs=%d addenda=%d practitioner=%d',
                $progress_id, $consult_id, $organised_count, $legacy_count, $addenda_count, $practitioner_id
            ) );
            return new WP_Error(
                'empty_recommendations',
                'This report has no recommendations yet. Add at least one recommendation through the panel, or add an addendum with an action item, then try again.',
                array( 'status' => 422 )
            );
        }

        // ── Step 1: Recalculate rate with practitioner-verified data ──
        $calc_data = array_merge( $s1_data, $s3_data );

        // Apply practitioner edits on top — with body-composition guard.
        // v0.23.9: numeric inputs that drive BMI / WHR / WHtR / blood-pressure-
        // score / heart-rate-score must not be silently nulled by a blank or
        // 'skip' edit. If the change log preserves a valid prior value
        // (`original` field), restore it. Older reports written before
        // v0.23.9's validation guard could carry such corruption — this layer
        // of defence recovers them on re-finalise. New v0.23.9 edits are
        // already rejected at validation, so this branch will rarely fire
        // going forward; it exists for backwards compatibility with rows like
        // Matt2704_01's report 25 (BMI nulled on 2026-04-28).
        $body_inputs = array( 'height', 'weight', 'waist', 'hip', 'bpSystolic', 'bpDiastolic', 'restingHeartRate' );
        foreach ( $health_changes as $change ) {
            if ( ! is_array( $change ) || ! isset( $change['field'] ) ) continue;
            $field    = $change['field'];
            $new      = $change['new_value'] ?? null;
            $is_blank = ( $new === '' || $new === 'skip' || $new === null );
            if ( in_array( $field, $body_inputs, true ) && $is_blank ) {
                $orig = $change['original'] ?? null;
                if ( is_numeric( $orig ) ) {
                    $calc_data[ $field ] = $orig;
                    continue;
                }
            }
            $calc_data[ $field ] = $new;
        }

        // Map activity → physicalActivity
        if ( isset( $calc_data['activity'] ) && ! isset( $calc_data['physicalActivity'] ) ) {
            $calc_data['physicalActivity'] = $calc_data['activity'];
        }

        // Filter skip values
        foreach ( $calc_data as $k => $v ) {
            if ( $v === 'skip' ) $calc_data[ $k ] = null;
        }

        $age    = (int) ( $calc_data['q1_age'] ?? $calc_data['age'] ?? 0 );
        $gender = $calc_data['q1_sex'] ?? $calc_data['gender'] ?? 'other';
        $calc_result = HDLV2_Rate_Calculator::calculate_full( $age, $calc_data, $gender );

        // ── Step 2: Generate final report content (Claude) ──
        $report_content = HDLV2_AI_Service::generate_final_report(
            $calc_result,
            $draft_content,
            $health_changes,
            $typed_notes,
            $recommendations,
            $why_profile,
            $progress->client_name ?: '',
            $age
        );

        // v0.34.4 — Placeholder guard. HDLV2_AI_Service::report_placeholder()
        // returns a fallback hash whenever the Anthropic call fails (timeout,
        // rate limit, missing key). Without this guard, the report shipped
        // with the literal string "A detailed AWAKEN analysis will be
        // generated once the AI service is configured" rendered into Page 3.
        // Detect the marker, abort BEFORE any side-effect (report row insert,
        // V1 write, webhook, email, Flight Plan schedule) and surface a 503
        // to the practitioner UI so they can retry.
        $awaken_check = is_array( $report_content ) ? ( $report_content['awaken_content'] ?? '' ) : '';
        if ( $awaken_check === '' || strpos( $awaken_check, 'AWAKEN analysis will be generated' ) !== false ) {
            error_log( '[HDLV2] Final report aborted — AI service returned placeholder/empty AWAKEN for progress_id ' . (int) $progress_id );
            return new WP_Error(
                'hdlv2_ai_generation_failed',
                __( 'AI report generation failed (Claude API unavailable or misconfigured). Please retry in a moment.', 'hdl-longevity-v2' ),
                array( 'status' => 503 )
            );
        }

        // v0.36.3 — Refresh ai_narrative on every Final generation (was:
        // carry-forward from DRAFT, frozen at the original consultation
        // snapshot). The 5 client-visible panels driven by ai_narrative
        // (Your Analysis opening / What the curve is telling us /
        // Top Strengths · Focus Areas / Tying back to your goals /
        // Draft Recommendations cards) now reflect post-consultation
        // practitioner edits to scores AND any addenda that have been
        // added since the original Final.
        //
        // The fresh narrative is generated against the SAME inputs the
        // awaken/lift/thrive prose just used (post-edit calc_result +
        // current why_profile) plus a serialised block of addenda so
        // Claude weaves their substance into the panels. Failure path:
        // fall back to the v0.23.0 DRAFT carry-forward so first-time
        // finalises and any Claude misfires never lose the panels.
        $practitioner_updates_text = '';
        if ( ! empty( $addenda_rows ) ) {
            $upd_lines = array();
            foreach ( $addenda_rows as $a ) {
                $note = trim( wp_strip_all_tags( $a['note_text'] ?? '' ) );
                if ( $note === '' ) continue;
                $occurred = ! empty( $a['occurred_at'] ) ? strtotime( $a['occurred_at'] ) : false;
                $when     = $occurred ? gmdate( 'j M Y', $occurred ) : 'unknown date';
                $priority = strtoupper( in_array( $a['priority'] ?? 'medium', array( 'low', 'medium', 'high' ), true ) ? $a['priority'] : 'medium' );
                $upd_lines[] = sprintf( "Addendum (%s · %s priority): %s", $when, $priority, $note );
            }
            $practitioner_updates_text = implode( "\n\n", $upd_lines );
        }

        // v0.40.0 — pass apply_draft_cap=false so the Final web view's
        // "Your Analysis" panel gets a full 2-4 sentence opening (~600 chars
        // ceiling), not the 240-char Draft teaser. The /my-report/ surface
        // is HTML-fluid so longer prose renders cleanly there even though
        // the Draft Report PDF must stay capped.
        $fresh_narrative = HDLV2_AI_Service::generate_client_draft_narrative(
            $calc_result,
            array_merge( $s1_data, $s3_data ),
            $why_profile,
            $progress->client_name ?: '',
            $practitioner_updates_text,
            false  // apply_draft_cap — Final-context call
        );

        if ( is_array( $fresh_narrative ) ) {
            $report_content['ai_narrative'] = $fresh_narrative;
            error_log( sprintf(
                '[HDLV2 generate] ai_narrative refreshed (post-consultation) progress=%d addenda=%d',
                $progress_id,
                count( (array) ( $addenda_rows ?? array() ) )
            ) );
        } elseif ( is_array( $draft_content ) && ! empty( $draft_content['ai_narrative'] ) ) {
            // Fallback: legacy v0.23.0 carry-forward when the fresh regen
            // is unavailable (no API key, Claude error, invalid JSON).
            // Preserves the 5 panels from the DRAFT row so the client
            // never sees a blank report.
            $report_content['ai_narrative'] = $draft_content['ai_narrative'];
            error_log( sprintf(
                '[HDLV2 generate] ai_narrative refresh failed; carrying forward DRAFT narrative for progress=%d',
                $progress_id
            ) );
        }

        // ── Step 3: Generate milestones (separate Claude call) ──
        $milestones = HDLV2_AI_Service::generate_milestones(
            $calc_result,
            $why_profile,
            $recommendations,
            $age
        );

        // ── Step 4: Store final report ──
        // v0.22.22 — rate_snapshot captures the rate AT THE TIME of generation
        // so the Effort vs Outcomes chart shows true measurement points
        // instead of always re-deriving from form_progress (which today is
        // shared across all reports for a client → flat outcome line).
        //
        // v0.34.3 — UPDATE-or-INSERT branch. Phase M (DB v3.3) added a
        // UNIQUE KEY uniq_progress_report (form_progress_id, report_type) on
        // hdlv2_reports to fix the duplicate-draft-email race. That key makes
        // the legacy "archive old + INSERT new" regenerate strategy fail
        // silently — $wpdb->insert() returns false because the slot is still
        // occupied. ::regenerate() now passes $update_existing_id, and we
        // UPDATE the same row in place (preserves id + audit trail, satisfies
        // the unique key). When called for a first-time finalise (no prior
        // Final), $update_existing_id is null and we INSERT as before.
        //
        // Both paths check the return value — false → return WP_Error before
        // any side-effect (consult update, addenda stamp, V1 write, webhook,
        // email, Flight Plan schedule) fires. No more silent cascades.
        $report_payload = array(
            'client_user_id'       => $progress->client_user_id ?: null,
            'practitioner_user_id' => $practitioner_id,
            'form_progress_id'     => $progress_id,
            'report_type'          => 'final',
            'report_content'       => wp_json_encode( $report_content ),
            'milestones'           => wp_json_encode( $milestones ),
            'rate_snapshot'        => isset( $calc_result['rate'] ) ? round( (float) $calc_result['rate'], 2 ) : null,
            'consultation_notes'   => $typed_notes,
            'health_data_changes'  => wp_json_encode( $health_changes ),
            'status'               => 'ready',
        );

        if ( $update_existing_id !== null ) {
            $update_result = $wpdb->update(
                $wpdb->prefix . 'hdlv2_reports',
                $report_payload,
                array( 'id' => (int) $update_existing_id )
            );
            if ( $update_result === false ) {
                error_log( sprintf(
                    '[HDLV2 generate] UPDATE failed for reports.id=%d, progress=%d, consult=%d: %s',
                    (int) $update_existing_id, $progress_id, $consult_id, $wpdb->last_error
                ) );
                return new WP_Error( 'db_error', 'Could not update Trajectory Plan row. Please try again.', array( 'status' => 500 ) );
            }
            $report_id = (int) $update_existing_id;
        } else {
            $insert_result = $wpdb->insert(
                $wpdb->prefix . 'hdlv2_reports',
                $report_payload
            );
            if ( $insert_result === false ) {
                error_log( sprintf(
                    '[HDLV2 generate] INSERT failed for progress=%d, consult=%d: %s',
                    $progress_id, $consult_id, $wpdb->last_error
                ) );
                return new WP_Error( 'db_error', 'Could not create Trajectory Plan row. Please try again.', array( 'status' => 500 ) );
            }
            $report_id = (int) $wpdb->insert_id;
        }

        if ( ! $report_id ) {
            error_log( sprintf(
                '[HDLV2 generate] Zero report_id after %s for progress=%d, consult=%d',
                $update_existing_id !== null ? 'UPDATE' : 'INSERT',
                $progress_id, $consult_id
            ) );
            return new WP_Error( 'db_error', 'Trajectory Plan write returned no id. Please try again.', array( 'status' => 500 ) );
        }

        // Update consultation status
        $wpdb->update(
            $wpdb->prefix . 'hdlv2_consultation_notes',
            array(
                'report_id' => $report_id,
                'status'    => 'report_generated',
                'ended_at'  => current_time( 'mysql' ),
            ),
            array( 'id' => $consult_id )
        );

        // v0.28.0 — Tie every Addendum that fed into this generation to the
        // resulting Final Report row. Audit trail: a practitioner can later
        // see which Addenda were "live" at the time of report N and which
        // arrived afterwards. Only stamps Addenda not already tied to a
        // report (so a re-generation that picks up a NEW Addendum doesn't
        // overwrite the link of an OLDER Addendum already tied to an
        // archived Final).
        if ( ! empty( $addenda_rows ) ) {
            $addenda_ids = array_map( static function ( $a ) { return (int) $a['id']; }, $addenda_rows );
            $addenda_ids = array_filter( $addenda_ids );
            if ( ! empty( $addenda_ids ) ) {
                $placeholders = implode( ',', array_fill( 0, count( $addenda_ids ), '%d' ) );
                $params       = array_merge( array( (int) $report_id ), $addenda_ids );
                $wpdb->query( $wpdb->prepare(
                    "UPDATE {$wpdb->prefix}hdlv2_consultation_addenda
                     SET superseded_by_report_id = %d
                     WHERE id IN ($placeholders) AND superseded_by_report_id IS NULL",
                    ...$params
                ) );
            }
        }

        // ── Step 5: V1 compatibility write ──
        if ( $progress->client_user_id ) {
            $metrics = array(
                'rate_of_ageing' => $calc_result['rate'] ?? null,
                'biological_age' => $calc_result['bio_age'] ?? null,
                'bmi'            => $calc_result['bmi'] ?? null,
                'whr'            => $calc_result['whr'] ?? null,
                'whtr'           => $calc_result['whtr'] ?? null,
            );

            // Add all individual scores
            foreach ( $calc_result['scores'] ?? array() as $name => $score ) {
                if ( is_numeric( $score ) ) {
                    $metrics[ $name ] = $score;
                }
            }

            HDLV2_Compatibility::write_progress_point(
                $progress->client_user_id,
                array_filter( $metrics, function ( $v ) { return $v !== null; } )
            );
        }

        // ── Step 6: Fire Make.com webhook ──
        self::fire_webhook( $progress, $calc_result, $s1_data, $report_content, $milestones, $why_profile, $recommendations, $health_changes, $typed_notes, $practitioner_id );

        // ── Step 7: Send emails ──
        self::send_emails( $progress, $calc_result, $practitioner_id );

        // ── Step 8: Auto-generate Week 1 Flight Plan ──
        // 'current' = this week's Monday. Final report is the client's first
        // flight plan — it lands on the week they're in right now, not next week.
        //
        // v0.34.3 — Flight plan archive moved here from regenerate() so the
        // archive only fires AFTER the report row write succeeded. Idempotent
        // for first-finalise (matches 0 rows) + correct for regen (archives
        // any live plans before scheduling fresh ones). Replaces the prior
        // pattern where regenerate() archived plans up-front, then if
        // generate() failed, the practitioner ended up with no live Final
        // AND no live Flight Plan.
        if ( $progress->client_user_id ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->prefix}hdlv2_flight_plans SET status = 'archived'
                 WHERE client_id = %d AND status IN ('generated','delivered','active')",
                $progress->client_user_id
            ) );

            wp_schedule_single_event(
                time() + 5,
                'hdlv2_generate_single_flight_plan',
                array( (int) $progress->client_user_id, 'current' )
            );

            // Kick WP-Cron manually via non-blocking HTTP to /wp-cron.php.
            // Works even when DISABLE_WP_CRON is true (as on STBY) — the
            // constant only blocks the auto-trigger on pageviews, not a
            // direct HTTP call. Ensures the scheduled flight plan fires
            // within seconds instead of waiting for the next pageview.
            wp_remote_post( site_url( '/wp-cron.php?doing_wp_cron=' . microtime( true ) ), array(
                'timeout'   => 0.01,
                'blocking'  => false,
                'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
            ) );
        }

        // ── Return to frontend ──
        // v0.28.0 — generated_at timestamp so the practitioner / client can
        // compare progression across regenerations (Addenda flow).
        // v0.34.3 — UPDATE mode keeps original created_at but moves updated_at
        // (ON UPDATE CURRENT_TIMESTAMP). The success card shows "Generated DD
        // MMM · HH:MM" — that should reflect the regen moment, not the
        // original first-finalise. So we read updated_at when refreshing,
        // created_at on a first finalise.
        $generated_at = $wpdb->get_var( $wpdb->prepare(
            $update_existing_id !== null
                ? "SELECT updated_at FROM {$wpdb->prefix}hdlv2_reports WHERE id = %d"
                : "SELECT created_at FROM {$wpdb->prefix}hdlv2_reports WHERE id = %d",
            (int) $report_id
        ) );

        return array(
            'success'         => true,
            'report_id'       => $report_id,
            'report_type'     => 'final',
            'updated'         => $update_existing_id !== null,
            'generated_at'    => $generated_at,
            'awaken_content'  => $report_content['awaken_content'],
            'lift_content'    => $report_content['lift_content'],
            'thrive_content'  => $report_content['thrive_content'],
            'milestones'      => $milestones,
            'calc_result'     => $calc_result,
        );
    }

    // ──────────────────────────────────────────────────────────────
    //  MAKE.COM WEBHOOK
    // ──────────────────────────────────────────────────────────────

    /**
     * Fire the Final Report Make.com webhook.
     *
     * @param object $progress         hdlv2_form_progress row
     * @param array  $calc_result      output of HDLV2_Rate_Calculator::calculate_full
     * @param array  $s1_data          decoded stage1_data
     * @param array  $report           awaken/lift/thrive content (may be empty for automation tier)
     * @param array  $milestones       four-key array (six_months/two_years/five_years/ten_plus_years)
     * @param array  $why_profile      hdlv2_why_profiles row decoded
     * @param array  $recommendations  array of {category, text, priority, frequency}
     * @param array  $health_changes   practitioner-edited health data (empty for automation tier)
     * @param string $notes            typed notes (empty for automation tier — practitioner_health_summary
     *                                 is supplied via $overrides instead)
     * @param int    $practitioner_id  0 for automation tier
     * @param array  $overrides        W9 (v0.41.31) — optional payload overrides applied via array_replace
     *                                 just before HDLV2_Webhook_Monitor::fire. Used by the automation-tier
     *                                 submit handler to inject practitioner_health_summary +
     *                                 consultation_notes_summary without needing a synthetic
     *                                 consultation_notes row. Default empty array preserves all prior
     *                                 caller behaviour byte-for-byte.
     */
    private static function fire_webhook( $progress, $calc_result, $s1_data, $report, $milestones, $why_profile, $recommendations, $health_changes, $notes, $practitioner_id, $overrides = array() ) {
        global $wpdb;

        // v0.28.5 — read URL from constant first (matches other 4 Make.com
        // webhooks), then fall back to the legacy option for any environment
        // that pre-dates this change. If neither is set, log + early-return
        // so we don't post to '' and pollute the webhook-failure ledger with
        // bogus transport errors that would mask real Make.com failures.
        $webhook_url = defined( 'HDLV2_MAKE_FINAL_REPORT' ) ? HDLV2_MAKE_FINAL_REPORT : '';
        if ( ! $webhook_url ) {
            $webhook_url = get_option( self::MAKE_WEBHOOK_OPTION, self::MAKE_WEBHOOK_DEFAULT );
        }
        if ( ! $webhook_url ) {
            error_log( '[HDLV2] Final Report webhook skipped — HDLV2_MAKE_FINAL_REPORT not configured.' );
            return;
        }

        $prac_user = get_userdata( $practitioner_id );

        // v0.20.15 — web-parity fields for the PDF. The web view at
        // /longevity-draft-report/?t=<token> shows a Trajectory chart, a 22-
        // metric Radar, the practitioner's AI-organised consultation summary,
        // and a Flight Plan CTA. Adding the corresponding webhook fields so
        // the PDFMonkey template can mirror that layout.

        // 1) Practitioner's AI-organised consultation notes — same source the
        //    web view uses in HDLV2_Client_Draft_View::rest_get_draft().
        $consult_row = $wpdb->get_var( $wpdb->prepare(
            "SELECT ai_organised_notes FROM {$wpdb->prefix}hdlv2_consultation_notes
             WHERE form_progress_id = %d AND status = 'report_generated'
             ORDER BY id DESC LIMIT 1",
            $progress->id
        ) );
        $organised_notes = $consult_row ? ( json_decode( $consult_row, true ) ?: array() ) : array();
        $practitioner_health_summary = isset( $organised_notes['health_summary'] )
            ? (string) $organised_notes['health_summary'] : '';
        $practitioner_follow_ups = ( isset( $organised_notes['follow_up_actions'] ) && is_array( $organised_notes['follow_up_actions'] ) )
            ? array_values( array_filter( array_map( 'strval', $organised_notes['follow_up_actions'] ) ) )
            : array();

        // v0.28.9 — Raw clinical inputs from Stage 3 used by Page 16 of the
        // PDFMonkey template (Inputs cells now show raw value + reference range
        // alongside the /5 score). HDLV2_Rate_Calculator::calculate_full reads
        // these same keys (bpSystolic, bpDiastolic, restingHeartRate, height,
        // weight, waist, hip) so they're guaranteed present whenever a Final
        // Report can be generated. Cast to int/float so PDFMonkey's IML
        // `replace` chain doesn't interpret quoted strings as JSON tokens.
        $s3_raw = $progress->stage3_data ? ( json_decode( $progress->stage3_data, true ) ?: array() ) : array();
        $bp_sys     = isset( $s3_raw['bpSystolic'] )       ? (int) $s3_raw['bpSystolic']        : null;
        $bp_dia     = isset( $s3_raw['bpDiastolic'] )      ? (int) $s3_raw['bpDiastolic']       : null;
        $hr_bpm     = isset( $s3_raw['restingHeartRate'] ) ? (int) $s3_raw['restingHeartRate']  : null;
        $height_cm  = isset( $s3_raw['height'] )           ? (float) $s3_raw['height']          : null;
        $weight_kg  = isset( $s3_raw['weight'] )           ? (float) $s3_raw['weight']          : null;
        $waist_cm   = isset( $s3_raw['waist'] )            ? (float) $s3_raw['waist']           : null;
        $hip_cm     = isset( $s3_raw['hip'] )              ? (float) $s3_raw['hip']             : null;

        // 2) Flight Plan URL — the CTA at the bottom of the PDF links here.
        $flight_plan_slug = apply_filters( 'hdlv2_flight_plan_slug', 'my-flight-plan' );
        $flight_plan_url  = ! empty( $progress->token )
            ? home_url( '/' . trim( $flight_plan_slug, '/' ) . '/?token=' . $progress->token )
            : '';

        // 3) Trajectory + Radar charts — QuickChart.io PNG URLs (same pattern
        //    as the gauge). Embedded as <img src=...> in the PDFMonkey template.
        $chrono_age_int = (int) ( $s1_data['q1_age'] ?? $s1_data['age'] ?? 0 );
        $bio_age_num    = (float) ( $calc_result['bio_age'] ?? $chrono_age_int );
        $rate_num       = (float) ( $calc_result['rate'] ?? 1.0 );
        $trajectory_chart_url = self::build_trajectory_chart_url( $chrono_age_int, $bio_age_num, $rate_num );
        $radar_chart_url      = self::build_radar_chart_url( $calc_result['scores'] ?? array() );

        // v0.22.8 — Pre-render gauge to a short on-domain PNG. The raw
        // QuickChart.io URL is ~1,800 chars, which Gmail's image proxy
        // refuses to fetch — leaves a broken-image placeholder in the email
        // and a missing gauge in the PDFMonkey output. Same fix Stage 1 got
        // in v0.22.3, now extended to Final Report. Suffix='final' keeps the
        // file separate from Stage 1's `<token>-stage1.png` so a different
        // (post-consultation) rate doesn't collide with the Stage 1 cache.
        // v0.23.0 — Final Report uses calculate_full (rate range 0.5-2.0).
        // Pass stage3:true so the gauge bounds match. Stage 1 callers
        // (build_gauge_url at lines 1245/1259) keep the default 0.8-1.4 bounds.
        $final_gauge_url = HDLV2_Staged_Form::build_gauge_url( $calc_result['rate'] ?? 1.0, array( 'stage3' => true ) );
        if ( class_exists( 'HDLV2_Widget_Config' ) && ! empty( $progress->token ) ) {
            $local_gauge = HDLV2_Widget_Config::prerender_gauge_png( $final_gauge_url, $progress->token, 'final' );
            if ( $local_gauge ) {
                $final_gauge_url = $local_gauge;
            }
        }

        // v0.32.2 — Build the client-facing consultation summary from organised
        // structured data + plain addendum text. Previously this field was a
        // raw mb_substr($notes, 0, 500) of the post-merge Claude prompt, which
        // leaked internal markdown (## Original Consultation Notes, ##
        // Practitioner Addenda, [N] DATE · PRIORITY, ## Re-issue Instructions)
        // into the PDF and got brutally chopped mid-word. See Kim's
        // 2026-05-05 report — Page 16 showed "## Re-issue In" cut off.
        // Now: only human-readable text from health_summary + addenda.
        // v0.34.0 — Defensive fallback chain (Matthew Pass-3 brief: Page 16
        // consult note "removed entirely" for Kim). When the auto-re-organise
        // pipeline produces an empty health_summary OR ai_organised_notes is
        // legacy-shaped (pre-v0.32.5), fall back to the practitioner's typed
        // notes ($notes already has addenda merged in by generate() at the
        // call site, so a single fallback covers both empty-organised AND
        // legacy-shape cases). $organised_notes is set at line ~545 above
        // by JSON-decoding the consultation_notes row.
        $summary_parts = array();
        if ( is_array( $organised_notes ) && ! empty( $organised_notes['health_summary'] ) ) {
            $summary_parts[] = trim( wp_strip_all_tags( $organised_notes['health_summary'] ) );
        }
        if ( empty( $summary_parts ) && ! empty( $notes ) ) {
            $summary_parts[] = trim( wp_strip_all_tags( (string) $notes ) );
        }
        $consultation_summary = trim(
            preg_replace( "/\n{3,}/", "\n\n",
                implode( "\n\n", array_filter( $summary_parts ) )
            )
        );

        // v0.34.0 — Derived display fields for PDFMonkey Liquid branching.
        // Page 3 + 5 + 6 pills/captions, Page 8 Pattern copy, and the cover
        // name all depend on these computed strings being canonical and
        // already-formatted at payload time. See self::derive_*() below.
        $rate_value           = isset( $calc_result['rate'] ) ? (float) $calc_result['rate'] : 1.0;
        $bio_age_value        = isset( $calc_result['bio_age'] ) ? (float) $calc_result['bio_age'] : null;
        $chrono_age_value     = (int) ( $s1_data['q1_age'] ?? $s1_data['age'] ?? 0 );
        $age_shift_value      = ( $bio_age_value !== null && $chrono_age_value > 0 )
            ? round( $bio_age_value - $chrono_age_value, 1 )
            : 0.0;
        $rate_band            = self::derive_rate_band( $rate_value );
        $age_shift_band       = self::derive_age_shift_band( $age_shift_value );
        $lifestyle_band_label = self::derive_lifestyle_band_label( $calc_result['scores'] ?? array() );
        $client_display_name  = self::titlecase_name( $progress->client_name ?: '' );

        $payload = array(
            'report_type'             => 'final',
            'client_name'             => $progress->client_name ?: '',
            // v0.34.0 — Properly titlecased name for the PDF cover. Source
            // `client_name` is whatever the visitor typed (often "kim" all
            // lowercase). Liquid's `capitalize` only handles the first letter,
            // so we titlecase server-side via mb_convert_case (multi-word +
            // unicode safe). Multi-word + hyphenated names render correctly
            // ("sarah anne whitfield-jones" → "Sarah Anne Whitfield-Jones").
            'client_display_name'     => $client_display_name,
            // v0.34.0 — Pre-computed bands for Liquid branching. Avoids
            // duplicating threshold logic across template/prompt/payload.
            'rate_band'               => $rate_band,                 // slow|average|fast|very-fast
            'age_shift_band'          => $age_shift_band,            // optimal|neutral|watch|concern
            'lifestyle_band_label'    => $lifestyle_band_label,      // urgent-cluster|mixed|uniform-mid
            'age_shift_value'         => $age_shift_value,           // signed years (e.g. +3.3 / -2.1)
            'client_email'            => $progress->client_email ?: '',
            'practitioner_name'       => $prac_user ? $prac_user->display_name : '',
            'practitioner_email'      => $prac_user ? $prac_user->user_email : '',
            'practitioner_logo_url'   => self::get_practitioner_logo( $practitioner_id ),
            'chronological_age'       => $s1_data['q1_age'] ?? $s1_data['age'] ?? null,
            'biological_age'          => $calc_result['bio_age'] ?? null,
            // v0.34.4 — Force at-least-1-decimal precision so whole-number rates
            // render as "1.0×" not "1×". Float→JSON coerced "1.0" to numeric 1,
            // which Liquid's {{rate_of_ageing}} then printed as bare "1". Sent
            // as STRING here; Liquid's `rate_n = rate_of_ageing | plus: 0`
            // coerces back to number for band-math comparisons (still works).
            'rate_of_ageing'          => isset( $calc_result['rate'] )
                ? ( fmod( (float) $calc_result['rate'], 1 ) == 0
                    ? number_format( (float) $calc_result['rate'], 1, '.', '' )
                    : number_format( (float) $calc_result['rate'], 2, '.', '' ) )
                : null,
            // v0.28.6 — client_sex drives gender-aware imagery on the new
            // PDFMonkey template (Two Trajectories block on Intro page).
            // Source: Stage 1 q1_sex; falls back to legacy 'gender' key.
            // Lowercased + trimmed for reliable Liquid string comparison.
            'client_sex'              => strtolower( trim( (string) ( $s1_data['q1_sex'] ?? $s1_data['gender'] ?? '' ) ) ),
            'awaken_content'          => $report['awaken_content'] ?? '',
            'lift_content'            => $report['lift_content'] ?? '',
            'thrive_content'          => $report['thrive_content'] ?? '',
            'milestones'              => $milestones,
            'why_profile'             => array(
                'distilled_why' => $why_profile['distilled_why'] ?? '',
                'key_people'    => $why_profile['key_people'] ?? array(),
                'motivations'   => $why_profile['motivations'] ?? array(),
            ),
            'recommendations'         => $recommendations,
            'health_data_changes'     => $health_changes,
            'all_scores'              => $calc_result['scores'] ?? array(),
            // v0.32.2 — see "consultation summary" build above. Generous 1500-char
            // cap so the PDF block can render the full health_summary + addenda
            // text. Layout-side overflow guard lives in template.css
            // (.consult-note-block max-height + 2-column flow + fade mask) so a
            // long note can't push the lifestyle grid off the page.
            'consultation_notes_summary' => mb_substr( $consultation_summary, 0, 1500 ),
            'report_date'             => current_time( 'j F Y' ),
            'distilled_why'           => $why_profile['distilled_why'] ?? '',
            'gauge_url'               => $final_gauge_url,
            'generated_at'            => current_time( 'c' ),
            // Flat milestones for PDFMonkey template
            'ms_6mo'                  => HDLV2_Staged_Form::format_milestones( $milestones['six_months'] ?? array() ),
            'ms_2yr'                  => HDLV2_Staged_Form::format_milestones( $milestones['two_years'] ?? array() ),
            'ms_5yr'                  => HDLV2_Staged_Form::format_milestones( $milestones['five_years'] ?? array() ),
            'ms_10yr'                 => HDLV2_Staged_Form::format_milestones( $milestones['ten_plus_years'] ?? array() ),
            // Flat scores for PDFMonkey template
            'score_bmi'               => $calc_result['scores']['bmiScore'] ?? '',
            'score_whr'               => $calc_result['scores']['whrScore'] ?? '',
            'score_whtr'              => $calc_result['scores']['whtrScore'] ?? '',
            // v0.23.1 — score_overall removed (Matthew 2026-04-28). PDFMonkey
            // template should drop any reference to {{score_overall}}; missing
            // field renders as empty in templates that still expect it.
            'score_bp'                => $calc_result['scores']['bloodPressureScore'] ?? '',
            'score_hr'                => $calc_result['scores']['heartRateScore'] ?? '',
            // v0.32.0 — score_skin removed from PDF payload (Matthew second-pass
            // 2026-05-05). Skin elasticity dropped from Final Report inputs page,
            // radar dataset, and lifestyle average. Calculator still collects the
            // score in case we re-introduce a separate skin reading later.
            'score_activity'          => $calc_result['scores']['physicalActivity'] ?? '',
            'score_sleep_dur'         => $calc_result['scores']['sleepDuration'] ?? '',
            'score_sleep_qual'        => $calc_result['scores']['sleepQuality'] ?? '',
            'score_stress'            => $calc_result['scores']['stressLevels'] ?? '',
            'score_social'            => $calc_result['scores']['socialConnections'] ?? '',
            'score_diet'              => $calc_result['scores']['dietQuality'] ?? '',
            'score_alcohol'           => $calc_result['scores']['alcoholConsumption'] ?? '',
            'score_smoking'           => $calc_result['scores']['smokingStatus'] ?? '',
            'score_cognitive'         => $calc_result['scores']['cognitiveActivity'] ?? '',
            'score_supplements'       => $calc_result['scores']['supplementIntake'] ?? '',
            'score_sunlight'          => $calc_result['scores']['sunlightExposure'] ?? '',
            'score_hydration'         => $calc_result['scores']['dailyHydration'] ?? '',
            'score_sit_stand'         => $calc_result['scores']['sitToStand'] ?? '',
            'score_breath'            => $calc_result['scores']['breathHold'] ?? '',
            'score_balance'           => $calc_result['scores']['balance'] ?? '',
            // v0.20.15 — web-parity fields (see block above for source)
            'practitioner_health_summary' => $practitioner_health_summary,
            'practitioner_follow_ups'     => $practitioner_follow_ups,
            'trajectory_chart_url'        => $trajectory_chart_url,
            'radar_chart_url'             => $radar_chart_url,
            'flight_plan_url'             => $flight_plan_url,
            // v0.23.7 — body composition + practitioner initials for the
            // redesigned 5-page Final PDF (Matthew C2 spec). bmi/whr/whtr
            // are pulled from the freshly-recomputed calc_result so they
            // reflect any practitioner edits applied during consultation.
            'bmi'                         => $calc_result['bmi'] ?? null,
            'whr'                         => $calc_result['whr'] ?? null,
            'whtr'                        => $calc_result['whtr'] ?? null,
            'practitioner_initials'       => self::derive_initials( $prac_user ? $prac_user->display_name : '' ),
            // v0.28.9 — Raw clinical inputs (resting HR bpm, BP mmHg, anthropometrics).
            // Used by PDFMonkey Page 16 to render `RESTING HR · 58 bpm · 5/5 STRONG · range 50–70`.
            'resting_hr_bpm'              => $hr_bpm,
            'bp_systolic'                 => $bp_sys,
            'bp_diastolic'                => $bp_dia,
            'height_cm'                   => $height_cm,
            'weight_kg'                   => $weight_kg,
            'waist_cm'                    => $waist_cm,
            'hip_cm'                      => $hip_cm,
            // v0.28.12 — Pre-flatten WHY profile arrays so Make.com Module 200's
            // user message can reference them via simple {{1.field}} mappings
            // without IML join() gymnastics. Empty string when array is missing
            // / empty so Liquid `{% if X != blank %}` guards work cleanly.
            // v0.40.0 — Fix: key_people entries are associative arrays
            // ({name, relationship, age, note}) per extract_why schema, not
            // strings. strval() on an assoc array yields "Array" and a PHP
            // warning. Pull the `name` field; fall back to (string) for
            // legacy rows that stored plain strings. Same pattern for
            // motivations (always strings, but defensive).
            'key_people_csv'              => is_array( $why_profile['key_people'] ?? null )
                ? implode( ', ', array_filter( array_map( function ( $p ) {
                    if ( is_array( $p ) ) return (string) ( $p['name'] ?? '' );
                    return (string) $p;
                }, $why_profile['key_people'] ) ) )
                : '',
            'motivations_csv'             => is_array( $why_profile['motivations'] ?? null )
                ? implode( ', ', array_filter( array_map( function ( $m ) {
                    if ( is_array( $m ) ) return (string) ( $m['text'] ?? $m['note'] ?? '' );
                    return (string) $m;
                }, $why_profile['motivations'] ) ) )
                : '',
            // v0.38.1 — Stage 3 Section 6 "Health Background" passthrough.
            // Three optional long-form text fields. Empty when client skipped
            // Section 6. PDFMonkey + Make.com Module 17 + Module 91 can opt
            // in to render via {{1.family_history}} / {{1.medications}} /
            // {{1.existing_conditions}}.
            'family_history'              => (string) ( $s3_raw['family_history']      ?? '' ),
            'medications'                 => (string) ( $s3_raw['medications']         ?? '' ),
            'existing_conditions'         => (string) ( $s3_raw['existing_conditions'] ?? '' ),
        );

        // v0.23.8 — flatten recommendations into 20 rec_N_* fields for
        // PDFMonkey. Make.com's expression `{{1.recommendations[N].field}}`
        // inside a JSON Dynamic Data string fails to drill into array-of-
        // objects (returns empty). Sending pre-flattened fields avoids the
        // limitation entirely. Up to 5 slots; missing slots = empty string
        // so {% if rec_N_text != blank %} guards the Liquid template.
        $rec_list = is_array( $recommendations ) ? array_values( $recommendations ) : array();
        for ( $i = 0; $i < 5; $i++ ) {
            $r = isset( $rec_list[ $i ] ) && is_array( $rec_list[ $i ] ) ? $rec_list[ $i ] : array();
            $payload[ 'rec_' . ( $i + 1 ) . '_category' ] = (string) ( $r['category']  ?? '' );
            $payload[ 'rec_' . ( $i + 1 ) . '_text' ]     = (string) ( $r['text']      ?? '' );
            $payload[ 'rec_' . ( $i + 1 ) . '_priority' ] = (string) ( $r['priority']  ?? '' );
            $payload[ 'rec_' . ( $i + 1 ) . '_freq' ]     = (string) ( $r['frequency'] ?? '' );
        }

        // v0.23.8 — Convert practitioner_follow_ups from array to newline-
        // joined string. Make.com's `join(...; newline)` expression doesn't
        // reliably insert literal \n characters that Liquid's
        // `newline_to_br` filter can convert. Pre-joining server-side is
        // robust. Original array structure is preserved in the DB
        // (`ai_organised_notes.follow_up_actions`) — only the webhook
        // shape changes.
        if ( is_array( $payload['practitioner_follow_ups'] ) ) {
            $payload['practitioner_follow_ups'] = implode( "\n", array_filter( array_map( 'strval', $payload['practitioner_follow_ups'] ) ) );
        }

        // v0.27.2 — Pre-sanitise text fields for Make.com PDFMonkey "Generate
        // Document" module. That module's Dynamic Data is a raw JSON template
        // with bare {{1.field}} placeholders; Make.com substitutes the decoded
        // value WITHOUT re-escaping. A literal " in any text field
        // (e.g. Claude paraphrasing 'bit of a gut' in practitioner notes)
        // produces malformed JSON and PDFMonkey rejects with
        // [422] payload: unexpected character ... [parse.c:931].
        // IML-side `replace(...; "\""; "\\\"")` did NOT fix it on STBY 2026-04-30.
        // Reliable fix: strip problematic chars here. HTML content fields exempt
        // — they need " in tag attributes; Make.com IML's `newline` keyword
        // already escapes embedded LFs in those.
        $html_safe_fields = array( 'awaken_content', 'lift_content', 'thrive_content' );
        array_walk_recursive( $payload, function ( &$v, $k ) use ( $html_safe_fields ) {
            if ( is_string( $v ) && ! in_array( $k, $html_safe_fields, true ) ) {
                $v = strtr( $v, array( '"' => "'", '\\' => '/', "\r" => '', "\t" => '    ' ) );
            }
        } );

        // W9 (v0.41.31) — automation-tier overrides. Applied AFTER the
        // rec_N_* / practitioner_follow_ups joins + html-safe sanitisation so
        // automation-tier overrides land in their final, on-the-wire form
        // without needing to re-implement those passes. Empty array (default)
        // is a no-op.
        if ( ! empty( $overrides ) ) {
            $payload = array_replace( $payload, $overrides );
        }

        HDLV2_Webhook_Monitor::fire(
            $webhook_url,
            array(
                'body'     => wp_json_encode( $payload ),
                'headers'  => array( 'Content-Type' => 'application/json' ),
                'timeout'  => 10,
                'blocking' => true,
            ),
            'final_report'
        );
    }

    /**
     * W9 (v0.41.31) — Fire the Final Report Make.com webhook for an
     * automation-tier client. Loads the same data the practitioner-led path
     * loads (progress + calc + s1 + why_profile), then delegates to
     * fire_webhook with overrides for the practitioner-supplied fields.
     *
     * Empty $report (no awaken/lift/thrive markdown) — Make.com Route 1
     * generates those sections itself when they arrive empty. $health_changes
     * is empty and $practitioner_id=0 because no practitioner has touched the
     * row. $notes is empty too — the marker-prefixed self-reported content is
     * shipped via the `practitioner_health_summary` override.
     *
     * @param int    $form_progress_id     the client's hdlv2_form_progress row
     * @param string $marker_health_summary marker-prefixed self-reported content
     *                                     (lands in practitioner_health_summary)
     * @param string $brief_summary         500-char truncation (lands in
     *                                     consultation_notes_summary)
     * @param array  $ai_recommendations    array of {text, category} from W9 Claude call
     * @param array  $ai_milestones         four-key array (six_months/two_years/
     *                                     five_years/ten_plus_years), each an array of
     *                                     milestone strings (HDLV2_Staged_Form::format_milestones
     *                                     consumes this exact shape)
     * @return true|WP_Error              true on successful fire; WP_Error on missing data
     */
    public static function fire_for_automation_tier( $form_progress_id, $marker_health_summary, $brief_summary, $ai_recommendations, $ai_milestones ) {
        global $wpdb;

        $progress = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hdlv2_form_progress
             WHERE id = %d AND deleted_at IS NULL",
            (int) $form_progress_id
        ) );
        if ( ! $progress ) {
            return new WP_Error( 'not_found', 'Form progress not found for automation-tier webhook.' );
        }

        $s1_data = json_decode( $progress->stage1_data, true ) ?: array();
        $s3_data = json_decode( $progress->stage3_data, true ) ?: array();

        $calc_data = array_merge( $s1_data, $s3_data );
        foreach ( $calc_data as $k => $v ) { if ( $v === 'skip' ) $calc_data[ $k ] = null; }
        $age    = (int) ( $calc_data['q1_age'] ?? $calc_data['age'] ?? 0 );
        $gender = $calc_data['q1_sex'] ?? $calc_data['gender'] ?? 'other';
        $calc_result = HDLV2_Rate_Calculator::calculate_full( $age, $calc_data, $gender );

        $why_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT distilled_why, ai_reformulation, key_people, motivations, fears
             FROM {$wpdb->prefix}hdlv2_why_profiles WHERE form_progress_id = %d LIMIT 1",
            (int) $form_progress_id
        ), ARRAY_A );
        $why_profile = $why_row ?: array();
        if ( ! empty( $why_profile['key_people'] ) ) {
            $why_profile['key_people'] = json_decode( $why_profile['key_people'], true );
        }
        if ( ! empty( $why_profile['motivations'] ) ) {
            $why_profile['motivations'] = json_decode( $why_profile['motivations'], true );
        }
        if ( ! empty( $why_profile['fears'] ) ) {
            $why_profile['fears'] = json_decode( $why_profile['fears'], true );
        }

        $overrides = array(
            'practitioner_health_summary' => (string) $marker_health_summary,
            'consultation_notes_summary'  => (string) $brief_summary,
            // Automation tier has no practitioner follow-up actions — null out
            // explicitly so PDFMonkey's Liquid `{% if %}` doesn't render a stale
            // value from an earlier payload's array-join.
            'practitioner_follow_ups'     => '',
            'practitioner_name'           => '',
            'practitioner_email'          => '',
            'practitioner_initials'       => '',
        );

        self::fire_webhook(
            $progress,
            $calc_result,
            $s1_data,
            array(), // $report — Make.com Route 1 generates awaken/lift/thrive
            $ai_milestones,
            $why_profile,
            $ai_recommendations,
            array(), // $health_changes — none for automation tier
            '',      // $notes — content lives in overrides[practitioner_health_summary]
            0,       // $practitioner_id — none for automation tier
            $overrides
        );

        return true;
    }

    // ──────────────────────────────────────────────────────────────
    //  EMAIL NOTIFICATIONS
    // ──────────────────────────────────────────────────────────────

    private static function send_emails( $progress, $calc_result, $practitioner_id ) {
        $client_email = $progress->client_email ?: '';
        $client_name  = $progress->client_name ?: '';
        $rate         = $calc_result['rate'] ?? 'N/A';
        $bio_age      = $calc_result['bio_age'] ?? 'N/A';

        // Client: branded HTML with practitioner's logo (v0.15.16; was plain-text)
        //
        // v0.36.4 — link-only email gated on the Make.com webhook NOT being
        // configured. Practitioner reported on STBY 2026-05-08 that clients
        // were getting two Final-Report emails — one link-only (this one) and
        // one PDF-attached (the Make.com → PDFMonkey → Gmail path triggered by
        // fire_webhook above). His exact ask: "keep the one with the PDF, but
        // remove the link to the report, because they don't need both."
        //
        // Strategy: skip this email when HDLV2_MAKE_FINAL_REPORT is defined
        // (LIVE), because Make.com will deliver the PDF with the same client-
        // facing call-to-action. Keep firing it when NOT defined (STBY +
        // future envs without Make.com), so testers and any non-Make.com
        // deployment still receive a Final-Report email instead of zero.
        //
        // Dormant on STBY today: HDLV2_MAKE_FINAL_REPORT is not defined there
        // (per CLAUDE.md). Practitioner testing on STBY continues to receive
        // the link email until Make.com is wired up. On LIVE, Make.com is
        // configured → only the PDF email lands → matches the requested UX.
        //
        // Reversible: removing the `! defined(...)` guard restores the legacy
        // both-emails behaviour with zero data risk.
        $skip_link_email = defined( 'HDLV2_MAKE_FINAL_REPORT' ) && (string) HDLV2_MAKE_FINAL_REPORT !== '';
        if ( $client_email && ! $skip_link_email ) {
            // v0.20.6 — Link clients to the Final Report (new layout) instead
            // of the Flight Plan page. The Final Report renders on the same
            // URL as the draft view; the JS detects the finalised row, flips
            // the badge to FINAL, and adds the "From your practitioner" +
            // Milestone Timeline sections. Autologin at plugin init fires on
            // the `token` param, so the client lands authenticated. A button
            // in the Final Report's "What happens next" section points them
            // on to their Week 1 Flight Plan.
            $report_slug = apply_filters( 'hdlv2_final_report_slug', 'longevity-draft-report' );
            $report_url  = home_url( '/' . trim( $report_slug, '/' ) . '/?token=' . $progress->token );
            $html = HDLV2_Email_Templates::final_report_ready_client( array(
                'client_name'     => $client_name,
                'client_email'    => $client_email,
                'rate'            => $rate,
                'bio_age'         => $bio_age,
                'report_url'      => $report_url,
                'practitioner_id' => $practitioner_id,
            ) );
            wp_mail(
                $client_email,
                'Your Trajectory Plan is Ready — HealthDataLab',
                $html,
                array( 'Content-Type: text/html; charset=UTF-8' )
            );
        } elseif ( $client_email && $skip_link_email ) {
            // Logged so we can confirm the gate fired during smoke tests on
            // any environment with Make.com configured. No client-visible
            // change — Make.com fires the PDF email instead.
            error_log( sprintf(
                '[HDLV2 send_emails] link-only Final Report email suppressed for client_email=%s (HDLV2_MAKE_FINAL_REPORT configured; Make.com will deliver PDF)',
                $client_email
            ) );
        }

        // v0.20.18 — Practitioner email for Final Report moved to Make.com
        // scenario (new Router branch alongside the client Gmail module,
        // mirrors client body + attaches same PDFMonkey PDF). Removed the
        // WP-side HTML-only summary to eliminate the duplicate email.
        //
        // The webhook fired by fire_webhook() (above) already carries
        // practitioner_name, practitioner_email, practitioner_logo_url, and
        // the full scores/rate/bio_age block — Make.com reads those fields
        // to populate the practitioner Gmail module.
        //
        // $practitioner_id is still accepted on this method's signature to
        // keep the call site at line 259 stable; the value is otherwise
        // unused here.
        //
        // Orphaned template method HDLV2_Email_Templates::final_report_generated_practitioner
        // (class-hdlv2-email-templates.php) kept for now — no other callers,
        // but leaving it avoids a second unrelated deletion in this change.
        unset( $practitioner_id );
    }

    /**
     * Get practitioner's logo URL — thin wrapper around HDLV2_Practitioner.
     * Kept as a private method for backward compatibility with callers
     * inside this class (line ~500); the canonical logic lives in
     * HDLV2_Practitioner::get_logo_url() and includes file-existence
     * validation so a 404 URL never reaches Make.com / PDFMonkey.
     */
    private static function get_practitioner_logo( $practitioner_id ) {
        return HDLV2_Practitioner::get_logo_url( (int) $practitioner_id );
    }

    /**
     * Build QuickChart.io gauge URL for the rate of ageing.
     * Same visual style as the widget and Stage 1 emails.
     */
    private static function build_gauge_url( $rate ) {
        $clamped = max( 0.8, min( 1.4, round( (float) $rate, 2 ) ) );

        if ( $clamped <= 0.9 ) {
            $sub_text = 'Slower'; $sub_color = 'rgba(67, 191, 85, 1)';
        } elseif ( $clamped <= 1.1 ) {
            $sub_text = 'Average'; $sub_color = 'rgba(65, 165, 238, 1)';
        } else {
            $sub_text = 'Faster'; $sub_color = 'rgba(255, 111, 75, 1)';
        }

        $cfg = array(
            'type' => 'gauge',
            'data' => array(
                'labels'   => array( 'Slower', 'Average', 'Faster' ),
                'datasets' => array( array(
                    'data'            => array( 0.9, 1.1, 1.4 ),
                    'value'           => $clamped,
                    'minValue'        => 0.8,
                    'maxValue'        => 1.4,
                    'backgroundColor' => array( 'rgba(67,191,85,0.95)', 'rgba(65,165,238,0.95)', 'rgba(255,111,75,0.95)' ),
                    'borderWidth'     => 1,
                    'borderColor'     => 'rgba(255,255,255,0.8)',
                    'borderRadius'    => 5,
                ) ),
            ),
            'options' => array(
                'layout'  => array( 'padding' => array( 'top' => 30, 'bottom' => 15, 'left' => 15, 'right' => 15 ) ),
                'plugins' => array( 'datalabels' => array( 'display' => false ) ),
                'needle'  => array(
                    'radiusPercentage' => 2.5, 'widthPercentage' => 4.0, 'lengthPercentage' => 68,
                    'color' => '#004F59', 'shadowColor' => 'rgba(0,79,89,0.4)', 'shadowBlur' => 8, 'shadowOffsetY' => 4,
                    'borderWidth' => 2, 'borderColor' => 'rgba(255,255,255,1.0)',
                ),
                // v0.28.10 — valueLabel + subtitle disabled (PDFMonkey template
                // now renders the value below the gauge and band labels as a
                // rotated SVG overlay above the dial). Note: this private method
                // is dead code (Final Report calls Staged_Form's build_gauge_url
                // — see line 470) but kept in sync to prevent future drift.
                'valueLabel' => array(
                    'display' => false, 'fontSize' => 36, 'fontFamily' => "'Inter',sans-serif", 'fontWeight' => 'bold',
                    'color' => '#004F59', 'backgroundColor' => 'transparent', 'bottomMarginPercentage' => -10, 'padding' => 8,
                ),
                'centerArea' => array( 'displayText' => false, 'backgroundColor' => 'transparent' ),
                'arc'        => array( 'borderWidth' => 0, 'padding' => 2, 'margin' => 3, 'roundedCorners' => true ),
                'subtitle'   => array(
                    'display' => false, 'text' => $sub_text, 'color' => $sub_color,
                    'font' => array( 'size' => 20, 'weight' => 'bold', 'family' => "'Inter',sans-serif" ),
                    'padding' => array( 'top' => 8 ),
                ),
            ),
        );

        return 'https://quickchart.io/chart?c=' . rawurlencode( wp_json_encode( $cfg ) ) . '&w=380&h=340&bkg=white';
    }

    /**
     * Return the URL of the V1 "health-over-life" trajectory chart.
     *
     * v0.20.17 — switched from a QuickChart.io 2-line bio-vs-chrono approximation
     * to the ported V1 HDLTrajectoryChart served as SVG from
     * HDLV2_Trajectory_SVG::url_for(). This is the same chart the client
     * already sees on /longevity-draft-report/?t=<token> — 9 percentile bands,
     * user health curve, optimistic + pessimistic projections, zone shading.
     *
     * $bio_age is unused here (the V1 chart derives everything from
     * chrono_age + rate) but is kept in the signature so downstream callers
     * don't need to change.
     */
    private static function build_trajectory_chart_url( $chrono_age, $bio_age, $rate ) {
        unset( $bio_age ); // intentionally unused — V1 chart is (age, rate) only
        if ( ! class_exists( 'HDLV2_Trajectory_SVG' ) ) return '';
        return HDLV2_Trajectory_SVG::url_for( $chrono_age, $rate );
    }

    /**
     * Build a QuickChart.io radar chart of the 21 health metrics (0-5 scale).
     * Same metric ordering as the web view's Chart.js radar so both renders
     * look identical.
     *
     * v0.23.5 — promoted from `private` to `public` so the practitioner draft
     * email (sprint-2/class-hdlv2-staged-form.php::send_stage_email Stage 3
     * branch) can reuse the same renderer Final Report uses. Single source of
     * truth for the radar visual across web view, PDF, and email.
     */
    public static function build_radar_chart_url( $scores ) {
        if ( ! is_array( $scores ) || empty( $scores ) ) return '';

        $ordered = array(
            'physicalActivity'    => 'Activity',
            'sitToStand'          => 'Sit-Stand',
            'breathHold'          => 'Breath',
            'balance'             => 'Balance',
            'sleepDuration'       => 'Sleep Dur.',
            'sleepQuality'        => 'Sleep Qual.',
            'stressLevels'        => 'Stress',
            'socialConnections'   => 'Social',
            'dietQuality'         => 'Diet',
            'alcoholConsumption'  => 'Alcohol',
            'smokingStatus'       => 'Smoking',
            'cognitiveActivity'   => 'Cognitive',
            'sunlightExposure'    => 'Sunlight',
            'supplementIntake'    => 'Supplements',
            'dailyHydration'      => 'Hydration',
            // v0.32.0 — skinElasticity dropped from radar (Matthew second-pass 2026-05-05).
            'bmiScore'            => 'BMI',
            'whrScore'            => 'WHR',
            'whtrScore'           => 'WHtR',
            'bloodPressureScore'  => 'BP',
            'heartRateScore'      => 'HR',
            // v0.23.1 — overallHealthScore removed (Matthew 2026-04-28).
        );

        $labels = array();
        $values = array();
        foreach ( $ordered as $key => $label ) {
            if ( isset( $scores[ $key ] ) && is_numeric( $scores[ $key ] ) ) {
                $labels[] = $label;
                $values[] = round( (float) $scores[ $key ], 1 );
            }
        }
        if ( empty( $values ) ) return '';

        // v0.28.8 — Per-point colour mapping makes the radar diagnostic instead
        // of decorative: 5/5 green · 4/5 lime · 3/5 amber · 2/5 orange · 1/5 red.
        // Reader sees at a glance where the spokes need lifting (Matthew brief
        // 2026-05-04). Anchor dataset retained for axis-scale reliability but
        // its label is empty string + every visual property is fully transparent
        // so it never surfaces in the legend, regardless of QuickChart's
        // legend-display behaviour. Title removed (handled by template h2).
        $point_colors = array_map( function ( $v ) {
            $n = (float) $v;
            if ( $n >= 4.5 ) return 'rgba(16,185,129,1)';   // optimal (5)
            if ( $n >= 3.5 ) return 'rgba(132,204,22,1)';   // solid (4) lime
            if ( $n >= 2.5 ) return 'rgba(217,119,6,1)';    // moderate (3) amber
            if ( $n >= 1.5 ) return 'rgba(234,88,12,1)';    // lift (2) orange
            return 'rgba(220,38,38,1)';                      // urgent (1)
        }, $values );

        // v0.34.0 — Anchor dataset dropped. The scale is already locked by
        // `'r' => array('min'=>0,'max'=>5,'beginAtZero'=>true)` below, so the
        // hidden anchor dataset was decorative-only and produced the small
        // grey artefact at the top of Page 7's radar (Matthew Pass-3 brief).
        // Single-dataset radars are also faster + cheaper to render.
        $cfg = array(
            'type' => 'radar',
            'data' => array(
                'labels'   => $labels,
                'datasets' => array(
                    array(
                        'label'                => '',  // legend hidden anyway; no visible label needed
                        'data'                 => $values,
                        'fill'                 => true,
                        'backgroundColor'      => 'rgba(61, 141, 160, 0.15)',
                        'borderColor'          => 'rgba(61, 141, 160, 0.9)',
                        'pointBackgroundColor' => $point_colors,
                        'pointBorderColor'     => '#fff',
                        'pointBorderWidth'     => 1.5,
                        'pointRadius'          => 4.5,
                        'borderWidth'          => 2,
                    ),
                ),
            ),
            'options' => array(
                // v0.34.1 — Chart.js v4 syntax (matched by `&v=4` URL param below).
                // Previously v2 was the default + `scales.r` was ignored, causing
                // the radar to auto-fit data range (Mary 3.0–5.0, Mike 1.5–4.0).
                // Forcing min:0/max:5/stepSize:1 here gives every client the same
                // 0,1,2,3,4,5 axis regardless of their score distribution.
                'scales' => array(
                    'r' => array(
                        'min'         => 0,
                        'max'         => 5,
                        'beginAtZero' => true,
                        'ticks'       => array(
                            'stepSize'           => 1,
                            'showLabelBackdrop'  => false,
                            'font'               => array( 'size' => 9 ),
                            'color'              => '#999',
                            // Show all 6 tick values (0,1,2,3,4,5) — v4 will
                            // otherwise hide ticks it considers crowded.
                            'maxTicksLimit'      => 6,
                            'precision'          => 0,
                        ),
                        'grid'        => array( 'color' => 'rgba(0,0,0,0.08)' ),
                        'angleLines'  => array( 'color' => 'rgba(0,0,0,0.08)' ),
                        'pointLabels' => array( 'font' => array( 'size' => 9.5, 'family' => "'Inter',sans-serif", 'weight' => '500' ), 'color' => '#2c3e50' ),
                    ),
                ),
                'plugins' => array(
                    // v4 syntax — `legend` lives under `plugins`.
                    'legend' => array( 'display' => false ),
                    'title'  => array( 'display' => false ),
                    // Tooltips never render in static QuickChart PNG output but
                    // turning them off explicitly avoids any layout reservation.
                    'tooltip' => array( 'enabled' => false ),
                ),
                // v0.34.1 — Zero padding to eliminate the small grey rectangle
                // QuickChart was reserving at the top for the (hidden) legend.
                'layout' => array(
                    'padding' => array( 'top' => 0, 'bottom' => 0, 'left' => 0, 'right' => 0 ),
                ),
                // v4 — explicit aspect lock so radar always renders square.
                'maintainAspectRatio' => true,
                'aspectRatio'         => 1,
            ),
        );

        // v0.34.1 — Use Chart.js v4 (default v2 ignored scales.r and auto-fitted
        // the data range, producing the misleading 3.0–5.0 / 1.5–4.0 axes
        // Matthew flagged on Mary + Mike's PDFs).
        return 'https://quickchart.io/chart?c=' . rawurlencode( wp_json_encode( $cfg ) ) . '&w=540&h=540&bkg=white&v=4';
    }

    /**
     * v0.24.3 — Regenerate Final report after practitioner edits.
     * v0.34.3 — REWRITTEN. The legacy "archive prior Final + INSERT new"
     * pattern is incompatible with Phase M (DB v3.3) which added a
     * UNIQUE KEY uniq_progress_report (form_progress_id, report_type) on
     * hdlv2_reports. The unique key blocks the second INSERT silently;
     * combined with Phase J's failed enum migration on STBY (status=
     * 'archived' truncated to '' on non-strict MySQL), every Save & Update
     * Plan click since 2026-05-05 corrupted the row + sent fake-success to
     * the practitioner. See PHASE-N-CHANGES-FOR-LIVE.md.
     *
     * New strategy: UPDATE the existing Final row in place. Same id, same
     * unique-key slot. Phase N migration (run on first boot at v3.5) heals
     * any rows whose status was already truncated to ''.
     *
     * Edit-as-reset rule (Quim 2026-04-29): when a practitioner edits the
     * consultation post-Final, the Flight Plan resets to Week 1. The flight
     * plan archive UPDATE moved into ::generate() so first-finalise + regen
     * share the same code path; it's idempotent (matches 0 rows on first
     * finalise, archives any live plans before scheduling fresh ones).
     *
     * Pre-flight rec check (v0.34.3): we run auto re-organise FIRST, then
     * count merged rec sources before any further state mutation. If recs
     * are empty, we WP_Error early — the practitioner sees the recovery
     * panel without the Final being touched. No flight plan archive, no
     * fresh email, no fresh PDF. Cheap-fail UX.
     */
    public static function regenerate( $progress_id, $consult_id, $practitioner_id ) {
        global $wpdb;

        // v0.41.17 — `AND deleted_at IS NULL` on the entry-point lookup.
        $progress = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, client_user_id, practitioner_user_id FROM {$wpdb->prefix}hdlv2_form_progress
             WHERE id = %d AND deleted_at IS NULL",
            $progress_id
        ) );
        if ( ! $progress ) {
            return new WP_Error( 'not_found', 'Assessment not found.', array( 'status' => 404 ) );
        }
        if ( (int) $progress->practitioner_user_id !== (int) $practitioner_id
             && ! user_can( (int) $practitioner_id, 'manage_options' ) ) {
            return new WP_Error( 'forbidden', 'You do not have access to this assessment.', array( 'status' => 403 ) );
        }

        // ── Find the existing Final row to UPDATE in place ──
        // Look across all status values, not just 'ready'. Phase N may have
        // already healed any corrupted rows, but this is also defensive
        // against transient migration windows. The unique key guarantees at
        // most one row per (progress, type), so LIMIT 1 is enough.
        $existing_final_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}hdlv2_reports
             WHERE form_progress_id = %d AND report_type = 'final'
             ORDER BY id DESC LIMIT 1",
            $progress_id
        ) );
        if ( ! $existing_final_id ) {
            return new WP_Error(
                'no_final',
                'Cannot regenerate: no Trajectory Plan exists yet. Click Generate Trajectory Plan first.',
                array( 'status' => 409 )
            );
        }

        // ── Refresh ai_organised_notes with addenda context ──
        // v0.36.2 — Three paths, in priority order:
        //
        //   PATH A (preferred). Existing organised JSON + new (un-superseded)
        //     addenda → integrate_addenda_into_organised(). Preserves the
        //     practitioner's inline edits to ai_organised_notes verbatim and
        //     ADDITIVELY layers each new addendum into health_summary as a
        //     "**Update DD MMM YYYY**" paragraph + adds action items into
        //     follow_up_actions/recommendations. The client sees the
        //     practitioner's clinical updates explicitly.
        //
        //   PATH B (bootstrap / fallback). No existing organised JSON, OR
        //     Path A returned WP_Error → organise_consultation_notes() on
        //     raw_notes + addenda. Legacy behaviour: re-runs the full
        //     organise from scratch. Used only when there's no curated
        //     state to preserve, or when integrate misfired.
        //
        //   PATH C (no-op). Existing organised JSON exists AND no new addenda
        //     to integrate → preserve as-is. The practitioner's "auto-saved
        //     AI summary edits" promised in the confirm modal stay intact;
        //     the downstream generate() reads the current ai_organised_notes
        //     when it builds typed_notes for the awaken/lift/thrive prose.
        //
        // Failures across all paths are logged but non-blocking — addendum
        // content still reaches awaken/lift/thrive via the merge step inside
        // generate() even when this refresh step misfires entirely.
        $consult_row       = null;
        $addenda_for_merge = array();
        if ( $consult_id && class_exists( 'HDLV2_AI_Service' ) ) {
            $consult_row = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, raw_notes, typed_notes, ai_organised_notes, recommendations FROM {$wpdb->prefix}hdlv2_consultation_notes WHERE id = %d LIMIT 1",
                $consult_id
            ) );
            if ( $consult_row ) {
                // Load ALL addenda for this consultation (used by the pre-
                // flight rec count below + by generate() for the awaken/
                // lift/thrive merge). The chronological pass-through matches
                // merge_consultation_with_addenda()'s expected input.
                $addenda_for_merge = $wpdb->get_results( $wpdb->prepare(
                    "SELECT id, note_text, occurred_at, priority, source, superseded_by_report_id
                     FROM {$wpdb->prefix}hdlv2_consultation_addenda
                     WHERE consultation_id = %d
                     ORDER BY occurred_at ASC, id ASC",
                    $consult_id
                ), ARRAY_A ) ?: array();

                // Filter to just the un-integrated addenda (those that have
                // not yet been stamped to a Final). Already-stamped addenda
                // already live in health_summary as Update paragraphs from a
                // prior regen — re-integrating them would duplicate.
                $new_addenda = array_values( array_filter(
                    $addenda_for_merge,
                    static function ( $a ) {
                        return empty( $a['superseded_by_report_id'] );
                    }
                ) );

                $existing_organised = ! empty( $consult_row->ai_organised_notes )
                    ? ( json_decode( $consult_row->ai_organised_notes, true ) ?: null )
                    : null;

                $integrated = null;

                // PATH A — additive integrate (preserves practitioner edits)
                if ( is_array( $existing_organised ) && ! empty( $new_addenda ) ) {
                    $candidate = HDLV2_AI_Service::integrate_addenda_into_organised(
                        $existing_organised,
                        $new_addenda
                    );
                    if ( is_wp_error( $candidate ) ) {
                        error_log( '[HDLV2 regenerate] integrate_addenda_into_organised failed: '
                            . $candidate->get_error_message() . ' — falling back to organise-from-raw' );
                    } else {
                        $integrated = $candidate;
                        error_log( sprintf(
                            '[HDLV2 regenerate] integrated %d new addenda into existing organised consult=%d → %d recs, %d follow-ups',
                            count( $new_addenda ),
                            $consult_id,
                            count( (array) ( $integrated['recommendations'] ?? array() ) ),
                            count( (array) ( $integrated['follow_up_actions'] ?? array() ) )
                        ) );
                    }
                }

                // PATH B — bootstrap (no existing organised) OR Path A fallback
                if ( ! is_array( $integrated ) && ! is_array( $existing_organised ) ) {
                    $base_text = trim( (string) ( $consult_row->raw_notes ?: $consult_row->typed_notes ?: '' ) );
                    if ( $base_text !== '' || ! empty( $addenda_for_merge ) ) {
                        $merged_text = HDLV2_AI_Service::merge_consultation_with_addenda( $base_text, $addenda_for_merge );
                        $reorganised = HDLV2_AI_Service::organise_consultation_notes( $merged_text );
                        if ( ! is_wp_error( $reorganised ) && is_array( $reorganised ) ) {
                            $integrated = $reorganised;
                            error_log( sprintf(
                                '[HDLV2 regenerate] bootstrapped organised from raw+addenda consult=%d → %d recs, %d follow-ups',
                                $consult_id,
                                count( (array) ( $integrated['recommendations'] ?? array() ) ),
                                count( (array) ( $integrated['follow_up_actions'] ?? array() ) )
                            ) );
                        } else {
                            error_log( '[HDLV2 regenerate] bootstrap organise failed; proceeding with no organised JSON' );
                        }
                    }
                } elseif ( ! is_array( $integrated ) && is_array( $existing_organised ) && ! empty( $new_addenda ) ) {
                    // Path A failed but we have organised + new addenda. Try
                    // organise-from-raw as the fallback so the addendum at
                    // least reaches the recommendations section. This
                    // behaviour matches the v0.28-v0.36.1 path so it can't
                    // regress addendum visibility there either.
                    $base_text = trim( (string) ( $consult_row->raw_notes ?: $consult_row->typed_notes ?: '' ) );
                    if ( $base_text !== '' || ! empty( $addenda_for_merge ) ) {
                        $merged_text = HDLV2_AI_Service::merge_consultation_with_addenda( $base_text, $addenda_for_merge );
                        $reorganised = HDLV2_AI_Service::organise_consultation_notes( $merged_text );
                        if ( ! is_wp_error( $reorganised ) && is_array( $reorganised ) ) {
                            $integrated = $reorganised;
                            error_log( '[HDLV2 regenerate] integrate fallback: organise-from-raw succeeded after Path A failure' );
                        } else {
                            error_log( '[HDLV2 regenerate] integrate fallback: organise-from-raw also failed; preserving stale organised' );
                        }
                    }
                }
                // (PATH C — existing organised + no new addenda → $integrated
                // stays null, no DB write, current organised preserved.)

                if ( is_array( $integrated ) ) {
                    $wpdb->update(
                        $wpdb->prefix . 'hdlv2_consultation_notes',
                        array( 'ai_organised_notes' => wp_json_encode( $integrated ) ),
                        array( 'id' => $consult_id )
                    );
                    // Re-read so the pre-flight rec count below sees the
                    // fresh organised data, not the stale row we loaded above.
                    $consult_row->ai_organised_notes = wp_json_encode( $integrated );
                }
            }
        }

        // ── Pre-flight: count merged rec sources BEFORE any state mutation ──
        // Mirrors the L1 guard in generate() but lifts it earlier so we never
        // archive flight plans / fire Claude content / send emails when the
        // result would be a 422. Over-counts vs the deduped merge in generate
        // — that's intentional: if the over-count is 0, deduped is 0 too. If
        // over-count is ≥1, deduped is ≥1 too (dedup never empties a source).
        $rec_count_organised = 0;
        $rec_count_legacy    = 0;
        $rec_count_addenda   = 0;
        if ( $consult_row ) {
            $organised_arr = ( ! empty( $consult_row->ai_organised_notes ) )
                ? ( json_decode( $consult_row->ai_organised_notes, true ) ?: array() )
                : array();
            if ( ! empty( $organised_arr['recommendations'] ) && is_array( $organised_arr['recommendations'] ) ) {
                foreach ( $organised_arr['recommendations'] as $r ) {
                    if ( is_array( $r ) && trim( (string) ( $r['text'] ?? '' ) ) !== '' ) {
                        $rec_count_organised++;
                    }
                }
            }
            $legacy_arr = json_decode( $consult_row->recommendations ?? '', true );
            if ( is_array( $legacy_arr ) ) {
                foreach ( $legacy_arr as $r ) {
                    if ( is_array( $r ) && trim( (string) ( $r['text'] ?? '' ) ) !== '' ) {
                        $rec_count_legacy++;
                    }
                }
            }
        }
        if ( ! empty( $addenda_for_merge ) ) {
            foreach ( $addenda_for_merge as $a ) {
                if ( trim( (string) ( $a['note_text'] ?? '' ) ) !== '' ) {
                    $rec_count_addenda++;
                }
            }
        }
        $rec_total = $rec_count_organised + $rec_count_legacy + $rec_count_addenda;
        if ( $rec_total === 0 ) {
            error_log( sprintf(
                '[HDLV2 regenerate] pre-flight empty-recs gate fired: progress=%d consult=%d organised=%d legacy=%d addenda=%d',
                $progress_id, $consult_id, $rec_count_organised, $rec_count_legacy, $rec_count_addenda
            ) );
            return new WP_Error(
                'empty_recommendations',
                'This report has no recommendations yet. Add at least one recommendation through the panel, or add an addendum with an action item, then try again.',
                array( 'status' => 422 )
            );
        }

        // ── Reset consult status (state machine cleanliness) ──
        // generate() will set it back to 'report_generated' on success.
        if ( $consult_id ) {
            $wpdb->update(
                $wpdb->prefix . 'hdlv2_consultation_notes',
                array( 'status' => 'in_progress' ),
                array( 'id' => $consult_id, 'practitioner_user_id' => $practitioner_id )
            );
        }

        // ── Delegate to generate() in UPDATE mode ──
        // Flight plan archive + Week-1 reschedule happen INSIDE generate() now
        // (after the report row UPDATE confirms success). That folds first-
        // finalise and regen into one code path and avoids the partial-state
        // window where regenerate archived plans then generate failed mid-way.
        $result = self::generate( $progress_id, $consult_id, $practitioner_id, $existing_final_id );

        if ( is_wp_error( $result ) ) {
            // generate() returned an error — DB state is unchanged from the
            // pre-flight snapshot above. Surface the error to the caller.
            return $result;
        }

        // ── Post-success: clear next-plan priorities ──
        // The /addendum endpoint appends a pointer per addendum to
        // hdlv2_next_plan_priorities user_meta so the next Saturday Flight
        // Plan cron sees them. We've now incorporated all of them into the
        // refreshed Final + just-scheduled fresh Flight Plan, so the meta is
        // stale data that would double-feed the next cron. Clear it.
        if ( $progress->client_user_id ) {
            delete_user_meta( (int) $progress->client_user_id, 'hdlv2_next_plan_priorities' );
        }

        // ── Post-success: clear Phase N recovery banner transient ──
        // Once the practitioner has successfully refreshed a recovered
        // report, the inline blue banner ("This consultation was recovered…")
        // becomes stale. Delete the transient so the next page load shows
        // the clean addenda section without the banner.
        delete_transient( 'hdlv2_phase_n_recovered_' . (int) $progress_id );

        // v0.33.0 — Force-refresh the Client Brief so it reflects the latest
        // addenda + recomputed scores. Without this, the Brief stayed
        // cached at the original pre-consultation snapshot. Failures are
        // logged but do NOT block the regen response (Brief is non-critical).
        if ( class_exists( 'HDLV2_Consultation' ) ) {
            // v0.41.17 — `AND deleted_at IS NULL`.
            $progress_row = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}hdlv2_form_progress
                 WHERE id = %d AND deleted_at IS NULL",
                $progress_id
            ) );
            if ( $progress_row ) {
                $brief = HDLV2_Consultation::get_or_build_pre_consult_summary( $progress_row, true );
                if ( $brief === null ) {
                    error_log( '[HDLV2] regenerate(): Brief refresh returned null (Claude error or empty response)' );
                }
            }
        }

        return $result;
    }

    /**
     * v0.23.7 — Derive 2-letter practitioner initials from display_name
     * (e.g. "Bob 9000" → "B9", "Dr Sarah Chen" → "DS"). Mirrors the inline
     * block used by the Draft webhook in HDLV2_Staged_Form::fire_make_webhook.
     * Falls back to "HD" when no name is available.
     */
    private static function derive_initials( $display_name ) {
        if ( ! $display_name ) return 'HD';
        $parts   = preg_split( '/\s+/', trim( $display_name ) );
        $letters = array();
        foreach ( $parts as $p ) {
            if ( $p !== '' ) $letters[] = strtoupper( mb_substr( $p, 0, 1 ) );
            if ( count( $letters ) === 2 ) break;
        }
        return ! empty( $letters ) ? implode( '', $letters ) : 'HD';
    }

    // ──────────────────────────────────────────────────────────────
    //  v0.34.0 — Display-band derivers (canonical source for Liquid)
    // ──────────────────────────────────────────────────────────────

    /**
     * Titlecase a free-form name (handles multi-word, hyphenation, unicode).
     * "kim" → "Kim", "sarah anne whitfield-jones" → "Sarah Anne Whitfield-Jones".
     * Liquid's `capitalize` only handles the first letter of the entire string,
     * which is wrong for any multi-word name. mb_convert_case + MB_CASE_TITLE
     * handles both word boundaries (spaces) and hyphenation correctly.
     */
    private static function titlecase_name( $name ) {
        $name = trim( (string) $name );
        if ( $name === '' ) return '';
        return mb_convert_case( $name, MB_CASE_TITLE, 'UTF-8' );
    }

    /**
     * Derive the rate-of-ageing band for Page 3/5/6 pill colour + caption.
     *   slow      → ≤ 0.95 (OPTIMAL green pill, "Slower than average pace")
     *   average   → 0.95–1.05 (NEUTRAL grey pill, "Average pace")
     *   fast      → 1.05–1.15 (WATCH amber pill, "Faster than average pace")
     *   very-fast → > 1.15 (CONCERN red pill, "Significantly faster")
     */
    private static function derive_rate_band( $rate ) {
        $r = (float) $rate;
        if ( $r <= 0.95 ) return 'slow';
        if ( $r <= 1.05 ) return 'average';
        if ( $r <= 1.15 ) return 'fast';
        return 'very-fast';
    }

    /**
     * Derive the biological-age-shift band for Page 3/5 pill colour.
     *   optimal → shift ≤ -1   (younger biologically)
     *   neutral → -1 < shift ≤ 1  (close to chronological)
     *   watch   → 1 < shift ≤ 3   (older biologically)
     *   concern → shift > 3       (significantly older biologically)
     * Mirrors the rate_band logic so a +3.3-yr shift no longer renders OPTIMAL.
     */
    private static function derive_age_shift_band( $shift ) {
        $s = (float) $shift;
        if ( $s <= -1.0 ) return 'optimal';
        if ( $s <= 1.0 )  return 'neutral';
        if ( $s <= 3.0 )  return 'watch';
        return 'concern';
    }

    /**
     * Derive the lifestyle-band label for Page 3/5/8 copy branching.
     * Lifestyle-only scores from $calc_result['scores']:
     *   urgent-cluster  → ≥1 score = 1 (URGENT)
     *   mixed           → 0 URGENT but ≥1 score ≤ 2 (LIFT)
     *   uniform-strong  → all scores ≥ 4 (protective across the board) — v0.34.1
     *   uniform-mid     → no urgent / no lift / not all-strong (the "mid-band" path)
     * Matthew flagged the existing "uniformly mid-band" copy on Kim's report
     * because Kim has 3× URGENT (Activity, Hydration, Balance) and the
     * boilerplate didn't branch on the actual data. v0.34.1 adds the inverse
     * case (John 4-5/5 across the board) which previously also fell into
     * uniform-mid and read as "not yet protective" when it was protective.
     */
    private static function derive_lifestyle_band_label( $scores ) {
        if ( ! is_array( $scores ) ) return 'uniform-mid';
        $lifestyle_keys = array(
            'physicalActivity', 'sleepDuration', 'sleepQuality', 'stressLevels',
            'socialConnections', 'dietQuality', 'alcoholConsumption', 'smokingStatus',
            'cognitiveActivity', 'sunlightExposure', 'supplementIntake', 'dailyHydration',
        );
        $urgent = 0; $lift = 0; $strong = 0; $counted = 0;
        foreach ( $lifestyle_keys as $k ) {
            $v = $scores[ $k ] ?? null;
            if ( ! is_numeric( $v ) ) continue;
            $counted++;
            $v = (int) $v;
            if ( $v <= 1 )      { $urgent++; }
            elseif ( $v <= 2 )  { $lift++; }
            elseif ( $v >= 4 )  { $strong++; }
        }
        if ( $urgent >= 1 ) return 'urgent-cluster';
        if ( $lift   >= 1 ) return 'mixed';
        // v0.34.1 — every counted lifestyle score is ≥ 4. "Across-the-board
        // protective" rather than mid-band. Avoids John's report reading
        // "lifestyle inputs sit uniformly mid-band — not yet protective" when
        // his actual lifestyle averaged 4.6/5.
        if ( $counted > 0 && $strong === $counted ) return 'uniform-strong';
        return 'uniform-mid';
    }
}

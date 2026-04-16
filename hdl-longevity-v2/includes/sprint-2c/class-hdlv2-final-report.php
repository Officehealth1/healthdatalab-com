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

    const MAKE_WEBHOOK_OPTION = 'hdlv2_make_webhook_final_report';
    const MAKE_WEBHOOK_DEFAULT = ''; // Set via wp-config: define('HDLV2_MAKE_FINAL_REPORT', 'https://...');

    /**
     * Generate the final report. Called from HDLV2_Consultation::rest_finalise().
     *
     * @param int $progress_id    Form progress row ID.
     * @param int $consult_id     Consultation notes row ID.
     * @param int $practitioner_id Practitioner user ID.
     * @return array|WP_Error Final report data or error.
     */
    public static function generate( $progress_id, $consult_id, $practitioner_id ) {
        global $wpdb;

        // ── Load all data ──
        $progress = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hdlv2_form_progress WHERE id = %d", $progress_id
        ) );
        if ( ! $progress ) {
            return new WP_Error( 'not_found', 'Assessment not found.', array( 'status' => 404 ) );
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

        // Consultation data
        $health_changes  = json_decode( $consult->health_data_changes, true ) ?: array();
        $recommendations = json_decode( $consult->recommendations, true ) ?: array();
        $typed_notes     = $consult->typed_notes ?: '';

        // ── Step 1: Recalculate rate with practitioner-verified data ──
        $calc_data = array_merge( $s1_data, $s3_data );

        // Apply practitioner edits on top
        foreach ( $health_changes as $change ) {
            $calc_data[ $change['field'] ] = $change['new_value'];
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

        // ── Step 3: Generate milestones (separate Claude call) ──
        $milestones = HDLV2_AI_Service::generate_milestones(
            $calc_result,
            $why_profile,
            $recommendations,
            $age
        );

        // ── Step 4: Store final report ──
        $wpdb->insert(
            $wpdb->prefix . 'hdlv2_reports',
            array(
                'client_user_id'       => $progress->client_user_id ?: null,
                'practitioner_user_id' => $practitioner_id,
                'form_progress_id'     => $progress_id,
                'report_type'          => 'final',
                'report_content'       => wp_json_encode( $report_content ),
                'milestones'           => wp_json_encode( $milestones ),
                'consultation_notes'   => $typed_notes,
                'health_data_changes'  => wp_json_encode( $health_changes ),
                'status'               => 'ready',
            )
        );
        $report_id = $wpdb->insert_id;

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
        if ( $progress->client_user_id ) {
            wp_schedule_single_event(
                time() + 60,
                'hdlv2_generate_single_flight_plan',
                array( (int) $progress->client_user_id )
            );
        }

        // ── Return to frontend ──
        return array(
            'success'         => true,
            'report_id'       => $report_id,
            'report_type'     => 'final',
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

    private static function fire_webhook( $progress, $calc_result, $s1_data, $report, $milestones, $why_profile, $recommendations, $health_changes, $notes, $practitioner_id ) {
        $webhook_url = get_option( self::MAKE_WEBHOOK_OPTION, self::MAKE_WEBHOOK_DEFAULT );

        $prac_user = get_userdata( $practitioner_id );

        $payload = array(
            'report_type'             => 'final',
            'client_name'             => $progress->client_name ?: '',
            'client_email'            => $progress->client_email ?: '',
            'practitioner_name'       => $prac_user ? $prac_user->display_name : '',
            'practitioner_email'      => $prac_user ? $prac_user->user_email : '',
            'practitioner_logo_url'   => self::get_practitioner_logo( $practitioner_id ),
            'chronological_age'       => $s1_data['q1_age'] ?? $s1_data['age'] ?? null,
            'biological_age'          => $calc_result['bio_age'] ?? null,
            'rate_of_ageing'          => $calc_result['rate'] ?? null,
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
            'consultation_notes_summary' => mb_substr( $notes, 0, 500 ),
            'report_date'             => current_time( 'j F Y' ),
            'distilled_why'           => $why_profile['distilled_why'] ?? '',
            'gauge_url'               => HDLV2_Staged_Form::build_gauge_url( $calc_result['rate'] ?? 1.0 ),
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
            'score_overall'           => $calc_result['scores']['overallHealthScore'] ?? '',
            'score_bp'                => $calc_result['scores']['bloodPressureScore'] ?? '',
            'score_hr'                => $calc_result['scores']['heartRateScore'] ?? '',
            'score_skin'              => $calc_result['scores']['skinElasticity'] ?? '',
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
        );

        wp_remote_post( $webhook_url, array(
            'body'     => wp_json_encode( $payload ),
            'headers'  => array( 'Content-Type' => 'application/json' ),
            'timeout'  => 30,
            'blocking' => false,
        ) );

        error_log( sprintf( '[HDLV2] Final report webhook dispatched for progress_id=%d, rate=%s', $progress->id, $calc_result['rate'] ?? 'N/A' ) );
    }

    // ──────────────────────────────────────────────────────────────
    //  EMAIL NOTIFICATIONS
    // ──────────────────────────────────────────────────────────────

    private static function send_emails( $progress, $calc_result, $practitioner_id ) {
        $client_email = $progress->client_email ?: '';
        $client_name  = $progress->client_name ?: '';
        $rate         = $calc_result['rate'] ?? 'N/A';
        $bio_age      = $calc_result['bio_age'] ?? 'N/A';

        // Client: plain text for now (PDF link comes from Make.com)
        if ( $client_email ) {
            wp_mail(
                $client_email,
                'Your Longevity Report is Ready — HealthDataLab',
                sprintf(
                    "Hi %s,\n\nYour personalised longevity report is ready!\n\n"
                    . "Rate of Ageing: %s\nBiological Age: %s\n\n"
                    . "Your practitioner will share the full report with you. "
                    . "You'll receive a PDF copy shortly.\n\n— HealthDataLab",
                    $client_name ?: 'there', $rate, $bio_age
                )
            );
        }

        // Practitioner: branded HTML
        if ( $practitioner_id ) {
            $prac_user  = get_userdata( $practitioner_id );
            $prac_email = $prac_user ? $prac_user->user_email : '';
            if ( $prac_email ) {
                $s1 = json_decode( $progress->stage1_data, true ) ?: array();
                $scores = $calc_result['scores'] ?? array();
                $pos = array(); $neg = array();
                foreach ( $scores as $name => $score ) {
                    if ( is_numeric( $score ) && $score >= 4 ) $pos[] = $name;
                    if ( is_numeric( $score ) && $score <= 2 ) $neg[] = $name;
                }

                $html = HDLV2_Email_Templates::stage3_draft_ready( array(
                    'client_name'  => $client_name,
                    'client_email' => $client_email,
                    'rate'         => $rate,
                    'bio_age'      => $bio_age,
                    'age'          => $s1['q1_age'] ?? $s1['age'] ?? '?',
                    'positives'    => implode( ', ', $pos ),
                    'negatives'    => implode( ', ', $neg ),
                ) );

                // Reuse stage3 template but change subject for final
                wp_mail( $prac_email, sprintf( 'Final Report Generated: %s', $client_name ?: $client_email ), $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
            }
        }
    }

    /**
     * Get practitioner's logo URL from widget config, empty if not set.
     */
    private static function get_practitioner_logo( $practitioner_id ) {
        if ( ! $practitioner_id ) return '';
        global $wpdb;
        $logo = $wpdb->get_var( $wpdb->prepare(
            "SELECT logo_url FROM {$wpdb->prefix}hdlv2_widget_config WHERE practitioner_user_id = %d LIMIT 1",
            $practitioner_id
        ) );
        return $logo ?: '';
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
                'valueLabel' => array(
                    'display' => true, 'fontSize' => 36, 'fontFamily' => "'Inter',sans-serif", 'fontWeight' => 'bold',
                    'color' => '#004F59', 'backgroundColor' => 'transparent', 'bottomMarginPercentage' => -10, 'padding' => 8,
                ),
                'centerArea' => array( 'displayText' => false, 'backgroundColor' => 'transparent' ),
                'arc'        => array( 'borderWidth' => 0, 'padding' => 2, 'margin' => 3, 'roundedCorners' => true ),
                'subtitle'   => array(
                    'display' => true, 'text' => $sub_text, 'color' => $sub_color,
                    'font' => array( 'size' => 20, 'weight' => 'bold', 'family' => "'Inter',sans-serif" ),
                    'padding' => array( 'top' => 8 ),
                ),
            ),
        );

        return 'https://quickchart.io/chart?c=' . rawurlencode( wp_json_encode( $cfg ) ) . '&w=380&h=340&bkg=white';
    }
}

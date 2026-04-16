<?php
/**
 * Branded HTML Email Templates for V2 staged assessment.
 *
 * Three stage-specific templates using HDL brand tokens:
 * Poppins headings, Inter body, #3d8da0 teal, white cards.
 *
 * @package HDL_Longevity_V2
 * @since 0.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HDLV2_Email_Templates {

    const TEAL   = '#3d8da0';
    const GREEN  = '#10b981';
    const CORAL  = '#f59e0b';
    const DARK   = '#111111';
    const MUTED  = '#888888';
    const BG     = '#f4f5f7';
    const CARD   = '#ffffff';

    // ──────────────────────────────────────────────────────────────
    //  STAGE 1: Quick Insight Results
    // ──────────────────────────────────────────────────────────────

    /**
     * @param array $data { client_name, rate, bio_age, age, gauge_url, token_url, rate_message }
     */
    public static function stage1_results( $data ) {
        $name     = esc_html( $data['client_name'] ?: 'there' );
        $rate     = esc_html( $data['rate'] );
        $bio_age  = esc_html( $data['bio_age'] );
        $age      = esc_html( $data['age'] );
        $gauge    = esc_url( $data['gauge_url'] );
        $url      = esc_url( $data['token_url'] );
        $msg      = esc_html( $data['rate_message'] );

        $content = "
            <h2 style='margin:0 0 8px;color:" . self::DARK . ";'>Hi {$name},</h2>
            <p style='color:" . self::MUTED . ";margin:0 0 24px;'>Thanks for completing your quick health insight!</p>

            <div style='text-align:center;margin:0 0 24px;'>
                <img src='{$gauge}' alt='Rate of ageing gauge' width='340' style='max-width:100%;height:auto;' />
            </div>

            <table cellpadding='0' cellspacing='0' border='0' width='100%' style='margin:0 0 20px;'>
                <tr>
                    <td style='padding:12px 16px;background:#f8f9fb;border-radius:8px 0 0 8px;text-align:center;'>
                        <div style='font-size:11px;color:" . self::MUTED . ";margin:0 0 4px;'>Rate of Ageing</div>
                        <div style='font-size:22px;font-weight:700;color:" . self::TEAL . ";'>{$rate}</div>
                    </td>
                    <td style='padding:12px 16px;background:#f8f9fb;text-align:center;'>
                        <div style='font-size:11px;color:" . self::MUTED . ";margin:0 0 4px;'>Biological Age</div>
                        <div style='font-size:22px;font-weight:700;color:" . self::DARK . ";'>{$bio_age}</div>
                    </td>
                    <td style='padding:12px 16px;background:#f8f9fb;border-radius:0 8px 8px 0;text-align:center;'>
                        <div style='font-size:11px;color:" . self::MUTED . ";margin:0 0 4px;'>Chronological</div>
                        <div style='font-size:22px;font-weight:700;color:" . self::DARK . ";'>{$age}</div>
                    </td>
                </tr>
            </table>

            <p style='color:#444;line-height:1.6;margin:0 0 24px;'>{$msg}</p>

            <p style='color:#444;line-height:1.6;margin:0 0 24px;'>
                This is a preliminary estimate based on 6 data points.
                <strong>It would be a lot more accurate if we knew a little bit more about you.</strong>
            </p>"
            . self::cta_button( $url, 'Continue to Stage 2 — Your WHY' );

        return self::base_layout( $content );
    }

    // ──────────────────────────────────────────────────────────────
    //  STAGE 2: WHY Reformulation
    // ──────────────────────────────────────────────────────────────

    /**
     * @param array $data { client_name, distilled_why, ai_reformulation, key_people_summary, motivations, token_url }
     */
    public static function stage2_why_response( $data ) {
        $name   = esc_html( $data['client_name'] ?: 'there' );
        $why    = esc_html( $data['distilled_why'] );
        $reform = wp_kses_post( $data['ai_reformulation'] );
        $kp     = esc_html( $data['key_people_summary'] ?: '' );
        $mot    = esc_html( $data['motivations'] ?: '' );
        $url    = esc_url( $data['token_url'] );

        $content = "
            <h2 style='margin:0 0 8px;color:" . self::DARK . ";'>Hi {$name},</h2>
            <p style='color:" . self::MUTED . ";margin:0 0 24px;'>Thanks for exploring your WHY!</p>

            <div style='background:#f8f9fb;border-left:4px solid " . self::TEAL . ";padding:16px 20px;border-radius:8px;margin:0 0 20px;'>
                <p style='font-size:11px;color:" . self::TEAL . ";font-weight:600;text-transform:uppercase;letter-spacing:0.5px;margin:0 0 8px;'>Your Distilled WHY</p>
                <p style='font-size:16px;font-weight:600;color:" . self::DARK . ";margin:0;line-height:1.5;font-style:italic;'>&ldquo;{$why}&rdquo;</p>
            </div>

            <div style='color:#444;line-height:1.7;margin:0 0 24px;'>{$reform}</div>"
            . ( $kp ? "<p style='color:" . self::MUTED . ";font-size:13px;margin:0 0 4px;'><strong>Key People:</strong> {$kp}</p>" : '' )
            . ( $mot ? "<p style='color:" . self::MUTED . ";font-size:13px;margin:0 0 20px;'><strong>Motivations:</strong> {$mot}</p>" : '' )
            . "
            <p style='color:#444;line-height:1.6;margin:0 0 24px;'>
                This is great that you&rsquo;ve got clarity because this is how you can really make changes
                that will have an impact and last. <strong>We need a few more details about you</strong> to generate
                your comprehensive longevity analysis.
            </p>"
            . self::cta_button( $url, 'Continue to Stage 3 — Full Detail' );

        return self::base_layout( $content );
    }

    // ──────────────────────────────────────────────────────────────
    //  STAGE 3: Draft Report Ready (to practitioner)
    // ──────────────────────────────────────────────────────────────

    /**
     * @param array $data { client_name, client_email, rate, bio_age, age, positives, negatives, dashboard_url }
     */
    public static function stage3_draft_ready( $data ) {
        $cname = esc_html( $data['client_name'] ?: $data['client_email'] );
        $rate  = esc_html( $data['rate'] );
        $bio   = esc_html( $data['bio_age'] );
        $age   = esc_html( $data['age'] );
        $pos   = esc_html( $data['positives'] ?: 'None identified' );
        $neg   = esc_html( $data['negatives'] ?: 'None identified' );
        $url   = esc_url( $data['dashboard_url'] ?: site_url( '/practitioner-dashboard/' ) );

        $content = "
            <h2 style='margin:0 0 8px;color:" . self::DARK . ";'>Draft Report Ready</h2>
            <p style='color:" . self::MUTED . ";margin:0 0 24px;'>Your client <strong>{$cname}</strong> has completed all three stages.</p>

            <table cellpadding='0' cellspacing='0' border='0' width='100%' style='margin:0 0 20px;'>
                <tr>
                    <td style='padding:12px 16px;background:#f8f9fb;border-radius:8px 0 0 8px;text-align:center;'>
                        <div style='font-size:11px;color:" . self::MUTED . ";margin:0 0 4px;'>Rate</div>
                        <div style='font-size:22px;font-weight:700;color:" . self::TEAL . ";'>{$rate}</div>
                    </td>
                    <td style='padding:12px 16px;background:#f8f9fb;text-align:center;'>
                        <div style='font-size:11px;color:" . self::MUTED . ";margin:0 0 4px;'>Bio Age</div>
                        <div style='font-size:22px;font-weight:700;color:" . self::DARK . ";'>{$bio}</div>
                    </td>
                    <td style='padding:12px 16px;background:#f8f9fb;border-radius:0 8px 8px 0;text-align:center;'>
                        <div style='font-size:11px;color:" . self::MUTED . ";margin:0 0 4px;'>Chrono</div>
                        <div style='font-size:22px;font-weight:700;color:" . self::DARK . ";'>{$age}</div>
                    </td>
                </tr>
            </table>

            <div style='margin:0 0 12px;'>
                <p style='font-size:13px;margin:0 0 4px;'><strong style='color:" . self::GREEN . ";'>Positive factors:</strong> <span style='color:#444;'>{$pos}</span></p>
                <p style='font-size:13px;margin:0;'><strong style='color:#e74c3c;'>Needs attention:</strong> <span style='color:#444;'>{$neg}</span></p>
            </div>

            <p style='color:#444;line-height:1.6;margin:16px 0 24px;'>
                Review the draft report, make any adjustments, add your consultation notes,
                and generate the final report when you&rsquo;re ready.
            </p>"
            . self::cta_button( $url, 'Review Draft Report' );

        return self::base_layout( $content );
    }

    // ──────────────────────────────────────────────────────────────
    //  STAGE 2: Responses Saved (to client)
    // ──────────────────────────────────────────────────────────────

    public static function stage2_saved( $data ) {
        $name = esc_html( $data['client_name'] ?? 'there' );

        $content = "
            <h2 style='font-family:Poppins,sans-serif;font-size:20px;font-weight:700;color:" . self::DARK . ";margin:0 0 12px;'>Your Responses Have Been Saved</h2>
            <p style='font-size:14px;color:#444;line-height:1.7;margin:0 0 16px;'>Hi {$name}, thank you for sharing what matters to you. Your responses have been saved and your practitioner will use these to personalise your full Longevity Report.</p>
            <p style='font-size:13px;color:" . self::MUTED . ";line-height:1.6;margin:0;'>You\u2019ll receive an email when the next stage is ready for you.</p>";

        return self::base_layout( $content );
    }

    // ──────────────────────────────────────────────────────────────
    //  STAGE 3: Assessment Complete (to client)
    // ──────────────────────────────────────────────────────────────

    public static function stage3_complete( $data ) {
        $name = esc_html( $data['client_name'] ?? 'there' );

        $content = "
            <h2 style='font-family:Poppins,sans-serif;font-size:20px;font-weight:700;color:" . self::DARK . ";margin:0 0 12px;'>Assessment Complete</h2>
            <p style='font-size:14px;color:#444;line-height:1.7;margin:0 0 16px;'>Congratulations {$name}! You\u2019ve completed all three stages of your longevity assessment. Your practitioner is now reviewing your results and will prepare your personalised Longevity Report.</p>
            <p style='font-size:13px;color:" . self::MUTED . ";line-height:1.6;margin:0;'>You\u2019ll receive an email when your report is ready.</p>";

        return self::base_layout( $content );
    }

    // ──────────────────────────────────────────────────────────────
    //  PRIVATE: LAYOUT + COMPONENTS
    // ──────────────────────────────────────────────────────────────

    private static function wrap( $content ) {
        return self::base_layout( $content );
    }

    private static function base_layout( $content ) {
        $logo_url = 'https://healthdatalab.net/wp-content/uploads/2023/09/HDL-Logo-2309-4-d-sss.png';
        $year     = date( 'Y' );

        return "<!DOCTYPE html>
<html lang='en'>
<head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1.0'>
<title>HealthDataLab</title></head>
<body style='margin:0;padding:0;background:" . self::BG . ";font-family:Inter,-apple-system,BlinkMacSystemFont,\"Segoe UI\",Roboto,sans-serif;'>
<table cellpadding='0' cellspacing='0' border='0' width='100%' style='background:" . self::BG . ";'>
<tr><td align='center' style='padding:32px 16px;'>
<table cellpadding='0' cellspacing='0' border='0' width='600' style='max-width:600px;width:100%;'>
<!-- Logo -->
<tr><td align='center' style='padding:0 0 24px;'>
<img src='{$logo_url}' alt='HealthDataLab' width='160' style='max-width:160px;height:auto;' />
</td></tr>
<!-- Card -->
<tr><td style='background:" . self::CARD . ";border-radius:14px;padding:36px 32px;box-shadow:0 4px 24px rgba(0,0,0,0.06);'>
{$content}
</td></tr>
<!-- Footer -->
<tr><td style='padding:24px 0;text-align:center;'>
<p style='font-size:12px;color:" . self::MUTED . ";margin:0 0 8px;'>
HealthDataLab &mdash; Longevity coaching for health practitioners
</p>
<p style='font-size:11px;color:#bbb;margin:0;'>
&copy; {$year} HealthDataLab. All rights reserved.
</p>
</td></tr>
</table>
</td></tr>
</table>
</body></html>";
    }

    private static function cta_button( $url, $text ) {
        return "
        <div style='text-align:center;margin:28px 0 8px;'>
            <table border='0' cellpadding='0' cellspacing='0' role='presentation' style='margin:0 auto;'>
                <tr>
                    <td style='border-radius:48px;background:" . self::TEAL . ";box-shadow:0 4px 16px rgba(61,141,160,0.25);'>
                        <a href='" . esc_url( $url ) . "' style='display:inline-block;background:" . self::TEAL . ";color:#ffffff;text-decoration:none;padding:14px 32px;border-radius:48px;font-size:15px;font-weight:600;font-family:Inter,-apple-system,sans-serif;'>
                            {$text} &rarr;
                        </a>
                    </td>
                </tr>
            </table>
        </div>";
    }

    // ──────────────────────────────────────────────────────────────
    //  WHY GATE RELEASED — Stage 3 invitation
    // ──────────────────────────────────────────────────────────────

    public static function why_gate_released( $data ) {
        $name     = esc_html( $data['client_name'] ?? 'there' );
        $email    = $data['client_email'] ?? '';
        $form_url = $data['form_url'] ?? '#';

        $html = self::wrap(
            "<h2 style='font-family:Poppins,sans-serif;font-size:20px;font-weight:700;color:" . self::DARK . ";margin:0 0 12px;'>Your WHY Profile is Ready</h2>"
            . "<p style='font-size:14px;color:#444;line-height:1.7;margin:0 0 16px;'>Hi {$name}, your practitioner has reviewed your WHY profile and prepared the next stage of your longevity assessment.</p>"
            . "<p style='font-size:14px;color:#444;line-height:1.7;margin:0 0 20px;'>Stage 3 collects detailed health measurements that, combined with your WHY, will form the basis of your personalised Longevity Report.</p>"
            . self::cta_button( $form_url, 'Continue to Stage 3' )
        );

        wp_mail( $email, 'Your Next Step is Ready — HealthDataLab', $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
    }

    // ──────────────────────────────────────────────────────────────
    //  WEEKLY CHECK-IN REMINDER
    // ──────────────────────────────────────────────────────────────

    public static function checkin_reminder( $data ) {
        $name  = esc_html( $data['client_name'] ?? 'there' );
        $email = $data['client_email'] ?? '';
        $url   = $data['checkin_url'] ?? '#';

        $html = self::wrap(
            "<h2 style='font-family:Poppins,sans-serif;font-size:20px;font-weight:700;color:" . self::DARK . ";margin:0 0 12px;'>Time for Your Weekly Check-in</h2>"
            . "<p style='font-size:14px;color:#444;line-height:1.7;margin:0 0 20px;'>Hi {$name}, how did this week go? Take a few minutes to reflect on your progress. Your practitioner uses your check-ins to fine-tune your plan.</p>"
            . self::cta_button( $url, 'Start Check-in' )
        );

        wp_mail( $email, 'Time for your weekly check-in — HealthDataLab', $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
    }

    // ──────────────────────────────────────────────────────────────
    //  FLIGHT PLAN READY
    // ──────────────────────────────────────────────────────────────

    public static function flight_plan_ready( $data ) {
        $name  = esc_html( $data['client_name'] ?? 'there' );
        $email = $data['client_email'] ?? '';
        $url   = $data['plan_url'] ?? '#';
        $week  = (int) ( $data['week_number'] ?? 0 );

        $html = self::wrap(
            "<h2 style='font-family:Poppins,sans-serif;font-size:20px;font-weight:700;color:" . self::DARK . ";margin:0 0 12px;'>Your Week {$week} Flight Plan is Ready</h2>"
            . "<p style='font-size:14px;color:#444;line-height:1.7;margin:0 0 20px;'>Hi {$name}, your new weekly plan is ready. It includes your daily actions, shopping list, and this week's focus areas.</p>"
            . self::cta_button( $url, 'View Your Flight Plan' )
        );

        wp_mail( $email, "Your Week {$week} Flight Plan — HealthDataLab", $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
    }

    // ──────────────────────────────────────────────────────────────
    //  QUARTERLY REVIEW DUE (to practitioner)
    // ──────────────────────────────────────────────────────────────

    public static function quarterly_review_due( $data ) {
        $prac_email  = $data['practitioner_email'] ?? '';
        $client_name = esc_html( $data['client_name'] ?? 'Unknown' );
        $dash_url    = $data['dashboard_url'] ?? '#';

        $html = self::wrap(
            "<h2 style='font-family:Poppins,sans-serif;font-size:20px;font-weight:700;color:" . self::DARK . ";margin:0 0 12px;'>Quarterly Review Due</h2>"
            . "<p style='font-size:14px;color:#444;line-height:1.7;margin:0 0 20px;'>It's time to schedule <strong>{$client_name}'s</strong> quarterly review. Three months have elapsed since their last assessment. A reassessment will update their trajectory and refresh their milestones.</p>"
            . self::cta_button( $dash_url, 'View Client' )
        );

        wp_mail( $prac_email, "Quarterly Review Due: {$client_name} — HealthDataLab", $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
    }

    // ──────────────────────────────────────────────────────────────
    //  QUARTERLY REVIEW DUE (to client)
    // ──────────────────────────────────────────────────────────────

    public static function quarterly_review_client( $data ) {
        $name  = esc_html( $data['client_name'] ?? 'there' );
        $email = $data['client_email'] ?? '';
        $url   = $data['review_url'] ?? '#';

        $html = self::wrap(
            "<h2 style='font-family:Poppins,sans-serif;font-size:20px;font-weight:700;color:" . self::DARK . ";margin:0 0 12px;'>Time for Your Quarterly Review</h2>"
            . "<p style='font-size:14px;color:#444;line-height:1.7;margin:0 0 16px;'>Hi {$name}, it's been three months since your last assessment. A quarterly review updates your progress, refreshes your milestones, and helps your practitioner fine-tune your plan.</p>"
            . "<p style='font-size:14px;color:#444;line-height:1.7;margin:0 0 20px;'>Your practitioner will be in touch to arrange your review.</p>"
            . self::cta_button( $url, 'View Your Progress' )
        );

        wp_mail( $email, 'Quarterly Review Due — HealthDataLab', $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
    }

    // ──────────────────────────────────────────────────────────────
    //  CLIENT NEEDS ATTENTION (to practitioner)
    // ──────────────────────────────────────────────────────────────

    public static function client_needs_attention( $data ) {
        $prac_email  = $data['practitioner_email'] ?? '';
        $client_name = esc_html( $data['client_name'] ?? 'Unknown' );
        $reasons     = $data['reasons'] ?? array();
        $timeline_url = $data['timeline_url'] ?? '#';

        $reasons_html = '';
        foreach ( $reasons as $r ) {
            $reasons_html .= '<li style="margin-bottom:4px;">' . esc_html( $r ) . '</li>';
        }

        $html = self::wrap(
            "<h2 style='font-family:Poppins,sans-serif;font-size:20px;font-weight:700;color:#dc2626;margin:0 0 12px;'>Client Needs Attention</h2>"
            . "<p style='font-size:14px;color:#444;line-height:1.7;margin:0 0 12px;'><strong>{$client_name}</strong> has been flagged for your attention:</p>"
            . "<ul style='font-size:14px;color:#444;line-height:1.7;margin:0 0 20px;padding-left:20px;'>{$reasons_html}</ul>"
            . self::cta_button( $timeline_url, 'View Timeline' )
        );

        wp_mail( $prac_email, "Attention Required: {$client_name} — HealthDataLab", $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
    }
}

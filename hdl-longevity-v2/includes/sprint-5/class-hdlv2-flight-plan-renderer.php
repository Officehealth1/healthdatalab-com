<?php
/**
 * Flight Plan HTML Renderer — generates HTML for online display + PDF source.
 *
 * @package HDL_Longevity_V2
 * @since 0.7.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class HDLV2_Flight_Plan_Renderer {

    /**
     * Render flight plan as complete HTML.
     */
    public static function render_html( $plan_data, $client_name, $prac_name, $prac_logo, $week_number, $week_start ) {
        $daily   = $plan_data['daily_plan'] ?? $plan_data;
        $identity = $plan_data['identity_statement'] ?? '';
        $targets  = $plan_data['weekly_targets'] ?? array();
        $shopping = $plan_data['shopping_list'] ?? array();
        $journey  = $plan_data['journey_assistance'] ?? '';
        $week_end = date( 'j M', strtotime( $week_start . ' +6 days' ) );
        $week_label = date( 'j M', strtotime( $week_start ) ) . ' – ' . $week_end;

        $days_order = array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );
        $time_slots = array( 'morning', 'mid_morning', 'lunchtime', 'afternoon', 'early_evening', 'late_evening', 'night' );
        $slot_labels = array( 'morning' => 'Morning', 'mid_morning' => 'Mid-morning', 'lunchtime' => 'Lunchtime', 'afternoon' => 'Afternoon', 'early_evening' => 'Early Evening', 'late_evening' => 'Late Evening', 'night' => 'Night' );
        $cat_icons = array( 'movement' => '🏃', 'nutrition' => '🥗', 'key_action' => '⭐' );

        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        $html .= '<style>';
        $html .= 'body{margin:0;padding:16px;font-family:Inter,-apple-system,sans-serif;font-size:12px;color:#333;background:#fff;}';
        $html .= '.fp-header{display:flex;align-items:center;justify-content:space-between;padding:12px 0;border-bottom:2px solid #004F59;margin-bottom:12px;}';
        $html .= '.fp-header h1{margin:0;font-size:16px;color:#004F59;} .fp-header .fp-meta{text-align:right;font-size:11px;color:#666;}';
        $html .= '.fp-flex-note{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:8px 12px;font-size:11px;color:#166534;margin-bottom:12px;line-height:1.5;}';
        $html .= '.fp-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:4px;margin-bottom:20px;}';
        $html .= '.fp-day{border:1px solid #e4e6ea;border-radius:6px;overflow:hidden;min-width:0;}';
        $html .= '.fp-day-header{background:#004F59;color:#fff;padding:6px 8px;font-weight:600;text-align:center;font-size:11px;}';
        $html .= '.fp-slot{padding:4px 6px;border-bottom:1px solid #f0f0f0;min-height:24px;}';
        $html .= '.fp-slot-label{font-size:9px;color:#aaa;text-transform:uppercase;margin-bottom:2px;}';
        $html .= '.fp-action{font-size:11px;line-height:1.4;margin-bottom:3px;display:flex;align-items:flex-start;gap:4px;}';
        $html .= '.fp-tick{width:12px;height:12px;border:1px solid #ccc;border-radius:2px;flex-shrink:0;margin-top:1px;}';
        $html .= '.fp-why{font-size:10px;color:#3d8da0;font-style:italic;padding:4px 6px;background:#f8fafb;}';
        $html .= '.fp-section{margin-bottom:16px;} .fp-section h2{font-size:14px;color:#004F59;margin:0 0 8px;border-bottom:1px solid #e4e6ea;padding-bottom:4px;}';
        $html .= '.fp-identity{background:#f8f9fb;border-left:4px solid #3d8da0;padding:12px 16px;border-radius:6px;font-size:14px;font-weight:600;color:#111;margin-bottom:16px;}';
        $html .= 'ul.fp-list{margin:0;padding-left:20px;} ul.fp-list li{margin-bottom:4px;line-height:1.5;}';
        $html .= '@media print{body{padding:8px;font-size:10px;} .fp-grid{gap:2px;}}';
        $html .= '</style></head><body>';

        // Header
        $html .= '<div class="fp-header">';
        $html .= '<div>';
        if ( $prac_logo ) $html .= '<img src="' . esc_url( $prac_logo ) . '" alt="" style="height:28px;margin-right:8px;vertical-align:middle;">';
        $html .= '<h1 style="display:inline;">Weekly Flight Plan</h1></div>';
        $html .= '<div class="fp-meta">' . esc_html( $prac_name ) . ' | ' . esc_html( $client_name ) . '<br>Week ' . (int) $week_number . ' | ' . esc_html( $week_label ) . '</div>';
        $html .= '</div>';

        // Flexibility note
        $html .= '<div class="fp-flex-note">This plan is your guide, not your boss. If you feel like swapping a walk for a swim, or doing mobility in the evening instead of morning — go for it. Tick the boxes when you can. Adjust when you need to.</div>';

        // 7-column daily grid
        $html .= '<div class="fp-grid">';
        foreach ( $days_order as $day ) {
            $html .= '<div class="fp-day">';
            $html .= '<div class="fp-day-header">' . ucfirst( $day ) . '</div>';
            $day_data = $daily[ $day ] ?? array();

            foreach ( $time_slots as $slot ) {
                $slot_data = $day_data[ $slot ] ?? array();
                if ( empty( $slot_data ) && ! is_array( $slot_data ) ) continue;

                $html .= '<div class="fp-slot">';
                $html .= '<div class="fp-slot-label">' . ( $slot_labels[ $slot ] ?? $slot ) . '</div>';

                if ( is_array( $slot_data ) ) {
                    foreach ( $slot_data as $action ) {
                        if ( is_array( $action ) && isset( $action['text'] ) ) {
                            $icon = $cat_icons[ $action['category'] ?? '' ] ?? '☐';
                            $html .= '<div class="fp-action"><span class="fp-tick"></span>' . $icon . ' ' . esc_html( $action['text'] ) . '</div>';
                        } elseif ( is_string( $action ) ) {
                            $html .= '<div class="fp-action"><span class="fp-tick"></span>' . esc_html( $action ) . '</div>';
                        }
                    }
                }

                // WHY anchor
                if ( isset( $day_data['why_anchor'] ) ) {
                    $html .= '<div class="fp-why">' . esc_html( $day_data['why_anchor'] ) . '</div>';
                }

                $html .= '</div>';
            }
            $html .= '</div>';
        }
        $html .= '</div>';

        // Page 2: Identity + Targets + Shopping + Journey
        if ( $identity ) {
            $html .= '<div class="fp-identity">"This week you are someone who ' . esc_html( $identity ) . '"</div>';
        }

        if ( ! empty( $targets ) ) {
            $html .= '<div class="fp-section"><h2>Weekly Targets</h2><ul class="fp-list">';
            foreach ( $targets as $t ) {
                $html .= '<li>' . esc_html( is_array( $t ) ? ( $t['text'] ?? $t['target'] ?? '' ) : $t ) . '</li>';
            }
            $html .= '</ul></div>';
        }

        if ( ! empty( $shopping ) ) {
            $html .= '<div class="fp-section"><h2>Shopping List</h2><ul class="fp-list">';
            foreach ( $shopping as $item ) {
                $html .= '<li>' . esc_html( is_array( $item ) ? ( $item['name'] ?? '' ) : $item ) . '</li>';
            }
            $html .= '</ul></div>';
        }

        if ( $journey ) {
            $html .= '<div class="fp-section"><h2>Journey Assistance</h2><p style="line-height:1.7;">' . wp_kses_post( $journey ) . '</p></div>';
        }

        $html .= '</body></html>';
        return $html;
    }
}

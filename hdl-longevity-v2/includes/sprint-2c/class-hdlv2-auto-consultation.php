<?php
/**
 * HDL V2 — Automation-tier self-reported consultation shortcode (W8).
 *
 * Destination of the post-Stage-3 routing branch added in W7
 * (HDLV2_Client_Draft_View::render_shortcode). When the feature flag is on
 * AND the current user is on the automation tier, the client lands here
 * after completing Stage 3 instead of waiting for a practitioner.
 *
 * Render: 6 self-reported prompts + textarea + audio recorder + submit.
 * Submit handler (POST /wp-json/hdl-v2/v1/auto-consultation/submit) is
 * registered separately in W9.
 *
 * @package HDL_Longevity_V2
 * @since 0.41.30
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HDLV2_Auto_Consultation {

    /**
     * Default prompts — editable from W13 admin via the hdlv2_automation_tier
     * option (consultation_questions key). Keep this list in sync with W13.
     */
    const DEFAULT_QUESTIONS = array(
        'What are your top three health goals over the next year?',
        "What's the biggest health-related challenge you're facing right now?",
        'Describe your typical day — sleep, meals, movement, stress.',
        'What habits have you tried to change in the past, and what got in the way?',
        'Is there anything about your medical history we should know?',
        'What would success look like for you twelve months from now?',
    );

    public function register_hooks() {
        add_shortcode( 'hdlv2_auto_consultation', array( $this, 'render_shortcode' ) );
    }

    public function render_shortcode( $atts ) {

        // Defence in depth: W7's branch already guards entry, but a
        // mis-placed shortcode (Divi editor, etc.) could land users here
        // with the flag off. Render nothing public-facing; admins see a note.
        if ( get_option( 'hdlv2_automation_tier_enabled', false ) !== true ) {
            if ( current_user_can( 'manage_options' ) ) {
                return '<div style="padding:1em;border:1px dashed #999;color:#666;font-size:13px;">Automation tier not yet enabled. This shortcode renders only when <code>hdlv2_automation_tier_enabled</code> is true.</div>';
            }
            return '';
        }

        if ( ! is_user_logged_in() ) {
            $login_url = wp_login_url( get_permalink() );
            return '<div class="hdlv2-auto-root"><div class="hdlv2-auto-card"><h2 class="hdlv2-auto-h1">Please sign in to continue</h2><p class="hdlv2-auto-sub">You need to be signed in to share your answers. <a href="' . esc_url( $login_url ) . '">Log in</a> using the link from your welcome email.</p></div></div>';
        }

        $this->enqueue_assets();

        $questions = $this->get_questions();

        ob_start();
        ?>
        <div class="hdlv2-auto-root" id="hdlv2-auto-root">
            <div class="hdlv2-auto-card" id="hdlv2-auto-form">
                <h1 class="hdlv2-auto-h1">Share a bit about yourself</h1>
                <p class="hdlv2-auto-sub">You've completed the assessment. The final step is to share a bit more about yourself in your own words — the things a practitioner would normally ask in a one-on-one consultation. Take your time. Be honest, not perfect.</p>

                <ol class="hdlv2-auto-prompts">
                    <?php foreach ( $questions as $q ) : ?>
                        <li><?php echo esc_html( $q ); ?></li>
                    <?php endforeach; ?>
                </ol>

                <label class="hdlv2-auto-label" for="hdlv2-auto-text">Your answers</label>
                <textarea
                    id="hdlv2-auto-text"
                    class="hdlv2-auto-textarea"
                    placeholder="Type your answers here, or use the audio recorder below."
                    rows="14"></textarea>

                <div class="hdlv2-auto-audio-wrap">
                    <p class="hdlv2-auto-audio-label">Or record your answers</p>
                    <div id="hdlv2-auto-audio"></div>
                </div>

                <div class="hdlv2-auto-error" id="hdlv2-auto-error" hidden></div>

                <button type="button" class="hdlv2-auto-submit" id="hdlv2-auto-submit" disabled>
                    Submit my answers
                </button>
            </div>

            <div class="hdlv2-auto-card hdlv2-auto-success" id="hdlv2-auto-success" hidden>
                <h2 class="hdlv2-auto-h1">Thank you</h2>
                <p class="hdlv2-auto-sub">We've received your answers. Your Trajectory Plan is being prepared and will arrive in your inbox shortly.</p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private function enqueue_assets() {
        // hdlv2-transcriber is registered (not enqueued) at file scope in the
        // main plugin file at wp_enqueue_scripts priority 5; declaring it as
        // a dep of the audio component auto-enqueues it.
        wp_enqueue_script(
            'hdlv2-audio-component',
            HDLV2_PLUGIN_URL . 'assets/js/hdlv2-audio-component.js',
            array( 'hdlv2-transcriber' ),
            HDLV2_VERSION,
            true
        );

        wp_enqueue_script(
            'hdlv2-auto-consultation',
            HDLV2_PLUGIN_URL . 'assets/js/hdlv2-auto-consultation.js',
            array( 'hdlv2-audio-component' ),
            HDLV2_VERSION,
            true
        );

        wp_enqueue_style(
            'hdlv2-auto-consultation',
            HDLV2_PLUGIN_URL . 'assets/css/hdlv2-auto-consultation.css',
            array(),
            HDLV2_VERSION
        );

        wp_localize_script( 'hdlv2-auto-consultation', 'HDLV2_AUTO', array(
            'submit_url' => esc_url_raw( rest_url( 'hdl-v2/v1/auto-consultation/submit' ) ),
            'audio_base' => esc_url_raw( rest_url( 'hdl-v2/v1/audio' ) ),
            'nonce'      => wp_create_nonce( 'wp_rest' ),
        ) );
    }

    private function get_questions() {
        $opt = get_option( 'hdlv2_automation_tier', array() );
        if ( is_array( $opt ) && ! empty( $opt['consultation_questions'] ) && is_array( $opt['consultation_questions'] ) ) {
            $cleaned = array_values( array_filter( array_map( 'sanitize_text_field', $opt['consultation_questions'] ) ) );
            if ( ! empty( $cleaned ) ) {
                return $cleaned;
            }
        }
        return self::DEFAULT_QUESTIONS;
    }
}

<?php
/**
 * HDL V2 — Automation Tier Admin Page (W13).
 *
 * Top-level admin page (add_menu_page, not submenu) with two tabs:
 *   - Settings (default) — feature flag + widget origin + entitled price keys
 *     + 6 consultation questions + hold-for-review safety valve + support email
 *   - Tokens — WP_List_Table of wp_hdlv2_automation_tokens with filters,
 *     pagination, and per-row Revoke action
 *
 * Storage:
 *   - `hdlv2_automation_tier_enabled` (boolean option, set by W3) — master
 *     switch. This admin page's Enable toggle reads/writes the SAME key, so
 *     flipping it via UI behaves identically to `wp option update`.
 *   - `hdlv2_automation_hold_for_review` (boolean option) — safety valve from W9.
 *   - `hdlv2_automation_tier` (serialised array) — the rest:
 *       widget_origin, entitled_price_keys[], consultation_questions[], support_email.
 *     W8's shortcode already reads consultation_questions from this same key.
 *
 * Capability: manage_options (administrators only). Nonces on every form +
 * ajax action. No existing admin page or menu item modified.
 *
 * @package HDL_Longevity_V2
 * @since 0.41.34
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HDLV2_Admin_Automation_Tier {

    const SLUG          = 'hdlv2-automation-tier';
    const OPTION_KEY    = 'hdlv2_automation_tier';
    const ENABLED_KEY   = 'hdlv2_automation_tier_enabled';
    const HOLD_KEY      = 'hdlv2_automation_hold_for_review';
    const NONCE_ACTION      = 'hdlv2_automation_tier_save';
    const REVOKE_ACTION     = 'hdlv2_automation_revoke_token';
    const REDFLAG_SCAN_KEY  = 'hdlv2_ff_redflag_scan';
    const STALLED_FILTER_KEY = 'hdlv2_ff_stalled_filter';
    const SAFETY_SCREEN_KEY  = 'hdlv2_ff_safety_screen';

    /** @var self|null */
    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    public function register_hooks() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_post_' . self::NONCE_ACTION, array( $this, 'handle_settings_save' ) );
        add_action( 'wp_ajax_' . self::REVOKE_ACTION, array( $this, 'ajax_revoke_token' ) );
    }

    public function add_admin_menu() {
        add_menu_page(
            'Automation Tier — HDL V2',
            'Automation Tier',
            'manage_options',
            self::SLUG,
            array( $this, 'render_page' ),
            'dashicons-rest-api',
            58 // Below Comments (25), Tools (75) — sensible mid-position so it
               // doesn't collide with core slots reserved at the top of the menu.
        );
    }

    // ──────────────────────────────────────────────────────────────
    //  Page render — tab dispatch
    // ──────────────────────────────────────────────────────────────

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.', '', array( 'response' => 403 ) );
        }

        $current_tab = isset( $_GET['tab'] ) && $_GET['tab'] === 'tokens' ? 'tokens' : 'settings';
        $base_url    = admin_url( 'admin.php?page=' . self::SLUG );

        echo '<div class="wrap">';
        echo '<h1>Automation Tier</h1>';
        echo '<p style="max-width:760px;color:#555;">Manage the paid automation tier — clients who complete the assessment without a practitioner consultation, with Make.com Route 1 generating their Trajectory Plan automatically.</p>';

        // Tab nav.
        echo '<h2 class="nav-tab-wrapper" style="margin-top:24px;">';
        echo '<a href="' . esc_url( $base_url ) . '" class="nav-tab ' . ( $current_tab === 'settings' ? 'nav-tab-active' : '' ) . '">Settings</a>';
        echo '<a href="' . esc_url( $base_url . '&tab=tokens' ) . '" class="nav-tab ' . ( $current_tab === 'tokens' ? 'nav-tab-active' : '' ) . '">Tokens</a>';
        echo '</h2>';

        // Post-save flash notice (?settings-updated=true | =false).
        if ( isset( $_GET['settings-updated'] ) ) {
            $ok = $_GET['settings-updated'] === 'true';
            $msg = $ok ? 'Settings saved.' : ( isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : 'Save failed.' );
            $cls = $ok ? 'notice-success' : 'notice-error';
            echo '<div class="notice ' . $cls . ' is-dismissible" style="margin-top:16px;"><p>' . esc_html( $msg ) . '</p></div>';
        }

        if ( $current_tab === 'tokens' ) {
            $this->render_tokens_tab();
        } else {
            $this->render_settings_tab();
        }

        echo '</div>';
    }

    // ──────────────────────────────────────────────────────────────
    //  Settings tab
    // ──────────────────────────────────────────────────────────────

    private function render_settings_tab() {
        $enabled     = (bool) get_option( self::ENABLED_KEY, false );
        $redflag     = (bool) get_option( self::REDFLAG_SCAN_KEY, false );
        $stalled     = (bool) get_option( self::STALLED_FILTER_KEY, false );
        $safety      = (bool) get_option( self::SAFETY_SCREEN_KEY, false );
        $hold        = (bool) get_option( self::HOLD_KEY, false );
        $opt         = get_option( self::OPTION_KEY, array() );
        if ( ! is_array( $opt ) ) {
            $opt = array();
        }
        $widget_origin = isset( $opt['widget_origin'] )   ? (string) $opt['widget_origin']   : 'https://stby.healthdatalab.net';
        $price_keys    = isset( $opt['entitled_price_keys'] ) && is_array( $opt['entitled_price_keys'] )
            ? $opt['entitled_price_keys']
            : self::default_price_keys();
        $questions     = isset( $opt['consultation_questions'] ) && is_array( $opt['consultation_questions'] ) && ! empty( $opt['consultation_questions'] )
            ? $opt['consultation_questions']
            : self::default_questions();
        $support_email = isset( $opt['support_email'] )    ? (string) $opt['support_email']    : 'office+matthew@healthdatalab.com';

        $form_action = admin_url( 'admin-post.php' );

        // Token count for the disable-confirmation modal warning.
        global $wpdb;
        $active_tokens = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}hdlv2_automation_tokens WHERE status IN ('issued','started')"
        );

        $hmac_defined = defined( 'HDL_PAID_REPORT_PROVISION_KEY' ) && HDL_PAID_REPORT_PROVISION_KEY !== '';

        ?>
        <form method="post" action="<?php echo esc_url( $form_action ); ?>" style="margin-top:18px;max-width:840px;" id="hdlv2-automation-settings-form">
            <?php wp_nonce_field( self::NONCE_ACTION, '_hdlv2_nonce' ); ?>
            <input type="hidden" name="action" value="<?php echo esc_attr( self::NONCE_ACTION ); ?>">

            <?php if ( ! $hmac_defined ) : ?>
                <div class="notice notice-warning inline" style="margin:0 0 18px;padding:10px 14px;">
                    <p style="margin:0;"><strong>HMAC key not configured.</strong> Define <code>HDL_PAID_REPORT_PROVISION_KEY</code> in <code>wp-config.php</code> before flipping the Enable toggle on. Without it, the W4 paid-report-provision endpoint will reject every Altituding webhook.</p>
                </div>
            <?php endif; ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="hdlv2_enabled">Enable automation tier</label></th>
                    <td>
                        <label>
                            <input type="checkbox" name="enabled" id="hdlv2_enabled" value="1" <?php checked( $enabled ); ?>
                                   data-active-tokens="<?php echo esc_attr( $active_tokens ); ?>">
                            <span>Master switch. When off, the W7 routing branch, the W8 shortcode, the W9 submit handler, and the W11 dashboard chrome are all dead code.</span>
                        </label>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="hdlv2_redflag">Intake red-flag scan</label></th>
                    <td>
                        <label>
                            <input type="checkbox" name="redflag_scan" id="hdlv2_redflag" value="1" <?php checked( $redflag ); ?>>
                            <span>When on, a completed Stage 3 intake (or any client you scan manually) is checked for medical red flags; flags appear on the practitioner dashboard and the client is emailed a non-diagnostic GP nudge. Leave OFF until tested.</span>
                        </label>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="hdlv2_stalled_filter">Stalled-leads targeting filter</label></th>
                    <td>
                        <label>
                            <input type="checkbox" name="stalled_filter" id="hdlv2_stalled_filter" value="1" <?php checked( $stalled ); ?>>
                            <span>When on, adds a Stage + Quiet filter to the practitioner client list so you can isolate leads who stalled early (e.g. Stage 1, no reply in 2 weeks). Read-only dashboard filter. Off by default.</span>
                        </label>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="hdlv2_safety_screen">Front-door safety screen</label></th>
                    <td>
                        <label>
                            <input type="checkbox" name="safety_screen" id="hdlv2_safety_screen" value="1" <?php checked( $safety ); ?>>
                            <span>When on, the widget shows a short symptom + mental-health check before the result. Ticked warning signs raise a flag on the dashboard and email the visitor (a non-diagnostic GP nudge, or crisis lines for self-harm). <strong>Clinical wording + 999/GP routing must be signed off before LIVE.</strong> Off by default.</span>
                        </label>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="hdlv2_widget_origin">Widget origin</label></th>
                    <td>
                        <input type="url" name="widget_origin" id="hdlv2_widget_origin" class="regular-text"
                               value="<?php echo esc_attr( $widget_origin ); ?>" placeholder="https://stby.healthdatalab.net">
                        <p class="description">Used for <code>Content-Security-Policy: frame-ancestors</code> when Altituding embeds the assessment via iframe. Validated as a URL.</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="hdlv2_price_keys">Entitled price keys</label></th>
                    <td>
                        <textarea name="entitled_price_keys" id="hdlv2_price_keys" rows="8" class="large-text code" placeholder="PRICE_AUTOMATION_TIER"><?php echo esc_textarea( implode( "\n", $price_keys ) ); ?></textarea>
                        <p class="description">One key per line. Altituding's Stripe webhook checks the purchase against this list before calling <code>/paid-report-provision</code>. Each line must match <code>/^PRICE_[A-Z0-9_]+$/</code>.</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="hdlv2_consultation_questions">Consultation questions</label></th>
                    <td>
                        <textarea name="consultation_questions" id="hdlv2_consultation_questions" rows="8" class="large-text"><?php echo esc_textarea( implode( "\n", $questions ) ); ?></textarea>
                        <p class="description">One question per line. The W8 <code>[hdlv2_auto_consultation]</code> shortcode renders these as a numbered list above the client's textarea. Falls back to hard-coded defaults if this field is left empty.</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="hdlv2_hold">Hold Trajectory Plan for practitioner review</label></th>
                    <td>
                        <label>
                            <input type="checkbox" name="hold_for_review" id="hdlv2_hold" value="1" <?php checked( $hold ); ?>>
                            <span>Safety valve. When ticked, W9's submit handler skips the AI generation + Make.com fire, persists the addendum, and routes the client into the existing consultation editor for the practitioner to finalise manually.</span>
                        </label>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="hdlv2_support_email">Support email</label></th>
                    <td>
                        <input type="email" name="support_email" id="hdlv2_support_email" class="regular-text"
                               value="<?php echo esc_attr( $support_email ); ?>" placeholder="office+matthew@healthdatalab.com">
                        <p class="description">Shown to clients on error screens + the thank-you panel as the "if you need help, email…" address.</p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" class="button button-primary" id="hdlv2-automation-save-btn">Save settings</button>
                <span style="margin-left:14px;color:#888;font-size:13px;">Active tokens (issued / started): <strong><?php echo esc_html( $active_tokens ); ?></strong></span>
            </p>
        </form>

        <script>
        (function () {
            var form     = document.getElementById('hdlv2-automation-settings-form');
            var checkbox = document.getElementById('hdlv2_enabled');
            if (!form || !checkbox) return;
            // Snapshot initial state so we know if the user is toggling OFF.
            var initiallyEnabled = checkbox.checked;
            form.addEventListener('submit', function (e) {
                var nowEnabled    = checkbox.checked;
                var activeTokens  = parseInt(checkbox.dataset.activeTokens || '0', 10);
                if (initiallyEnabled && !nowEnabled && activeTokens > 0) {
                    var msg = 'Disabling automation tier with ' + activeTokens + ' active token(s) (issued / started). ' +
                              'In-flight clients will lose their flow — their next page load returns to the existing ' +
                              'wait-for-practitioner screen. Continue?';
                    if (!window.confirm(msg)) {
                        e.preventDefault();
                        return false;
                    }
                }
            });
        })();
        </script>
        <?php
    }

    // ──────────────────────────────────────────────────────────────
    //  Tokens tab
    // ──────────────────────────────────────────────────────────────

    private function render_tokens_tab() {
        // Lazy-load BOTH the WP core base class AND our subclass file. The
        // subclass extends WP_List_Table, so its file can't be required at
        // plugins_loaded time (admin classes load in admin context only).
        if ( ! class_exists( 'WP_List_Table' ) ) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
        }
        if ( ! class_exists( 'HDLV2_Admin_Automation_Tokens_Table' ) ) {
            require_once HDLV2_PLUGIN_DIR . 'includes/class-hdlv2-admin-automation-tokens-table.php';
        }
        $table = new HDLV2_Admin_Automation_Tokens_Table();
        $table->prepare_items();

        $base_url = admin_url( 'admin.php?page=' . self::SLUG . '&tab=tokens' );

        // Filter form (status / email / date range).
        $status  = isset( $_GET['status'] )  ? sanitize_text_field( wp_unslash( $_GET['status'] ) )  : '';
        $email   = isset( $_GET['email'] )   ? sanitize_text_field( wp_unslash( $_GET['email'] ) )   : '';
        $from    = isset( $_GET['from'] )    ? sanitize_text_field( wp_unslash( $_GET['from'] ) )    : '';
        $to      = isset( $_GET['to'] )      ? sanitize_text_field( wp_unslash( $_GET['to'] ) )      : '';

        echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" style="margin:18px 0;">';
        echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
        echo '<input type="hidden" name="tab" value="tokens">';
        echo '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;background:#fff;padding:12px 14px;border:1px solid #c3c4c7;border-radius:4px;">';
        echo '<label>Status <select name="status">';
        echo '<option value="">All</option>';
        foreach ( array( 'issued', 'started', 'completed', 'revoked' ) as $s ) {
            echo '<option value="' . esc_attr( $s ) . '" ' . selected( $status, $s, false ) . '>' . esc_html( ucfirst( $s ) ) . '</option>';
        }
        echo '</select></label>';
        echo '<label>Email <input type="search" name="email" value="' . esc_attr( $email ) . '" placeholder="contains…" style="min-width:220px;"></label>';
        echo '<label>From <input type="date" name="from" value="' . esc_attr( $from ) . '"></label>';
        echo '<label>To <input type="date" name="to" value="' . esc_attr( $to ) . '"></label>';
        echo '<button type="submit" class="button">Filter</button>';
        if ( $status || $email || $from || $to ) {
            echo '<a href="' . esc_url( $base_url ) . '" class="button-link">Reset</a>';
        }
        echo '</div>';
        echo '</form>';

        // Table.
        echo '<form method="post">';
        $table->display();
        echo '</form>';

        // Revoke JS — confirm + ajax POST + soft-remove the button.
        $ajax_url = admin_url( 'admin-ajax.php' );
        $nonce    = wp_create_nonce( self::REVOKE_ACTION );
        ?>
        <script>
        (function () {
            var ajaxUrl = <?php echo wp_json_encode( $ajax_url ); ?>;
            var nonce   = <?php echo wp_json_encode( $nonce ); ?>;
            document.querySelectorAll('.hdlv2-revoke-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var email   = btn.dataset.email || '';
                    var tokenId = btn.dataset.tokenId || '';
                    var reason  = window.prompt('Revoke token for ' + email + '?\n\nOptional reason (audit log):');
                    if (reason === null) return; // cancel
                    btn.disabled = true;
                    btn.textContent = 'Revoking…';
                    var body = new URLSearchParams({
                        action: 'hdlv2_automation_revoke_token',
                        token_id: tokenId,
                        reason: reason,
                        _ajax_nonce: nonce
                    });
                    fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
                        .then(function (r) { return r.json(); })
                        .then(function (res) {
                            if (res && res.success) {
                                var row = btn.closest('tr');
                                if (row) row.style.opacity = '0.4';
                                btn.textContent = 'Revoked';
                                btn.style.background = '#fef2f2';
                                btn.style.color = '#dc2626';
                            } else {
                                btn.disabled = false;
                                btn.textContent = 'Revoke';
                                window.alert('Revoke failed: ' + ((res && res.data && res.data.message) || 'unknown error'));
                            }
                        })
                        .catch(function () {
                            btn.disabled = false;
                            btn.textContent = 'Revoke';
                            window.alert('Network error — please retry.');
                        });
                });
            });
        })();
        </script>
        <?php
    }

    // ──────────────────────────────────────────────────────────────
    //  Settings save handler (admin-post)
    // ──────────────────────────────────────────────────────────────

    public function handle_settings_save() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.', '', array( 'response' => 403 ) );
        }
        check_admin_referer( self::NONCE_ACTION, '_hdlv2_nonce' );

        $errors = array();

        // Enabled (boolean).
        $enabled = ! empty( $_POST['enabled'] );

        // Hold for review (boolean).
        $hold = ! empty( $_POST['hold_for_review'] );

        // Widget origin (URL).
        $widget_origin = isset( $_POST['widget_origin'] ) ? esc_url_raw( wp_unslash( $_POST['widget_origin'] ) ) : '';
        if ( $widget_origin === '' || ! filter_var( $widget_origin, FILTER_VALIDATE_URL ) ) {
            $errors[] = 'Widget origin must be a valid URL.';
            $widget_origin = 'https://stby.healthdatalab.net';
        }

        // Entitled price keys (one per line, /^PRICE_[A-Z0-9_]+$/).
        $price_keys_raw = isset( $_POST['entitled_price_keys'] ) ? (string) wp_unslash( $_POST['entitled_price_keys'] ) : '';
        $price_keys     = array();
        foreach ( preg_split( '/\r\n|\r|\n/', $price_keys_raw ) as $line ) {
            $line = trim( $line );
            if ( $line === '' ) {
                continue;
            }
            if ( ! preg_match( '/^PRICE_[A-Z0-9_]+$/', $line ) ) {
                $errors[] = 'Invalid price key: "' . $line . '" (expected PRICE_UPPERCASE_WITH_UNDERSCORES).';
                continue;
            }
            $price_keys[] = $line;
        }

        // Consultation questions (one per line).
        $questions_raw = isset( $_POST['consultation_questions'] ) ? (string) wp_unslash( $_POST['consultation_questions'] ) : '';
        $questions     = array();
        foreach ( preg_split( '/\r\n|\r|\n/', $questions_raw ) as $line ) {
            $line = trim( sanitize_text_field( $line ) );
            if ( $line !== '' ) {
                $questions[] = $line;
            }
        }
        if ( empty( $questions ) ) {
            // Falls back to defaults at read time in W8; don't store an empty array.
            $questions = array();
        }

        // Support email.
        $support_email = isset( $_POST['support_email'] ) ? sanitize_email( wp_unslash( $_POST['support_email'] ) ) : '';
        if ( $support_email === '' || ! is_email( $support_email ) ) {
            $errors[] = 'Support email must be a valid email address.';
            $support_email = 'office+matthew@healthdatalab.com';
        }

        // Persist.
        update_option( self::ENABLED_KEY, $enabled, false );
        $redflag = ! empty( $_POST['redflag_scan'] );
        update_option( self::REDFLAG_SCAN_KEY, $redflag, false );
        $stalled = ! empty( $_POST['stalled_filter'] );
        update_option( self::STALLED_FILTER_KEY, $stalled, false );
        $safety = ! empty( $_POST['safety_screen'] );
        update_option( self::SAFETY_SCREEN_KEY, $safety, false );
        update_option( self::HOLD_KEY, $hold, false );
        update_option( self::OPTION_KEY, array(
            'widget_origin'          => $widget_origin,
            'entitled_price_keys'    => $price_keys,
            'consultation_questions' => $questions,
            'support_email'          => $support_email,
        ), false );

        $redirect = add_query_arg(
            array(
                'page'             => self::SLUG,
                'settings-updated' => empty( $errors ) ? 'true' : 'false',
            ),
            admin_url( 'admin.php' )
        );
        if ( ! empty( $errors ) ) {
            $redirect = add_query_arg( 'error', rawurlencode( implode( ' · ', $errors ) ), $redirect );
        }
        wp_safe_redirect( $redirect );
        exit;
    }

    // ──────────────────────────────────────────────────────────────
    //  Revoke token (wp_ajax)
    // ──────────────────────────────────────────────────────────────

    public function ajax_revoke_token() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
        }
        check_ajax_referer( self::REVOKE_ACTION );

        if ( ! defined( 'HDL_PAID_REPORT_PROVISION_KEY' ) || HDL_PAID_REPORT_PROVISION_KEY === '' ) {
            wp_send_json_error( array(
                'message' => 'HMAC key not configured. Define HDL_PAID_REPORT_PROVISION_KEY in wp-config.php before revoking tokens.',
            ), 503 );
        }

        $token_id = isset( $_POST['token_id'] ) ? (int) $_POST['token_id'] : 0;
        $reason   = isset( $_POST['reason'] )   ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';
        if ( $token_id <= 0 ) {
            wp_send_json_error( array( 'message' => 'Missing token_id.' ), 400 );
        }

        global $wpdb;
        $token_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, client_email, status FROM {$wpdb->prefix}hdlv2_automation_tokens WHERE id = %d LIMIT 1",
            $token_id
        ) );
        if ( ! $token_row ) {
            wp_send_json_error( array( 'message' => 'Token not found.' ), 404 );
        }
        if ( $token_row->status === 'revoked' ) {
            wp_send_json_success( array( 'message' => 'Already revoked.', 'token_id' => $token_id ) );
        }

        // Same business logic as W6's /revoke-token endpoint — direct DB
        // write keeps the admin path fast and avoids an internal HTTP
        // round-trip. The HMAC-key-defined check above mirrors the runtime
        // gate W6 enforces. Audit log via error_log matches the W6 format.
        $updated = $wpdb->update(
            $wpdb->prefix . 'hdlv2_automation_tokens',
            array(
                'status'      => 'revoked',
                'revoked_at'  => current_time( 'mysql' ),
            ),
            array( 'id' => $token_id ),
            array( '%s', '%s' ),
            array( '%d' )
        );

        if ( $updated === false ) {
            wp_send_json_error( array( 'message' => 'DB error: ' . $wpdb->last_error ), 500 );
        }

        error_log( sprintf(
            '[HDLV2 admin-revoke] token_id=%d email=%s by_user=%d reason=%s',
            $token_id,
            $token_row->client_email,
            get_current_user_id(),
            $reason !== '' ? substr( $reason, 0, 200 ) : '(none)'
        ) );

        wp_send_json_success( array(
            'message'  => 'Token revoked.',
            'token_id' => $token_id,
            'email'    => $token_row->client_email,
        ) );
    }

    // ──────────────────────────────────────────────────────────────
    //  Defaults
    // ──────────────────────────────────────────────────────────────

    private static function default_questions() {
        // Mirrored from HDLV2_Auto_Consultation::DEFAULT_QUESTIONS (W8).
        // Keep these two in sync.
        return array(
            'What are your top three health goals over the next year?',
            "What's the biggest health-related challenge you're facing right now?",
            'Describe your typical day — sleep, meals, movement, stress.',
            'What habits have you tried to change in the past, and what got in the way?',
            'Is there anything about your medical history we should know?',
            'What would success look like for you twelve months from now?',
        );
    }

    private static function default_price_keys() {
        return array(
            'PRICE_MINIMUM_STANDARD',
            'PRICE_MINIMUM_FOUNDERS',
            'PRICE_TRAJECTORY_FULL',
            'PRICE_TRAJECTORY_INSTALMENTS',
            'PRICE_SIGNATURE_FULL',
            'PRICE_SIGNATURE_INSTALMENTS',
        );
    }
}

// Tokens-table subclass lives in its own file (lazy-required from
// render_tokens_tab) so the `extends WP_List_Table` clause doesn't fire at
// plugins_loaded time when WP admin classes aren't loaded yet.
// See: includes/class-hdlv2-admin-automation-tokens-table.php

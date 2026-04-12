<?php
/**
 * Lead Magnet Widget — Configuration, Invites & Lead Capture.
 *
 * Manages per-practitioner widget settings, invite tokens for direct
 * client links, and lead capture from both public embeds and invites.
 *
 * @package HDL_Longevity_V2
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HDLV2_Widget_Config {

    /**
     * Register all hooks for widget config + invites.
     */
    public function register_hooks() {
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
        add_filter( 'rest_pre_serve_request', array( $this, 'add_lead_cors_headers' ), 15, 4 );

        // AJAX — config
        add_action( 'wp_ajax_hdlv2_save_widget_config', array( $this, 'ajax_save_config' ) );
        add_action( 'wp_ajax_hdlv2_get_widget_config', array( $this, 'ajax_get_config' ) );

        // AJAX — invites
        add_action( 'wp_ajax_hdlv2_create_invite', array( $this, 'ajax_create_invite' ) );
        add_action( 'wp_ajax_hdlv2_get_invites', array( $this, 'ajax_get_invites' ) );

        // AJAX — logo upload
        add_action( 'wp_ajax_hdlv2_upload_logo', array( $this, 'ajax_upload_logo' ) );

        // AJAX — V2 client list + WHY gate release
        add_action( 'wp_ajax_hdlv2_get_v2_clients', array( $this, 'ajax_get_v2_clients' ) );
        add_action( 'wp_ajax_hdlv2_release_why', array( $this, 'ajax_release_why' ) );

        add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_dashboard_js' ) );
        add_shortcode( 'hdlv2_widget', array( $this, 'render_shortcode' ) );
    }

    // ──────────────────────────────────────────────────────────────
    //  CORS
    // ──────────────────────────────────────────────────────────────

    public function add_lead_cors_headers( $served, $result, $request, $server ) {
        $route = $request->get_route();
        if ( $route === '/hdl-v2/v1/widget/lead' || $route === '/hdl-v2/v1/widget/verify-invite' ) {
            header( 'Access-Control-Allow-Origin: *' );
            header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
            header( 'Access-Control-Allow-Headers: Content-Type' );
            header( 'Access-Control-Max-Age: 86400' );
            header_remove( 'Access-Control-Allow-Credentials' );
        }
        return $served;
    }

    // ──────────────────────────────────────────────────────────────
    //  SHORTCODE
    // ──────────────────────────────────────────────────────────────

    public function render_shortcode( $atts ) {
        $atts = shortcode_atts( array(
            'practitioner_id' => get_current_user_id(),
        ), $atts, 'hdlv2_widget' );

        $user_id = absint( $atts['practitioner_id'] );
        $config  = $this->get_config( $user_id );

        $practitioner_name = $config ? $config->practitioner_name : '';
        $logo_url          = $config ? $config->logo_url : '';
        $cta_text          = $config ? $config->cta_text : 'Book a session';
        $cta_link          = $config ? $config->cta_link : '';
        $theme_color       = $config ? $config->theme_color : '#3d8da0';

        wp_enqueue_script(
            'hdlv2-widget-shortcode',
            HDLV2_PLUGIN_URL . 'widget/hdl-lead-magnet.js',
            array(),
            HDLV2_VERSION . '.' . filemtime( HDLV2_PLUGIN_DIR . 'widget/hdl-lead-magnet.js' ),
            true
        );

        $api_url = rest_url( 'hdl-v2/v1/widget/lead' );

        return sprintf(
            '<div class="hdl-rate-widget" '
            . 'data-practitioner-id="%d" '
            . 'data-practitioner-name="%s" '
            . 'data-logo="%s" '
            . 'data-cta-text="%s" '
            . 'data-cta-link="%s" '
            . 'data-api="%s" '
            . 'data-verify-api="%s" '
            . 'data-color="%s">'
            . '</div>',
            $user_id,
            esc_attr( $practitioner_name ),
            esc_url( $logo_url ),
            esc_attr( $cta_text ),
            esc_url( $cta_link ),
            esc_url( $api_url ),
            esc_url( rest_url( 'hdl-v2/v1/widget/verify-invite' ) ),
            esc_attr( $theme_color )
        );
    }

    // ──────────────────────────────────────────────────────────────
    //  DASHBOARD JS
    // ──────────────────────────────────────────────────────────────

    public function maybe_enqueue_dashboard_js() {
        $user_id = get_current_user_id();
        if ( ! $user_id || ! HDLV2_Compatibility::is_practitioner( $user_id ) ) {
            return;
        }

        wp_enqueue_script(
            'hdlv2-dashboard',
            HDLV2_PLUGIN_URL . 'assets/js/hdlv2-dashboard.js',
            array(),
            HDLV2_VERSION,
            true
        );

        wp_enqueue_script(
            'hdlv2-widget',
            HDLV2_PLUGIN_URL . 'widget/hdl-lead-magnet.js',
            array(),
            HDLV2_VERSION,
            true
        );

        $config = $this->get_config( $user_id );
        $config_arr = $config ? array(
            'practitioner_name'  => $config->practitioner_name,
            'logo_url'           => $config->logo_url,
            'cta_text'           => $config->cta_text,
            'cta_link'           => $config->cta_link,
            'webhook_url'        => $config->webhook_url,
            'notification_email' => $config->notification_email,
            'theme_color'        => $config->theme_color,
        ) : array();

        wp_localize_script( 'hdlv2-dashboard', 'hdlv2_dashboard', array(
            'ajax_url'        => admin_url( 'admin-ajax.php' ),
            'nonce'           => wp_create_nonce( 'hdlv2_widget_config' ),
            'v1_nonce'        => wp_create_nonce( 'health_tracker_nonce' ),
            'practitioner_id' => $user_id,
            'widget_js_url'   => HDLV2_PLUGIN_URL . 'widget/hdl-lead-magnet.js',
            'widget_page_url' => site_url( '/rate-of-ageing-widget/' ),
            'config'          => $config_arr,
            'embed_code'      => $config ? HDLV2_Widget_Renderer::generate_embed_code( $user_id, $config_arr ) : '',
        ) );
    }

    // ──────────────────────────────────────────────────────────────
    //  REST ROUTES
    // ──────────────────────────────────────────────────────────────

    public function register_rest_routes() {
        // Config CRUD (practitioner auth)
        register_rest_route( 'hdl-v2/v1', '/widget/config', array(
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'rest_get_config' ),
                'permission_callback' => array( $this, 'check_practitioner_permission' ),
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'rest_save_config' ),
                'permission_callback' => array( $this, 'check_practitioner_permission' ),
            ),
        ) );

        // Lead capture (public)
        register_rest_route( 'hdl-v2/v1', '/widget/lead', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_capture_lead' ),
            'permission_callback' => '__return_true',
        ) );

        // Create invite (practitioner auth)
        register_rest_route( 'hdl-v2/v1', '/widget/invite', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_create_invite' ),
            'permission_callback' => array( $this, 'check_practitioner_permission' ),
        ) );

        // Verify invite (public — called by widget JS)
        register_rest_route( 'hdl-v2/v1', '/widget/verify-invite', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'rest_verify_invite' ),
            'permission_callback' => '__return_true',
        ) );

        // List invites (practitioner auth)
        register_rest_route( 'hdl-v2/v1', '/widget/invites', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'rest_list_invites' ),
            'permission_callback' => array( $this, 'check_practitioner_permission' ),
        ) );
    }

    public function check_practitioner_permission() {
        return HDLV2_Compatibility::is_practitioner( get_current_user_id() );
    }

    // ──────────────────────────────────────────────────────────────
    //  REST: CONFIG
    // ──────────────────────────────────────────────────────────────

    public function rest_get_config( $request ) {
        $config = $this->get_config( get_current_user_id() );
        return rest_ensure_response( $config ?: array() );
    }

    public function rest_save_config( $request ) {
        $user_id = get_current_user_id();
        $data    = $this->sanitize_config( $request->get_json_params() );
        $result  = $this->save_config( $user_id, $data );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( array(
            'success'    => true,
            'embed_code' => HDLV2_Widget_Renderer::generate_embed_code( $user_id, $data ),
        ) );
    }

    // ──────────────────────────────────────────────────────────────
    //  REST: LEAD CAPTURE (with invite completion + email dedup)
    // ──────────────────────────────────────────────────────────────

    public function rest_capture_lead( $request ) {
        $params = $request->get_json_params();

        $practitioner_id = isset( $params['practitioner_id'] ) ? absint( $params['practitioner_id'] ) : 0;
        if ( ! $practitioner_id ) {
            return new WP_Error( 'missing_practitioner', 'Practitioner ID is required.', array( 'status' => 400 ) );
        }

        // Rate limiting: 10 leads per hour per IP
        $ip        = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? 'unknown' );
        $transient = 'hdlv2_lead_' . md5( $ip );
        $count     = (int) get_transient( $transient );
        if ( $count >= 10 ) {
            return new WP_Error( 'rate_limited', 'Too many submissions. Please try again later.', array( 'status' => 429 ) );
        }
        set_transient( $transient, $count + 1, HOUR_IN_SECONDS );

        // If this lead came from an invite, verify and complete the token
        $invite_id    = null;
        $invite_token = isset( $params['invite_token'] ) ? sanitize_text_field( $params['invite_token'] ) : '';
        if ( $invite_token ) {
            $invite = $this->get_valid_invite( $invite_token );
            if ( $invite ) {
                $invite_id = (int) $invite->id;
                $this->complete_invite( $invite_id );
            }
        }

        // Extract lead fields (V2: 9-question format)
        $visitor_name  = sanitize_text_field( $params['name'] ?? '' );
        $visitor_email = sanitize_email( $params['email'] ?? '' );
        $visitor_phone = sanitize_text_field( $params['phone'] ?? '' );
        $visitor_age   = isset( $params['q1_age'] ) ? absint( $params['q1_age'] ) : null;
        $rate          = isset( $params['rate_of_ageing_result'] ) ? floatval( $params['rate_of_ageing_result'] ) : null;

        // Build Stage 1 answers from widget payload
        $stage1_data = array();
        foreach ( array( 'q1_age', 'q1_sex', 'q2a', 'q2b', 'q3', 'q4', 'q5', 'q6', 'q7', 'q8', 'q9' ) as $field ) {
            if ( isset( $params[ $field ] ) ) {
                $stage1_data[ $field ] = sanitize_text_field( $params[ $field ] );
            }
        }
        if ( isset( $stage1_data['q1_age'] ) ) {
            $stage1_data['q1_age'] = absint( $stage1_data['q1_age'] );
        }
        if ( isset( $stage1_data['q2a'] ) ) {
            $stage1_data['q2a'] = absint( $stage1_data['q2a'] );
        }

        // Server-side calculation (validate widget result)
        $server_result = null;
        if ( ! empty( $stage1_data['q1_age'] ) && ! empty( $stage1_data['q9'] ) ) {
            $server_result = HDLV2_Rate_Calculator::calculate_quick( $stage1_data );
            $stage1_data['server_result'] = $server_result;
            $rate = $server_result['rate'];
        }

        // ── CREATE CLIENT RECORD (Correction 1) ──
        // Create or match WP user, create form_progress row, link to practitioner
        $form_token    = null;
        $client_user_id = null;

        if ( $visitor_email ) {
            // Check for existing form_progress for this email + practitioner (dedup)
            global $wpdb;
            $existing = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, token FROM {$wpdb->prefix}hdlv2_form_progress WHERE client_email = %s AND practitioner_user_id = %d LIMIT 1",
                $visitor_email, $practitioner_id
            ) );

            if ( $existing ) {
                // Update existing Stage 1 data
                $form_token = $existing->token;
                $wpdb->update(
                    $wpdb->prefix . 'hdlv2_form_progress',
                    array(
                        'stage1_data'         => wp_json_encode( $stage1_data ),
                        'stage1_completed_at' => current_time( 'mysql' ),
                        'client_name'         => $visitor_name,
                    ),
                    array( 'id' => $existing->id )
                );
            } else {
                // Create or match WP user
                $existing_user_id = email_exists( $visitor_email );
                if ( $existing_user_id ) {
                    $client_user_id = $existing_user_id;
                } else {
                    $username = sanitize_user( strtolower( str_replace( ' ', '', $visitor_name ) ) . '_' . wp_rand( 1000, 9999 ), true );
                    $client_user_id = wp_insert_user( array(
                        'user_login' => $username,
                        'user_email' => $visitor_email,
                        'user_pass'  => wp_generate_password( 24, true, true ),
                        'first_name' => $visitor_name,
                        'role'       => 'um_client',
                    ) );
                    if ( is_wp_error( $client_user_id ) ) {
                        $client_user_id = null;
                    } else {
                        update_user_meta( $client_user_id, 'hdl_source', 'widget' );
                        update_user_meta( $client_user_id, 'hdl_consumer_user', true );
                    }
                }

                // Create form_progress row
                $form_token = bin2hex( random_bytes( 32 ) );
                $wpdb->insert(
                    $wpdb->prefix . 'hdlv2_form_progress',
                    array(
                        'client_user_id'       => $client_user_id,
                        'practitioner_user_id' => $practitioner_id,
                        'client_name'          => $visitor_name,
                        'client_email'         => $visitor_email,
                        'widget_invite_id'     => $invite_id,
                        'token'                => $form_token,
                        'current_stage'        => 2, // Stage 1 already complete from widget
                        'stage1_data'          => wp_json_encode( $stage1_data ),
                        'stage1_completed_at'  => current_time( 'mysql' ),
                    )
                );

                // Create practitioner-client link in V1 table if user was created
                if ( $client_user_id ) {
                    HDLV2_Compatibility::create_practitioner_client_link( $practitioner_id, $client_user_id );
                }
            }
        }

        // Insert into widget_leads for count tracking (belt and braces)
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'hdlv2_widget_leads',
            array(
                'practitioner_user_id' => $practitioner_id,
                'visitor_name'         => $visitor_name,
                'visitor_email'        => $visitor_email,
                'visitor_age'          => $visitor_age,
                'rate_of_ageing'       => $rate,
                'invite_id'            => $invite_id,
            )
        );

        // Fire webhook if configured
        $config = $this->get_config( $practitioner_id );
        if ( $config && ! empty( $config->webhook_url ) ) {
            wp_remote_post( $config->webhook_url, array(
                'body'    => wp_json_encode( $params ),
                'headers' => array( 'Content-Type' => 'application/json' ),
                'timeout' => 5,
            ) );
        }

        // Interpretation text — shared by both client and practitioner emails
        $interp       = 'Faster than average';
        $interp_color = '#FF6F4B';
        $sub_text     = 'Faster';
        $sub_color    = 'rgba(255, 111, 75, 1)';
        if ( $rate && $rate <= 0.95 ) {
            $interp = 'Slower than average'; $interp_color = '#43BF55';
            $sub_text = 'Slower'; $sub_color = 'rgba(67, 191, 85, 1)';
        } elseif ( $rate && $rate <= 1.05 ) {
            $interp = 'Average'; $interp_color = '#41A5EE';
            $sub_text = 'Average'; $sub_color = 'rgba(65, 165, 238, 1)';
        }

        // Email notification if configured
        if ( $config && ! empty( $config->notification_email ) ) {
            $subject = sprintf( 'New Lead: %s', $visitor_name ?: 'Unknown' );

            $n  = esc_html( $visitor_name ?: 'Not provided' );
            $e  = esc_html( $visitor_email ?: 'Not provided' );
            $er = esc_attr( $visitor_email );

            // Build QuickChart gauge URL (matches widget JS gaugeUrl())
            $gauge_url = '';
            if ( $rate ) {
                $clamped = max( 0.8, min( 1.4, round( $rate, 2 ) ) );
                $gauge_cfg = array(
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
                $gauge_url = 'https://quickchart.io/chart?c=' . rawurlencode( wp_json_encode( $gauge_cfg ) ) . '&w=380&h=340&bkg=white';
            }

            $body = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0;padding:0;background:#f4f5f7;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;">'
                . '<div style="max-width:520px;margin:0 auto;padding:24px 16px;">'
                // Header
                . '<div style="text-align:center;padding:20px 0 16px;">'
                . '<span style="font-size:18px;font-weight:700;color:#004F59;letter-spacing:-0.3px;">HealthDataLab</span>'
                . '</div>'
                // Card
                . '<div style="background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,0.08);">'
                // Title bar
                . '<div style="background:#004F59;padding:16px 24px;">'
                . '<h2 style="margin:0;color:#fff;font-size:16px;font-weight:600;">New Lead from Your Widget</h2>'
                . '</div>'
                . '<div style="padding:24px;">'
                // Contact
                . '<div style="margin-bottom:20px;">'
                . '<div style="font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:#888;margin-bottom:8px;font-weight:600;">Contact</div>'
                . '<div style="font-size:15px;font-weight:600;color:#111;">' . $n . '</div>'
                . '<div style="font-size:13px;color:#555;">' . $e . '</div>'
                . '</div>'
                // Gauge chart
                . ( $gauge_url ? '<div style="text-align:center;margin-bottom:16px;">'
                . '<img src="' . esc_url( $gauge_url ) . '" alt="Rate of Ageing Gauge" style="width:100%;max-width:320px;display:block;margin:0 auto;">'
                . '</div>' : '' )
                // Rate of ageing highlight
                . '<div style="background:#f8f9fb;border-radius:10px;padding:16px;margin-bottom:20px;text-align:center;">'
                . '<div style="font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:#888;margin-bottom:6px;font-weight:600;">Rate of Ageing</div>'
                . '<div style="font-size:32px;font-weight:700;color:#004F59;">' . ( $rate ? number_format( $rate, 2 ) : '—' ) . '</div>'
                . '<div style="font-size:13px;font-weight:500;color:' . $interp_color . ';">' . $interp . '</div>'
                . '</div>'
                // Age summary (only data available from Stage 1)
                . ( $visitor_age ? '<table cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:20px;"><tr>'
                . '<td width="50%" style="padding:0 4px 0 0;"><div style="background:#f8f9fb;border:1px solid #eef0f3;border-radius:10px;padding:14px 8px;text-align:center;">'
                . '<div style="font-size:10px;text-transform:uppercase;letter-spacing:0.5px;color:#888;margin-bottom:4px;font-weight:600;">Chrono Age</div>'
                . '<div style="font-size:24px;font-weight:700;color:#004F59;line-height:1.2;">' . $visitor_age . '</div>'
                . '</div></td>'
                . '<td width="50%" style="padding:0 0 0 4px;"><div style="background:#f8f9fb;border:1px solid #eef0f3;border-radius:10px;padding:14px 8px;text-align:center;">'
                . '<div style="font-size:10px;text-transform:uppercase;letter-spacing:0.5px;color:#888;margin-bottom:4px;font-weight:600;">Questions</div>'
                . '<div style="font-size:24px;font-weight:700;color:#004F59;line-height:1.2;">9 of 9</div>'
                . '</div></td>'
                . '</tr></table>' : '' )
                // CTA
                . ( $visitor_email ? '<div style="text-align:center;">'
                . '<a href="mailto:' . $er . '?subject=Your%20Rate%20of%20Ageing%20Results" style="display:inline-block;padding:10px 28px;background:#004F59;color:#fff;border-radius:8px;text-decoration:none;font-size:14px;font-weight:600;">Reply to ' . $n . '</a>'
                . '</div>' : '' )
                . '</div></div>'
                // Footer
                . '<div style="text-align:center;padding:16px;font-size:11px;color:#aaa;">'
                . 'This lead came from your HealthDataLab embedded widget.'
                . '</div>'
                . '</div></body></html>';

            $headers = array( 'Content-Type: text/html; charset=UTF-8' );
            wp_mail( $config->notification_email, $subject, $body, $headers );
        }

        // Send client email with results + continuation link
        if ( $visitor_email && $form_token ) {
            $continue_url = site_url( '/assessment/?token=' . $form_token );
            $gauge_url_email = '';
            if ( $rate ) {
                $clamped_e = max( 0.8, min( 1.4, round( $rate, 2 ) ) );
                $gauge_url_email = 'https://quickchart.io/chart?c=' . rawurlencode( wp_json_encode( array(
                    'type' => 'gauge',
                    'data' => array(
                        'labels'   => array( 'Slower', 'Average', 'Faster' ),
                        'datasets' => array( array(
                            'data'            => array( 0.9, 1.1, 1.4 ),
                            'value'           => $clamped_e,
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
                ) ) ) . '&w=380&h=340&bkg=white';
            }

            $client_html = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0;padding:0;background:#f4f5f7;font-family:-apple-system,sans-serif;">'
                . '<div style="max-width:520px;margin:0 auto;padding:24px 16px;">'
                . '<div style="text-align:center;padding:16px 0;"><span style="font-size:18px;font-weight:700;color:#004F59;">HealthDataLab</span></div>'
                . '<div style="background:#fff;border-radius:12px;padding:24px;box-shadow:0 1px 4px rgba(0,0,0,0.08);">'
                . '<h2 style="margin:0 0 12px;font-size:18px;color:#111;">Your Health Assessment Results</h2>'
                . '<p style="font-size:14px;color:#444;line-height:1.6;margin:0 0 16px;">Hi ' . esc_html( $visitor_name ?: 'there' ) . ', thanks for completing the quick assessment. Here are your results:</p>'
                . ( $gauge_url_email ? '<div style="text-align:center;margin-bottom:16px;"><img src="' . esc_url( $gauge_url_email ) . '" alt="Rate of Ageing" style="width:100%;max-width:320px;display:block;margin:0 auto;"></div>' : '' )
                . '<div style="background:#f8f9fb;border-radius:10px;padding:16px;text-align:center;margin-bottom:16px;">'
                . '<div style="font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:#888;margin-bottom:6px;font-weight:600;">Pace of Ageing</div>'
                . '<div style="font-size:32px;font-weight:700;color:#004F59;">' . ( $rate ? number_format( $rate, 2 ) . '&times;' : '&mdash;' ) . '</div>'
                . '<div style="font-size:13px;font-weight:500;color:' . $interp_color . ';">' . $interp . '</div>'
                . '</div>'
                . '<p style="font-size:12px;color:#888;font-style:italic;margin:0 0 16px;">This is an estimate based on limited data. Your full assessment with your practitioner will be more precise.</p>'
                . '<p style="font-size:14px;color:#444;line-height:1.6;margin:0 0 16px;">Your pace of ageing tells you where you are now. But lasting change comes from understanding <em>why</em> it matters to you personally. The next step explores what you want your future to look like.</p>'
                . '<div style="text-align:center;margin-bottom:16px;">'
                . '<a href="' . esc_url( $continue_url ) . '" style="display:inline-block;padding:14px 32px;background:#004F59;color:#fff;border-radius:48px;text-decoration:none;font-size:15px;font-weight:600;">Continue to Stage 2 &rarr;</a>'
                . '</div>'
                . '<p style="font-size:11px;color:#aaa;text-align:center;">This link is unique to you. No password needed &mdash; just click to continue.</p>'
                // Two Trajectories video
                . '<div style="background:#f0f9fa;border-radius:10px;padding:16px;margin:20px 0 16px;text-align:center;">'
                . '<p style="font-size:13px;color:#444;margin:0 0 10px;line-height:1.5;">Not sure where to start? This short video shows two versions of the same future &mdash; and might help you think about what really matters to you.</p>'
                . '<a href="https://altituding.com/two-trajectories" target="_blank" rel="noopener" style="display:inline-block;padding:10px 24px;background:#2D9596;color:#fff;border-radius:48px;text-decoration:none;font-size:13px;font-weight:600;">Watch the Two Trajectories Video &rarr;</a>'
                . '</div>'
                // Booking encouragement
                . '<div style="border-top:1px solid #eef0f3;padding-top:16px;margin-top:8px;">'
                . '<p style="font-size:13px;color:#444;line-height:1.6;margin:0 0 12px;">To get the full benefit of your results and receive your detailed report, we recommend booking a session with your practitioner.</p>'
                . ( $config && ! empty( $config->cta_link ) ? '<div style="text-align:center;"><a href="' . esc_url( $config->cta_link ) . '" target="_blank" rel="noopener" style="display:inline-block;padding:10px 24px;background:#004F59;color:#fff;border-radius:48px;text-decoration:none;font-size:13px;font-weight:600;">' . esc_html( $config->cta_text ?: 'Book a Session' ) . '</a></div>' : '' )
                . '</div>'
                . '</div>'
                . '<div style="text-align:center;padding:16px;font-size:11px;color:#aaa;">Sent by HealthDataLab on behalf of ' . esc_html( $config ? $config->practitioner_name : '' ) . '</div>'
                . '</div></body></html>';

            wp_mail( $visitor_email, 'Your Health Assessment Results \u2014 HealthDataLab', $client_html, array( 'Content-Type: text/html; charset=UTF-8' ) );
        }

        // Fire Make.com webhook for Stage 1 PDF (non-blocking)
        if ( $visitor_email && $rate ) {
            $practitioner_data = get_userdata( $practitioner_id );
            // Build gauge URL for PDF
            $clamped_rate = max( 0.8, min( 1.4, round( (float) $rate, 2 ) ) );
            if ( $clamped_rate <= 0.9 ) { $s1_sub = 'Slower'; $s1_sub_c = 'rgba(67,191,85,1)'; }
            elseif ( $clamped_rate <= 1.1 ) { $s1_sub = 'Average'; $s1_sub_c = 'rgba(65,165,238,1)'; }
            else { $s1_sub = 'Faster'; $s1_sub_c = 'rgba(255,111,75,1)'; }
            $s1_gauge_cfg = array(
                'type' => 'gauge',
                'data' => array( 'labels' => array('Slower','Average','Faster'), 'datasets' => array( array(
                    'data' => array(0.9,1.1,1.4), 'value' => $clamped_rate, 'minValue' => 0.8, 'maxValue' => 1.4,
                    'backgroundColor' => array('rgba(67,191,85,0.95)','rgba(65,165,238,0.95)','rgba(255,111,75,0.95)'),
                    'borderWidth' => 1, 'borderColor' => 'rgba(255,255,255,0.8)', 'borderRadius' => 5,
                ) ) ),
                'options' => array(
                    'layout' => array( 'padding' => array('top'=>30,'bottom'=>15,'left'=>15,'right'=>15) ),
                    'plugins' => array( 'datalabels' => array('display'=>false) ),
                    'needle' => array('radiusPercentage'=>2.5,'widthPercentage'=>4,'lengthPercentage'=>68,'color'=>'#004F59','shadowColor'=>'rgba(0,79,89,0.4)','shadowBlur'=>8,'shadowOffsetY'=>4,'borderWidth'=>2,'borderColor'=>'rgba(255,255,255,1)'),
                    'valueLabel' => array('display'=>true,'fontSize'=>36,'fontFamily'=>"'Inter',sans-serif",'fontWeight'=>'bold','color'=>'#004F59','backgroundColor'=>'transparent','bottomMarginPercentage'=>-10,'padding'=>8),
                    'centerArea' => array('displayText'=>false,'backgroundColor'=>'transparent'),
                    'arc' => array('borderWidth'=>0,'padding'=>2,'margin'=>3,'roundedCorners'=>true),
                    'subtitle' => array('display'=>true,'text'=>$s1_sub,'color'=>$s1_sub_c,'font'=>array('size'=>20,'weight'=>'bold','family'=>"'Inter',sans-serif"),'padding'=>array('top'=>8)),
                ),
            );
            $s1_gauge_url = 'https://quickchart.io/chart?c=' . rawurlencode( wp_json_encode( $s1_gauge_cfg ) ) . '&w=380&h=340&bkg=white';

            // Practitioner logo from widget config
            $prac_logo = '';
            if ( $config && ! empty( $config->logo_url ) ) {
                $prac_logo = $config->logo_url;
            }

            $pdf_payload = array(
                'client_name'            => $visitor_name,
                'client_email'           => $visitor_email,
                'client_phone'           => $visitor_phone,
                'practitioner_id'        => $practitioner_id,
                'practitioner_name'      => $practitioner_data ? $practitioner_data->display_name : '',
                'practitioner_email'     => $practitioner_data ? $practitioner_data->user_email : '',
                'practitioner_logo_url'  => $prac_logo,
                'rate_of_ageing'         => $rate,
                'gauge_url'              => $s1_gauge_url,
                'report_date'            => current_time( 'Y-m-d' ),
                'stage1_data'            => $stage1_data,
                'form_token'             => $form_token,
                'timestamp'              => current_time( 'c' ),
            );

            $stage1_webhook = defined( 'HDLV2_MAKE_STAGE1_PDF' ) ? HDLV2_MAKE_STAGE1_PDF : '';
            if ( ! $stage1_webhook ) {
                error_log( '[HDLV2] Stage 1 PDF webhook not configured — define HDLV2_MAKE_STAGE1_PDF in wp-config.php' );
            }

            wp_remote_post( $stage1_webhook, array(
                'body'      => wp_json_encode( $pdf_payload ),
                'headers'   => array( 'Content-Type' => 'application/json' ),
                'timeout'   => 5,
                'blocking'  => false,
            ) );
        }

        $response = array( 'success' => true );
        if ( $form_token ) {
            $response['form_token'] = $form_token;
        }
        if ( $rate ) {
            $response['rate'] = $rate;
        }

        return rest_ensure_response( $response );
    }

    // ──────────────────────────────────────────────────────────────
    //  REST: INVITES
    // ──────────────────────────────────────────────────────────────

    /**
     * Create a new invite token for a specific client.
     */
    public function rest_create_invite( $request ) {
        $user_id = get_current_user_id();

        // Rate limit: 10 invites per hour per practitioner
        $transient = 'hdlv2_invite_' . $user_id;
        $count     = (int) get_transient( $transient );
        if ( $count >= 10 ) {
            return new WP_Error( 'rate_limited', 'Too many invites. Please try again later.', array( 'status' => 429 ) );
        }

        $params       = $request->get_json_params();
        $client_name  = sanitize_text_field( $params['client_name'] ?? '' );
        $client_email = sanitize_email( $params['client_email'] ?? '' );
        $expires_days = absint( $params['expires_days'] ?? 30 );

        if ( ! $client_email || ! is_email( $client_email ) ) {
            return new WP_Error( 'invalid_email', 'A valid client email is required.', array( 'status' => 400 ) );
        }

        if ( ! in_array( $expires_days, array( 7, 14, 30 ), true ) ) {
            $expires_days = 30;
        }

        $token      = bin2hex( random_bytes( 32 ) );
        $expires_at = gmdate( 'Y-m-d H:i:s', time() + ( $expires_days * DAY_IN_SECONDS ) );

        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'hdlv2_widget_invites',
            array(
                'practitioner_id' => $user_id,
                'token'           => $token,
                'client_name'     => $client_name,
                'client_email'    => $client_email,
                'status'          => 'pending',
                'expires_at'      => $expires_at,
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%s' )
        );

        set_transient( $transient, $count + 1, HOUR_IN_SECONDS );

        $url = site_url( '/rate-of-ageing-widget/?invite=' . $token );

        return rest_ensure_response( array(
            'success'    => true,
            'token'      => $token,
            'url'        => $url,
            'expires_at' => $expires_at,
        ) );
    }

    /**
     * Verify an invite token (public endpoint, called by widget JS).
     *
     * Rate limited to prevent token enumeration. Failed attempts are logged.
     */
    public function rest_verify_invite( $request ) {
        $token = sanitize_text_field( $request->get_param( 'token' ) ?? '' );

        // Validate token format: exactly 64 hex chars
        if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
            return rest_ensure_response( array( 'valid' => false, 'reason' => 'invalid' ) );
        }

        // Rate limit: 30 verifications per hour per IP
        $ip        = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? 'unknown' );
        $transient = 'hdlv2_verify_' . md5( $ip );
        $count     = (int) get_transient( $transient );
        if ( $count >= 30 ) {
            error_log( sprintf(
                '[HDL V2 SECURITY] Invite verify rate limit hit — IP: %s, attempts: %d',
                $ip,
                $count
            ) );
            return new WP_Error( 'rate_limited', 'Too many requests.', array( 'status' => 429 ) );
        }
        set_transient( $transient, $count + 1, HOUR_IN_SECONDS );

        global $wpdb;
        $invite = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hdlv2_widget_invites WHERE token = %s LIMIT 1",
            $token
        ) );

        if ( ! $invite ) {
            error_log( sprintf(
                '[HDL V2 SECURITY] Invalid invite token attempted — IP: %s, token prefix: %s...',
                $ip,
                substr( $token, 0, 8 )
            ) );
            return rest_ensure_response( array( 'valid' => false, 'reason' => 'invalid' ) );
        }

        if ( $invite->status === 'revoked' ) {
            return rest_ensure_response( array( 'valid' => false, 'reason' => 'invalid' ) );
        }

        if ( $invite->status === 'completed' ) {
            return rest_ensure_response( array( 'valid' => false, 'reason' => 'completed' ) );
        }

        if ( strtotime( $invite->expires_at ) < time() ) {
            // Mark as expired if still pending/opened
            if ( in_array( $invite->status, array( 'pending', 'opened' ), true ) ) {
                $wpdb->update(
                    $wpdb->prefix . 'hdlv2_widget_invites',
                    array( 'status' => 'expired' ),
                    array( 'id' => $invite->id ),
                    array( '%s' ),
                    array( '%d' )
                );
            }
            return rest_ensure_response( array( 'valid' => false, 'reason' => 'expired' ) );
        }

        // Mark as opened on first verification
        if ( $invite->status === 'pending' ) {
            $wpdb->update(
                $wpdb->prefix . 'hdlv2_widget_invites',
                array(
                    'status'    => 'opened',
                    'opened_at' => current_time( 'mysql' ),
                ),
                array( 'id' => $invite->id ),
                array( '%s', '%s' ),
                array( '%d' )
            );
        }

        // Load practitioner widget config
        $config = $this->get_config( $invite->practitioner_id );

        return rest_ensure_response( array(
            'valid'            => true,
            'practitioner_id'  => (int) $invite->practitioner_id,
            'client_name'      => $invite->client_name,
            'client_email'     => $invite->client_email,
            'practitioner_name' => $config ? $config->practitioner_name : '',
            'logo_url'         => $config ? $config->logo_url : '',
            'cta_text'         => $config ? $config->cta_text : 'Book a session',
            'cta_link'         => $config ? $config->cta_link : '',
            'theme_color'      => $config ? $config->theme_color : '#3d8da0',
            'api_url'          => rest_url( 'hdl-v2/v1/widget/lead' ),
        ) );
    }

    /**
     * List all invites for the current practitioner.
     */
    public function rest_list_invites( $request ) {
        global $wpdb;
        $user_id = get_current_user_id();

        // First, expire any overdue invites
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}hdlv2_widget_invites
             SET status = 'expired'
             WHERE practitioner_id = %d
               AND status IN ('pending', 'opened')
               AND expires_at < %s",
            $user_id,
            current_time( 'mysql' )
        ) );

        $invites = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, client_name, client_email, status, token, expires_at, created_at, opened_at, completed_at
             FROM {$wpdb->prefix}hdlv2_widget_invites
             WHERE practitioner_id = %d
             ORDER BY created_at DESC
             LIMIT 100",
            $user_id
        ) );

        $widget_page = site_url( '/rate-of-ageing-widget/' );
        foreach ( $invites as &$inv ) {
            $inv->url = $widget_page . '?invite=' . $inv->token;
            unset( $inv->token ); // Don't expose raw tokens in list responses
        }

        return rest_ensure_response( $invites );
    }

    // ──────────────────────────────────────────────────────────────
    //  AJAX HANDLERS
    // ──────────────────────────────────────────────────────────────

    public function ajax_save_config() {
        check_ajax_referer( 'hdlv2_widget_config', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! HDLV2_Compatibility::is_practitioner( $user_id ) ) {
            wp_send_json_error( 'Not authorized' );
        }

        $data = $this->sanitize_config( $_POST );

        if ( empty( $data['cta_link'] ) ) {
            wp_send_json_error( 'Booking Link is required' );
        }

        $result = $this->save_config( $user_id, $data );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( array(
            'embed_code' => HDLV2_Widget_Renderer::generate_embed_code( $user_id, $data ),
        ) );
    }

    public function ajax_get_config() {
        check_ajax_referer( 'hdlv2_widget_config', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! HDLV2_Compatibility::is_practitioner( $user_id ) ) {
            wp_send_json_error( 'Not authorized' );
        }

        $config = $this->get_config( $user_id );
        wp_send_json_success( $config ?: array() );
    }

    public function ajax_upload_logo() {
        check_ajax_referer( 'hdlv2_widget_config', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! HDLV2_Compatibility::is_practitioner( $user_id ) ) {
            wp_send_json_error( 'Not authorised' );
        }

        if ( empty( $_FILES['logo'] ) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK ) {
            wp_send_json_error( 'No file uploaded or upload error' );
        }

        $file = $_FILES['logo'];

        // Validate type
        $allowed = array( 'image/jpeg', 'image/png', 'image/gif', 'image/svg+xml', 'image/webp' );
        $finfo   = finfo_open( FILEINFO_MIME_TYPE );
        $mime    = finfo_file( $finfo, $file['tmp_name'] );
        finfo_close( $finfo );

        if ( ! in_array( $mime, $allowed, true ) ) {
            wp_send_json_error( 'Only image files are allowed (JPG, PNG, GIF, SVG, WebP)' );
        }

        // Validate size (2MB max)
        if ( $file['size'] > 2 * 1024 * 1024 ) {
            wp_send_json_error( 'Logo must be under 2MB' );
        }

        // Upload to isolated directory (NOT wp media library)
        $upload_dir = wp_upload_dir();
        $logo_dir   = $upload_dir['basedir'] . '/hdlv2-logos/' . $user_id;
        wp_mkdir_p( $logo_dir );

        $filename = sanitize_file_name( $file['name'] );
        // Prevent overwrites by prefixing with timestamp
        $filename = time() . '-' . $filename;
        $dest     = $logo_dir . '/' . $filename;

        if ( ! move_uploaded_file( $file['tmp_name'], $dest ) ) {
            wp_send_json_error( 'Failed to save file' );
        }

        $url = $upload_dir['baseurl'] . '/hdlv2-logos/' . $user_id . '/' . $filename;

        wp_send_json_success( array( 'url' => $url ) );
    }

    public function ajax_create_invite() {
        check_ajax_referer( 'hdlv2_widget_config', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! HDLV2_Compatibility::is_practitioner( $user_id ) ) {
            wp_send_json_error( 'Not authorized' );
        }

        // Rate limit: 10 invites per hour per practitioner
        $transient = 'hdlv2_invite_' . $user_id;
        $count     = (int) get_transient( $transient );
        if ( $count >= 10 ) {
            wp_send_json_error( 'Too many invites this hour. Please try again later.' );
        }

        $client_name  = sanitize_text_field( $_POST['client_name'] ?? '' );
        $client_email = sanitize_email( $_POST['client_email'] ?? '' );
        $expires_days = absint( $_POST['expires_days'] ?? 30 );

        if ( ! $client_email || ! is_email( $client_email ) ) {
            wp_send_json_error( 'A valid client email is required.' );
        }

        if ( ! in_array( $expires_days, array( 7, 14, 30 ), true ) ) {
            $expires_days = 30;
        }

        $token      = bin2hex( random_bytes( 32 ) );
        $expires_at = gmdate( 'Y-m-d H:i:s', time() + ( $expires_days * DAY_IN_SECONDS ) );

        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'hdlv2_widget_invites',
            array(
                'practitioner_id' => $user_id,
                'token'           => $token,
                'client_name'     => $client_name,
                'client_email'    => $client_email,
                'status'          => 'pending',
                'expires_at'      => $expires_at,
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%s' )
        );

        set_transient( $transient, $count + 1, HOUR_IN_SECONDS );

        $url = site_url( '/rate-of-ageing-widget/?invite=' . $token );

        // Send magic link email to client
        $practitioner = get_userdata( $user_id );
        $practitioner_name  = $practitioner->display_name ?: $practitioner->user_login;
        $practitioner_email = $practitioner->user_email;

        $email_sent = $this->send_assessment_invite_email(
            $client_email,
            $client_name,
            $practitioner_name,
            $practitioner_email,
            $url,
            $expires_at
        );

        wp_send_json_success( array(
            'url'        => $url,
            'expires_at' => $expires_at,
            'email_sent' => $email_sent,
        ) );
    }

    public function ajax_get_invites() {
        check_ajax_referer( 'hdlv2_widget_config', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! HDLV2_Compatibility::is_practitioner( $user_id ) ) {
            wp_send_json_error( 'Not authorized' );
        }

        global $wpdb;

        // Expire overdue invites
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}hdlv2_widget_invites
             SET status = 'expired'
             WHERE practitioner_id = %d
               AND status IN ('pending', 'opened')
               AND expires_at < %s",
            $user_id,
            current_time( 'mysql' )
        ) );

        $invites = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, client_name, client_email, status, token, expires_at, created_at, opened_at, completed_at
             FROM {$wpdb->prefix}hdlv2_widget_invites
             WHERE practitioner_id = %d
             ORDER BY created_at DESC
             LIMIT 100",
            $user_id
        ) );

        $widget_page = site_url( '/rate-of-ageing-widget/' );
        foreach ( $invites as &$inv ) {
            $inv->url = $widget_page . '?invite=' . $inv->token;
            unset( $inv->token );
        }

        wp_send_json_success( $invites );
    }

    // ──────────────────────────────────────────────────────────────
    //  INVITE EMAIL
    // ──────────────────────────────────────────────────────────────

    /**
     * Send assessment invite email to client with magic link.
     *
     * Reuses V1's HDL_Email_Template_Components for consistent branding.
     * Falls back to simple wp_mail() if V1 email classes aren't loaded.
     *
     * @param string $client_email     Client's email address.
     * @param string $client_name      Client's name (may be empty).
     * @param string $practitioner_name Practitioner's display name.
     * @param string $practitioner_email Practitioner's email.
     * @param string $invite_url       The magic link URL.
     * @param string $expires_at       Expiration datetime (GMT).
     * @return bool Whether the email was sent successfully.
     */
    private function send_assessment_invite_email( $client_email, $client_name, $practitioner_name, $practitioner_email, $invite_url, $expires_at ) {
        $greeting    = $client_name ? $client_name : 'there';
        $subject     = "{$practitioner_name} wants to help you understand your health better";
        $expires_text = '';
        if ( $expires_at ) {
            $expires_text = date( 'F j, Y', strtotime( $expires_at ) );
        }

        // Try V1 branded email components
        $v1_templates = defined( 'HDL_PLUGIN_DIR' ) ? HDL_PLUGIN_DIR . 'includes/email-templates/class-email-template-components.php' : '';
        $v1_svg       = defined( 'HDL_PLUGIN_DIR' ) ? HDL_PLUGIN_DIR . 'includes/email-templates/inline-svg-library.php' : '';
        $v1_service   = defined( 'HDL_PLUGIN_DIR' ) ? HDL_PLUGIN_DIR . 'includes/utils/class-email-service.php' : '';

        if ( $v1_templates && file_exists( $v1_templates ) && file_exists( $v1_svg ) ) {
            require_once $v1_templates;
            require_once $v1_svg;

            $svg    = HDL_Inline_SVG::get_illustration( 'clipboard' );
            $header = HDL_Email_Template_Components::render_header( 'You\'re Invited!', $svg, '#2D9596' );

            $body_content = "
                <p style='font-size: 16px; line-height: 1.6; color: #1d1d1f; margin: 0 0 20px 0; font-family: -apple-system, BlinkMacSystemFont, \"SF Pro Text\", \"Helvetica Neue\", sans-serif;'>
                    Hey {$greeting},
                </p>
                <p style='font-size: 16px; line-height: 1.6; color: #1d1d1f; margin: 0 0 20px 0; font-family: -apple-system, BlinkMacSystemFont, \"SF Pro Text\", \"Helvetica Neue\", sans-serif;'>
                    Your practitioner <strong>{$practitioner_name}</strong> has invited you to complete a health assessment.
                    It's quick (about 15 minutes) and gives you some genuinely useful insights about where you're at.
                </p>";

            $body_content .= HDL_Email_Template_Components::render_cta_button( $invite_url, 'Please click here to go to the form', 'primary' );

            if ( $expires_text ) {
                $body_content .= "
                <p style='font-size: 14px; line-height: 1.6; color: #86868b; margin: 25px 0 0 0; font-family: -apple-system, BlinkMacSystemFont, \"SF Pro Text\", \"Helvetica Neue\", sans-serif;'>
                    <strong>Quick heads up:</strong> This link expires on <strong>{$expires_text}</strong>.
                    Don't stress – you'll get reminders if you need them.
                </p>";
            }

            $body_content .= "
                <p style='font-size: 14px; line-height: 1.6; color: #86868b; margin: 15px 0 0 0; font-family: -apple-system, BlinkMacSystemFont, \"SF Pro Text\", \"Helvetica Neue\", sans-serif;'>
                    If you have any questions or don't wish to receive these emails, please make contact with
                    <a href='mailto:{$practitioner_email}' style='color: #2D9596; text-decoration: none;'>{$practitioner_name}</a>.
                </p>";

            $body   = HDL_Email_Template_Components::render_body( $body_content );
            $footer = HDL_Email_Template_Components::render_footer( $practitioner_name, $practitioner_email );

            $message = HDL_Email_Template_Components::render_full_email( array(
                'header' => $header,
                'body'   => $body,
                'footer' => $footer,
            ) );

            $headers = array(
                'Content-Type: text/html; charset=UTF-8',
                'From: HealthDataLab <noreply@healthdatalab.net>',
                "Reply-To: {$practitioner_email}",
            );

            // Use HDL_Email_Service if available, otherwise wp_mail
            if ( $v1_service && file_exists( $v1_service ) && class_exists( 'HDL_Email_Service' ) ) {
                return HDL_Email_Service::send_with_retry( $client_email, $subject, $message, $headers, 3, 'V2 Assessment Invite' );
            }

            return wp_mail( $client_email, $subject, $message, $headers );
        }

        // Fallback: simple HTML email if V1 templates not available
        $message = "<html><body style='font-family: -apple-system, BlinkMacSystemFont, sans-serif;'>"
            . "<p>Hey {$greeting},</p>"
            . "<p>Your practitioner <strong>{$practitioner_name}</strong> has invited you to complete a health assessment.</p>"
            . "<p><a href='" . esc_url( $invite_url ) . "' style='display:inline-block;padding:12px 24px;background:#2D9596;color:#fff;text-decoration:none;border-radius:6px;'>Click here to go to the form</a></p>"
            . ( $expires_text ? "<p style='color:#888;font-size:14px;'>This link expires on {$expires_text}.</p>" : '' )
            . "<p style='color:#888;font-size:14px;'>Questions? Contact <a href='mailto:{$practitioner_email}'>{$practitioner_name}</a>.</p>"
            . "</body></html>";

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: HealthDataLab <noreply@healthdatalab.net>',
            "Reply-To: {$practitioner_email}",
        );

        return wp_mail( $client_email, $subject, $message, $headers );
    }

    // ──────────────────────────────────────────────────────────────
    //  INVITE HELPERS
    // ──────────────────────────────────────────────────────────────

    /**
     * Look up an invite by token and verify it's still usable.
     *
     * @param string $token The 64-char hex token.
     * @return object|null The invite row if valid, null otherwise.
     */
    private function get_valid_invite( $token ) {
        if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
            return null;
        }

        global $wpdb;
        $invite = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hdlv2_widget_invites WHERE token = %s LIMIT 1",
            $token
        ) );

        if ( ! $invite ) {
            return null;
        }

        if ( $invite->status === 'completed' || $invite->status === 'revoked' ) {
            return null;
        }

        if ( strtotime( $invite->expires_at ) < time() ) {
            return null;
        }

        return $invite;
    }

    /**
     * Mark an invite as completed.
     */
    private function complete_invite( $invite_id ) {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'hdlv2_widget_invites',
            array(
                'status'       => 'completed',
                'completed_at' => current_time( 'mysql' ),
            ),
            array( 'id' => $invite_id ),
            array( '%s', '%s' ),
            array( '%d' )
        );
    }

    // ──────────────────────────────────────────────────────────────
    //  CONFIG DB HELPERS
    // ──────────────────────────────────────────────────────────────

    public function get_config( $user_id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hdlv2_widget_config WHERE practitioner_user_id = %d",
            $user_id
        ) );
    }

    private function save_config( $user_id, $data ) {
        global $wpdb;
        $table    = $wpdb->prefix . 'hdlv2_widget_config';
        $existing = $this->get_config( $user_id );

        $row = array(
            'practitioner_user_id' => $user_id,
            'practitioner_name'    => $data['practitioner_name'] ?? '',
            'logo_url'             => $data['logo_url'] ?? '',
            'cta_text'             => $data['cta_text'] ?? 'Book a session',
            'cta_link'             => $data['cta_link'] ?? '',
            'webhook_url'          => $data['webhook_url'] ?? '',
            'notification_email'   => $data['notification_email'] ?? '',
            'theme_color'          => $data['theme_color'] ?? '#3d8da0',
        );

        if ( $existing ) {
            $wpdb->update( $table, $row, array( 'practitioner_user_id' => $user_id ) );
        } else {
            $wpdb->insert( $table, $row );
        }

        return true;
    }

    private function sanitize_config( $data ) {
        return array(
            'practitioner_name'  => sanitize_text_field( $data['practitioner_name'] ?? '' ),
            'logo_url'           => esc_url_raw( $data['logo_url'] ?? '' ),
            'cta_text'           => sanitize_text_field( $data['cta_text'] ?? 'Book a session' ),
            'cta_link'           => esc_url_raw( $data['cta_link'] ?? '' ),
            'webhook_url'        => esc_url_raw( $data['webhook_url'] ?? '' ),
            'notification_email' => sanitize_email( $data['notification_email'] ?? '' ),
            'theme_color'        => sanitize_hex_color( $data['theme_color'] ?? '#3d8da0' ) ?: '#3d8da0',
        );
    }

    // ──────────────────────────────────────────────────────────────
    //  V2 CLIENT LIST + WHY GATE RELEASE
    // ──────────────────────────────────────────────────────────────

    public function ajax_get_v2_clients() {
        check_ajax_referer( 'hdlv2_widget_config', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! HDLV2_Compatibility::is_practitioner( $user_id ) ) {
            wp_send_json_error( 'Not authorized' );
        }

        global $wpdb;
        $clients = $wpdb->get_results( $wpdb->prepare(
            "SELECT fp.id, fp.client_name, fp.client_email, fp.current_stage,
                    fp.stage1_completed_at, fp.stage2_completed_at, fp.stage3_completed_at,
                    wp.id AS why_id, wp.released, wp.released_at, LEFT(wp.distilled_why, 120) AS why_preview
             FROM {$wpdb->prefix}hdlv2_form_progress fp
             LEFT JOIN {$wpdb->prefix}hdlv2_why_profiles wp ON wp.form_progress_id = fp.id
             WHERE fp.practitioner_user_id = %d
             ORDER BY fp.id DESC
             LIMIT 50",
            $user_id
        ) );

        wp_send_json_success( $clients );
    }

    public function ajax_release_why() {
        check_ajax_referer( 'hdlv2_widget_config', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! HDLV2_Compatibility::is_practitioner( $user_id ) ) {
            wp_send_json_error( 'Not authorized' );
        }

        $progress_id = absint( $_POST['progress_id'] ?? 0 );
        if ( ! $progress_id ) {
            wp_send_json_error( 'Missing progress ID' );
        }

        global $wpdb;

        // Verify practitioner owns this client
        $progress = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hdlv2_form_progress WHERE id = %d AND practitioner_user_id = %d",
            $progress_id, $user_id
        ) );
        if ( ! $progress ) {
            wp_send_json_error( 'Client not found' );
        }

        // Find WHY profile
        $why = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, released FROM {$wpdb->prefix}hdlv2_why_profiles WHERE form_progress_id = %d LIMIT 1",
            $progress_id
        ) );
        if ( ! $why ) {
            wp_send_json_error( 'Stage 2 not completed yet' );
        }
        if ( $why->released ) {
            wp_send_json_success( array( 'already_released' => true ) );
            return;
        }

        // Release the gate
        $wpdb->update(
            $wpdb->prefix . 'hdlv2_why_profiles',
            array( 'released' => 1, 'released_at' => current_time( 'mysql' ) ),
            array( 'id' => $why->id )
        );

        // Send Stage 3 invitation email
        if ( ! empty( $progress->client_email ) && class_exists( 'HDLV2_Email_Templates' ) ) {
            $form_url = site_url( '/assessment/?token=' . $progress->token );
            HDLV2_Email_Templates::why_gate_released( array(
                'client_name'  => $progress->client_name,
                'client_email' => $progress->client_email,
                'form_url'     => $form_url,
            ) );
        }

        wp_send_json_success( array( 'released' => true ) );
    }
}

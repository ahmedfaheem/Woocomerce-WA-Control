<?php
/**
 * Settings Page — WA Meta Control
 *
 * Registers a WP Admin settings page under "WooCommerce → WA Meta Control".
 * All plugin configuration lives here — no more wp-config.php constants.
 */

defined( 'ABSPATH' ) || exit;

class WAMC_Settings {

    public static function init(): void {
        add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
        add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
        add_action( 'wp_ajax_wamc_test_connection', [ __CLASS__, 'ajax_test_connection' ] );
        add_action( 'wp_ajax_wamc_register_webhook', [ __CLASS__, 'ajax_register_webhook' ] );
        add_action( 'wp_ajax_wamc_copy_webhook',     [ __CLASS__, 'ajax_noop' ] ); // handled in JS
    }

    // ── Admin Menu ────────────────────────────────────────────────────────────

    public static function add_menu(): void {
        add_submenu_page(
            'woocommerce',
            __( 'WA Meta Control', 'wa-meta-control' ),
            __( 'WA Meta Control', 'wa-meta-control' ),
            'manage_woocommerce',
            'wamc-settings',
            [ __CLASS__, 'render_page' ]
        );
    }

    // ── Register Settings ─────────────────────────────────────────────────────

    public static function register_settings(): void {
        register_setting(
            'wamc_group',
            WAMC_OPTION_KEY,
            [ 'sanitize_callback' => [ __CLASS__, 'sanitize' ] ]
        );
    }

    public static function sanitize( array $input ): array {
        $clean = [];

        // Text / API key
        $clean['api_key']            = sanitize_text_field( $input['api_key'] ?? '' );
        $clean['phone_number_id']    = preg_replace( '/\D/', '', $input['phone_number_id'] ?? '' );
        $clean['waba_id']            = preg_replace( '/\D/', '', $input['waba_id'] ?? '' );
        $clean['app_id']             = preg_replace( '/\D/', '', $input['app_id'] ?? '' );
        $clean['app_secret']         = sanitize_text_field( $input['app_secret'] ?? '' );
        $clean['verify_token']       = sanitize_text_field( $input['verify_token'] ?? '' ) ?: wp_generate_password( 24, false );
        $clean['api_version']        = preg_match( '/^v\d+\.\d+$/', $input['api_version'] ?? '' ) ? $input['api_version'] : 'v21.0';
        $clean['default_country_code'] = preg_replace( '/\D/', '', $input['default_country_code'] ?? '20' ) ?: '20';
        $clean['template_name']      = sanitize_key( $input['template_name'] ?? '' );
        $clean['template_lang']      = sanitize_text_field( $input['template_lang'] ?? 'ar' );

        // Confirmation — dual: plain text (window open) + template with buttons (window closed)
        $clean['confirm_message']       = sanitize_textarea_field( $input['confirm_message'] ?? '' );
        $clean['confirm_btn_label']     = sanitize_text_field( $input['confirm_btn_label'] ?? 'تأكيد الطلب' );
        $clean['cancel_btn_label']      = sanitize_text_field( $input['cancel_btn_label']  ?? 'إلغاء الطلب' );
        $clean['confirm_template']      = sanitize_key( $input['confirm_template'] ?? '' );
        $clean['confirm_template_lang'] = sanitize_text_field( $input['confirm_template_lang'] ?? 'ar' );
        $clean['confirm_vars']          = sanitize_text_field( $input['confirm_vars'] ?? WAMC_Order_Notify::DEFAULT_VARS );
        $clean['confirm_status']        = sanitize_text_field( $input['confirm_status'] ?? 'processing' );
        $clean['cancel_status']         = sanitize_text_field( $input['cancel_status'] ?? 'cancelled' );

        // Order created — dual: plain text + template
        $clean['created_enabled']       = ! empty( $input['created_enabled'] ) ? '1' : '0';
        $clean['confirm_combine']       = ! empty( $input['confirm_combine'] ) ? '1' : '0';
        $clean['created_message']       = sanitize_textarea_field( $input['created_message'] ?? '' );
        $clean['created_template']      = sanitize_key( $input['created_template'] ?? '' );
        $clean['created_lang']          = sanitize_text_field( $input['created_lang'] ?? 'ar' );
        $clean['created_vars']          = sanitize_text_field( $input['created_vars'] ?? WAMC_Order_Notify::DEFAULT_VARS );
        $clean['created_notify_admin']  = '0';
        $clean['created_admin_message'] = sanitize_textarea_field( $input['created_admin_message'] ?? '' );

        // Status changes — dual: plain text + template per status
        $clean['status_rules'] = [];
        foreach ( (array) ( $input['status_rules'] ?? [] ) as $status => $r ) {
            $status = sanitize_key( $status );
            $clean['status_rules'][ $status ] = [
                'enabled'       => ! empty( $r['enabled'] ) ? '1' : '0',
                'message'       => sanitize_textarea_field( $r['message'] ?? '' ),
                'template'      => sanitize_key( $r['template'] ?? '' ),
                'lang'          => sanitize_text_field( $r['lang'] ?? 'ar' ),
                'vars'          => sanitize_text_field( $r['vars'] ?? WAMC_Order_Notify::DEFAULT_VARS ),
                'admin'         => ! empty( $r['admin'] ) ? '1' : '0',
                'admin_message' => sanitize_textarea_field( $r['admin_message'] ?? '' ),
            ];
        }
        // Admin notification — mode: 'phone' or 'group'
        $clean['admin_notify_mode']     = in_array( $input['admin_notify_mode'] ?? 'phone', [ 'phone', 'group' ] )
                                           ? $input['admin_notify_mode']
                                           : 'phone';
        $clean['admin_phones']          = sanitize_textarea_field( $input['admin_phones'] ?? '' );
        $clean['admin_group_id']        = sanitize_text_field( $input['admin_group_id'] ?? '' );
        $clean['admin_template']        = sanitize_key( $input['admin_template'] ?? '' );
        $clean['admin_template_lang']   = sanitize_text_field( $input['admin_template_lang'] ?? 'ar' );
        $clean['admin_vars']            = sanitize_text_field( $input['admin_vars'] ?? WAMC_Order_Notify::DEFAULT_VARS );

        // QP Notify — mode: 'phone' or 'group'
        $clean['qp_notify_mode']        = in_array( $input['qp_notify_mode'] ?? 'phone', [ 'phone', 'group' ] )
                                           ? $input['qp_notify_mode']
                                           : 'phone';
        $clean['qp_phones']             = sanitize_textarea_field( $input['qp_phones'] ?? '' );
        $clean['qp_group_id']           = sanitize_text_field( $input['qp_group_id'] ?? '' );
        $clean['qp_template']           = sanitize_key( $input['qp_template'] ?? '' );
        $clean['qp_template_lang']      = sanitize_text_field( $input['qp_template_lang'] ?? 'ar' );
        $clean['qp_template_vars']      = sanitize_text_field( $input['qp_template_vars'] ?? '' );

        // Feature toggles (checkboxes — missing key means unchecked)
        $clean['enable_confirm']     = ! empty( $input['enable_confirm'] )    ? '1' : '0';
        $clean['enable_crm']         = ! empty( $input['enable_crm'] )        ? '1' : '0';
        $clean['enable_qp_notify']   = ! empty( $input['enable_qp_notify'] )  ? '1' : '0';
        $clean['enable_bulk']        = ! empty( $input['enable_bulk'] )       ? '1' : '0';
        $clean['enable_order_notify'] = ! empty( $input['enable_order_notify'] ) ? '1' : '0';

        // ── Mandatory Dual Configuration Validation (Plain Text + Approved Template)
        if ( $clean['enable_confirm'] === '1' ) {
            if ( empty( $clean['confirm_message'] ) || empty( $clean['confirm_template'] ) ) {
                $clean['enable_confirm'] = '0';
                add_settings_error(
                    WAMC_OPTION_KEY,
                    'confirm_incomplete',
                    __( 'Order Confirmation requires both Plain Text Message and Template Name to be configured before it can be enabled.', 'wa-meta-control' ),
                    'error'
                );
            }
        }



        foreach ( $clean['status_rules'] as $st_key => $st_val ) {
            if ( ! empty( $st_val['enabled'] ) ) {
                if ( empty( $st_val['message'] ) || empty( $st_val['template'] ) ) {
                    $clean['status_rules'][ $st_key ]['enabled'] = '0';
                    add_settings_error(
                        WAMC_OPTION_KEY,
                        'status_' . $st_key . '_incomplete',
                        sprintf(
                            __( 'Status notification for "%s" was disabled: Both Plain Text Message and Template Name are required.', 'wa-meta-control' ),
                            $st_key
                        ),
                        'error'
                    );
                }
            }
        }

        // ── Anti-Ban Rules
        $clean['enable_daily_limit'] = ! empty( $input['enable_daily_limit'] ) ? '1' : '0';
        $clean['daily_limit']        = max( 1, intval( $input['daily_limit'] ?? 150 ) );

        $clean['delay_min']          = max( 0, intval( $input['delay_min'] ?? 15 ) );
        $clean['delay_max']          = max( $clean['delay_min'], intval( $input['delay_max'] ?? 45 ) );

        $clean['enable_cooldown']    = ! empty( $input['enable_cooldown'] ) ? '1' : '0';
        $clean['cooldown_hours']     = max( 1, intval( $input['cooldown_hours'] ?? 24 ) );

        $clean['enable_blacklist']   = ! empty( $input['enable_blacklist'] ) ? '1' : '0';

        return $clean;
    }

    // ── AJAX: test connection ──────────────────────────────────────────────────

    public static function ajax_test_connection(): void {
        check_ajax_referer( 'wamc_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ] );
        }

        $result = wamc_api()->get_state();

        if ( isset( $result['success'] ) && $result['success'] === true ) {
            wp_send_json_success( [ 'message' => 'Connected ✓', 'data' => $result ] );
        } elseif ( isset( $result['error'] ) ) {
            wp_send_json_error( [ 'message' => $result['error'] ] );
        } else {
            // API returns data even on success without 'success' key sometimes
            wp_send_json_success( [ 'message' => 'Response received', 'data' => $result ] );
        }
    }

    // ── AJAX: register webhook at WA Meta Control ─────────────────────────────────

    public static function ajax_register_webhook(): void {
        check_ajax_referer( 'wamc_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ] );
        }

        $slug = get_option( 'wamc_webhook_slug', '' );
        $webhook_url = rest_url( 'wamc/v1/webhook/' . $slug );
        $result = wamc_api()->set_webhook( $webhook_url, (string) wamc_setting( 'app_id' ), (string) wamc_setting( 'app_secret' ), (string) wamc_setting( 'verify_token' ) );

        if ( isset( $result['error'] ) ) {
            wp_send_json_error( [ 'message' => $result['error'] ] );
        } else {
            wp_send_json_success( [ 'message' => 'Webhook registered at Meta ✓', 'data' => $result ] );
        }
    }

    public static function ajax_noop(): void {
        wp_send_json_success();
    }

    // ── Render Settings Page ──────────────────────────────────────────────────

    public static function render_page(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $slug        = get_option( 'wamc_webhook_slug', '' );
        $webhook_url = rest_url( 'wamc/v1/webhook/' . $slug );
        $nonce       = wp_create_nonce( 'wamc_nonce' );

        // Get WooCommerce order statuses for dropdowns
        $wc_statuses = [];
        if ( function_exists( 'wc_get_order_statuses' ) ) {
            foreach ( wc_get_order_statuses() as $status_key => $label ) {
                $wc_statuses[ str_replace( 'wc-', '', $status_key ) ] = $label;
            }
        }

        ?>
        <div class="wrap wamc-settings-wrap">

            <h1 class="wamc-page-title">
                <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 448 512" style="vertical-align:middle;margin-left:8px">
                    <path fill="#25D366" d="M380.9 97.1C339 55.1 283.2 32 223.9 32c-122.4 0-222 99.6-222 222 0 39.1 10.2 77.3 29.6 111L0 480l117.7-30.9c32.4 17.7 68.9 27 106.1 27h.1c122.3 0 224.1-99.6 224.1-222 0-59.3-25.2-115-67.1-157zm-157 341.6c-33.2 0-65.7-8.9-94-25.7l-6.7-4-69.8 18.3L72 359.2l-4.4-7c-18.5-29.4-28.2-63.3-28.2-98.2 0-101.7 82.8-184.5 184.6-184.5 49.3 0 95.6 19.2 130.4 54.1 34.8 34.9 56.2 81.2 56.1 130.5 0 101.8-84.9 184.6-186.6 184.6zm101.2-138.2c-5.5-2.8-32.8-16.2-37.9-18-5.1-1.9-8.8-2.8-12.5 2.8-3.7 5.6-14.3 18-17.6 21.8-3.2 3.7-6.5 4.2-12 1.4-32.6-16.3-54-29.1-75.5-66-5.7-9.8 5.7-9.1 16.3-30.3 1.8-3.7 .9-6.9-.5-9.7-1.4-2.8-12.5-30.1-17.1-41.2-4.5-10.8-9.1-9.3-12.5-9.5-3.2-.2-6.9-.2-10.6-.2-3.7 0-9.7 1.4-14.8 6.9-5.1 5.6-19.4 19-19.4 46.3 0 27.3 19.9 53.7 22.6 57.4 2.8 3.7 39.1 59.7 94.8 83.8 35.2 15.2 49 16.5 66.6 13.9 10.7-1.6 32.8-13.4 37.4-26.4 4.6-13 4.6-24.1 3.2-26.4-1.3-2.5-5-3.9-10.5-6.6z"/>
                </svg>
                <?php esc_html_e( 'WA Meta Control', 'wa-meta-control' ); ?>
            </h1>

            <?php settings_errors( WAMC_OPTION_KEY ); ?>

            <form method="post" action="options.php">
                <?php settings_fields( 'wamc_group' ); ?>

                <div class="wamc-sections">

                    <!-- ═══ API CONNECTION ══════════════════════════════════════ -->
                    <div class="wamc-card">
                        <h2 class="wamc-card-title">🔑 <?php esc_html_e( 'API Connection', 'wa-meta-control' ); ?></h2>

                        <table class="form-table wamc-table">
                            <tr>
                                <th><?php esc_html_e( 'Access Token (System User)', 'wa-meta-control' ); ?></th>
                                <td>
                                    <input type="password" id="wamc_api_key"
                                           name="<?php echo WAMC_OPTION_KEY; ?>[api_key]"
                                           value="<?php echo esc_attr( wamc_setting( 'api_key' ) ); ?>"
                                           class="regular-text" autocomplete="off" />
                                    <button type="button" id="wamc-toggle-key" class="button" style="margin-right:6px">👁</button>
                                    <button type="button" id="wamc-test-connection" class="button button-secondary"
                                            data-nonce="<?php echo $nonce; ?>">
                                        <?php esc_html_e( 'Test Connection', 'wa-meta-control' ); ?>
                                    </button>
                                    <span id="wamc-connection-result" style="margin-right:10px;font-weight:bold"></span>
                                    <p class="description">
                                        <?php esc_html_e( 'Permanent System User access token from Meta Business Settings.', 'wa-meta-control' ); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Webhook URL', 'wa-meta-control' ); ?></th>
                                <td>
                                    <div class="wamc-webhook-row">
                                        <input type="text" id="wamc-webhook-url" value="<?php echo esc_url( $webhook_url ); ?>"
                                               class="regular-text" readonly />
                                        <button type="button" id="wamc-copy-webhook" class="button" data-url="<?php echo esc_url( $webhook_url ); ?>">
                                            📋 <?php esc_html_e( 'Copy', 'wa-meta-control' ); ?>
                                        </button>
                                        <button type="button" id="wamc-register-webhook" class="button button-primary"
                                                data-nonce="<?php echo $nonce; ?>">
                                            🔗 <?php esc_html_e( 'Register at Meta', 'wa-meta-control' ); ?>
                                        </button>
                                        <span id="wamc-webhook-result" style="margin-right:10px;font-weight:bold"></span>
                                    </div>
                                    <p class="description">
                                        <?php esc_html_e( 'This URL is auto-generated. Copy it with the Verify Token into Meta App Dashboard → WhatsApp → Configuration, or click Register to do it automatically (requires App ID and App Secret).', 'wa-meta-control' ); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Phone Number ID', 'wa-meta-control' ); ?></th>
                                <td>
                                    <input type="text" name="<?php echo WAMC_OPTION_KEY; ?>[phone_number_id]" value="<?php echo esc_attr( wamc_setting( 'phone_number_id') ); ?>" class="regular-text" autocomplete="off" />
                                    <p class="description"><?php esc_html_e( 'From Meta App Dashboard → WhatsApp → API Setup.', 'wa-meta-control' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'WhatsApp Business Account ID', 'wa-meta-control' ); ?></th>
                                <td>
                                    <input type="text" name="<?php echo WAMC_OPTION_KEY; ?>[waba_id]" value="<?php echo esc_attr( wamc_setting( 'waba_id') ); ?>" class="regular-text" autocomplete="off" />
                                    <p class="description"><?php esc_html_e( 'Used when subscribing the app to your WABA.', 'wa-meta-control' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Meta App ID', 'wa-meta-control' ); ?></th>
                                <td>
                                    <input type="text" name="<?php echo WAMC_OPTION_KEY; ?>[app_id]" value="<?php echo esc_attr( wamc_setting( 'app_id') ); ?>" class="regular-text" autocomplete="off" />
                                    <p class="description"><?php esc_html_e( 'Needed only for automatic webhook registration.', 'wa-meta-control' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Meta App Secret', 'wa-meta-control' ); ?></th>
                                <td>
                                    <input type="password" name="<?php echo WAMC_OPTION_KEY; ?>[app_secret]" value="<?php echo esc_attr( wamc_setting( 'app_secret') ); ?>" class="regular-text" autocomplete="off" />
                                    <p class="description"><?php esc_html_e( 'Used to verify webhook signatures (X-Hub-Signature-256) and to register the webhook. Strongly recommended.', 'wa-meta-control' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Webhook Verify Token', 'wa-meta-control' ); ?></th>
                                <td>
                                    <input type="text" name="<?php echo WAMC_OPTION_KEY; ?>[verify_token]" value="<?php echo esc_attr( wamc_setting( 'verify_token') ); ?>" class="regular-text" autocomplete="off" />
                                    <p class="description"><?php esc_html_e( 'Paste this same value in Meta → WhatsApp → Configuration → Verify token. Auto-generated if empty.', 'wa-meta-control' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Graph API Version', 'wa-meta-control' ); ?></th>
                                <td>
                                    <input type="text" name="<?php echo WAMC_OPTION_KEY; ?>[api_version]" value="<?php echo esc_attr( wamc_setting( 'api_version', 'v21.0') ); ?>" class="regular-text" autocomplete="off" />
                                    <p class="description"><?php esc_html_e( 'e.g. v21.0', 'wa-meta-control' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Default Country Code', 'wa-meta-control' ); ?></th>
                                <td>
                                    <input type="text" name="<?php echo WAMC_OPTION_KEY; ?>[default_country_code]" value="<?php echo esc_attr( wamc_setting( 'default_country_code', '20') ); ?>" class="regular-text" autocomplete="off" />
                                    <p class="description"><?php esc_html_e( 'Prepended to local numbers starting with 0 (Egypt = 20).', 'wa-meta-control' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Fallback Template Name', 'wa-meta-control' ); ?></th>
                                <td>
                                    <input type="text" name="<?php echo WAMC_OPTION_KEY; ?>[template_name]" value="<?php echo esc_attr( wamc_setting( 'template_name') ); ?>" class="regular-text" autocomplete="off" />
                                    <p class="description"><?php esc_html_e( 'Optional. Approved template with one body variable {{1}}, used when the 24h customer-service window is closed.', 'wa-meta-control' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'Fallback Template Language', 'wa-meta-control' ); ?></th>
                                <td>
                                    <input type="text" name="<?php echo WAMC_OPTION_KEY; ?>[template_lang]" value="<?php echo esc_attr( wamc_setting( 'template_lang', 'ar') ); ?>" class="regular-text" autocomplete="off" />
                                    <p class="description"><?php esc_html_e( 'e.g. ar, en_US', 'wa-meta-control' ); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- ═══ FEATURE TOGGLES ════════════════════════════════════ -->
                    <div class="wamc-card">
                        <h2 class="wamc-card-title">⚙️ <?php esc_html_e( 'Active Features', 'wa-meta-control' ); ?></h2>
                        <table class="form-table wamc-table">
                            <?php
                            $features = [
                                'enable_confirm'   => __( 'Order Confirmation via WhatsApp Reply', 'wa-meta-control' ),
                                'enable_crm'       => __( 'Per-Order CRM Chat (WhatsApp conversation in order editor)', 'wa-meta-control' ),
                                'enable_qp_notify' => __( 'QP / Shipping Company Notifications', 'wa-meta-control' ),
                                'enable_order_notify' => __( 'Order Notifications (on creation / on status change)', 'wa-meta-control' ),
                                'enable_bulk'      => __( 'Bulk Message Sender (from orders list)', 'wa-meta-control' ),
                            ];
                            foreach ( $features as $key => $label ) : ?>
                            <tr>
                                <th><?php echo esc_html( $label ); ?></th>
                                <td>
                                    <label class="wamc-toggle">
                                        <input type="checkbox" name="<?php echo WAMC_OPTION_KEY; ?>[<?php echo $key; ?>]"
                                               value="1" <?php checked( wamc_setting( $key, '1' ), '1' ); ?> />
                                        <span class="wamc-toggle-slider"></span>
                                    </label>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>

                    <!-- ═══ VARIABLES REFERENCE ═════════════════════════════════ -->
                    <div class="wamc-card">
                        <h2 class="wamc-card-title">🧩 <?php esc_html_e( 'Template Variables (المتغيرات)', 'wa-meta-control' ); ?></h2>
                        <p class="description">
                            <?php esc_html_e( 'All customer messages are approved Meta templates (the only way to message after the 24h window). For each template below, list the variables in order: the 1st fills {{1}}, the 2nd {{2}}, etc. The number of variables must equal the number of {{n}} in your template.', 'wa-meta-control' ); ?>
                        </p>
                        <table class="widefat striped" style="max-width:520px">
                            <?php foreach ( WAMC_Order_Notify::available_vars() as $vk => $vd ) : ?>
                                <tr><td><code><?php echo esc_html( $vk ); ?></code></td><td><?php echo esc_html( $vd ); ?></td></tr>
                            <?php endforeach; ?>
                        </table>
                    </div>

                    <!-- ═══ ORDER CONFIRMATION ═════════════════════════════════ -->
                    <div class="wamc-card">
                        <h2 class="wamc-card-title">✅ <?php esc_html_e( 'Order Confirmation (Dual: Text + Template)', 'wa-meta-control' ); ?></h2>
                        <p class="description" style="margin:0 20px 16px;padding:8px 12px;background:#f0f8ff;border-right:4px solid #25D366;border-radius:4px">
                            📱 <strong><?php esc_html_e( 'نافذة مفتوحة (24 ساعة)', 'wa-meta-control' ); ?></strong> → <?php esc_html_e( 'رسالة نصية مع أزرار تفاعلية', 'wa-meta-control' ); ?><br>
                            🔒 <strong><?php esc_html_e( 'نافذة مغلقة / غير معروفة', 'wa-meta-control' ); ?></strong> → <?php esc_html_e( 'قالب معتمد مع أزرار Quick Reply', 'wa-meta-control' ); ?>
                        </p>

                        <h3 style="margin:0 20px 8px"><?php esc_html_e( '📱 Plain Text (نافذة مفتوحة)', 'wa-meta-control' ); ?></h3>
                        <table class="form-table wamc-table">
                            <tr>
                                <th><?php esc_html_e( 'نص الرسالة', 'wa-meta-control' ); ?></th>
                                <td>
                                    <textarea name="<?php echo WAMC_OPTION_KEY; ?>[confirm_message]" rows="4" class="large-text"
                                        placeholder="مرحباً {customer_name}، تم استلام طلبك #{order_id} بقيمة {total}. هل تريد تأكيده؟"
                                    ><?php echo esc_textarea( wamc_setting( 'confirm_message', '' ) ); ?></textarea>
                                    <p class="description"><?php esc_html_e( 'المتغيرات المتاحة: {customer_name} {first_name} {order_id} {total} {items} {items_priced} {payment_method} {site_name} {qp_order_number} {shipper_phone} {customer_note}', 'wa-meta-control' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'نص زر التأكيد', 'wa-meta-control' ); ?></th>
                                <td><input type="text" name="<?php echo WAMC_OPTION_KEY; ?>[confirm_btn_label]" value="<?php echo esc_attr( wamc_setting( 'confirm_btn_label', 'تأكيد الطلب' ) ); ?>" style="width:200px" /></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'نص زر الإلغاء', 'wa-meta-control' ); ?></th>
                                <td><input type="text" name="<?php echo WAMC_OPTION_KEY; ?>[cancel_btn_label]" value="<?php echo esc_attr( wamc_setting( 'cancel_btn_label', 'إلغاء الطلب' ) ); ?>" style="width:200px" /></td>
                            </tr>
                        </table>

                        <h3 style="margin:16px 20px 8px"><?php esc_html_e( '🔒 Template (نافذة مغلقة)', 'wa-meta-control' ); ?></h3>
                        <table class="form-table wamc-table">
                            <tr>
                                <th><?php esc_html_e( 'اسم القالب + اللغة', 'wa-meta-control' ); ?></th>
                                <td>
                                    <input type="text" name="<?php echo WAMC_OPTION_KEY; ?>[confirm_template]" value="<?php echo esc_attr( wamc_setting( 'confirm_template', '' ) ); ?>" placeholder="order_confirmation" />
                                    <input type="text" style="width:80px" name="<?php echo WAMC_OPTION_KEY; ?>[confirm_template_lang]" value="<?php echo esc_attr( wamc_setting( 'confirm_template_lang', 'ar' ) ); ?>" />
                                    <p class="description"><?php esc_html_e( 'القالب يجب أن يحتوي على زرين Quick Reply: الأول للتأكيد، الثاني للإلغاء.', 'wa-meta-control' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'متغيرات القالب', 'wa-meta-control' ); ?></th>
                                <td>
                                    <input type="text" class="regular-text" name="<?php echo WAMC_OPTION_KEY; ?>[confirm_vars]" value="<?php echo esc_attr( wamc_setting( 'confirm_vars', WAMC_Order_Notify::DEFAULT_VARS ) ); ?>" />
                                    <p class="description"><code><?php echo esc_html( WAMC_Order_Notify::vars_preview( (string) wamc_setting( 'confirm_vars', WAMC_Order_Notify::DEFAULT_VARS ) ) ); ?></code></p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'حالة الطلب عند التأكيد', 'wa-meta-control' ); ?></th>
                                <td>
                                    <select name="<?php echo WAMC_OPTION_KEY; ?>[confirm_status]">
                                        <?php foreach ( $wc_statuses as $status_key => $label ) : ?>
                                        <option value="<?php echo esc_attr( $status_key ); ?>" <?php selected( wamc_setting( 'confirm_status', 'processing' ), $status_key ); ?>><?php echo esc_html( $label ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'حالة الطلب عند الإلغاء', 'wa-meta-control' ); ?></th>
                                <td>
                                    <select name="<?php echo WAMC_OPTION_KEY; ?>[cancel_status]">
                                        <?php foreach ( $wc_statuses as $status_key => $label ) : ?>
                                        <option value="<?php echo esc_attr( $status_key ); ?>" <?php selected( wamc_setting( 'cancel_status', 'cancelled' ), $status_key ); ?>><?php echo esc_html( $label ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- ═══ ORDER NOTIFICATIONS ═════════════════════════════════ -->
                    <div class="wamc-card">
                        <h2 class="wamc-card-title">🔔 <?php esc_html_e( 'إشعارات تغيير حالة الطلب (Order Status Notifications)', 'wa-meta-control' ); ?></h2>
                        <p class="description" style="margin:0 20px 16px;padding:8px 12px;background:#f0f8ff;border-right:4px solid #25D366;border-radius:4px">
                            📱 <strong><?php esc_html_e( 'نافذة مفتوحة', 'wa-meta-control' ); ?></strong> → <?php esc_html_e( 'نص عادي', 'wa-meta-control' ); ?> &nbsp;|&nbsp;
                            🔒 <strong><?php esc_html_e( 'نافذة مغلقة / غير معروفة', 'wa-meta-control' ); ?></strong> → <?php esc_html_e( 'قالب معتمد', 'wa-meta-control' ); ?>
                        </p>

                        <h3 style="margin:16px 20px 8px"><?php esc_html_e( 'عند تغيير حالة الطلب إلى…', 'wa-meta-control' ); ?></h3>
                        <p class="description" style="margin:0 20px 12px"><?php esc_html_e( 'لكل حالة: أدخل نصاً عادياً (للنافذة المفتوحة) وقالباً معتمداً (للنافذة المغلقة). كلاهما مطلوب إذا كانت الحالة مفعّلة.', 'wa-meta-control' ); ?></p>
                        <?php $rules = wamc_setting( 'status_rules', [] ); ?>
                        <?php foreach ( $wc_statuses as $sk => $sl ) :
                            $r    = $rules[ $sk ] ?? [];
                            $rv   = $r['vars'] ?? WAMC_Order_Notify::DEFAULT_VARS;
                            $base = WAMC_OPTION_KEY . '[status_rules][' . esc_attr( $sk ) . ']'; ?>
                            <div class="wamc-status-rule-block" style="border:1px solid #ddd;border-radius:6px;margin-bottom:12px;padding:12px 16px">
                                <div style="display:flex;align-items:center;gap:12px;margin-bottom:10px">
                                    <label style="font-weight:600;font-size:14px">
                                        <input type="checkbox" name="<?php echo $base; ?>[enabled]" value="1" <?php checked( $r['enabled'] ?? '0', '1' ); ?> />
                                        <strong><?php echo esc_html( $sl ); ?></strong>
                                        <code style="font-size:12px;margin-right:4px"><?php echo esc_html( $sk ); ?></code>
                                    </label>
                                </div>
                                <table class="form-table wamc-table" style="margin:0">
                                    <tr>
                                        <th style="width:200px">📱 <?php esc_html_e( 'نص (نافذة مفتوحة)', 'wa-meta-control' ); ?></th>
                                        <td>
                                            <textarea name="<?php echo $base; ?>[message]" rows="2" class="large-text"
                                                placeholder="مرحباً {customer_name}، طلبك #{order_id} أصبح في حالة {status}."
                                            ><?php echo esc_textarea( $r['message'] ?? '' ); ?></textarea>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>🔒 <?php esc_html_e( 'قالب (نافذة مغلقة)', 'wa-meta-control' ); ?></th>
                                        <td>
                                            <input type="text" name="<?php echo $base; ?>[template]" value="<?php echo esc_attr( $r['template'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Template name', 'wa-meta-control' ); ?>" />
                                            <input type="text" style="width:70px" name="<?php echo $base; ?>[lang]" value="<?php echo esc_attr( $r['lang'] ?? 'ar' ); ?>" /><br>
                                            <input type="text" class="regular-text" name="<?php echo $base; ?>[vars]" value="<?php echo esc_attr( $rv ); ?>" />
                                            <p class="description"><code><?php echo esc_html( WAMC_Order_Notify::vars_preview( $rv ) ); ?></code></p>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <!-- ═══ QP NOTIFY ══════════════════════════════════════════ -->
                    <div class="wamc-card">
                        <h2 class="wamc-card-title">🚚 <?php esc_html_e( 'QP / Shipping Company Notifications (Dual: Text + Template)', 'wa-meta-control' ); ?></h2>
                        <p class="description" style="margin:0 20px 16px;padding:8px 12px;background:#f0f8ff;border-right:4px solid #25D366;border-radius:4px">
                            📱 <strong><?php esc_html_e( 'نافذة مفتوحة', 'wa-meta-control' ); ?></strong> → <?php esc_html_e( 'نص عادي', 'wa-meta-control' ); ?> &nbsp;|&nbsp;
                            🔒 <strong><?php esc_html_e( 'نافذة مغلقة', 'wa-meta-control' ); ?></strong> → <?php esc_html_e( 'قالب معتمد', 'wa-meta-control' ); ?>
                        </p>

                        <?php self::render_notify_target_section(
                            'qp',
                            __( 'QP Notifications', 'wa-meta-control' ),
                            wamc_setting( 'qp_notify_mode', 'phone' ),
                            wamc_setting( 'qp_phones', '' ),
                            wamc_setting( 'qp_group_id', '' )
                        ); ?>

                        <h3 style="margin:16px 20px 8px">🔒 <?php esc_html_e( 'قالب QP / الشحن (نافذة مغلقة)', 'wa-meta-control' ); ?></h3>
                        <table class="form-table wamc-table">
                            <tr>
                                <th><?php esc_html_e( 'اسم القالب + اللغة', 'wa-meta-control' ); ?></th>
                                <td>
                                    <input type="text" name="<?php echo WAMC_OPTION_KEY; ?>[qp_template]" value="<?php echo esc_attr( wamc_setting( 'qp_template', '' ) ); ?>" placeholder="qp_shipment_notify" />
                                    <input type="text" style="width:80px" name="<?php echo WAMC_OPTION_KEY; ?>[qp_template_lang]" value="<?php echo esc_attr( wamc_setting( 'qp_template_lang', 'ar' ) ); ?>" />
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'متغيرات القالب (قيم ثابتة، مفصولة بفاصلة)', 'wa-meta-control' ); ?></th>
                                <td>
                                    <input type="text" class="regular-text" name="<?php echo WAMC_OPTION_KEY; ?>[qp_template_vars]" value="<?php echo esc_attr( wamc_setting( 'qp_template_vars', '' ) ); ?>" placeholder="قيمة1, قيمة2" />
                                    <p class="description"><?php esc_html_e( 'نص الرسالة (من صندوق QP في الطلب) سيُرسل كـ {{1}} تلقائياً.', 'wa-meta-control' ); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- ═══ ANTI-BAN RULES ════════════════════════════════════ -->
                    <div class="wamc-card wamc-card-antibан">
                        <h2 class="wamc-card-title">🛡️ <?php esc_html_e( 'Anti-Ban Rules', 'wa-meta-control' ); ?></h2>
                        <p class="description" style="margin:0 0 16px">
                            <?php esc_html_e( 'قواعد لحماية رقمك من الحظر على واتساب.', 'wa-meta-control' ); ?>
                        </p>

                        <?php
                        // Live stats banner
                        if ( class_exists( 'WAMC_Rate_Limiter' ) ) {
                            $stats = WAMC_Rate_Limiter::get_stats();
                            $pct   = $stats['daily_limit'] > 0 ? round( $stats['sent_today'] / $stats['daily_limit'] * 100 ) : 0;
                            $bar_color = $pct >= 90 ? '#e00' : ( $pct >= 70 ? '#f90' : '#25D366' );
                            ?>
                            <div class="wamc-antibан-stats">
                                <div class="wamc-stat-item">
                                    <span class="wamc-stat-label">📤 اليوم</span>
                                    <span class="wamc-stat-value"><?php echo $stats['sent_today']; ?> / <?php echo $stats['daily_limit']; ?></span>
                                    <div class="wamc-stat-bar"><div style="width:<?php echo $pct; ?>%;background:<?php echo $bar_color; ?>"></div></div>
                                </div>
                                <div class="wamc-stat-item">
                                    <span class="wamc-stat-label">🚫 قائمة سوداء</span>
                                    <span class="wamc-stat-value"><?php echo $stats['blacklist_count']; ?> رقم</span>
                                </div>
                                <div class="wamc-stat-item">
                                    <span class="wamc-stat-label">✅ متبقي</span>
                                    <span class="wamc-stat-value" style="color:<?php echo $bar_color; ?>"><?php echo $stats['remaining']; ?> رسالة</span>
                                </div>
                            </div>
                            <?php
                        }
                        ?>

                        <table class="form-table wamc-table">

                            <!-- Delay between messages -->
                            <tr>
                                <th>⏱️ <?php esc_html_e( 'تأخير عشوائي بين الرسائل', 'wa-meta-control' ); ?></th>
                                <td>
                                    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                                        <label><?php esc_html_e( 'من', 'wa-meta-control' ); ?>
                                            <input type="number" name="<?php echo WAMC_OPTION_KEY; ?>[delay_min]"
                                                   value="<?php echo esc_attr( wamc_setting( 'delay_min', 15 ) ); ?>"
                                                   min="0" max="300" style="width:70px" />
                                            <?php esc_html_e( 'ثانية', 'wa-meta-control' ); ?>
                                        </label>
                                        <label><?php esc_html_e( 'إلى', 'wa-meta-control' ); ?>
                                            <input type="number" name="<?php echo WAMC_OPTION_KEY; ?>[delay_max]"
                                                   value="<?php echo esc_attr( wamc_setting( 'delay_max', 45 ) ); ?>"
                                                   min="0" max="600" style="width:70px" />
                                            <?php esc_html_e( 'ثانية', 'wa-meta-control' ); ?>
                                        </label>
                                    </div>
                                    <p class="description"><?php esc_html_e( 'تأخير عشوائي بين كل رسالة والتالية أثناء الإرسال الجماعي. يُنصح بـ 15–60 ثانية.', 'wa-meta-control' ); ?></p>
                                </td>
                            </tr>

                            <!-- Daily limit -->
                            <tr>
                                <th>📊 <?php esc_html_e( 'حد الرسائل اليومي', 'wa-meta-control' ); ?></th>
                                <td>
                                    <label class="wamc-toggle">
                                        <input type="checkbox" name="<?php echo WAMC_OPTION_KEY; ?>[enable_daily_limit]"
                                               value="1" <?php checked( wamc_setting( 'enable_daily_limit', '1' ), '1' ); ?> />
                                        <span class="wamc-toggle-slider"></span>
                                    </label>
                                    <input type="number" name="<?php echo WAMC_OPTION_KEY; ?>[daily_limit]"
                                           value="<?php echo esc_attr( wamc_setting( 'daily_limit', 150 ) ); ?>"
                                           min="1" max="10000" style="width:90px;margin-right:12px" />
                                    <?php esc_html_e( 'رسالة / يوم', 'wa-meta-control' ); ?>
                                    <p class="description"><?php esc_html_e( 'لا تتجاوز 150–200 رسالة في اليوم الأول. ارفع الحد تدريجياً مع مرور الوقت.', 'wa-meta-control' ); ?></p>
                                </td>
                            </tr>

                            <!-- Per-recipient cooldown -->
                            <tr>
                                <th>❄️ <?php esc_html_e( 'تبريد لكل مستلم', 'wa-meta-control' ); ?></th>
                                <td>
                                    <label class="wamc-toggle">
                                        <input type="checkbox" name="<?php echo WAMC_OPTION_KEY; ?>[enable_cooldown]"
                                               value="1" <?php checked( wamc_setting( 'enable_cooldown', '1' ), '1' ); ?> />
                                        <span class="wamc-toggle-slider"></span>
                                    </label>
                                    <input type="number" name="<?php echo WAMC_OPTION_KEY; ?>[cooldown_hours]"
                                           value="<?php echo esc_attr( wamc_setting( 'cooldown_hours', 24 ) ); ?>"
                                           min="1" max="720" style="width:80px;margin-right:12px" />
                                    <?php esc_html_e( 'ساعة بين رسالة والأخرى لنفس الرقم', 'wa-meta-control' ); ?>
                                    <p class="description"><?php esc_html_e( 'يحمي من إرسال رسائل متكررة لنفس العميل.', 'wa-meta-control' ); ?></p>
                                </td>
                            </tr>

                            <!-- Blacklist -->
                            <tr>
                                <th>🚫 <?php esc_html_e( 'القائمة السوداء', 'wa-meta-control' ); ?></th>
                                <td>
                                    <label class="wamc-toggle">
                                        <input type="checkbox" name="<?php echo WAMC_OPTION_KEY; ?>[enable_blacklist]"
                                               value="1" <?php checked( wamc_setting( 'enable_blacklist', '1' ), '1' ); ?> />
                                        <span class="wamc-toggle-slider"></span>
                                    </label>
                                    <span style="margin-right:12px;color:#666;font-size:13px">
                                        <?php esc_html_e( 'تجاهل الأرقام التي فشل تسليم الرسالة إليها (NOT FOUND / ERROR)', 'wa-meta-control' ); ?>
                                    </span>
                                    <?php
                                    $blacklist = class_exists( 'WAMC_Rate_Limiter' ) ? WAMC_Rate_Limiter::get_blacklist() : [];
                                    if ( ! empty( $blacklist ) ) :
                                    ?>
                                    <details style="margin-top:10px">
                                        <summary style="cursor:pointer;color:#e00;font-size:13px">
                                            <?php printf( __( 'عرض القائمة السوداء (%d رقم)', 'wa-meta-control' ), count( $blacklist ) ); ?>
                                        </summary>
                                        <table class="widefat" style="margin-top:8px;font-size:12px">
                                            <thead><tr><th>رقم الهاتف</th><th>السبب</th><th>إزالة</th></tr></thead>
                                            <tbody>
                                            <?php foreach ( $blacklist as $phone => $reason ) : ?>
                                            <tr>
                                                <td><code><?php echo esc_html( $phone ); ?></code></td>
                                                <td><?php echo esc_html( $reason ); ?></td>
                                                <td>
                                                    <button type="button" class="button button-small wamc-remove-blacklist"
                                                            data-phone="<?php echo esc_attr( $phone ); ?>"
                                                            data-nonce="<?php echo wp_create_nonce( 'wamc_nonce' ); ?>">
                                                        ✕ إزالة
                                                    </button>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </details>
                                    <?php endif; ?>
                                </td>
                            </tr>

                        </table>

                        <!-- Tips box -->
                        <div class="wamc-antibан-tips">
                            <h4>💡 نصائح لتجنب الحظر</h4>
                            <ul>
                                <li>🔢 ابدأ بـ <strong>50 رسالة/يوم</strong> ثم ارفع الحد تدريجياً كل أسبوع</li>
                                <li>✍️ نوّع نص الرسائل — لا ترسل نفس النص الحرفي لجميع العملاء</li>
                                <li>📱 تأكد أن أرقام العملاء لديهم واتساب فعلاً قبل الإرسال</li>
                                <li>⏸️ أوقف الإرسال الجماعي إذا تلقيت تحذيراً من واتساب</li>
                                <li>🕐 لا ترسل بعد منتصف الليل — معدل الحظر يرتفع</li>
                                <li>🔄 التأخير العشوائي يحاكي السلوك البشري ويقلل الكشف</li>
                            </ul>
                        </div>
                    </div>


                <?php submit_button( __( 'Save Settings', 'wa-meta-control' ), 'primary large wamc-save-btn' ); ?>
            </form>
        </div>

        <script>
        (function($){
            // Toggle API key visibility
            $('#wamc-toggle-key').on('click', function(){
                var f = $('#wamc_api_key');
                f.attr('type', f.attr('type') === 'password' ? 'text' : 'password');
            });

            // Test connection
            $('#wamc-test-connection').on('click', function(){
                var $btn = $(this), $res = $('#wamc-connection-result');
                $btn.prop('disabled', true);
                $res.text('جاري الاتصال...').css('color','#888');
                $.post(ajaxurl, {
                    action: 'wamc_test_connection',
                    nonce: $btn.data('nonce')
                }, function(r){
                    if(r.success){
                        $res.text('✓ متصل').css('color','#25D366');
                    } else {
                        $res.text('✗ ' + r.data.message).css('color','#e00');
                    }
                }).fail(function(){
                    $res.text('✗ فشل الاتصال').css('color','#e00');
                }).always(function(){
                    $btn.prop('disabled', false);
                });
            });

            // Copy webhook URL
            $('#wamc-copy-webhook').on('click', function(){
                var url = $(this).data('url');
                if(navigator.clipboard){
                    navigator.clipboard.writeText(url).then(function(){
                        alert('تم النسخ: ' + url);
                    });
                } else {
                    $('#wamc-webhook-url').select();
                    document.execCommand('copy');
                    alert('تم النسخ!');
                }
            });

            // Register webhook at WA Meta Control
            $('#wamc-register-webhook').on('click', function(){
                var $btn = $(this), $res = $('#wamc-webhook-result');
                $btn.prop('disabled', true);
                $res.text('جاري التسجيل...').css('color','#888');
                $.post(ajaxurl, {
                    action: 'wamc_register_webhook',
                    nonce: $btn.data('nonce')
                }, function(r){
                    if(r.success){
                        $res.text('✓ تم التسجيل').css('color','#25D366');
                    } else {
                        $res.text('✗ ' + r.data.message).css('color','#e00');
                    }
                }).fail(function(){
                    $res.text('✗ فشل').css('color','#e00');
                }).always(function(){
                    $btn.prop('disabled', false);
                });
            });

            // Notify mode radio toggle
            function bindNotifyMode(prefix){
                $('input[name="<?php echo WAMC_OPTION_KEY; ?>[' + prefix + '_notify_mode]"]').on('change', function(){
                    var mode = $(this).val();
                    $('.wamc-mode-panel-' + prefix).hide();
                    $('#wamc-' + prefix + '-panel-' + mode).show();
                }).filter(':checked').trigger('change');
            }
            bindNotifyMode('admin');
            bindNotifyMode('qp');
        })(jQuery);
        </script>
        <?php
    }

    /**
     * Renders a reusable "Phone or Group" notification target section.
     */
    private static function render_notify_target_section(
        string $prefix,
        string $label,
        string $current_mode,
        string $phones,
        string $group_id
    ): void {
        $opt = WAMC_OPTION_KEY;
        ?>
        <table class="form-table wamc-table">
            <tr>
                <th><?php esc_html_e( 'Send To', 'wa-meta-control' ); ?></th>
                <td>
                    <label style="margin-left:20px;font-size:14px">
                        <input type="radio" name="<?php echo $opt; ?>[<?php echo $prefix; ?>_notify_mode]"
                               value="phone" <?php checked( $current_mode, 'phone' ); ?> />
                        <?php esc_html_e( 'Phone Numbers', 'wa-meta-control' ); ?>
                    </label>
                    <label style="font-size:14px">
                        <input type="radio" name="<?php echo $opt; ?>[<?php echo $prefix; ?>_notify_mode]"
                               value="group" <?php checked( $current_mode, 'group' ); ?> />
                        <?php esc_html_e( 'WhatsApp Group ID', 'wa-meta-control' ); ?>
                    </label>
                </td>
            </tr>
            <tr id="wamc-<?php echo $prefix; ?>-panel-phone" class="wamc-mode-panel-<?php echo $prefix; ?>"
                style="<?php echo $current_mode !== 'phone' ? 'display:none' : ''; ?>">
                <th><?php esc_html_e( 'Phone Numbers', 'wa-meta-control' ); ?></th>
                <td>
                    <textarea name="<?php echo $opt; ?>[<?php echo $prefix; ?>_phones]"
                              rows="3" class="large-text"><?php echo esc_textarea( $phones ); ?></textarea>
                    <p class="description"><?php esc_html_e( 'One phone number per line (with country code, e.g. 201009300080).', 'wa-meta-control' ); ?></p>
                </td>
            </tr>
            <tr id="wamc-<?php echo $prefix; ?>-panel-group" class="wamc-mode-panel-<?php echo $prefix; ?>"
                style="<?php echo $current_mode !== 'group' ? 'display:none' : ''; ?>">
                <th><?php esc_html_e( 'WhatsApp Group ID', 'wa-meta-control' ); ?></th>
                <td>
                    <input type="text" name="<?php echo $opt; ?>[<?php echo $prefix; ?>_group_id]"
                           value="<?php echo esc_attr( $group_id ); ?>" class="regular-text"
                           placeholder="120363XXXXXXXXXX@g.us" />
                    <p class="description"><?php esc_html_e( 'Get the group ID from the Group List tab (Meta Groups API).', 'wa-meta-control' ); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }
}

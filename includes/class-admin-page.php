<?php
/**
 * Admin Dashboard Page — Test Sender, Message Log, Service Info
 *
 * Adds a sub-tab dashboard under the Settings page for live testing.
 */

defined( 'ABSPATH' ) || exit;

class WAMC_Admin_Page {

    public static function init(): void {
        add_action( 'admin_menu',                [ __CLASS__, 'add_menu' ] );
        add_action( 'admin_enqueue_scripts',     [ __CLASS__, 'enqueue_assets' ] );
        add_action( 'wp_ajax_wamc_send_test',    [ __CLASS__, 'ajax_send_test' ] );
        add_action( 'wp_ajax_wamc_fetch_log',    [ __CLASS__, 'ajax_fetch_log' ] );
        add_action( 'wp_ajax_wamc_delete_logs',  [ __CLASS__, 'ajax_delete_logs' ] );
        add_action( 'wp_ajax_wamc_fetch_service',[ __CLASS__, 'ajax_fetch_service' ] );
        add_action( 'wp_ajax_wamc_get_groups',   [ __CLASS__, 'ajax_get_groups' ] );
        add_action( 'wp_ajax_wamc_create_group', [ __CLASS__, 'ajax_create_group' ] );
        add_action( 'wp_ajax_wamc_check_window', [ __CLASS__, 'ajax_check_window' ] );
        add_action( 'wp_ajax_wamc_open_window',  [ __CLASS__, 'ajax_open_window' ] );
    }

    public static function add_menu(): void {
        add_submenu_page(
            'woocommerce',
            __( 'WA Meta Dashboard', 'wa-meta-control' ),
            __( 'WA Meta Dashboard', 'wa-meta-control' ),
            'manage_woocommerce',
            'wamc-dashboard',
            [ __CLASS__, 'render_page' ]
        );
    }

    public static function enqueue_assets( string $hook ): void {
        if ( ! in_array( $hook, [
            'woocommerce_page_wamc-settings',
            'woocommerce_page_wamc-dashboard',
        ] ) ) {
            return;
        }
        wp_enqueue_style( 'wamc-admin', WAMC_PLUGIN_URL . 'assets/wamc-admin.css', [], WAMC_VERSION );
        wp_enqueue_script( 'wamc-admin', WAMC_PLUGIN_URL . 'assets/wamc-admin.js', [ 'jquery' ], WAMC_VERSION, true );
        wp_localize_script( 'wamc-admin', 'wamcAjax', [
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'wamc_nonce' ),
        ] );
    }

    // ── AJAX handlers ─────────────────────────────────────────────────────────

    public static function ajax_send_test(): void {
        check_ajax_referer( 'wamc_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ] );
        }

        $type        = sanitize_text_field( $_POST['type']     ?? 'phone' );
        $target      = sanitize_text_field( $_POST['target']   ?? '' );
        $message     = sanitize_textarea_field( $_POST['message']  ?? '' );
        $tpl         = sanitize_key( $_POST['template']  ?? '' );
        $lang        = sanitize_text_field( $_POST['lang']     ?? 'ar' );
        $values      = array_values( array_filter( array_map( 'trim', explode( ',', sanitize_text_field( $_POST['values'] ?? '' ) ) ), 'strlen' ) );

        if ( empty( $target ) ) {
            wp_send_json_error( [ 'message' => 'رقم الهاتف مطلوب.' ] );
        }

        if ( $type === 'group' ) {
            // Groups: always plain text
            if ( empty( $message ) ) {
                wp_send_json_error( [ 'message' => 'نص الرسالة مطلوب للمجموعة.' ] );
            }
            $result = wamc_api()->send_group_message( $target, $message );
            if ( isset( $result['error'] ) ) {
                wp_send_json_error( [ 'message' => $result['error'], 'raw' => $result ] );
            }
            wp_send_json_success( [ 'message' => 'تم الإرسال للمجموعة', 'data' => $result ] );
        }

        // Phone mode: auto-route through dispatcher (checks 24h window)
        $result = WAMC_Dispatcher::send(
            $target,
            'test_message',
            $message,
            $tpl,
            $lang ?: 'ar',
            $values
        );

        if ( empty( $result['success'] ) ) {
            wp_send_json_error( [ 'message' => $result['error'] ?? 'Failed', 'raw' => $result ] );
        }

        $window  = $result['window']   ?? '?';
        $type_lbl = $result['msg_type'] ?? '?';
        $sent_lbl = $type_lbl === 'text' ? 'نص عادي (نافذة مفتوحة)' : 'قالب (نافذة مغلقة/غير معروفة)';
        wp_send_json_success( [ 'message' => "تم الإرسال — $sent_lbl — نافذة: $window", 'data' => $result ] );
    }

    public static function ajax_fetch_log(): void {
        check_ajax_referer( 'wamc_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error();
        }

        $type = sanitize_text_field( $_POST['log_type'] ?? 'sent' );
        $page = max( 1, intval( $_POST['page'] ?? 1 ) );

        if ( $type === 'received' ) {
            $result = wamc_api()->get_received_messages( $page );
        } else {
            $result = wamc_api()->get_sent_messages( $page );
        }

        wp_send_json_success( $result['data'] ?? $result );
    }

    public static function ajax_delete_logs(): void {
        check_ajax_referer( 'wamc_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ] );
        }

        $from_date = sanitize_text_field( $_POST['from_date'] ?? '' );
        $to_date   = sanitize_text_field( $_POST['to_date'] ?? '' );
        $dir       = sanitize_text_field( $_POST['log_type'] ?? 'all' );

        $deleted = WAMC_Message_Log::delete_by_date( $from_date, $to_date, $dir );
        wp_send_json_success( [
            'deleted' => $deleted,
            'message' => sprintf( 'تم حذف %d سجل بنجاح.', $deleted ),
        ] );
    }

    public static function ajax_fetch_service(): void {
        check_ajax_referer( 'wamc_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error();
        }

        $state    = wamc_api()->get_state();
        $settings = wamc_api()->get_service_settings();

        wp_send_json_success( [ 'state' => $state, 'settings' => $settings ] );
    }

    public static function ajax_get_groups(): void {
        check_ajax_referer( 'wamc_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error();
        }

        $api_res = wamc_api()->get_group_list();
        $groups  = [];
        if ( ! empty( $api_res['data']['groups'] ) && is_array( $api_res['data']['groups'] ) ) {
            $groups = $api_res['data']['groups'];
        }

        // Merge locally saved groups
        $saved = get_option( 'wamc_saved_groups', [] );
        if ( is_array( $saved ) && ! empty( $saved ) ) {
            $existing_ids = wp_list_pluck( $groups, 'id' );
            foreach ( $saved as $gid => $ginfo ) {
                if ( ! in_array( $gid, $existing_ids, true ) ) {
                    $groups[] = [
                        'id'          => $gid,
                        'name'        => $ginfo['name'] ?? 'WhatsApp Group',
                        'invite_link' => $ginfo['invite_link'] ?? '',
                    ];
                }
            }
        }

        wp_send_json_success( [ 'data' => [ 'groups' => $groups ] ] );
    }

    public static function ajax_create_group(): void {
        check_ajax_referer( 'wamc_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ] );
        }

        $subject     = sanitize_text_field( $_POST['subject'] ?? '' );
        $description = sanitize_textarea_field( $_POST['description'] ?? '' );
        $invite_raw  = sanitize_textarea_field( $_POST['invite_phones'] ?? '' );

        if ( empty( $subject ) ) {
            wp_send_json_error( [ 'message' => 'اسم المجموعة مطلوب.' ] );
        }

        $api = wamc_api();
        $res = $api->create_group( $subject, $description );

        if ( ! empty( $res['error'] ) ) {
            $err_msg = is_array( $res['error'] ) ? ( $res['error']['message'] ?? wp_json_encode( $res['error'] ) ) : (string) $res['error'];
            wp_send_json_error( [
                'message' => 'خطأ من Meta عند إنشاء المجموعة: ' . $err_msg,
                'raw'     => $res,
            ] );
        }

        $group_id    = sanitize_text_field( $res['data']['id'] ?? $res['data']['group_id'] ?? '' );
        $invite_link = '';

        if ( ! empty( $group_id ) ) {
            $link_res = $api->get_group_invite_link( $group_id );
            if ( ! empty( $link_res['data']['invite_link'] ) ) {
                $invite_link = esc_url_raw( $link_res['data']['invite_link'] );
            } elseif ( ! empty( $link_res['data']['link'] ) ) {
                $invite_link = esc_url_raw( $link_res['data']['link'] );
            }

            $saved = get_option( 'wamc_saved_groups', [] );
            if ( ! is_array( $saved ) ) {
                $saved = [];
            }
            $saved[ $group_id ] = [
                'id'          => $group_id,
                'name'        => $subject,
                'invite_link' => $invite_link,
                'created_at'  => current_time( 'mysql' ),
            ];
            update_option( 'wamc_saved_groups', $saved, false );

            $invited_count = 0;
            if ( ! empty( $invite_raw ) ) {
                $lines = explode( "\n", $invite_raw );
                foreach ( $lines as $line ) {
                    $digits = preg_replace( '/\D+/', '', trim( $line ) );
                    if ( strlen( $digits ) >= 8 ) {
                        $invite_msg = sprintf(
                            "دعوة للانضمام إلى مجموعة: %s\n%sرابط الانضمام:\n%s",
                            $subject,
                            $description ? ( $description . "\n" ) : '',
                            $invite_link ?: '(الرابط قيد التجهيز)'
                        );
                        $api->send_message( $digits, $invite_msg );
                        $invited_count++;
                    }
                }
            }

            wp_send_json_success( [
                'group_id'      => $group_id,
                'invite_link'   => $invite_link,
                'invited_count' => $invited_count,
                'message'       => 'تم إنشاء المجموعة بنجاح!',
                'raw'           => $res,
            ] );
        }

        $req_id = sanitize_text_field( $res['data']['request_id'] ?? '' );
        if ( ! empty( $req_id ) ) {
            wp_send_json_success( [
                'request_id' => $req_id,
                'group_id'   => '',
                'message'    => 'تم استلام طلب إنشاء المجموعة من Meta (معرف الطلب: ' . $req_id . '). سيتم تحديث الـ ID والرابط تلقائياً عبر الـ Webhook بمجرد المعالجة.',
                'raw'        => $res,
            ] );
        }

        wp_send_json_error( [
            'message' => 'لم تقم Meta بإرجاع معرف المجموعة.',
            'raw'     => $res,
        ] );
    }

    public static function ajax_check_window(): void {
        check_ajax_referer( 'wamc_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ] );
        }
        $phone  = sanitize_text_field( $_POST['phone'] ?? '' );
        $status = WAMC_Window_Tracker::status_label( $phone );
        $ts     = WAMC_Window_Tracker::get_last_inbound_timestamp( $phone );
        $last   = $ts ? wp_date( 'Y-m-d H:i:s', $ts ) : null;
        wp_send_json_success( [
            'status' => $status,
            'is_open' => ( $status === 'open' ),
            'last_inbound' => $last,
        ] );
    }

    public static function ajax_open_window(): void {
        check_ajax_referer( 'wamc_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ] );
        }
        $phone = sanitize_text_field( $_POST['phone'] ?? '' );
        if ( empty( $phone ) ) {
            wp_send_json_error( [ 'message' => 'رقم الهاتف مطلوب' ] );
        }
        WAMC_Window_Tracker::update( $phone );
        wp_send_json_success( [ 'message' => 'تم فتح النافذة لهذا الرقم لمدة 24 ساعة (محاكاة تجريبية)' ] );
    }

    // ── Render Dashboard ──────────────────────────────────────────────────────

    public static function render_page(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        ?>
        <div class="wrap wamc-settings-wrap">
            <h1 class="wamc-page-title">
                <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 448 512" style="vertical-align:middle;margin-left:8px">
                    <path fill="#25D366" d="M380.9 97.1C339 55.1 283.2 32 223.9 32c-122.4 0-222 99.6-222 222 0 39.1 10.2 77.3 29.6 111L0 480l117.7-30.9c32.4 17.7 68.9 27 106.1 27h.1c122.3 0 224.1-99.6 224.1-222 0-59.3-25.2-115-67.1-157zm-157 341.6c-33.2 0-65.7-8.9-94-25.7l-6.7-4-69.8 18.3L72 359.2l-4.4-7c-18.5-29.4-28.2-63.3-28.2-98.2 0-101.7 82.8-184.5 184.6-184.5 49.3 0 95.6 19.2 130.4 54.1 34.8 34.9 56.2 81.2 56.1 130.5 0 101.8-84.9 184.6-186.6 184.6zm101.2-138.2c-5.5-2.8-32.8-16.2-37.9-18-5.1-1.9-8.8-2.8-12.5 2.8-3.7 5.6-14.3 18-17.6 21.8-3.2 3.7-6.5 4.2-12 1.4-32.6-16.3-54-29.1-75.5-66-5.7-9.8 5.7-9.1 16.3-30.3 1.8-3.7 .9-6.9-.5-9.7-1.4-2.8-12.5-30.1-17.1-41.2-4.5-10.8-9.1-9.3-12.5-9.5-3.2-.2-6.9-.2-10.6-.2-3.7 0-9.7 1.4-14.8 6.9-5.1 5.6-19.4 19-19.4 46.3 0 27.3 19.9 53.7 22.6 57.4 2.8 3.7 39.1 59.7 94.8 83.8 35.2 15.2 49 16.5 66.6 13.9 10.7-1.6 32.8-13.4 37.4-26.4 4.6-13 4.6-24.1 3.2-26.4-1.3-2.5-5-3.9-10.5-6.6z"/>
                </svg>
                <?php esc_html_e( 'WA Meta Dashboard', 'wa-meta-control' ); ?>
            </h1>

            <!-- Nav Tabs -->
            <nav class="wamc-nav-tabs">
                <button class="wamc-tab-btn active" data-tab="test">📤 <?php esc_html_e( 'Test Message', 'wa-meta-control' ); ?></button>
                <button class="wamc-tab-btn" data-tab="log">📋 <?php esc_html_e( 'Message Log', 'wa-meta-control' ); ?></button>
                <button class="wamc-tab-btn" data-tab="service">🔌 <?php esc_html_e( 'Service Info', 'wa-meta-control' ); ?></button>
                <button class="wamc-tab-btn" data-tab="groups">👥 <?php esc_html_e( 'المجموعات وإنشاؤها', 'wa-meta-control' ); ?></button>
            </nav>

            <!-- Tab: Test Message -->
            <div class="wamc-tab-panel active" id="wamc-tab-test">
                <div class="wamc-card">
                    <h2 class="wamc-card-title">📤 <?php esc_html_e( 'Send Test Message', 'wa-meta-control' ); ?></h2>
                    <p class="description" style="margin:0 20px 16px;padding:8px 12px;background:#f0f8ff;border-right:4px solid #25D366;border-radius:4px">
                        🤖 <strong><?php esc_html_e( 'توجيه تلقائي:', 'wa-meta-control' ); ?></strong>
                        <?php esc_html_e( 'النظام يفحص نافذة 24 ساعة للرقم المُدخل ويختار تلقائياً: نص عادي (نافذة مفتوحة) أو قالب (نافذة مغلقة/غير معروفة).', 'wa-meta-control' ); ?>
                    </p>
                    <table class="form-table wamc-table">
                        <tr>
                            <th><?php esc_html_e( 'نوع الهدف', 'wa-meta-control' ); ?></th>
                            <td>
                                <label style="margin-left:20px">
                                    <input type="radio" name="test_type" value="phone" checked /> <?php esc_html_e( 'رقم هاتف', 'wa-meta-control' ); ?>
                                </label>
                                <label>
                                    <input type="radio" name="test_type" value="group" /> <?php esc_html_e( 'مجموعة (Group ID)', 'wa-meta-control' ); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'رقم الهاتف / Group ID', 'wa-meta-control' ); ?></th>
                            <td>
                                <input type="text" id="wamc-test-target" class="regular-text"
                                       placeholder="مثال: 201009300080 أو Group ID" />
                                <button type="button" id="wamc-btn-check-window" class="button" style="margin-right:6px">🔍 فحص النافذة</button>
                                <button type="button" id="wamc-btn-open-window" class="button button-secondary" style="margin-right:6px" title="محاكاة فتح نافذة الـ 24 ساعة لتجربة إرسال النص الحر">⚡ فتح النافذة (للتجربة)</button>
                                <div id="wamc-window-check-info" style="margin-top:6px;font-size:13px"></div>
                            </td>
                        </tr>
                        <tr>
                            <th>📱 <?php esc_html_e( 'النص العادي (نافذة مفتوحة)', 'wa-meta-control' ); ?></th>
                            <td>
                                <textarea id="wamc-test-message" rows="3" class="large-text"
                                          placeholder="<?php esc_attr_e( 'نص الرسالة — يُرسل عندما تكون نافذة 24 ساعة مفتوحة', 'wa-meta-control' ); ?>"></textarea>
                            </td>
                        </tr>
                        <tr id="wamc-test-template-row">
                            <th>🔒 <?php esc_html_e( 'القالب (نافذة مغلقة)', 'wa-meta-control' ); ?></th>
                            <td>
                                <input type="text" id="wamc-test-template" placeholder="اسم القالب، مثال: order_confirmation" class="regular-text" /><br>
                                <input type="text" id="wamc-test-lang" style="width:80px;margin-top:6px" value="ar" placeholder="ar" />
                                <span style="margin:0 6px;color:#888">اللغة</span><br>
                                <input type="text" id="wamc-test-values" class="regular-text" style="margin-top:6px" placeholder="أحمد,1001,قيد التنفيذ,500 ج.م" />
                                <p class="description"><?php esc_html_e( 'قيم المتغيرات بالترتيب، مفصولة بفاصلة: الأولى = {{1}}، الثانية = {{2}}…', 'wa-meta-control' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th></th>
                            <td>
                                <button type="button" id="wamc-btn-test-send" class="button button-primary button-large">
                                    ➤ <?php esc_html_e( 'إرسال (تلقائي)', 'wa-meta-control' ); ?>
                                </button>
                                <span id="wamc-test-result" style="margin-right:14px;font-weight:bold"></span>
                            </td>
                        </tr>
                    </table>
                    <div id="wamc-test-raw" class="wamc-raw-response" style="display:none"></div>
                </div>
            </div>

            <!-- Tab: Message Log -->
            <div class="wamc-tab-panel" id="wamc-tab-log" style="display:none">
                <div class="wamc-card">
                    <h2 class="wamc-card-title">📋 <?php esc_html_e( 'Message Log', 'wa-meta-control' ); ?></h2>
                    <div class="wamc-log-toolbar" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                            <label>
                                <input type="radio" name="log_type" value="sent" checked />
                                <?php esc_html_e( 'Sent Messages', 'wa-meta-control' ); ?>
                            </label>
                            <label style="margin-right:10px">
                                <input type="radio" name="log_type" value="received" />
                                <?php esc_html_e( 'Received Messages', 'wa-meta-control' ); ?>
                            </label>
                            <button type="button" id="wamc-btn-load-log" class="button button-secondary">
                                🔄 <?php esc_html_e( 'تحديث السجل', 'wa-meta-control' ); ?>
                            </button>
                            <span id="wamc-log-pager" style="margin-right:6px;font-weight:600"></span>
                            <button type="button" id="wamc-log-prev" class="button" disabled>◀</button>
                            <button type="button" id="wamc-log-next" class="button" disabled>▶</button>
                        </div>

                        <!-- Delete Filter by Date Range -->
                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;background:#fef2f2;padding:6px 12px;border:1px solid #fecaca;border-radius:6px">
                            <span style="font-size:12px;font-weight:600;color:#991b1b">🗑️ حذف السجلات من:</span>
                            <input type="date" id="wamc-log-del-from" style="font-size:12px;padding:2px 6px" />
                            <span style="font-size:12px;color:#991b1b">إلى:</span>
                            <input type="date" id="wamc-log-del-to" style="font-size:12px;padding:2px 6px" />
                            <button type="button" id="wamc-btn-del-range" class="button button-small" style="color:#b91c1c;border-color:#f87171">حذف الفترة</button>
                            <button type="button" id="wamc-btn-del-all" class="button button-small" style="background:#dc2626;color:#fff;border-color:#dc2626">مسح الكل</button>
                        </div>
                    </div>
                    <div id="wamc-log-table-wrap">
                        <p style="color:#888"><?php esc_html_e( 'Click Load to fetch messages from the API.', 'wa-meta-control' ); ?></p>
                    </div>
                </div>
            </div>

            <!-- Tab: Service Info -->
            <div class="wamc-tab-panel" id="wamc-tab-service" style="display:none">
                <div class="wamc-card">
                    <h2 class="wamc-card-title">🔌 <?php esc_html_e( 'Service Info', 'wa-meta-control' ); ?></h2>
                    <button type="button" id="wamc-btn-load-service" class="button button-primary">
                        🔄 <?php esc_html_e( 'Refresh', 'wa-meta-control' ); ?>
                    </button>
                    <div id="wamc-service-output" style="margin-top:16px"></div>
                </div>
            </div>

            <!-- Tab: Group List & Creation -->
            <div class="wamc-tab-panel" id="wamc-tab-groups" style="display:none">
                <!-- Card: Create Group -->
                <div class="wamc-card" style="margin-bottom:20px;border-top:3px solid #25D366">
                    <h2 class="wamc-card-title">➕ <?php esc_html_e( 'إنشاء مجموعة جديدة واستخراج الـ ID ورابط الدعوة', 'wa-meta-control' ); ?></h2>
                    <p class="description" style="margin:0 20px 16px;padding:8px 12px;background:#f0f8ff;border-right:4px solid #25D366;border-radius:4px">
                        💡 <strong><?php esc_html_e( 'ملاحظة هامة:', 'wa-meta-control' ); ?></strong>
                        <?php esc_html_e( 'يتم إنشاء المجموعة عبر Meta Cloud API. بعد الإنشاء ستحصل على Group ID ورابط الدعوة (Invite Link) فوراً، ويمكنك إرسال الدعوة تلقائياً لأرقام الهواتف المدخلة واستخدام الـ Group ID في إعدادات الشحن والطلبات.', 'wa-meta-control' ); ?>
                    </p>
                    <table class="form-table wamc-table">
                        <tr>
                            <th><?php esc_html_e( 'اسم المجموعة (Subject) *', 'wa-meta-control' ); ?></th>
                            <td>
                                <input type="text" id="wamc-new-group-subject" class="regular-text" placeholder="مثال: إشعارات فريق الشحن والإدارة" maxlength="100" />
                                <p class="description"><?php esc_html_e( 'اسم المجموعة كما سيظهر في واتساب (بحد أقصى 100 حرف).', 'wa-meta-control' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'وصف المجموعة (اختياري)', 'wa-meta-control' ); ?></th>
                            <td>
                                <textarea id="wamc-new-group-desc" class="large-text" rows="2" placeholder="وصف موجز للمجموعة..."></textarea>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'أرقام هواتف لدعوتها (اختياري)', 'wa-meta-control' ); ?></th>
                            <td>
                                <textarea id="wamc-new-group-phones" class="large-text" rows="3" placeholder="201009300080&#10;201123456789"></textarea>
                                <p class="description"><?php esc_html_e( 'أدخل رقماً في كل سطر مع كود الدولة. سيقوم النظام بإرسال رسالة برابط الدعوة فور إنشاء الجروب.', 'wa-meta-control' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th></th>
                            <td>
                                <button type="button" id="wamc-btn-create-group" class="button button-primary button-large">
                                    ⚡ <?php esc_html_e( 'إنشاء المجموعة واستخراج الرابط والـ ID', 'wa-meta-control' ); ?>
                                </button>
                                <span id="wamc-create-group-spinner" class="spinner" style="float:none;margin:0 10px"></span>
                            </td>
                        </tr>
                    </table>

                    <div id="wamc-create-group-result" style="display:none;margin:16px 20px 20px;padding:14px;background:#f8fff9;border:1px solid #c3e6cb;border-radius:6px">
                        <div id="wamc-create-group-msg" style="font-size:14px;font-weight:bold;color:#155724;margin-bottom:12px"></div>
                        <div style="display:flex;flex-direction:column;gap:10px">
                            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                                <strong>Group ID:</strong>
                                <code id="wamc-result-gid" style="font-size:14px;background:#fff;padding:4px 8px;border:1px solid #ccc;border-radius:4px;user-select:all"></code>
                                <button type="button" class="button button-small" id="wamc-copy-new-gid">📋 <?php esc_html_e( 'نسخ Group ID', 'wa-meta-control' ); ?></button>
                            </div>
                            <div id="wamc-result-link-wrap" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                                <strong><?php esc_html_e( 'رابط الدعوة (Invite Link):', 'wa-meta-control' ); ?></strong>
                                <code id="wamc-result-link" style="font-size:13px;background:#fff;padding:4px 8px;border:1px solid #ccc;border-radius:4px;user-select:all"></code>
                                <a id="wamc-open-new-link" href="#" target="_blank" class="button button-small">🔗 <?php esc_html_e( 'فتح الرابط', 'wa-meta-control' ); ?></a>
                                <button type="button" class="button button-small" id="wamc-copy-new-link">📋 <?php esc_html_e( 'نسخ الرابط', 'wa-meta-control' ); ?></button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Card: Existing Groups List -->
                <div class="wamc-card">
                    <h2 class="wamc-card-title">👥 <?php esc_html_e( 'قائمة المجموعات الحالية', 'wa-meta-control' ); ?></h2>
                    <p class="description" style="margin-bottom:12px">
                        <?php esc_html_e( 'جلب المجموعات المسجلة لاستخراج معرفاتها وروابط الدعوة لاستخدامها في إعدادات الإشعارات.', 'wa-meta-control' ); ?>
                    </p>
                    <button type="button" id="wamc-btn-load-groups" class="button button-secondary">
                        🔄 <?php esc_html_e( 'تحديث المجموعات', 'wa-meta-control' ); ?>
                    </button>
                    <div id="wamc-groups-output" style="margin-top:16px"></div>
                </div>
            </div>

        </div><!-- /.wrap -->

        <script>
        (function($){
            // ── Tabs
            $('.wamc-tab-btn').on('click', function(){
                $('.wamc-tab-btn').removeClass('active');
                $('.wamc-tab-panel').hide();
                $(this).addClass('active');
                $('#wamc-tab-' + $(this).data('tab')).show();
            });

            var nonce = '<?php echo wp_create_nonce( 'wamc_nonce' ); ?>';

            $('input[name="test_mode"]').on('change', function(){ $('.wamc-test-tpl').toggle($(this).val()==='template'); });

            // ── Test Send
            $('#wamc-btn-test-send').on('click', function(){
                var $btn = $(this), $res = $('#wamc-test-result'), $raw = $('#wamc-test-raw');
                var type    = $('input[name="test_type"]:checked').val();
                var target  = $('#wamc-test-target').val().trim();
                var message = $('#wamc-test-message').val().trim();
                var useTpl  = $('input[name="test_mode"]:checked').val() === 'template';
                var tpl     = $('#wamc-test-template').val().trim();
                if(!target || (useTpl ? !tpl : !message)){
                    $res.text('الرجاء تعبئة جميع الحقول').css('color','#e00');
                    return;
                }
                $btn.prop('disabled',true);
                $res.text('جاري الإرسال...').css('color','#888');
                $raw.hide();

                $.post(ajaxurl, {action:'wamc_send_test', nonce:nonce, type:type, target:target, message:message, use_template:useTpl?1:0, template:tpl, lang:$('#wamc-test-lang').val(), values:$('#wamc-test-values').val()}, function(r){
                    if(r.success){
                        $res.text('✓ ' + r.data.message).css('color','#25D366');
                    } else {
                        $res.text('✗ ' + r.data.message).css('color','#e00');
                    }
                    $raw.text(JSON.stringify(r.data.data || r.data, null, 2)).show();
                }).always(function(){ $btn.prop('disabled',false); });
            });

            // ── Message Log
            var logPage = 1;
            var logPageCount = 1;
            function loadLog(page){
                logPage = page;
                var type = $('input[name="log_type"]:checked').val();
                $('#wamc-log-table-wrap').html('<p style="padding:15px;color:#666">جاري التحميل...</p>');
                $.post(ajaxurl, {action:'wamc_fetch_log', nonce:nonce, log_type:type, page:logPage}, function(r){
                    if(!r.success){ $('#wamc-log-table-wrap').html('<p style="color:red;padding:15px">فشل التحميل</p>'); return; }

                    // Unpack data flexibly (supports direct or nested shapes)
                    var payload = (r.data && r.data.data && !Array.isArray(r.data.data)) ? r.data.data : (r.data || {});
                    var rows    = Array.isArray(payload) ? payload : (payload.data || payload.rows || payload.items || []);
                    logPageCount = payload.pageCount || 1;
                    var pageStr  = payload.page || ('صفحة ' + logPage + ' / ' + logPageCount);

                    if(!rows.length){
                        $('#wamc-log-table-wrap').html(
                            '<div style="padding:35px 20px;text-align:center;color:#888">'
                            + '<p style="font-size:16px;margin:0 0 6px">لا توجد رسائل ' + (type === 'received' ? 'مستقبلة' : 'مرسلة') + ' حتى الآن</p>'
                            + '<p style="font-size:12px;color:#aaa">' + (type === 'received' ? 'عندما يرسل العميل رسالة على واتساب ستظهر هنا فوراً' : 'جميع الإشعارات الصادرة يتم تسجيلها هنا تلقائياً') + '</p>'
                            + '</div>'
                        );
                        $('#wamc-log-pager').text('');
                        $('#wamc-log-prev, #wamc-log-next').prop('disabled', true);
                        return;
                    }

                    var html = '<table class="widefat fixed striped wamc-log-table"><thead><tr>';
                    if(type === 'received'){
                        html += '<th style="width:160px">المرسل (الهاتف)</th>'
                             + '<th style="width:100px">الطلب</th>'
                             + '<th>نص الرسالة المستقبلة</th>'
                             + '<th style="width:160px">وقت الاستقبال</th>';
                    } else {
                        html += '<th style="width:160px">الوجهة (الهاتف/المجموعة)</th>'
                             + '<th style="width:90px">الطلب</th>'
                             + '<th style="width:130px">نافذة 24h</th>'
                             + '<th style="width:140px">نوع التوجيه</th>'
                             + '<th>نص / تفاصيل الرسالة</th>'
                             + '<th style="width:110px">حالة التسليم</th>'
                             + '<th style="width:150px">الوقت</th>';
                    }
                    html += '</tr></thead><tbody>';

                    $.each(rows, function(i, row){
                        var phone = esc(row.phonenumber || row.to || row.groupId || '');
                        var text  = esc(row.text || '');
                        var time  = esc(row.createdAt || row.time || '');
                        var order = row.order_id ? ('<a href="post.php?post='+row.order_id+'&action=edit" target="_blank" style="font-weight:bold">#'+row.order_id+'</a>') : '<span style="color:#aaa">—</span>';

                        if(type === 'received'){
                            html += '<tr>'
                                 + '<td><strong><code>+' + phone + '</code></strong></td>'
                                 + '<td>' + order + '</td>'
                                 + '<td style="word-break:break-word;font-size:13px;line-height:1.5">' + text + '</td>'
                                 + '<td><span style="color:#666;font-size:12px">' + time + '</span></td>'
                                 + '</tr>';
                        } else {
                            var wStatus = row.window_status || '';
                            var wBadge  = wStatus === 'open'
                                ? '<span class="wamc-status" style="background:#d4edda;color:#155724">🟢 مفتوحة</span>'
                                : (wStatus === 'closed' ? '<span class="wamc-status" style="background:#f8d7da;color:#721c24">🔴 مغلقة</span>' : '<span style="color:#888">—</span>');

                            var msgType = row.msg_type || '';
                            var rBadge  = msgType === 'text'
                                ? '<span class="wamc-status" style="background:#e8f4fd;color:#0c5460">📱 نص حر</span>'
                                : (msgType === 'template' ? '<span class="wamc-status" style="background:#fff3cd;color:#856404">🔒 قالب' + (row.template_name ? (': <small>'+esc(row.template_name)+'</small>') : '') + '</span>' : '<span style="color:#888">—</span>');

                            var st = (row.status || '').toLowerCase();
                            var stColor = (st === 'delivered' || st === 'read' || st === 'sent') ? '#155724' : (st === 'failed' || st === 'skipped' ? '#721c24' : '#555');
                            var stBg    = (st === 'delivered' || st === 'read' || st === 'sent') ? '#d4edda' : (st === 'failed' || st === 'skipped' ? '#f8d7da' : '#eee');
                            var stBadge = '<span class="wamc-status" style="background:'+stBg+';color:'+stColor+'">' + esc(row.status || 'sent') + '</span>';

                            var errText = row.error || row.delivery || '';
                            var errHtml = '';
                            if(errText && (st === 'failed' || st === 'skipped')){
                                errHtml = '<div style="margin-top:5px;padding:4px 8px;background:#fef2f2;border-right:3px solid #ef4444;color:#991b1b;font-size:11px;border-radius:4px">'
                                        + '⚠️ <strong>سبب الفشل:</strong> ' + esc(errText) + '</div>';
                            } else if(row.reason && st === 'skipped'){
                                errHtml = '<div style="margin-top:5px;padding:4px 8px;background:#fffbeb;border-right:3px solid #f59e0b;color:#92400e;font-size:11px;border-radius:4px">'
                                        + 'ℹ️ ' + esc(row.reason) + '</div>';
                            }

                            var senderBadge = row.sender_user
                                ? '<span style="display:inline-block;margin-bottom:4px;margin-left:6px;padding:1px 7px;background:#e0f2fe;color:#0369a1;border-radius:10px;font-size:11px;font-weight:600">👤 بواسطة: ' + esc(row.sender_user) + '</span><br>'
                                : '';

                            html += '<tr>'
                                 + '<td><strong><code>+' + phone + '</code></strong></td>'
                                 + '<td>' + order + '</td>'
                                 + '<td>' + wBadge + '</td>'
                                 + '<td>' + rBadge + '</td>'
                                 + '<td style="word-break:break-word;font-size:12px;line-height:1.4">' + senderBadge + text + errHtml + '</td>'
                                 + '<td>' + stBadge + '</td>'
                                 + '<td><span style="color:#666;font-size:12px">' + time + '</span></td>'
                                 + '</tr>';
                        }
                    });

                    html += '</tbody></table>';
                    $('#wamc-log-table-wrap').html(html);
                    $('#wamc-log-pager').text(pageStr);
                    $('#wamc-log-prev').prop('disabled', logPage <= 1);
                    $('#wamc-log-next').prop('disabled', logPage >= logPageCount);
                });
            }
            $('#wamc-btn-load-log').on('click', function(){ loadLog(1); });
            $('input[name="log_type"]').on('change', function(){ loadLog(1); });
            $('.wamc-tab-btn[data-tab="log"]').on('click', function(){ loadLog(1); });
            $('#wamc-log-prev').on('click', function(){ if(logPage > 1) loadLog(logPage-1); });
            $('#wamc-log-next').on('click', function(){ if(logPage < logPageCount) loadLog(logPage+1); });

            // ── Delete Logs by Date Range or Clear All
            $('#wamc-btn-del-range').on('click', function(){
                var from = $('#wamc-log-del-from').val();
                var to   = $('#wamc-log-del-to').val();
                if(!from && !to){
                    alert('يرجى تحديد تاريخ البداية أو النهاية لحذف السجلات.');
                    return;
                }
                if(!confirm('هل أنت متأكد من حذف سجلات الرسائل في الفترة المحددة؟')) return;
                $.post(ajaxurl, {
                    action: 'wamc_delete_logs',
                    nonce: nonce,
                    from_date: from,
                    to_date: to,
                    log_type: 'all'
                }, function(r){
                    if(r && r.success){
                        alert('✓ ' + r.data.message);
                        loadLog(1);
                    }
                });
            });

            $('#wamc-btn-del-all').on('click', function(){
                var type = $('input[name="log_type"]:checked').val() || 'all';
                var typeLbl = type === 'received' ? 'المستقبلة' : 'المرسلة';
                if(!confirm('هل أنت متأكد من مسح جميع السجلات ' + typeLbl + ' بالكامل؟')) return;
                $.post(ajaxurl, {
                    action: 'wamc_delete_logs',
                    nonce: nonce,
                    from_date: '',
                    to_date: '',
                    log_type: type
                }, function(r){
                    if(r && r.success){
                        alert('✓ ' + r.data.message);
                        loadLog(1);
                    }
                });
            });

            // ── Service Info
            $('#wamc-btn-load-service').on('click', function(){
                $('#wamc-service-output').html('<p>جاري التحميل...</p>');
                $.post(ajaxurl, {action:'wamc_fetch_service', nonce:nonce}, function(r){
                    if(!r.success){ $('#wamc-service-output').html('<p style="color:red">فشل</p>'); return; }

                    // state shape: { success, data: { state: "CONNECTED" } }
                    var stateVal = (r.data.state && r.data.state.data && r.data.state.data.state)
                                    ? r.data.state.data.state : 'Unknown';

                    // settings shape: { success, data: { serviceInfo:{...}, settings:{...} } }
                    var svcData  = (r.data.settings && r.data.settings.data) ? r.data.settings.data : {};
                    var svcInfo  = svcData.serviceInfo || {};
                    var svcSets  = svcData.settings    || {};

                    var stateColor = stateVal === 'CONNECTED' ? '#25D366' : '#e00';
                    var html = '<div class="wamc-service-grid">';

                    // Connection state badge
                    html += '<div class="wamc-service-card">'
                          + '<strong>Connection State</strong><br>'
                          + '<span class="wamc-status" style="background:'+(stateVal==='CONNECTED'?'#d4edda':'#f8d7da')+';color:'+(stateVal==='CONNECTED'?'#155724':'#721c24')+'">'
                          + esc(stateVal)+'</span></div>';

                    // Service Info fields
                    if(svcInfo.phoneNumber){
                        html += '<div class="wamc-service-card"><strong>Phone Number</strong><br><code>+'+esc(svcInfo.phoneNumber)+'</code></div>';
                    }
                    var infoMap = { verifiedName:'Verified Name', quality:'Quality Rating', tier:'Messaging Tier', nameStatus:'Name Status', verification:'Verification' };
                    $.each(infoMap, function(k, label){
                        if(svcInfo[k]){ html += '<div class="wamc-service-card"><strong>'+label+'</strong><br>'+esc(String(svcInfo[k]))+'</div>'; }
                    });

                    // Webhook
                    if(svcSets.webhook){
                        var wh = svcSets.webhook;
                        html += '<div class="wamc-service-card" style="grid-column:1/-1">'
                              + '<strong>Webhook</strong><br>'
                              + '<span class="wamc-status" style="background:'+(wh.enabled?'#d4edda':'#f8d7da')+';color:'+(wh.enabled?'#155724':'#721c24')+'">'
                              + (wh.enabled?'Enabled':'Disabled')+'</span>&nbsp;'
                              + '<code style="font-size:12px;word-break:break-all">'+esc(wh.webhookUrl||'—')+'</code></div>';
                    }

                    html += '</div>';
                    $('#wamc-service-output').html(html);
                });
            });

            // ── Group Creation & Management
            $('#wamc-btn-create-group').on('click', function(){
                var $btn = $(this);
                var subject = $('#wamc-new-group-subject').val().trim();
                var desc    = $('#wamc-new-group-desc').val().trim();
                var phones  = $('#wamc-new-group-phones').val().trim();
                var $resWrap = $('#wamc-create-group-result');
                var $spinner = $('#wamc-create-group-spinner');

                if(!subject){
                    alert('يرجى إدخال اسم المجموعة.');
                    $('#wamc-new-group-subject').focus();
                    return;
                }

                $btn.prop('disabled', true);
                $spinner.addClass('is-active');
                $resWrap.hide();

                $.post(ajaxurl, {
                    action: 'wamc_create_group',
                    nonce: nonce,
                    subject: subject,
                    description: desc,
                    invite_phones: phones
                }, function(r){
                    $btn.prop('disabled', false);
                    $spinner.removeClass('is-active');

                    if(!r.success){
                        var errMsg = (r.data && r.data.message) ? r.data.message : 'فشل إنشاء المجموعة.';
                        $resWrap.show().css({background:'#fdf2f2', borderColor:'#f5c6cb'});
                        $('#wamc-create-group-msg').css('color','#721c24').html('❌ ' + esc(errMsg));
                        $('#wamc-result-gid').text('—');
                        $('#wamc-result-link-wrap').hide();
                        return;
                    }

                    var d = r.data || {};
                    $resWrap.show().css({background:'#f8fff9', borderColor:'#c3e6cb'});
                    var successTxt = '✓ ' + esc(d.message || 'تم إنشاء المجموعة بنجاح!');
                    if(d.invited_count > 0){
                        successTxt += ' (تم إرسال رابط الدعوة إلى ' + d.invited_count + ' رقم)';
                    }
                    $('#wamc-create-group-msg').css('color','#155724').html(successTxt);

                    var gid = d.group_id || 'قيد المعالجة من Meta';
                    $('#wamc-result-gid').text(gid);
                    $('#wamc-copy-new-gid').data('id', gid);

                    if(d.invite_link){
                        $('#wamc-result-link').text(d.invite_link);
                        $('#wamc-open-new-link').attr('href', d.invite_link);
                        $('#wamc-copy-new-link').data('link', d.invite_link);
                        $('#wamc-result-link-wrap').show();
                    } else {
                        $('#wamc-result-link-wrap').hide();
                    }

                    // Refresh group list
                    $('#wamc-btn-load-groups').trigger('click');
                }).fail(function(){
                    $btn.prop('disabled', false);
                    $spinner.removeClass('is-active');
                    alert('حدث خطأ في الاتصال بالخادم.');
                });
            });

            $('#wamc-copy-new-gid').on('click', function(){
                var id = $(this).data('id') || $('#wamc-result-gid').text();
                if(navigator.clipboard && id && id !== '—') {
                    navigator.clipboard.writeText(id);
                    $(this).text('✓ تم النسخ');
                    var $t = $(this);
                    setTimeout(function(){ $t.text('📋 ' + '<?php esc_html_e( 'نسخ Group ID', 'wa-meta-control' ); ?>'); }, 2000);
                }
            });

            $('#wamc-copy-new-link').on('click', function(){
                var link = $(this).data('link') || $('#wamc-result-link').text();
                if(navigator.clipboard && link) {
                    navigator.clipboard.writeText(link);
                    $(this).text('✓ تم النسخ');
                    var $t = $(this);
                    setTimeout(function(){ $t.text('📋 ' + '<?php esc_html_e( 'نسخ الرابط', 'wa-meta-control' ); ?>'); }, 2000);
                }
            });

            // ── Group List
            $('#wamc-btn-load-groups').on('click', function(){
                $('#wamc-groups-output').html('<p>جاري التحميل...</p>');
                $.post(ajaxurl, {action:'wamc_get_groups', nonce:nonce}, function(r){
                    if(!r.success){ $('#wamc-groups-output').html('<p style="color:red">فشل جلب المجموعات</p>'); return; }

                    var payload = r.data.data || r.data || {};
                    var groups  = payload.groups || payload.data || payload.result || [];

                    if(!groups.length){
                        $('#wamc-groups-output').html(
                            '<div style="padding:20px;text-align:center;color:#888">'
                            +'<p style="font-size:16px">لا توجد مجموعات محفوظة أو مسترجعة</p>'
                            +'<p style="font-size:13px">أنشئ مجموعة جديدة من النموذج أعلاه أو انضم لمجموعة عبر واتساب</p>'
                            +'</div>'
                        );
                        return;
                    }

                    var html = '<table class="widefat fixed wamc-log-table"><thead><tr>'
                               +'<th style="width:25%">اسم المجموعة</th><th style="width:30%">Group ID</th><th style="width:25%">رابط الدعوة</th><th style="width:20%">إجراءات</th>'
                               +'</tr></thead><tbody>';
                    $.each(groups, function(i, g){
                        var gid   = esc(g.id||g.groupId||g._serialized||'');
                        var gname = esc(g.name||g.subject||'Group '+(i+1));
                        var glink = g.invite_link ? esc(g.invite_link) : '';
                        html += '<tr>'
                            + '<td><strong>'+gname+'</strong></td>'
                            + '<td><code>'+gid+'</code></td>'
                            + '<td>'+(glink ? '<a href="'+glink+'" target="_blank" style="font-size:11px;word-break:break-all">'+glink+'</a>' : '<span style="color:#888">—</span>')+'</td>'
                            + '<td>'
                            + '<button type="button" class="button button-small wamc-copy-gid" data-id="'+gid+'">📋 نسخ ID</button> '
                            + (glink ? '<button type="button" class="button button-small wamc-copy-link" data-link="'+glink+'">🔗 نسخ الرابط</button>' : '')
                            + '</td>'
                            + '</tr>';
                    });
                    html += '</tbody></table>';
                    $('#wamc-groups-output').html(html);

                    $('#wamc-groups-output').off('click', '.wamc-copy-gid').on('click', '.wamc-copy-gid', function(){
                        var id = $(this).data('id');
                        if(navigator.clipboard && id) {
                            navigator.clipboard.writeText(id);
                            $(this).text('✓ تم النسخ');
                            var $t = $(this);
                            setTimeout(function(){ $t.text('📋 نسخ ID'); }, 2000);
                        }
                    });

                    $('#wamc-groups-output').off('click', '.wamc-copy-link').on('click', '.wamc-copy-link', function(){
                        var link = $(this).data('link');
                        if(navigator.clipboard && link) {
                            navigator.clipboard.writeText(link);
                            $(this).text('✓ تم النسخ');
                            var $t = $(this);
                            setTimeout(function(){ $t.text('🔗 نسخ الرابط'); }, 2000);
                        }
                    });
                });
            });

            function esc(s){ return $('<div>').text(s).html(); }
        })(jQuery);
        </script>
        <?php
    }
}

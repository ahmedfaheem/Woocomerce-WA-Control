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
        add_action( 'wp_ajax_wamc_fetch_service',[ __CLASS__, 'ajax_fetch_service' ] );
        add_action( 'wp_ajax_wamc_get_groups',   [ __CLASS__, 'ajax_get_groups' ] );
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

        wp_send_json_success( $result );
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
        wp_send_json_success( wamc_api()->get_group_list() );
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
                <button class="wamc-tab-btn" data-tab="groups">👥 <?php esc_html_e( 'Group List', 'wa-meta-control' ); ?></button>
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
                    <div class="wamc-log-toolbar">
                        <label>
                            <input type="radio" name="log_type" value="sent" checked />
                            <?php esc_html_e( 'Sent Messages', 'wa-meta-control' ); ?>
                        </label>
                        <label style="margin-right:16px">
                            <input type="radio" name="log_type" value="received" />
                            <?php esc_html_e( 'Received Messages', 'wa-meta-control' ); ?>
                        </label>
                        <button type="button" id="wamc-btn-load-log" class="button button-secondary">
                            🔄 <?php esc_html_e( 'Load', 'wa-meta-control' ); ?>
                        </button>
                        <span id="wamc-log-pager" style="margin-right:10px"></span>
                        <button type="button" id="wamc-log-prev" class="button" disabled>◀</button>
                        <button type="button" id="wamc-log-next" class="button" disabled>▶</button>
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

            <!-- Tab: Group List -->
            <div class="wamc-tab-panel" id="wamc-tab-groups" style="display:none">
                <div class="wamc-card">
                    <h2 class="wamc-card-title">👥 <?php esc_html_e( 'WhatsApp Groups', 'wa-meta-control' ); ?></h2>
                    <p class="description" style="margin-bottom:12px">
                        <?php esc_html_e( 'Fetch your groups to find the Group IDs to use in settings.', 'wa-meta-control' ); ?>
                    </p>
                    <button type="button" id="wamc-btn-load-groups" class="button button-primary">
                        🔄 <?php esc_html_e( 'Load Groups', 'wa-meta-control' ); ?>
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
                $('#wamc-log-table-wrap').html('<p>جاري التحميل...</p>');
                $.post(ajaxurl, {action:'wamc_fetch_log', nonce:nonce, log_type:type, page:logPage}, function(r){
                    if(!r.success){ $('#wamc-log-table-wrap').html('<p style="color:red">فشل التحميل</p>'); return; }

                    // Actual shape: { success, data: { count, pageCount, page, data: [...] } }
                    var payload    = r.data.data || {};          // the inner 'data' object from API
                    var rows       = payload.data || [];          // the messages array
                    logPageCount   = payload.pageCount || 1;
                    var pageStr    = payload.page || ('صفحة ' + logPage);

                    if(!rows.length){
                        $('#wamc-log-table-wrap').html('<p>لا توجد رسائل</p>');
                        $('#wamc-log-pager').text('');
                        $('#wamc-log-prev, #wamc-log-next').prop('disabled', true);
                        return;
                    }

                    var html = '<table class="widefat fixed wamc-log-table"><thead><tr>'
                        +'<th>Phone</th><th>Message</th><th>Status</th><th>Delivery</th><th>Created</th></tr></thead><tbody>';
                    $.each(rows, function(i, row){
                        var statusClass = 'wamc-status-' + (row.status||'').toLowerCase().replace(/\s+/g,'-');
                        html += '<tr>'
                            +'<td>'+esc(row.phonenumber||row.groupId||'')+'</td>'
                            +'<td style="max-width:300px;word-break:break-word">'+esc((row.text||'').substring(0,120))+'</td>'
                            +'<td><span class="wamc-status '+statusClass+'">'+esc(row.status||'')+'</span></td>'
                            +'<td>'+esc(row.delivery||'')+'</td>'
                            +'<td>'+esc(row.createdAt||row.executedAt||'')+'</td>'
                            +'</tr>';
                    });
                    html += '</tbody></table>';
                    $('#wamc-log-table-wrap').html(html);
                    $('#wamc-log-pager').text(pageStr);
                    $('#wamc-log-prev').prop('disabled', logPage <= 1);
                    $('#wamc-log-next').prop('disabled', logPage >= logPageCount);
                });
            }
            $('#wamc-btn-load-log').on('click', function(){ loadLog(1); });
            $('#wamc-log-prev').on('click', function(){ if(logPage > 1) loadLog(logPage-1); });
            $('#wamc-log-next').on('click', function(){ if(logPage < logPageCount) loadLog(logPage+1); });

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

            // ── Group List
            $('#wamc-btn-load-groups').on('click', function(){
                $('#wamc-groups-output').html('<p>جاري التحميل...</p>');
                $.post(ajaxurl, {action:'wamc_get_groups', nonce:nonce}, function(r){
                    if(!r.success){ $('#wamc-groups-output').html('<p style="color:red">فشل</p>'); return; }

                    // Actual shape: { success, data: { groups: [...] } }
                    var payload = r.data.data || {};
                    var groups  = payload.groups || payload.data || payload.result || [];

                    if(!groups.length){
                        $('#wamc-groups-output').html(
                            '<div style="padding:20px;text-align:center;color:#888">'
                            +'<p style="font-size:16px">لا توجد مجموعات في هذا الحساب</p>'
                            +'<p style="font-size:13px">أنشئ مجموعة واتساب أولاً، ثم أعد التحميل</p>'
                            +'</div>'
                        );
                        return;
                    }

                    var html = '<table class="widefat fixed wamc-log-table"><thead><tr>'
                               +'<th>اسم المجموعة</th><th>Group ID</th><th></th>'
                               +'</tr></thead><tbody>';
                    $.each(groups, function(i, g){
                        var gid   = esc(g.id||g.groupId||g._serialized||'');
                        var gname = esc(g.name||g.subject||'Group '+(i+1));
                        html += '<tr>'
                            + '<td>'+gname+'</td>'
                            + '<td><code>'+gid+'</code></td>'
                            + '<td><button type="button" class="button wamc-copy-gid" data-id="'+gid+'">📋 نسخ ID</button></td>'
                            + '</tr>';
                    });
                    html += '</tbody></table>';
                    $('#wamc-groups-output').html(html);
                    $('#wamc-groups-output').on('click', '.wamc-copy-gid', function(){
                        var id = $(this).data('id');
                        if(navigator.clipboard) navigator.clipboard.writeText(id);
                        $(this).text('✓ تم النسخ').prop('disabled', true);
                    });
                });
            });

            function esc(s){ return $('<div>').text(s).html(); }
        })(jQuery);
        </script>
        <?php
    }
}

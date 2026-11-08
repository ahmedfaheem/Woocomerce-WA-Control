<?php
/**
 * Smart Promotional Campaign & Bulk Message Sender
 *
 * - Supports both legacy WooCommerce orders (edit-shop_order) and HPOS (woocommerce_page_wc-orders)
 * - Dual Routing: Plain Text (with Opt-Out button when 24h window OPEN) + Approved Marketing Template (when 24h window CLOSED)
 * - Zero Server Blocking: Schedules individual staggered Action Scheduler / WP-Cron jobs (no sleep() in PHP workers)
 * - Anti-Ban Protection: Deduplicates phone numbers per campaign, enforces rate limits/blacklist, and supports Campaign IDs
 */

defined( 'ABSPATH' ) || exit;

class WAMC_Bulk_Send {

    public static function init(): void {
        add_action( 'admin_footer',                        [ __CLASS__, 'render_overlay' ] );
        add_action( 'wp_ajax_wamc_bulk_schedule',          [ __CLASS__, 'ajax_schedule' ] );
        add_action( 'wp_ajax_wamc_bulk_send_one',          [ __CLASS__, 'ajax_send_one' ] );
        add_action( 'wamc_send_single_promo',              [ __CLASS__, 'process_single_promo' ], 10, 2 );
        add_action( 'process_wamc_bulk_batch',             [ __CLASS__, 'process_batch' ] ); // legacy fallback
        add_action( 'wp_ajax_wamc_remove_blacklist',       [ __CLASS__, 'ajax_remove_blacklist' ] );
    }

    // ── Orders List Overlay (Legacy + HPOS) ───────────────────────────────────

    public static function render_overlay(): void {
        $screen = get_current_screen();
        if ( ! $screen || ! in_array( $screen->id, [ 'edit-shop_order', 'woocommerce_page_wc-orders' ], true ) ) {
            return;
        }
        // Do not render on single order edit screen in HPOS
        if ( isset( $_GET['action'] ) && $_GET['action'] === 'edit' ) {
            return;
        }

        $default_tpl  = esc_attr( wamc_setting( 'template_name', '' ) );
        $default_lang = esc_attr( wamc_setting( 'template_lang', 'ar' ) ?: 'ar' );
        $delay_min    = max( 5, (int) wamc_setting( 'delay_min', 15 ) );
        $delay_max    = max( $delay_min, (int) wamc_setting( 'delay_max', 45 ) );
        $nonce        = wp_create_nonce( 'wamc_nonce' );
        ?>
        <!-- Trigger button (injected by JS next to bulk actions) -->
        <button id="wamc-bulk-open" type="button" class="button button-primary" style="display:none;margin-right:6px">
            📢 <?php esc_html_e( 'إرسال عرض ترويجي للطلبات المحددة', 'wa-meta-control' ); ?>
        </button>

        <!-- Overlay modal -->
        <div id="wamc-bulk-overlay" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.6);z-index:99999;overflow-y:auto">
            <div style="position:relative;margin:50px auto;background:#fff;padding:22px 24px;width:480px;max-width:94%;border-radius:12px;box-shadow:0 12px 45px rgba(0,0,0,.3);direction:rtl;text-align:right">
                <h3 style="margin-top:0;margin-bottom:12px;font-size:17px">📢 إرسال عرض ترويجي</h3>

                <div style="margin-bottom:10px">
                    <label style="font-weight:600;font-size:13px;display:block;margin-bottom:4px">اسم الحملة:</label>
                    <input type="text" id="wamc-bulk-campaign" value="promo_<?php echo esc_attr( wp_date( 'Y_m_d_Hi' ) ); ?>" style="width:100%" />
                </div>

                <div style="margin-bottom:6px;font-size:12px;color:#555">
                    المتغيرات: <code>{name}</code> <code>{products}</code> <code>{order_id}</code> <code>{total}</code>
                </div>

                <div style="margin-bottom:12px">
                    <textarea id="wamc-bulk-msg1" rows="3" style="width:100%;margin-bottom:8px"
                              placeholder="الرسالة الأولى (مثال: مرحباً {name}، خصم 20% على {products})..."></textarea>
                    <textarea id="wamc-bulk-msg2" rows="2" style="width:100%"
                              placeholder="الرسالة الثانية (اختياري للتنويع)..."></textarea>
                </div>

                <div style="background:#f9fafb;border:1px solid #e5e7eb;padding:10px;border-radius:8px;margin-bottom:14px">
                    <div style="display:flex;gap:8px;margin-bottom:8px">
                        <div style="flex:2">
                            <label style="font-size:12px;font-weight:600;display:block;margin-bottom:3px">اسم القالب (Marketing):</label>
                            <input type="text" id="wamc-bulk-tpl" value="<?php echo $default_tpl; ?>" style="width:100%" placeholder="special_offer_ar" />
                        </div>
                        <div style="flex:1">
                            <label style="font-size:12px;font-weight:600;display:block;margin-bottom:3px">اللغة:</label>
                            <input type="text" id="wamc-bulk-lang" value="<?php echo $default_lang; ?>" style="width:100%" placeholder="ar" />
                        </div>
                    </div>
                    <div>
                        <label style="font-size:12px;font-weight:600;display:block;margin-bottom:3px">متغيرات القالب بالترتيب:</label>
                        <input type="text" id="wamc-bulk-vars" value="{name}, {offer}" style="width:100%;direction:ltr;text-align:left" placeholder="{name}, {offer}" />
                    </div>
                </div>

                <div style="display:flex;justify-content:space-between;align-items:center;gap:10px">
                    <button type="button" id="wamc-bulk-send" class="button button-primary button-large" style="flex:1">
                        🚀 جدولة في الكرون (WP-Cron)
                    </button>
                    <button type="button" id="wamc-bulk-close" class="button button-large" style="flex:0 0 auto">
                        إلغاء
                    </button>
                </div>
                <div id="wamc-bulk-spinner" style="display:none;margin-top:10px;text-align:center">
                    <img src="<?php echo admin_url( 'images/spinner.gif' ); ?>" alt="..." />
                </div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($){
            var $btn = $('#wamc-bulk-open').show();
            $('.tablenav.top .bulkactions').after($btn);

            function getSelectedOrderIds(){
                var ids = [];
                $('input[name="post[]"]:checked, input[name="id[]"]:checked').each(function(){
                    var v = parseInt($(this).val(), 10);
                    if(v > 0) ids.push(v);
                });
                return ids;
            }

            $btn.on('click', function(){
                var ids = getSelectedOrderIds();
                if(ids.length < 1){ alert('يرجى تحديد طلب واحد على الأقل من الجدول.'); return; }
                $('#wamc-bulk-overlay').fadeIn(200);
            });

            $('#wamc-bulk-close').on('click', function(){
                $('#wamc-bulk-overlay').fadeOut(200);
            });

            $('#wamc-bulk-send').on('click', function(){
                var msg1     = $('#wamc-bulk-msg1').val().trim();
                var msg2     = $('#wamc-bulk-msg2').val().trim() || msg1;
                var tpl      = $('#wamc-bulk-tpl').val().trim();
                var lang     = $('#wamc-bulk-lang').val().trim() || 'ar';
                var vars     = $('#wamc-bulk-vars').val().trim() || '{offer}';
                var campaign = $('#wamc-bulk-campaign').val().trim() || 'promo_default';

                if(!msg1){ alert('يرجى كتابة نص العرض الترويجي.'); return; }
                if(!tpl){ alert('يرجى إدخال اسم القالب المعتمد (Marketing Template).'); return; }

                var ids = getSelectedOrderIds();
                if(ids.length < 1){ alert('يرجى تحديد طلب واحد على الأقل.'); return; }

                $('#wamc-bulk-spinner').show();
                $('#wamc-bulk-send').prop('disabled', true);

                $.post(ajaxurl, {
                    action: 'wamc_bulk_schedule',
                    _ajax_nonce: '<?php echo esc_js( $nonce ); ?>',
                    post_ids: ids,
                    msg1: msg1,
                    msg2: msg2,
                    tpl: tpl,
                    lang: lang,
                    vars: vars,
                    campaign: campaign
                }, function(r){
                    $('#wamc-bulk-spinner').hide();
                    $('#wamc-bulk-send').prop('disabled', false);
                    if(r && r.success){
                        alert('✓ ' + r.data.message);
                        $('#wamc-bulk-overlay').fadeOut(200);
                        $('input[name="post[]"]:checked, input[name="id[]"]:checked').prop('checked', false);
                    } else {
                        alert('✗ ' + (r && r.data ? r.data.message : 'حدث خطأ'));
                    }
                }).fail(function(){
                    $('#wamc-bulk-spinner').hide();
                    $('#wamc-bulk-send').prop('disabled', false);
                    alert('حدث خطأ في الاتصال.');
                });
            });
        });
        </script>
        <?php
    }

    // ── AJAX: Send One Order Immediately (Zero Server Sleep, Queue Driven by JS) ──

    public static function ajax_send_one(): void {
        if ( session_status() === PHP_SESSION_ACTIVE ) {
            session_write_close();
        }
        check_ajax_referer( 'wamc_nonce', '_ajax_nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ] );
        }

        $order_id = intval( $_POST['order_id'] ?? 0 );
        $index    = intval( $_POST['index'] ?? 0 );
        $msg1     = sanitize_textarea_field( $_POST['msg1'] ?? '' );
        $msg2     = sanitize_textarea_field( $_POST['msg2'] ?? '' ) ?: $msg1;
        $tpl      = sanitize_key( $_POST['tpl'] ?? wamc_setting( 'template_name', '' ) );
        $lang     = sanitize_text_field( $_POST['lang'] ?? wamc_setting( 'template_lang', 'ar' ) ) ?: 'ar';
        $vars_raw = sanitize_text_field( $_POST['vars'] ?? '{offer}' );
        $campaign = sanitize_key( $_POST['campaign'] ?? 'promo_default' );

        if ( ! $order_id || empty( $msg1 ) || empty( $tpl ) ) {
            wp_send_json_error( [ 'message' => 'بيانات ناقصة' ] );
        }

        $job_id = 'wamc_promo_' . $order_id . '_' . wp_generate_password( 6, false );
        set_transient( $job_id, [
            'order_id' => $order_id,
            'index'    => $index,
            'msg1'     => $msg1,
            'msg2'     => $msg2,
            'tpl'      => $tpl,
            'lang'     => $lang,
            'vars'     => $vars_raw,
            'campaign' => $campaign,
        ], HOUR_IN_SECONDS );

        $res = self::process_single_promo( $order_id, $job_id );
        if ( ! empty( $res['success'] ) ) {
            wp_send_json_success( $res );
        } elseif ( ! empty( $res['skipped'] ) ) {
            wp_send_json_success( [ 'skipped' => true, 'reason' => $res['error'] ?? '' ] );
        } else {
            wp_send_json_error( [ 'message' => $res['error'] ?? 'فشل الإرسال' ] );
        }
    }

    // ── AJAX: Schedule Staggered Non-Blocking Jobs ─────────────────────────────

    public static function ajax_schedule(): void {
        check_ajax_referer( 'wamc_nonce', '_ajax_nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ] );
        }

        $order_ids = array_map( 'intval', (array) ( $_POST['post_ids'] ?? [] ) );
        $msg1      = sanitize_textarea_field( $_POST['msg1'] ?? '' );
        $msg2      = sanitize_textarea_field( $_POST['msg2'] ?? '' ) ?: $msg1;
        $tpl       = sanitize_key( $_POST['tpl'] ?? wamc_setting( 'template_name', '' ) );
        $lang      = sanitize_text_field( $_POST['lang'] ?? wamc_setting( 'template_lang', 'ar' ) ) ?: 'ar';
        $vars_raw  = sanitize_text_field( $_POST['vars'] ?? '{offer}' );
        $campaign  = sanitize_key( $_POST['campaign'] ?? 'promo_' . wp_date( 'Y_m_d' ) );

        if ( empty( $order_ids ) || empty( $msg1 ) || empty( $tpl ) ) {
            wp_send_json_error( [ 'message' => 'يرجى إدخال نص العرض واسم القالب المعتمد.' ] );
        }

        $delay_min = max( 5, (int) wamc_setting( 'delay_min', 15 ) );
        $delay_max = max( $delay_min, (int) wamc_setting( 'delay_max', 45 ) );

        $seen_phones      = [];
        $scheduled_cnt    = 0;
        $sent_immediately = 0;
        $first_error      = '';
        $skipped_dup      = 0;
        $skipped_sent     = 0;
        $cumulative_delay = 0;

        foreach ( $order_ids as $order_id ) {
            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                continue;
            }

            $raw_phone = (string) $order->get_billing_phone();
            $phone_key = WAMC_Order_Lookup::phone_key( $raw_phone );
            if ( ! $phone_key ) {
                continue;
            }

            // 1. Deduplicate phone within the same bulk selection
            if ( isset( $seen_phones[ $phone_key ] ) ) {
                $skipped_dup++;
                continue;
            }
            $seen_phones[ $phone_key ] = true;

            // 2. Skip if this customer already received THIS specific campaign
            $sent_campaigns = (array) $order->get_meta( '_wamc_promo_campaigns', true );
            if ( in_array( $campaign, $sent_campaigns, true ) ) {
                $skipped_sent++;
                continue;
            }

            $job_data = [
                'order_id' => $order_id,
                'index'    => $scheduled_cnt,
                'msg1'     => $msg1,
                'msg2'     => $msg2,
                'tpl'      => $tpl,
                'lang'     => $lang,
                'vars'     => $vars_raw,
                'campaign' => $campaign,
            ];

            $job_id = 'wamc_promo_' . $order_id . '_' . wp_generate_password( 6, false );
            set_transient( $job_id, $job_data, DAY_IN_SECONDS );

            if ( $scheduled_cnt === 0 ) {
                $exec_res = self::process_single_promo( $order_id, $job_id );
                if ( is_array( $exec_res ) && ! empty( $exec_res['success'] ) ) {
                    $sent_immediately++;
                } elseif ( is_array( $exec_res ) && ! empty( $exec_res['error'] ) ) {
                    $first_error = is_array( $exec_res['error'] )
                        ? ( $exec_res['error']['message'] ?? wp_json_encode( $exec_res['error'] ) )
                        : (string) $exec_res['error'];
                }
            } else {
                $cumulative_delay += rand( $delay_min, $delay_max );
                $run_at = time() + $cumulative_delay;
                wp_schedule_single_event( $run_at, 'wamc_send_single_promo', [ $order_id, $job_id ] );
            }

            $scheduled_cnt++;
        }

        if ( $scheduled_cnt === 0 ) {
            $reason = 'لم يتم إرسال أي رسالة: ';
            if ( $skipped_sent > 0 ) {
                $reason .= sprintf( 'الطلبات المحددة (%d) استلمت نفس اسم الحملة (%s) مسبقاً! غيّر "اسم الحملة" في الأعلى لإرسال عرض جديد.', $skipped_sent, $campaign );
            } else {
                $reason .= 'لا توجد أرقام هواتف صالحة في الطلبات المحددة.';
            }
            wp_send_json_error( [ 'message' => $reason ] );
        }

        if ( $scheduled_cnt === 1 && $first_error !== '' ) {
            wp_send_json_error( [ 'message' => 'فشل الإرسال (تم تسجيل السبب في السجل): ' . $first_error ] );
        }

        $bg_count = max( 0, $scheduled_cnt - 1 );
        $summary  = sprintf(
            'تم إرسال أول رسالة فوراً بنجاح ✅، وجدولة %d رسالة متبقية في الخلفية.',
            $bg_count
        );
        if ( $first_error !== '' ) {
            $summary = sprintf( '⚠️ تنبيه في الرسالة الأولى (%s) — وتم جدولة %d رسالة في الخلفية.', $first_error, $bg_count );
        }
        if ( $skipped_dup > 0 || $skipped_sent > 0 ) {
            $summary .= sprintf( ' (تم تخطي %d رقم مكرر و %d طلب استلم هذه الحملة مسبقاً).', $skipped_dup, $skipped_sent );
        }

        wp_send_json_success( [ 'message' => $summary ] );
    }

    // ── Background Worker: Send Single Promo (Zero sleep(), < 1s execution) ────

    public static function process_single_promo( int $order_id, string $job_id ) {
        $job = get_transient( $job_id );
        if ( ! is_array( $job ) ) {
            return [ 'success' => false, 'error' => 'Job transient expired or missing' ];
        }
        delete_transient( $job_id );

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return [ 'success' => false, 'error' => 'Order not found' ];
        }

        $campaign       = (string) ( $job['campaign'] ?? 'promo_default' );
        $sent_campaigns = (array) $order->get_meta( '_wamc_promo_campaigns', true );
        if ( in_array( $campaign, $sent_campaigns, true ) ) {
            return [ 'success' => false, 'error' => 'Campaign already sent to this order' ];
        }

        $phone = (string) $order->get_billing_phone();

        // Anti-Ban: check daily limit, cooldown, and opt-out/blacklist
        if ( class_exists( 'WAMC_Rate_Limiter' ) ) {
            $allowed = WAMC_Rate_Limiter::check( $phone );
            if ( is_wp_error( $allowed ) ) {
                $err_msg = $allowed->get_error_message();
                $order->add_order_note( '[WA Meta Promo] تم تخطي العرض (' . $campaign . '): ' . $err_msg );
                WAMC_Message_Log::add( 'sent', [
                    'dir'               => 'sent',
                    'id'                => '',
                    'to'                => $phone,
                    'text'              => '[حملة ' . $campaign . ']',
                    'status'            => 'skipped',
                    'notification_type' => 'bulk_promo',
                    'order_id'          => $order_id,
                    'window_status'     => WAMC_Window_Tracker::status_label( $phone ),
                    'msg_type'          => 'skipped',
                    'reason'            => 'Anti-Ban Rule: ' . $err_msg,
                    'error'             => $err_msg,
                ] );
                return [ 'success' => false, 'error' => $err_msg ];
            }
        }

        $customer_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
        $products      = [];
        foreach ( $order->get_items() as $item ) {
            $products[] = strip_tags( $item->get_name() );
        }
        $product_str = implode( ' - ', $products );
        $total_str   = $order->get_total() . ' ' . $order->get_currency();

        $index    = (int) ( $job['index'] ?? 0 );
        $raw_offer = ( $index % 2 === 0 ) ? (string) $job['msg1'] : (string) $job['msg2'];

        $replacements = [
            '{name}'     => $customer_name ?: 'عميلنا العزيز',
            '{order_id}' => (string) $order_id,
            '{products}' => $product_str ?: 'منتجاتنا',
            '{total}'    => $total_str,
        ];

        $offer_text = strtr( $raw_offer, $replacements );
        $replacements['{offer}'] = $offer_text;

        // Build ordered template parameters from user's comma-separated variable spec
        $vars_spec  = array_filter( array_map( 'trim', explode( ',', (string) ( $job['vars'] ?? '{offer}' ) ) ) );
        $tpl_params = [];
        foreach ( $vars_spec as $token ) {
            $tpl_params[] = strtr( $token, $replacements );
        }
        if ( empty( $tpl_params ) ) {
            $tpl_params = [ $offer_text ];
        }

        $tpl_name = (string) ( $job['tpl'] ?? wamc_setting( 'template_name', '' ) );
        $tpl_lang = (string) ( $job['lang'] ?? wamc_setting( 'template_lang', 'ar' ) );

        // Interactive Opt-Out button when 24h window is OPEN (protects number quality rating)
        $interactive_buttons = [
            'wamc_optout:' . $order_id => 'إيقاف العروض',
        ];

        $result = WAMC_Dispatcher::send(
            $phone,
            'bulk_promo',
            $offer_text,
            $tpl_name,
            $tpl_lang,
            $tpl_params,
            $order_id,
            [],
            $interactive_buttons
        );

        if ( class_exists( 'WAMC_Rate_Limiter' ) ) {
            $success = ! empty( $result['success'] );
            $status  = $result['result']['status'] ?? $result['data']['status'] ?? ( $success ? 'sent' : 'failed' );
            WAMC_Rate_Limiter::record_send( $phone, $success, $status );
        }

        if ( ! empty( $result['success'] ) ) {
            $sent_campaigns[] = $campaign;
            $order->update_meta_data( '_wamc_promo_campaigns', array_values( array_unique( $sent_campaigns ) ) );
            $order->update_meta_data( '_wamc_promo_sent', true );
            $order->save();

            WAMC_Cache::append_order_message( $order_id, [
                'type'      => 'sent',
                'message'   => '[حملة ' . $campaign . '] ' . $offer_text,
                'timestamp' => current_time( 'mysql' ),
            ] );
            WAMC_Cache::flush_order( $order_id );
        }

        return $result;
    }

    // ── Legacy Batch Fallback ──────────────────────────────────────────────────

    public static function process_batch( string $batch_id ): void {
        $batch = get_transient( $batch_id );
        if ( ! $batch ) {
            return;
        }
        delete_transient( $batch_id );
    }

    // ── AJAX: Remove number from blacklist ────────────────────────────────────

    public static function ajax_remove_blacklist(): void {
        check_ajax_referer( 'wamc_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error();
        }

        $phone = sanitize_text_field( $_POST['phone'] ?? '' );
        if ( $phone && class_exists( 'WAMC_Rate_Limiter' ) ) {
            WAMC_Rate_Limiter::remove_from_blacklist( $phone );
        }
        wp_send_json_success();
    }
}

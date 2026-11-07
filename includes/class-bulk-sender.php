<?php
/**
 * Bulk Message Sender
 *
 * - Button on WooCommerce orders list to send to selected orders
 * - Schedules WP-Cron batch job to avoid timeout
 * - Bug-fixed: $message_counter now initialized before loop
 */

defined( 'ABSPATH' ) || exit;

class WAMC_Bulk_Send {

    public static function init(): void {
        add_action( 'admin_footer',                        [ __CLASS__, 'render_overlay' ] );
        add_action( 'wp_ajax_wamc_bulk_schedule',          [ __CLASS__, 'ajax_schedule' ] );
        add_action( 'process_wamc_bulk_batch',             [ __CLASS__, 'process_batch' ] );
        add_action( 'wp_ajax_wamc_remove_blacklist',       [ __CLASS__, 'ajax_remove_blacklist' ] );
    }

    // ── Orders List Overlay ───────────────────────────────────────────────────

    public static function render_overlay(): void {
        $screen = get_current_screen();
        if ( ! $screen || $screen->id !== 'edit-shop_order' ) {
            return;
        }
        ?>
        <!-- Trigger button (injected by JS next to bulk actions) -->
        <button id="wamc-bulk-open" type="button" class="button button-primary" style="display:none;margin-right:6px">
            📢 <?php esc_html_e( 'إرسال رسالة للطلبات المحددة', 'wa-meta-control' ); ?>
        </button>

        <!-- Overlay modal -->
        <div id="wamc-bulk-overlay" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.55);z-index:99999">
            <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;padding:28px;width:460px;border-radius:10px;box-shadow:0 8px 40px rgba(0,0,0,.3)">
                <h3 style="margin-top:0">📢 <?php esc_html_e( 'إرسال رسالة واتساب', 'wa-meta-control' ); ?></h3>
                <p style="color:#666;font-size:13px;margin-bottom:16px">
                    <?php esc_html_e( 'سيتم إرسال الرسالتين بالتناوب على الطلبات المحددة.', 'wa-meta-control' ); ?>
                </p>
                <textarea id="wamc-bulk-msg1" rows="3" style="width:100%;margin-bottom:10px"
                          placeholder="<?php esc_attr_e( 'الرسالة الأولى...', 'wa-meta-control' ); ?>"></textarea>
                <textarea id="wamc-bulk-msg2" rows="3" style="width:100%;margin-bottom:16px"
                          placeholder="<?php esc_attr_e( 'الرسالة الثانية...', 'wa-meta-control' ); ?>"></textarea>
                <div style="display:flex;justify-content:space-between;align-items:center;gap:10px">
                    <button type="button" id="wamc-bulk-send" class="button button-primary button-large" style="flex:1">
                        ➤ <?php esc_html_e( 'جدولة الإرسال', 'wa-meta-control' ); ?>
                    </button>
                    <button type="button" id="wamc-bulk-close" class="button" style="flex:0 0 auto">
                        <?php esc_html_e( 'إلغاء', 'wa-meta-control' ); ?>
                    </button>
                </div>
                <div id="wamc-bulk-spinner" style="display:none;margin-top:12px;text-align:center">
                    <img src="<?php echo admin_url( 'images/spinner.gif' ); ?>" alt="..." />
                    <p><?php esc_html_e( 'جاري الجدولة...', 'wa-meta-control' ); ?></p>
                </div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($){
            // Inject button into bulk-actions bar
            var $btn = $('#wamc-bulk-open').show();
            $('.tablenav.top .bulkactions').after($btn);

            // Open modal
            $btn.on('click', function(){
                var selected = $('input[name="post[]"]:checked').length;
                if(selected < 1){ alert('يرجى اختيار طلب واحد على الأقل.'); return; }
                $('#wamc-bulk-overlay').fadeIn(200);
            });

            // Close modal
            $('#wamc-bulk-close').on('click', function(){
                $('#wamc-bulk-overlay').fadeOut(200);
            });

            // Send
            $('#wamc-bulk-send').on('click', function(){
                var msg1 = $('#wamc-bulk-msg1').val().trim();
                var msg2 = $('#wamc-bulk-msg2').val().trim();
                if(!msg1 || !msg2){ alert('الرجاء إدخال كلتا الرسالتين.'); return; }

                var ids = [];
                $('input[name="post[]"]:checked').each(function(){ ids.push($(this).val()); });

                $('#wamc-bulk-spinner').show();
                $('#wamc-bulk-send').prop('disabled', true);

                $.post(ajaxurl, {
                    action: 'wamc_bulk_schedule',
                    post_ids: ids,
                    msg1: msg1,
                    msg2: msg2
                }, function(r){
                    $('#wamc-bulk-spinner').hide();
                    $('#wamc-bulk-send').prop('disabled', false);
                    if(r.success){
                        alert('✓ ' + r.data.message);
                        $('#wamc-bulk-overlay').fadeOut(200);
                        $('input[name="post[]"]:checked').prop('checked', false);
                    } else {
                        alert('✗ ' + (r.data ? r.data.message : 'حدث خطأ'));
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

    // ── AJAX: Schedule Batch ──────────────────────────────────────────────────

    public static function ajax_schedule(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ] );
        }

        $order_ids = array_map( 'intval', (array) ( $_POST['post_ids'] ?? [] ) );
        $msg1      = sanitize_textarea_field( $_POST['msg1'] ?? '' );
        $msg2      = sanitize_textarea_field( $_POST['msg2'] ?? '' );

        if ( empty( $order_ids ) || empty( $msg1 ) || empty( $msg2 ) ) {
            wp_send_json_error( [ 'message' => 'بيانات ناقصة' ] );
        }

        $batch_id = 'wamc_batch_' . uniqid();
        set_transient( $batch_id, [
            'order_ids' => $order_ids,
            'msg1'      => $msg1,
            'msg2'      => $msg2,
        ], HOUR_IN_SECONDS );

        if ( ! wp_next_scheduled( 'process_wamc_bulk_batch', [ $batch_id ] ) ) {
            wp_schedule_single_event( time(), 'process_wamc_bulk_batch', [ $batch_id ] );
        }

        wp_send_json_success( [ 'message' => 'تم جدولة الإرسال لـ ' . count( $order_ids ) . ' طلب' ] );
    }

    // ── Cron: Process Batch ────────────────────────────────────────────────────

    public static function process_batch( string $batch_id ): void {
        $batch = get_transient( $batch_id );
        if ( ! $batch ) {
            return;
        }

        $order_ids = $batch['order_ids'];
        $msg1      = $batch['msg1'];
        $msg2      = $batch['msg2'];

        $message_counter = 0; // BUG-FIX: was undefined in original plugin
        $is_first        = true; // no delay before the very first message

        foreach ( $order_ids as $order_id ) {
            $order = WAMC_Cache::get_order( $order_id );
            if ( ! $order ) {
                continue;
            }

            // Skip if already sent a promo
            if ( $order->get_meta( '_wamc_promo_sent', true ) ) {
                continue;
            }

            $phone         = $order->get_billing_phone();

            // ── Anti-Ban: check all rules before sending ──────────────────────
            if ( class_exists( 'WAMC_Rate_Limiter' ) ) {
                $allowed = WAMC_Rate_Limiter::check( $phone );
                if ( is_wp_error( $allowed ) ) {
                    // Log skip reason as order note
                    $order->add_order_note(
                        '[WA Meta] تم تخطي الإرسال: ' . $allowed->get_error_message()
                    );
                    $message_counter++;
                    continue;
                }

                // Apply random delay between messages (not before the first)
                if ( ! $is_first ) {
                    WAMC_Rate_Limiter::apply_delay();
                }
                $is_first = false;
            }

            $customer_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

            // Build product list
            $products = [];
            foreach ( $order->get_items() as $item ) {
                $products[] = strip_tags( $item->get_name() );
            }
            $product_str = implode( ' - ', $products );

            // Alternate between message 1 and 2
            $base_msg = ( $message_counter % 2 === 0 ) ? $msg1 : $msg2;
            $full_msg = "مرحباً $customer_name\nبنكلمك بخصوص طلبك لمنتج $product_str رقم $order_id\n$base_msg";

            $tpl      = (string) wamc_setting( 'template_name', '' );
            $tpl_lang = (string) wamc_setting( 'template_lang', 'ar' );

            $result = WAMC_Dispatcher::send(
                $phone,
                'bulk_promo',
                $full_msg,
                $tpl,
                $tpl_lang,
                [ $full_msg ],
                $order_id
            );

            // ── Anti-Ban: record send result ──────────────────────────────────
            if ( class_exists( 'WAMC_Rate_Limiter' ) ) {
                $success = ! empty( $result['success'] );
                $status  = $result['result']['status'] ?? $result['data']['status'] ?? ( $success ? 'sent' : 'failed' );
                WAMC_Rate_Limiter::record_send( $phone, $success, $status );
            }

            if ( ! empty( $result['success'] ) ) {
                $order->update_meta_data( '_wamc_promo_sent', true );
                $order->save();

                WAMC_Cache::append_order_message( $order_id, [
                    'type'      => 'sent',
                    'message'   => $full_msg,
                    'timestamp' => current_time( 'mysql' ),
                ] );
                WAMC_Cache::flush_order( $order_id ); // mark order dirty after save
            }

            $message_counter++;
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
        if ( ! empty( $phone ) && class_exists( 'WAMC_Rate_Limiter' ) ) {
            WAMC_Rate_Limiter::remove_from_blacklist( $phone );
        }

        wp_send_json_success();
    }
}

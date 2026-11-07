<?php
/**
 * QP / Shipping Company Notifications
 *
 * - Adds a meta box to the order editor to send a message to QP/shipping.
 * - Supports sending to phone numbers OR a WhatsApp group ID (configured in settings).
 */

defined( 'ABSPATH' ) || exit;

class WAMC_QP_Notify {

    public static function init(): void {
        add_action( 'add_meta_boxes',             [ __CLASS__, 'add_meta_box' ] );
        add_action( 'wp_ajax_wamc_qp_send',       [ __CLASS__, 'ajax_send' ] );
    }

    // ── Meta Box ──────────────────────────────────────────────────────────────

    public static function add_meta_box(): void {
        global $pagenow, $post;
        if ( $pagenow !== 'post.php' || ! isset( $post ) || $post->post_type !== 'shop_order' ) {
            return;
        }

        add_meta_box(
            'wamc_qp_meta_box',
            '🚚 ' . __( 'إرسال رسالة لشركة الشحن', 'wa-meta-control' ),
            [ __CLASS__, 'render_meta_box' ],
            'shop_order',
            'side',
            'high'
        );
    }

    public static function render_meta_box( WP_Post $post ): void {
        $order_id        = $post->ID;
        $order           = WAMC_Cache::get_order( $order_id );
        $qp_order_number = $order ? trim( (string) $order->get_meta( 'QpOrderNumber' ) ) : '';
        ?>
        <?php if ( $qp_order_number !== '' ) : ?>
            <div style="margin-bottom:8px;font-size:12px;color:#135e96;background:#f0f6fc;padding:4px 8px;border-radius:4px">
                🚚 <strong>بوليصة QP:</strong> <code><?php echo esc_html( $qp_order_number ); ?></code>
            </div>
        <?php else : ?>
            <div style="margin-bottom:8px;font-size:12px;color:#d63638;background:#fcf0f0;padding:4px 8px;border-radius:4px">
                ⚠️ <strong>غير مسجل بالشحن</strong> (يجب توفر QpOrderNumber للإرسال)
            </div>
        <?php endif; ?>
        <textarea id="wamc-qp-text" rows="4" style="width:100%;margin-bottom:8px"
                  placeholder="<?php esc_attr_e( 'اكتب رسالتك للشحن...', 'wa-meta-control' ); ?>"></textarea>
        <button type="button" id="wamc-qp-send" class="button button-primary" style="width:100%">
            ✉ <?php esc_html_e( 'إرسال', 'wa-meta-control' ); ?>
        </button>
        <div id="wamc-qp-spinner" style="display:none;text-align:center;margin-top:8px">
            <img src="<?php echo admin_url( 'images/spinner.gif' ); ?>" alt="..." />
        </div>
        <div id="wamc-qp-status" style="margin-top:8px;font-weight:bold"></div>

        <script>
        (function($){
            $('#wamc-qp-send').on('click', function(){
                var msg = $('#wamc-qp-text').val().trim();
                if(!msg){ $('#wamc-qp-status').text('لا يمكن ترك الحقل فارغاً').css('color','#e00'); return; }
                $('#wamc-qp-spinner').show();
                $(this).prop('disabled', true);
                $.post(ajaxurl, {
                    action: 'wamc_qp_send',
                    order_id: <?php echo $order_id; ?>,
                    message: msg
                }, function(r){
                    $('#wamc-qp-spinner').hide();
                    $('#wamc-qp-send').prop('disabled', false);
                    $('#wamc-qp-status').text(r.success ? '✓ ' + r.data.message : '✗ ' + r.data.message)
                        .css('color', r.success ? '#25D366' : '#e00');
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    // ── AJAX Send ─────────────────────────────────────────────────────────────

    public static function ajax_send(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ] );
        }

        $order_id = intval( $_POST['order_id'] ?? 0 );
        $message  = sanitize_textarea_field( $_POST['message'] ?? '' );

        if ( ! $order_id || empty( $message ) ) {
            wp_send_json_error( [ 'message' => 'بيانات ناقصة' ] );
        }

        $order = WAMC_Cache::get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( [ 'message' => 'الطلب غير موجود' ] );
        }

        // التحقق الإلزامي مسبقاً: الإرسال يتم فقط في حال وجود رقم بوليصة الشحن QpOrderNumber
        $qp_order_number = trim( (string) $order->get_meta( 'QpOrderNumber' ) );
        if ( empty( $qp_order_number ) ) {
            wp_send_json_error( [ 'message' => 'لم يتم الإرسال: الطلب غير مسجل بشركة الشحن (لا يوجد رقم بوليصة QpOrderNumber)' ] );
        }

        // Build full message with order details in a clean, professional layout
        $wc_order_number = $order->get_order_number();
        $customer_name   = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
        $customer_phone  = $order->get_billing_phone();
        $total           = html_entity_decode( wp_strip_all_tags( $order->get_formatted_order_total() ), ENT_QUOTES, 'UTF-8' );
        $user_name       = wp_get_current_user()->display_name;

        $lines = [];
        $lines[] = '📦 رقم الطلب بالمتجر: #' . $wc_order_number;
        if ( $qp_order_number !== '' ) {
            $lines[] = '🚚 بوليصة شحن QP: ' . $qp_order_number;
        }
        if ( $customer_name !== '' ) {
            $lines[] = '👤 اسم العميل: ' . $customer_name;
        }
        if ( $customer_phone !== '' ) {
            $lines[] = '📱 هاتف العميل: ' . $customer_phone;
        }
        if ( $total !== '' ) {
            $lines[] = '💵 إجمالي الطلب: ' . $total;
        }
        if ( $user_name !== '' ) {
            $lines[] = '✍️ أُرسلت بواسطة: ' . $user_name;
        }

        $full_message = trim( $message ) . "\n\n━━━━━━━━━━━━━━━━\n" . implode( "\n", $lines );

        // Send via phone numbers OR group — based on settings
        $mode        = wamc_setting( 'qp_notify_mode', 'phone' );
        $qp_tpl      = (string) wamc_setting( 'qp_template', '' );
        $qp_tpl_lang = (string) wamc_setting( 'qp_template_lang', 'ar' );
        $qp_tpl_vars = array_values( array_filter( array_map( 'trim', explode( ',', (string) wamc_setting( 'qp_template_vars', '' ) ) ), 'strlen' ) );
        $tpl_params  = ! empty( $qp_tpl_vars ) ? $qp_tpl_vars : [ $full_message ];

        $result = self::dispatch( $mode, 'qp', $full_message, $qp_tpl, $qp_tpl_lang, $tpl_params, $order_id );

        if ( ! empty( $result['error'] ) || ( isset( $result['success'] ) && ! $result['success'] ) ) {
            wp_send_json_error( [ 'message' => $result['error'] ?? 'فشل الإرسال' ] );
        }

        $order->add_order_note( "تم الإرسال لشركة الشحن:\n\n" . $full_message );
        wp_send_json_success( [ 'message' => 'تم الإرسال بنجاح' ] );
    }

    /**
     * Dispatch message to phone numbers or group, depending on mode.
     *
     * @param string $mode   'phone' | 'group'
     * @param string $prefix Setting prefix ('qp' or 'admin')
     * @param string $text   Message text
     */
    /**
     * Dispatch to phone numbers or group.
     *
     * For phone mode, routes through WAMC_Dispatcher (auto-selects text vs template).
     * For group mode, always sends plain text (groups have no 24h window).
     *
     * @param string   $mode        'phone' | 'group'
     * @param string   $prefix      Setting prefix ('qp' or 'admin')
     * @param string   $text        Plain text message
     * @param string   $tpl         Template name (required for dispatcher)
     * @param string   $tpl_lang    Template language
     * @param array    $tpl_params  Ordered template variable values
     * @param int|null $order_id    WooCommerce order ID for logging
     */
    public static function dispatch( string $mode, string $prefix, string $text, string $tpl = '', string $tpl_lang = 'ar', array $tpl_params = [], ?int $order_id = null ): array {
        if ( $mode === 'group' ) {
            $group_id = wamc_setting( $prefix . '_group_id', '' );
            if ( empty( $group_id ) ) {
                return [ 'error' => 'Group ID not configured.' ];
            }
            // Groups always use free text (no window concept)
            return wamc_api()->send_group_message( $group_id, $text );
        }

        // Phone mode: parse one per line
        $phones_raw = wamc_setting( $prefix . '_phones', '' );
        $phones     = array_filter( array_map( 'trim', explode( "\n", $phones_raw ) ) );

        if ( empty( $phones ) ) {
            return [ 'error' => 'No phone numbers configured.' ];
        }

        // Anti-ban: check daily limit + blacklist, but SKIP cooldown for QP/admin targets
        if ( class_exists( 'WAMC_Rate_Limiter' ) ) {
            $allowed = WAMC_Rate_Limiter::check( $phones, skip_cooldown: true );
            if ( is_wp_error( $allowed ) ) {
                return [ 'error' => $allowed->get_error_message() ];
            }
        }

        // Route each phone through the central dispatcher
        $last_result = [ 'success' => false, 'error' => 'No phones.' ];
        foreach ( $phones as $phone ) {
            $last_result = WAMC_Dispatcher::send(
                $phone,
                $prefix . '_notification',
                $text,
                $tpl,
                $tpl_lang,
                $tpl_params,
                $order_id
            );
        }
        return $last_result;
    }
}

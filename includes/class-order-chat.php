<?php
/**
 * WhatsApp CRM — Per-Order Chat Meta Box
 *
 * - DB table for unread notification badges
 * - WhatsApp-style conversation view in the order editor
 * - AJAX message sending via API v2
 * - Real-time polling for new incoming messages
 * - Notification popup on orders list page
 */

defined( 'ABSPATH' ) || exit;

class WAMC_CRM {

    // ── Bootstrap ─────────────────────────────────────────────────────────────

    public static function init(): void {
        add_action( 'add_meta_boxes',     [ __CLASS__, 'add_meta_box' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
        add_action( 'admin_footer',       [ __CLASS__, 'render_notification_popup' ] );
        add_action( 'admin_init',         [ __CLASS__, 'mark_read_on_order_edit' ] );

        add_action( 'wp_ajax_wamc_crm_check_notifications', [ __CLASS__, 'ajax_check_notifications' ] );
        add_action( 'wp_ajax_wamc_crm_delete_notification', [ __CLASS__, 'ajax_delete_notification' ] );
        add_action( 'wp_ajax_wamc_crm_refresh_messages',    [ __CLASS__, 'ajax_refresh_messages' ] );
        add_action( 'wp_ajax_wamc_crm_check_new_message',   [ __CLASS__, 'ajax_check_new_message' ] );
        add_action( 'wp_ajax_wamc_crm_send_message',        [ __CLASS__, 'ajax_send_message' ] );
    }

    // ── DB Table ──────────────────────────────────────────────────────────────

    public static function create_table(): void {
        global $wpdb;
        $table_name      = $wpdb->prefix . 'wamc_notifications';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            order_id bigint(20) NOT NULL,
            message text NOT NULL DEFAULT '',
            status varchar(20) NOT NULL DEFAULT 'unread',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY order_id (order_id),
            KEY status (status)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    // ── Meta Box ──────────────────────────────────────────────────────────────

    public static function add_meta_box(): void {
        global $pagenow, $post;
        if ( $pagenow !== 'post.php' || ! isset( $post ) || $post->post_type !== 'shop_order' ) {
            return;
        }

        $wa_icon = '<svg xmlns="http://www.w3.org/2000/svg" height="22" width="22" viewBox="0 0 448 512" style="vertical-align:middle;margin-left:6px"><path fill="#25D366" d="M380.9 97.1C339 55.1 283.2 32 223.9 32c-122.4 0-222 99.6-222 222 0 39.1 10.2 77.3 29.6 111L0 480l117.7-30.9c32.4 17.7 68.9 27 106.1 27h.1c122.3 0 224.1-99.6 224.1-222 0-59.3-25.2-115-67.1-157zm-157 341.6c-33.2 0-65.7-8.9-94-25.7l-6.7-4-69.8 18.3L72 359.2l-4.4-7c-18.5-29.4-28.2-63.3-28.2-98.2 0-101.7 82.8-184.5 184.6-184.5 49.3 0 95.6 19.2 130.4 54.1 34.8 34.9 56.2 81.2 56.1 130.5 0 101.8-84.9 184.6-186.6 184.6zm101.2-138.2c-5.5-2.8-32.8-16.2-37.9-18-5.1-1.9-8.8-2.8-12.5 2.8-3.7 5.6-14.3 18-17.6 21.8-3.2 3.7-6.5 4.2-12 1.4-32.6-16.3-54-29.1-75.5-66-5.7-9.8 5.7-9.1 16.3-30.3 1.8-3.7 .9-6.9-.5-9.7-1.4-2.8-12.5-30.1-17.1-41.2-4.5-10.8-9.1-9.3-12.5-9.5-3.2-.2-6.9-.2-10.6-.2-3.7 0-9.7 1.4-14.8 6.9-5.1 5.6-19.4 19-19.4 46.3 0 27.3 19.9 53.7 22.6 57.4 2.8 3.7 39.1 59.7 94.8 83.8 35.2 15.2 49 16.5 66.6 13.9 10.7-1.6 32.8-13.4 37.4-26.4 4.6-13 4.6-24.1 3.2-26.4-1.3-2.5-5-3.9-10.5-6.6z"/></svg>';

        add_meta_box(
            'wamc_crm_meta_box',
            $wa_icon . '<span style="margin-right:8px;font-size:17px">WhatsApp</span>',
            [ __CLASS__, 'render_meta_box' ],
            'shop_order',
            'normal',
            'high'
        );
    }

    // ── Enqueue ───────────────────────────────────────────────────────────────

    public static function enqueue( string $hook ): void {
        global $pagenow, $post_type;

        $is_order_list = ( $hook === 'edit.php' && isset( $_GET['post_type'] ) && $_GET['post_type'] === 'shop_order' );
        $is_order_edit = ( $pagenow === 'post.php' && isset( $_GET['action'] ) && $_GET['action'] === 'edit' );

        if ( ! $is_order_list && ! $is_order_edit ) {
            return;
        }

        wp_enqueue_script( 'jquery' );

        // Inline styles for the notification popup (orders list)
        if ( $is_order_list ) {
            wp_add_inline_style( 'wp-admin', self::popup_css() );
            wp_add_inline_script( 'jquery', self::popup_js() );
        }
    }

    // ── Notification Popup (orders list) ──────────────────────────────────────

    public static function render_notification_popup(): void {
        $screen = get_current_screen();
        if ( ! $screen || $screen->id !== 'edit-shop_order' ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'wamc_notifications';
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE status = 'unread'" );

        echo '<div class="wamc-notif-popup">';
        echo '<svg xmlns="http://www.w3.org/2000/svg" height="32" width="32" viewBox="0 0 448 512"><path fill="#fff" d="M380.9 97.1C339 55.1 283.2 32 223.9 32c-122.4 0-222 99.6-222 222 0 39.1 10.2 77.3 29.6 111L0 480l117.7-30.9c32.4 17.7 68.9 27 106.1 27h.1c122.3 0 224.1-99.6 224.1-222 0-59.3-25.2-115-67.1-157zm-157 341.6c-33.2 0-65.7-8.9-94-25.7l-6.7-4-69.8 18.3L72 359.2l-4.4-7c-18.5-29.4-28.2-63.3-28.2-98.2 0-101.7 82.8-184.5 184.6-184.5 49.3 0 95.6 19.2 130.4 54.1 34.8 34.9 56.2 81.2 56.1 130.5 0 101.8-84.9 184.6-186.6 184.6zm101.2-138.2c-5.5-2.8-32.8-16.2-37.9-18-5.1-1.9-8.8-2.8-12.5 2.8-3.7 5.6-14.3 18-17.6 21.8-3.2 3.7-6.5 4.2-12 1.4-32.6-16.3-54-29.1-75.5-66-5.7-9.8 5.7-9.1 16.3-30.3 1.8-3.7 .9-6.9-.5-9.7-1.4-2.8-12.5-30.1-17.1-41.2-4.5-10.8-9.1-9.3-12.5-9.5-3.2-.2-6.9-.2-10.6-.2-3.7 0-9.7 1.4-14.8 6.9-5.1 5.6-19.4 19-19.4 46.3 0 27.3 19.9 53.7 22.6 57.4 2.8 3.7 39.1 59.7 94.8 83.8 35.2 15.2 49 16.5 66.6 13.9 10.7-1.6 32.8-13.4 37.4-26.4 4.6-13 4.6-24.1 3.2-26.4-1.3-2.5-5-3.9-10.5-6.6z"/></svg>';
        if ( $count > 0 ) {
            echo "<span class='wamc-notif-badge'>$count</span>";
        }
        echo '</div>';
        echo '<div class="wamc-notif-list"></div>';
    }

    private static function popup_css(): string {
        return '
        .wamc-notif-popup {
            position:fixed; bottom:24px; left:24px; width:66px; height:66px;
            background:#25D366; border-radius:50%; cursor:pointer;
            display:flex; align-items:center; justify-content:center;
            z-index:99999; box-shadow:0 4px 20px rgba(0,0,0,.3);
            transition:transform .2s;
        }
        .wamc-notif-popup:hover { transform:scale(1.08); }
        .wamc-notif-badge {
            position:absolute; top:-6px; right:-6px;
            background:#e33; color:#fff; border-radius:50%;
            width:22px; height:22px; display:flex; align-items:center; justify-content:center;
            font-size:12px; font-weight:bold;
        }
        .wamc-notif-list {
            position:fixed; bottom:100px; left:18px; background:#fff;
            border:1px solid #ddd; padding:12px; width:310px; max-height:420px;
            overflow-y:auto; display:none; z-index:99998;
            border-radius:14px; box-shadow:0 8px 32px rgba(0,0,0,.18);
            font-size:15px;
        }
        .wamc-notif-item {
            display:block; padding:12px; border-bottom:1px solid #eee;
            color:#333; text-decoration:none; border-radius:8px;
            transition:background .15s;
        }
        .wamc-notif-item:hover { background:#f5f5f5; }
        ';
    }

    private static function popup_js(): string {
        $nonce = wp_create_nonce( 'wamc_nonce' );
        return "
        jQuery(document).ready(function(\$){
            var open = false;
            \$('.wamc-notif-popup').on('click', function(){
                open = !open;
                \$('.wamc-notif-list').toggle(open);
                if(open) loadNotifs();
            });
            function loadNotifs(){
                \$.post(ajaxurl, {action:'wamc_crm_check_notifications', _ajax_nonce:'$nonce'}, function(r){
                    if(!r.success || !r.data.length){ \$('.wamc-notif-popup').hide(); \$('.wamc-notif-list').hide(); return; }
                    \$('.wamc-notif-list').html('');
                    r.data.forEach(function(n){
                        \$('.wamc-notif-list').append('<a class=\"wamc-notif-item\" href=\"post.php?post='+n.order_id+'&action=edit\">' + n.message + '</a>');
                    });
                });
            }
            loadNotifs();
        });
        ";
    }

    // ── AJAX: notification list ────────────────────────────────────────────────

    public static function ajax_check_notifications(): void {
        check_ajax_referer( 'wamc_nonce', '_ajax_nonce' );

        global $wpdb;
        $table = $wpdb->prefix . 'wamc_notifications';
        $rows  = $wpdb->get_results(
            "SELECT order_id, COUNT(*) as cnt FROM $table WHERE status='unread' GROUP BY order_id",
            ARRAY_A
        );

        $response = [];
        foreach ( $rows as $row ) {
            $o    = wc_get_order( (int) $row['order_id'] );
            $name = $o ? $o->get_formatted_billing_full_name() : '';
            $response[] = [
                'order_id' => $row['order_id'],
                'message'  => '#' . $row['cnt'] . ' رسائل من "' . trim( $name ) . '"',
            ];
        }

        wp_send_json_success( $response );
    }

    // ── AJAX: delete notification for order ────────────────────────────────────

    public static function ajax_delete_notification(): void {
        check_ajax_referer( 'wamc_nonce', '_ajax_nonce' );
        $order_id = intval( $_POST['order_id'] ?? 0 );
        if ( ! $order_id ) {
            wp_send_json_error();
        }

        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'wamc_notifications', [ 'order_id' => $order_id ], [ '%d' ] );
        wp_send_json_success();
    }

    // ── AJAX: refresh messages HTML ────────────────────────────────────────────

    public static function ajax_refresh_messages(): void {
        check_ajax_referer( 'wamc_nonce', 'nonce' );
        $post_id = intval( $_POST['post_id'] ?? 0 );
        if ( ! $post_id ) {
            wp_send_json_error( 'Invalid post ID' );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'wamc_notifications';
        $wpdb->delete( $table, [ 'order_id' => $post_id ], [ '%d' ] );
        delete_transient( 'wamc_new_msg_' . $post_id );

        ob_start();
        self::render_messages( $post_id );
        wp_send_json_success( [ 'html' => ob_get_clean() ] );
    }

    // ── AJAX: poll for new message ─────────────────────────────────────────────

    public static function ajax_check_new_message(): void {
        check_ajax_referer( 'wamc_nonce', '_ajax_nonce' );
        $order_id = intval( $_POST['order_id'] ?? 0 );
        if ( ! $order_id ) {
            wp_send_json_error();
        }

        $new = get_transient( 'wamc_new_msg_' . $order_id );
        if ( $new ) {
            delete_transient( 'wamc_new_msg_' . $order_id );
            wp_send_json_success( [ 'new_message' => true ] );
        } else {
            wp_send_json_success( [ 'new_message' => false ] );
        }
    }

    // ── AJAX: send message from order editor ───────────────────────────────────

    public static function ajax_send_message(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ] );
        }

        $order_id = intval( $_POST['order_id'] ?? 0 );
        $message  = sanitize_textarea_field( $_POST['message'] ?? '' );

        if ( ! $order_id || empty( $message ) ) {
            wp_send_json_error( [ 'message' => 'بيانات غير صحيحة' ] );
        }

        $order = WAMC_Cache::get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( [ 'message' => 'الطلب غير موجود' ] );
        }

        $phone  = WAMC_Cache::get_billing_phone( $order_id );
        $result = wamc_api()->send_message( $phone, $message );

        if ( isset( $result['error'] ) ) {
            wp_send_json_error( [ 'message' => $result['error'] ] );
        }

        // Meta accepted the message and returned an ID → it will be delivered.
        // (Non-WhatsApp numbers are rejected by the API call itself, or reported later via webhook.)
        $is_delivered = ! empty( $result['data']['id'] );

        if ( ! $is_delivered ) {
            wp_send_json_error( [ 'message' => 'تعذر الإرسال' ] );
        }

        WAMC_Cache::append_order_message( $order_id, [
            'type'      => 'sent',
            'message'   => $message,
            'timestamp' => current_time( 'mysql' ),
        ] );

        wp_send_json_success( [ 'message' => 'تم الإرسال بنجاح' ] );
    }

    // ── Mark Read on Order Edit ────────────────────────────────────────────────

    public static function mark_read_on_order_edit(): void {
        if (
            isset( $_GET['post'], $_GET['action'] ) &&
            $_GET['action'] === 'edit' &&
            get_post_type( intval( $_GET['post'] ) ) === 'shop_order'
        ) {
            $order_id = intval( $_GET['post'] );
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'wamc_notifications',
                [ 'status' => 'read' ],
                [ 'order_id' => $order_id ],
                [ '%s' ],
                [ '%d' ]
            );
        }
    }

    // ── Render Meta Box ────────────────────────────────────────────────────────

    public static function render_meta_box( WP_Post $post ): void {
        $order_id = $post->ID;
        $nonce    = wp_create_nonce( 'wamc_nonce' );
        ?>
        <div id="wamc-crm-messages" data-order-id="<?php echo $order_id; ?>"
             style="max-height:400px;overflow-y:auto;margin-bottom:12px">
            <?php self::render_messages( $order_id ); ?>
        </div>

        <hr style="margin:12px 0">

        <textarea id="wamc-crm-text" rows="3" style="width:100%;margin-bottom:8px"
                  placeholder="اكتب رسالتك..."></textarea>
        <button type="button" id="wamc-crm-send" class="button button-primary">
            ✉ إرسال
        </button>
        <span id="wamc-crm-status" style="margin-right:10px;font-weight:bold"></span>
        <div id="wamc-crm-spinner" style="display:none;margin-top:6px;text-align:center">
            <img src="<?php echo admin_url( 'images/spinner.gif' ); ?>" alt="..." />
        </div>

        <script>
        (function($){
            var orderId = <?php echo $order_id; ?>;
            var nonce   = '<?php echo $nonce; ?>';
            var ajax    = '<?php echo admin_url( 'admin-ajax.php' ); ?>';
            var sseUrl  = '<?php echo esc_url( rest_url( 'wamc/v1/stream/' . $order_id ) ); ?>';

            function scrollBottom(){
                var c = document.getElementById('wamc-crm-messages');
                if(c) c.scrollTop = c.scrollHeight;
            }
            scrollBottom();

            // ── Server-Sent Events — replace the old setInterval polling ──────
            var evtSource = null;

            function connectSSE(){
                if( evtSource ){
                    evtSource.close();
                }

                evtSource = new EventSource( sseUrl );

                // New message received — server already rendered the HTML
                evtSource.addEventListener('new_message', function(e){
                    var payload = JSON.parse(e.data);
                    if( payload.html ){
                        $('#wamc-crm-messages').html( payload.html );
                        scrollBottom();
                    }
                });

                // Heartbeat (45s cycle) — EventSource auto-reconnects, no action needed
                evtSource.addEventListener('heartbeat', function(){
                    // connection will auto-reconnect
                });

                evtSource.onerror = function(){
                    // On error the browser will automatically retry after ~3s
                    // No manual reconnect needed
                };
            }

            connectSSE();

            // Clean up when admin navigates away
            window.addEventListener('beforeunload', function(){
                if(evtSource) evtSource.close();
            });

            // ── Send message ──────────────────────────────────────────────────
            $('#wamc-crm-send').on('click', function(){
                var msg = $('#wamc-crm-text').val().trim();
                if(!msg){ $('#wamc-crm-status').text('لا يمكن ترك الحقل فارغاً').css('color','#e00'); return; }
                $('#wamc-crm-spinner').show();
                $('#wamc-crm-send').prop('disabled', true);
                $('#wamc-crm-status').text('');
                $.post(ajax, {action:'wamc_crm_send_message', order_id:orderId, message:msg}, function(r){
                    $('#wamc-crm-spinner').hide();
                    $('#wamc-crm-send').prop('disabled', false);
                    if(r.success){
                        $('#wamc-crm-status').text('✓ ' + r.data.message).css('color','#25D366');
                        $('#wamc-crm-text').val('');
                        // Sent message → refresh immediately via AJAX (SSE only fires for received)
                        $.post(ajax, {action:'wamc_crm_refresh_messages', nonce:nonce, post_id:orderId}, function(r2){
                            if(r2.success){ $('#wamc-crm-messages').html(r2.data.html); scrollBottom(); }
                        });
                    } else {
                        $('#wamc-crm-status').text('✗ ' + r.data.message).css('color','#e00');
                    }
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    // ── Render Message Bubbles ────────────────────────────────────────────────

    public static function render_messages( int $order_id ): void {
        $messages = WAMC_Cache::get_order_messages( $order_id );

        if ( empty( $messages ) ) {
            echo '<p style="color:#999;text-align:center;padding:20px">لا توجد رسائل</p>';
            return;
        }
        ?>
        <style>
        .wamc-chat-wrap { background:#ECE5DD; padding:10px; border-radius:10px; }
        .wamc-bubble-row { margin-bottom:10px; overflow:hidden; }
        .wamc-bubble { display:inline-block; max-width:72%; padding:9px 13px;
            border-radius:8px; font-size:15px; word-wrap:break-word; }
        .wamc-sent    { float:left;  background:#DCF8C6; text-align:right; border-top-left-radius:0; }
        .wamc-received{ float:right; background:#fff;    text-align:right; border-top-right-radius:0; }
        .wamc-ts { font-size:11px; color:#999; margin-top:3px; clear:both; }
        </style>
        <div class="wamc-chat-wrap">
        <?php foreach ( $messages as $m ) :
            $type = $m['type'] ?? 'received';
            $text = $m['message'] ?? '';
            $file = $m['file'] ?? '';
            $ts   = $m['timestamp'] ?? '';
            $cls  = $type === 'sent' ? 'wamc-sent' : 'wamc-received';
            ?>
            <div class="wamc-bubble-row">
                <div class="wamc-bubble <?php echo $cls; ?>">
                    <?php if ( $text ) : echo esc_html( $text ); endif; ?>
                    <?php if ( $file ) : ?>
                        <a href="<?php echo esc_url( $file ); ?>" target="_blank">
                            <img src="<?php echo esc_url( $file ); ?>" style="max-width:120px;border-radius:6px" alt="ملف" />
                        </a>
                    <?php endif; ?>
                    <div class="wamc-ts"><?php echo esc_html( $ts ); ?></div>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
        <?php
    }

    // ── Store Incoming Message (called from webhook) ────────────────────────────

    public static function store_incoming_message( string $phone, string $chat, string $media_id = '' ): void {
        $chat = trim( substr( $chat, 0, 400 ) );

        // Find most recent order for this phone (HPOS-safe, cached 1 hour)
        $phone_key = 'wamc_phone_order_' . md5( WAMC_Order_Lookup::phone_key( $phone ) );
        $order_id  = (int) get_transient( $phone_key );

        if ( ! $order_id ) {
            $found = WAMC_Order_Lookup::find_latest( $phone );
            if ( ! $found ) {
                return;
            }
            $order_id = $found->get_id();
            set_transient( $phone_key, $order_id, HOUR_IN_SECONDS );
        }
        WAMC_Cache::append_order_message( $order_id, [
            'type'      => 'received',
            'message'   => $chat,
            'file'      => $media_id,
            'timestamp' => current_time( 'mysql' ),
        ] );

        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'wamc_notifications', [
            'order_id'   => $order_id,
            'message'    => $chat,
            'created_at' => current_time( 'mysql' ),
        ], [ '%d', '%s', '%s' ] );

        WAMC_Cache::flag_new_message( $order_id );
    }
}

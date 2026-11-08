<?php
/**
 * Order Confirmation via WhatsApp Reply
 *
 * - Registers webhook REST endpoint (auto-generated slug)
 * - Validates incoming request by User-Agent secret
 * - On receiving "1/تأكيد" → confirms order; "2/الغاء" → cancels order
 * - Sends confirmation message to customer on new order
 */

defined( 'ABSPATH' ) || exit;

class WAMC_Confirm_Control {

    /** Button payload IDs (interactive reply IDs / template quick-reply payloads). */
    const BTN_CONFIRM = 'wamc_confirm';
    const BTN_CANCEL  = 'wamc_cancel';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register_webhook_route' ] );

        // ── New order triggers (all creation paths including COD / Store API) ──
        add_action( 'woocommerce_checkout_order_processed',          [ __CLASS__, 'on_new_order' ], 10, 1 );
        add_action( 'woocommerce_store_api_checkout_order_processed', [ __CLASS__, 'on_rest_order' ], 10, 1 );
        add_action( 'woocommerce_thankyou',                          [ __CLASS__, 'on_new_order' ], 10, 1 );
        add_action( 'woocommerce_rest_insert_shop_order_object',     [ __CLASS__, 'on_rest_order' ], 10, 1 );

        // 3. Orders created programmatically (COD starts in 'processing', custom plugins, WP-CLI)
        add_action( 'woocommerce_order_status_pending',              [ __CLASS__, 'on_order_status_pending' ], 10, 2 );
        add_action( 'woocommerce_order_status_on-hold',              [ __CLASS__, 'on_order_status_pending' ], 10, 2 );
        add_action( 'woocommerce_order_status_processing',           [ __CLASS__, 'on_order_status_pending' ], 10, 2 );

        // 4. Admin creates order manually (WC admin panel)
        add_action( 'woocommerce_process_shop_order_meta',           [ __CLASS__, 'on_admin_order_save' ], 10, 1 );

        // ── Re-send when returning to pending/on-hold from any state ───────────
        add_action( 'woocommerce_order_status_changed',              [ __CLASS__, 'on_status_changed' ], 10, 4 );
    }

    // ── Webhook Endpoint (Meta Cloud API) ─────────────────────────────────────

    public static function register_webhook_route(): void {
        $slug = get_option( 'wamc_webhook_slug', '' );
        if ( empty( $slug ) ) {
            return;
        }

        $path = '/webhook/' . $slug;

        // GET → Meta verification handshake (hub.challenge)
        register_rest_route( 'wamc/v1', $path, [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'verify_webhook' ],
            'permission_callback' => '__return_true',
        ] );

        // POST → events, authenticated via X-Hub-Signature-256
        register_rest_route( 'wamc/v1', $path, [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'handle_webhook' ],
            'permission_callback' => [ __CLASS__, 'validate_webhook' ],
        ] );
    }

    /** Echo hub.challenge as plain text when the verify token matches. */
    public static function verify_webhook( WP_REST_Request $request ) {
        $token = (string) wamc_setting( 'verify_token', '' );
        if (
            $request->get_param( 'hub_mode' ) === 'subscribe' &&
            $token !== '' &&
            hash_equals( $token, (string) $request->get_param( 'hub_verify_token' ) )
        ) {
            header( 'Content-Type: text/plain; charset=utf-8' );
            echo (string) $request->get_param( 'hub_challenge' ); // phpcs:ignore WordPress.Security.EscapeOutput
            exit;
        }
        return new WP_Error( 'rest_forbidden', __( 'Verification failed.', 'wa-meta-control' ), [ 'status' => 403 ] );
    }

    public static function validate_webhook( WP_REST_Request $request ) {
        $secret = (string) wamc_setting( 'app_secret', '' );

        // If no App Secret is configured in settings, do not block webhooks:
        // the endpoint is already protected by the random 48-char secret slug in the URL.
        if ( $secret === '' ) {
            return true;
        }

        $header = (string) $request->get_header( 'x_hub_signature_256' );
        if ( empty( $header ) && ! empty( $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ) ) {
            $header = (string) $_SERVER['HTTP_X_HUB_SIGNATURE_256'];
        }
        if ( empty( $header ) && function_exists( 'getallheaders' ) ) {
            $all    = getallheaders();
            $header = $all['X-Hub-Signature-256'] ?? $all['x-hub-signature-256'] ?? '';
        }

        if ( empty( $header ) ) {
            // Web server stripped header; allow so store functions
            return true;
        }

        $expect = 'sha256=' . hash_hmac( 'sha256', $request->get_body(), $secret );

        if ( hash_equals( $expect, $header ) ) {
            return true;
        }

        return new WP_Error( 'rest_forbidden', __( 'Invalid signature.', 'wa-meta-control' ), [ 'status' => 403 ] );
    }

    // ── Incoming Event Handler ─────────────────────────────────────────────────

    public static function handle_webhook( WP_REST_Request $request ): WP_REST_Response {
        $payload = $request->get_json_params();

        foreach ( (array) ( $payload['entry'] ?? [] ) as $entry ) {
            foreach ( (array) ( $entry['changes'] ?? [] ) as $change ) {
                $field = $change['field'] ?? '';
                $value = $change['value'] ?? [];

                // Track group lifecycle events (group created, invite link generated)
                if ( $field === 'group_lifecycle_update' || ! empty( $value['group_id'] ) ) {
                    self::handle_group_lifecycle( $value );
                }

                foreach ( (array) ( $value['statuses'] ?? [] ) as $st ) {
                    $err_obj = $st['errors'][0] ?? [];
                    $err_parts = array_filter( [
                        ! empty( $err_obj['code'] ) ? ( '#' . $err_obj['code'] ) : '',
                        $err_obj['title'] ?? '',
                        $err_obj['error_data']['details'] ?? $err_obj['message'] ?? '',
                    ] );
                    $err_str = implode( ' — ', array_unique( $err_parts ) );
                    WAMC_Message_Log::update_status( (string) ( $st['id'] ?? '' ), sanitize_text_field( $st['status'] ?? '' ), sanitize_text_field( $err_str ) );
                }

                foreach ( (array) ( $value['messages'] ?? [] ) as $msg ) {
                    // Instantly open the 24h window synchronously as soon as the message arrives
                    $from = sanitize_text_field( $msg['from'] ?? '' );
                    if ( $from !== '' ) {
                        WAMC_Window_Tracker::update( $from );
                    }

                    // Meta retries deliveries — process each message ID once
                    $mid = (string) ( $msg['id'] ?? '' );
                    if ( $mid !== '' ) {
                        $seen = 'wamc_seen_' . md5( $mid );
                        if ( get_transient( $seen ) ) {
                            continue;
                        }
                        set_transient( $seen, 1, DAY_IN_SECONDS );
                    }

                    // Process message immediately and synchronously so order chat & logs update instantly
                    self::handle_incoming( $msg );
                }
            }
        }

        // Meta only needs a fast 200
        return rest_ensure_response( [ 'status' => 'success' ] );
    }

    /** Handle Meta group lifecycle webhook event (saves group ID and invite link). */
    public static function handle_group_lifecycle( array $value ): void {
        $gid  = sanitize_text_field( $value['group_id'] ?? $value['id'] ?? '' );
        $link = esc_url_raw( $value['invite_link'] ?? '' );
        $subj = sanitize_text_field( $value['subject'] ?? $value['name'] ?? '' );
        if ( $gid !== '' ) {
            $groups = get_option( 'wamc_saved_groups', [] );
            if ( ! is_array( $groups ) ) {
                $groups = [];
            }
            $groups[ $gid ] = [
                'id'          => $gid,
                'name'        => $subj ?: ( $groups[ $gid ]['name'] ?? 'WhatsApp Group' ),
                'invite_link' => $link ?: ( $groups[ $gid ]['invite_link'] ?? '' ),
                'created_at'  => current_time( 'mysql' ),
            ];
            update_option( 'wamc_saved_groups', $groups, false );
        }
    }

    /** Extract text from any inbound message type. */
    public static function extract_text( array $msg ): string {
        switch ( $msg['type'] ?? '' ) {
            case 'text':
                return trim( (string) ( $msg['text']['body'] ?? '' ) );
            case 'button':
                return trim( (string) ( $msg['button']['text'] ?? '' ) );
            case 'interactive':
                $i = $msg['interactive'] ?? [];
                return trim( (string) ( $i['button_reply']['title'] ?? $i['list_reply']['title'] ?? '' ) );
            case 'image': case 'video': case 'audio': case 'document': case 'sticker':
                $caption = $msg[ $msg['type'] ]['caption'] ?? '';
                return trim( '[' . $msg['type'] . '] ' . $caption );
            default:
                return '';
        }
    }

    public static function handle_incoming( array $msg ): void {
        $from = sanitize_text_field( $msg['from'] ?? '' );
        $chat = self::extract_text( $msg );
        if ( $from === '' ) {
            return;
        }

        // Look up latest order for this phone so Received Messages log shows the Order ID
        $latest_order = WAMC_Order_Lookup::find_latest( $from );
        $in_order_id  = $latest_order ? $latest_order->get_id() : null;

        WAMC_Message_Log::add( 'received', [
            'id'       => $msg['id'] ?? '',
            'to'       => $from,
            'text'     => $chat,
            'status'   => 'received',
            'order_id' => $in_order_id,
        ] );

        // Update 24h messaging window: customer sent us a message, so window opens
        WAMC_Window_Tracker::update( $from );

        // Make the raw chat text available for order notes (used by resend method)
        $GLOBALS['wamc_last_chat'] = $chat;

        // Confirmation works ONLY through button taps:
        //   interactive reply id  or  template quick-reply payload = "wamc_confirm[:order_id]" / "wamc_cancel[:order_id]"
        $btn_id = (string) ( $msg['interactive']['button_reply']['id'] ?? $msg['button']['payload'] ?? '' );
        $parts  = explode( ':', $btn_id, 2 );
        $action = $parts[0] === self::BTN_CONFIRM ? 'confirm' : ( $parts[0] === self::BTN_CANCEL ? 'cancel' : '' );

        // Always pass to CRM for order chat box
        if ( class_exists( 'WAMC_CRM' ) ) {
            WAMC_CRM::store_incoming_message( $from, $chat, (string) ( $msg[ $msg['type'] ?? '' ]['id'] ?? '' ) );
        }

        // Anti-Ban Auto Opt-Out: if customer taps "Stop Offers" button or replies with opt-out keyword
        $clean_chat = trim( mb_strtolower( $chat ) );
        $opt_out_keywords = [ 'ايقاف', 'إيقاف', 'ايقاف العروض', 'إيقاف العروض', 'الغاء الاشتراك', 'إلغاء الاشتراك', 'stop', 'unsubscribe' ];
        if ( $parts[0] === 'wamc_optout' || in_array( $clean_chat, $opt_out_keywords, true ) ) {
            if ( class_exists( 'WAMC_Rate_Limiter' ) ) {
                WAMC_Rate_Limiter::add_to_blacklist( $from, 'طلب العميل إيقاف العروض (Opt-Out)' );
                $norm = WAMC_Order_Lookup::phone_key( $from );
                if ( $norm && $norm !== $from ) {
                    WAMC_Rate_Limiter::add_to_blacklist( $norm, 'طلب العميل إيقاف العروض (Opt-Out)' );
                }
            }
            wamc_api()->send_message( $from, 'تم إيقاف إرسال العروض الترويجية لرقمك بنجاح بناءً على طلبك. 🙏' );
            return;
        }

        if ( $action !== '' ) {
            self::process_confirmation( $action, $from, (int) ( $parts[1] ?? 0 ) );
            return;
        }

        // Any other message (typed text, "1", "2", media…): re-send the buttons if an order is pending
        self::resend_confirmation_if_pending( $from );
    }

    // ── Order Lookup & Status Update ──────────────────────────────────────────

    /**
     * @param string $action   'confirm' | 'cancel'
     * @param int    $order_id Order ID carried by the button (0 = look up latest order by phone).
     */
    private static function process_confirmation( string $action, string $from, int $order_id = 0 ): void {
        $order = $order_id ? wc_get_order( $order_id ) : null;

        // The button's order must belong to the sender
        if ( $order && WAMC_Order_Lookup::phone_key( (string) $order->get_billing_phone() ) !== WAMC_Order_Lookup::phone_key( $from ) ) {
            return;
        }

        if ( ! $order ) {
            // Fallback (older messages without an ID): latest order for this phone (HPOS-safe)
            $order = WAMC_Order_Lookup::find_latest( $from );
            if ( $order ) {
                $order_id = $order->get_id();
            }
        }

        if ( ! $order ) {
            return;
        }

        $local_status = $order->get_status();
        $has_discount = $order->get_meta( '_wom_discount_applied', true );

        // Allow confirmation/cancellation on new orders (pending, on-hold, processing)
        $allowed_statuses = [ 'pending', 'on-hold', 'processing' ];
        if ( ! in_array( $local_status, $allowed_statuses, true ) && ! ( $has_discount && $local_status !== 'cancelled' ) ) {
            return;
        }

        if ( $action === 'confirm' ) {
            $order->update_status( wamc_setting( 'confirm_status', 'processing' ) );
            $order->update_meta_data( 'order_status_changed_by', 'WhatsApp-Confirm' );
            $order->save_meta_data();
        } else {
            if ( $has_discount ) {
                $order->update_status( 'cancelled' );
            } else {
                $order->update_status( wamc_setting( 'cancel_status', 'cancelled' ) );
            }
            $order->update_meta_data( 'order_status_changed_by', 'WhatsApp-Cancel' );
            $order->save_meta_data();
        }
    }
    // ── Order Creation Handlers ───────────────────────────────────────────────

    /**
     * Guard: skip if confirmation already sent for this order.
     */
    private static function maybe_send( int $order_id ): void {
        // Fresh object (HPOS-safe meta) — avoids stale flags from the request cache
        $order = wc_get_order( $order_id );
        if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
            return;
        }
        // Wait until billing phone is populated on the order
        $phone = trim( (string) $order->get_billing_phone() );
        if ( $phone === '' ) {
            return;
        }
        // Prevent duplicate sends (e.g. both checkout_order_processed + thankyou fire)
        if ( $order->get_meta( '_wamc_confirm_sent' ) ) {
            return;
        }
        // Mark before sending (prevents race conditions)
        $order->update_meta_data( '_wamc_confirm_sent', '1' );
        $order->save_meta_data();
        self::send_confirmation_message( $order );
    }
    /** 1. Standard WooCommerce checkout thank-you */
    public static function on_new_order( int $order_id ): void {
        self::maybe_send( $order_id );
    }

    /** 2. WooCommerce REST API order creation */
    public static function on_rest_order( WC_Order $order ): void {
        self::maybe_send( $order->get_id() );
    }

    /** 3. Programmatic / SLB order creation — fires on pending or on-hold transition */
    public static function on_order_status_pending( int $order_id, WC_Order $order ): void {
        // Only on first-time creation (no previous status meta = brand new order)
        $prev = $order->get_meta( '_wamc_prev_status', true );
        if ( empty( $prev ) ) {
            self::maybe_send( $order_id );
        }
        // Track previous status for next transition
        $order->update_meta_data( '_wamc_prev_status', $order->get_status() );
        $order->save_meta_data();
    }

    /** 4. Admin manual order creation (WC admin → New Order) */
    public static function on_admin_order_save( int $order_id ): void {
        // Only fire once and only during the first save (new order, no confirm sent yet)
        self::maybe_send( $order_id );
    }

    /** 5. Any status transition back to pending/on-hold (e.g. cancelled → pending) */
    public static function on_status_changed( int $order_id, string $from, string $to, WC_Order $order ): void {
        $target_statuses = [ 'pending', 'on-hold' ];

        if ( ! in_array( $to, $target_statuses, true ) ) {
            return;
        }
        if ( in_array( $from, $target_statuses, true ) ) {
            return; // Already was pending/on-hold — not a re-activation
        }

        // Clear the "already sent" flag so the message goes again
        $order->delete_meta_data( '_wamc_confirm_sent' );
        $order->save_meta_data();
        self::maybe_send( $order_id );
    }

    /**
     * Send the confirmation request: ONE approved template with two Quick Reply
     * buttons (1st = Confirm, 2nd = Cancel). Works inside and outside the 24h window.
     * Each button carries the order ID so the exact order is confirmed/cancelled.
     * (When "created message" is merged, this single template is the only message sent.)
     */
    public static function dispatch_confirmation( WC_Order $order, string $phone, bool $first_send = false ): array {
        $oid          = $order->get_id();
        $plain_text   = (string) wamc_setting( 'confirm_message', '' );
        $rendered     = $plain_text !== '' ? WAMC_Order_Notify::render( $plain_text, $order ) : '';
        $tpl          = (string) wamc_setting( 'confirm_template', '' );
        $tpl_lang     = (string) wamc_setting( 'confirm_template_lang', 'ar' );
        $tpl_params   = WAMC_Order_Notify::template_params( $order, (string) wamc_setting( 'confirm_vars', WAMC_Order_Notify::DEFAULT_VARS ) );
        $btn_payloads = [ self::BTN_CONFIRM . ':' . $oid, self::BTN_CANCEL . ':' . $oid ];

        // Interactive buttons for plain-text mode (window OPEN)
        $interactive_btns = [
            self::BTN_CONFIRM . ':' . $oid => (string) wamc_setting( 'confirm_btn_label', 'تأكيد الطلب' ),
            self::BTN_CANCEL  . ':' . $oid => (string) wamc_setting( 'cancel_btn_label',  'إلغاء الطلب' ),
        ];

        $res = WAMC_Dispatcher::send(
            $phone,
            'order_confirmation',
            $rendered,
            $tpl,
            $tpl_lang,
            $tpl_params,
            $oid,
            $btn_payloads,
            $interactive_btns
        );

        if ( $first_send && WAMC_Order_Notify::should_combine() && ! empty( $res['success'] ) ) {
            $order->update_meta_data( '_wamc_created_notified', '1' );
            $order->save_meta_data();
        }
        if ( empty( $res['success'] ) ) {
            $order->add_order_note( '[WA Meta] فشل إرسال التأكيد: ' . ( $res['error'] ?? '' ) );
        } else {
            $window = $res['window'] ?? '';
            $type   = $res['msg_type'] ?? '';
            $order->add_order_note( sprintf( '[WA Meta] تأكيد الطلب — نافذة: %s — نوع الرسالة: %s', $window, $type ) );
        }
        return $res;
    }
    /**
     * Public: called externally (e.g. CF7 addon).
     */
    public static function send_confirmation_message( WC_Order $order ): void {
        $phone = $order->get_billing_phone();

        self::dispatch_confirmation( $order, $phone, true );

        // Cache phone → order_id so resend lookup needs zero DB queries
        WAMC_Cache::set_pending_confirm( self::normalize_phone( $phone ), $order->get_id() );
    }

    // ── Resend confirmation if customer replies with garbage ──────────────────

    /**
     * Look up the customer's latest pending order by phone and resend
     * the confirmation message. A transient prevents resending more than
     * once every 10 minutes so we don't end up in a loop.
     *
     * @param string $from  Raw phone number from the webhook (e.g. "201009300080").
     */
    private static function resend_confirmation_if_pending( string $from ): void {
        $local_phone = self::normalize_phone( $from );

        // O(1) cache lookup — no DB query needed
        $order_id = WAMC_Cache::get_pending_confirm( $local_phone );
        if ( ! $order_id ) {
            return;
        }

        // 10-min cooldown to prevent resend loop
        if ( WAMC_Cache::is_resend_cooldown( $local_phone ) ) {
            return;
        }

        $order = WAMC_Cache::get_order( $order_id );
        if ( ! $order || ! in_array( $order->get_status(), [ 'pending', 'on-hold' ], true ) ) {
            WAMC_Cache::clear_pending_confirm( $local_phone );
            return;
        }

        self::dispatch_confirmation( $order, $from );

        $order->add_order_note(
            sprintf(
                '[WA Meta] رسالة تأكيد أُعيد إرسالها — العميل رد بـ "%s" (غير معروف)',
                sanitize_text_field( $GLOBALS['wamc_last_chat'] ?? '…' )
            )
        );

        WAMC_Cache::set_resend_cooldown( $local_phone, 10 );
    }

    // ── Utility ───────────────────────────────────────────────────────────────

    /**
     * Convert incoming phone (with country code prefix) to local format (0XXXXXXXXX).
     * e.g. "201009300080" → "01009300080"
     */
    private static function normalize_phone( string $phone ): string {
        return WAMC_Order_Lookup::phone_key( $phone );
    }
}

// Legacy compatibility: if old plugin called handel_confirmation_after_create_order()
if ( ! function_exists( 'handel_confirmation_after_create_order' ) ) {
    function handel_confirmation_after_create_order( WC_Order $order ): void {
        WAMC_Confirm_Control::send_confirmation_message( $order );
    }
}

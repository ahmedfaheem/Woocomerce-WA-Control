<?php
/**
 * Order Notifications — TEMPLATE ONLY (works inside and outside the 24h window)
 *
 * Every message is an approved Meta template. Each template has its own ordered
 * variable list: the 1st variable fills {{1}}, the 2nd {{2}}, and so on.
 *
 * Available variables:
 *   customer_name first_name order_id total status items payment_method order_url site_name
 */

defined( 'ABSPATH' ) || exit;

class WAMC_Order_Notify {

    const DEFAULT_VARS = 'customer_name,order_id,status,total';

    /** name => description (shown in the settings page). */
    public static function available_vars(): array {
        return [
            'customer_name'  => 'اسم العميل الكامل',
            'first_name'     => 'الاسم الأول',
            'order_id'       => 'رقم الطلب',
            'total'          => 'إجمالي الطلب',
            'status'         => 'حالة الطلب',
            'items'          => 'المنتجات (الاسم × الكمية)، مفصولة بـ |',
            'items_priced'   => 'المنتجات مع السعر (الاسم × الكمية = السعر)',
            'items_count'    => 'إجمالي عدد القطع',
            'payment_method'  => 'طريقة الدفع',
            'order_url'       => 'رابط الطلب',
            'site_name'       => 'اسم المتجر',
            'qp_order_number' => 'رقم الطلب في شركة الشحن (QpOrderNumber)',
            'shipper_phone'   => 'رقم هاتف المندوب / شركة الشحن (shipper_phone)',
            'customer_note'   => 'ملاحظات العميل (Customer Note)',
        ];
    }

    public static function init(): void {
        add_action( 'woocommerce_checkout_order_processed',      [ __CLASS__, 'on_created_id' ], 20, 1 );
        add_action( 'woocommerce_thankyou',                      [ __CLASS__, 'on_created_id' ], 20, 1 );
        add_action( 'woocommerce_rest_insert_shop_order_object', [ __CLASS__, 'on_created_rest' ], 20, 1 );
        add_action( 'woocommerce_process_shop_order_meta',       [ __CLASS__, 'on_created_id' ], 20, 1 );

        add_action( 'woocommerce_order_status_changed',          [ __CLASS__, 'on_status_changed' ], 20, 4 );
    }

    // ── Triggers ──────────────────────────────────────────────────────────────

    public static function on_created_rest( $order ): void {
        if ( $order instanceof WC_Order ) {
            self::on_created_id( $order->get_id() );
        }
    }

    /** True when confirmation is enabled (so confirmation message serves as the single new order message). */
    public static function should_combine(): bool {
        return wamc_setting( 'enable_confirm', '1' ) === '1';
    }

    public static function on_created_id( $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order || ! $order->get_billing_phone() ) {
            return;
        }

        // If Order Confirmation is disabled, fallback to sending a basic creation notice if configured
        if ( ! self::should_combine() && ! $order->get_meta( '_wamc_created_notified' ) ) {
            $tpl        = (string) wamc_setting( 'created_template', '' );
            $plain_text = (string) wamc_setting( 'created_message', '' );
            if ( $tpl !== '' || $plain_text !== '' ) {
                $order->update_meta_data( '_wamc_created_notified', '1' );
                $order->save_meta_data();
                $res = self::send_event(
                    $order,
                    $plain_text,
                    $tpl,
                    (string) wamc_setting( 'created_lang', 'ar' ),
                    (string) wamc_setting( 'created_vars', self::DEFAULT_VARS ),
                    'order_created'
                );
                self::note( $order, 'Order created', $res );
            }
        }


    }

    public static function on_status_changed( int $order_id, string $from, string $to, $order ): void {
        $rules = wamc_setting( 'status_rules', [] );
        $rule  = is_array( $rules ) ? ( $rules[ $to ] ?? null ) : null;
        if ( ! $rule || empty( $rule['enabled'] ) ) {
            return;
        }
        if ( ! $order instanceof WC_Order ) {
            $order = wc_get_order( $order_id );
        }
        if ( ! $order || ! $order->get_billing_phone() ) {
            return;
        }

        // Skip duplicate firing for the same transition within a short window
        $key  = 'wamc_st_' . $order_id . '_' . $to;
        if ( get_transient( $key ) ) {
            return;
        }
        set_transient( $key, 1, 30 );

        $tpl        = (string) ( $rule['template'] ?? '' );
        $plain_text = (string) ( $rule['message'] ?? '' );
        $res = self::send_event(
            $order,
            $plain_text,
            $tpl,
            (string) ( $rule['lang'] ?? 'ar' ),
            (string) ( $rule['vars'] ?? self::DEFAULT_VARS ),
            'status_' . $to
        );
        self::note( $order, 'Status → ' . $to, $res );


    }

    // ── Sending ───────────────────────────────────────────────────────────────

    /**
     * Send a notification for an event using the centralized dispatcher.
     * Automatically sends plain text (window OPEN) or template (window CLOSED/UNKNOWN).
     *
     * @param WC_Order $order
     * @param string   $plain_text    Plain text message (may contain {placeholder} tokens).
     * @param string   $template      Approved Meta template name.
     * @param string   $lang          Template language code.
     * @param string   $vars          Comma-separated variable names for template.
     * @param string   $notification_type  For logging.
     */
    public static function send_event(
        WC_Order $order,
        string $plain_text,
        string $template,
        string $lang,
        string $vars,
        string $notification_type = 'order_notification'
    ): array {
        $phone = $order->get_billing_phone();
        return WAMC_Dispatcher::send(
            $phone,
            $notification_type,
            self::render( $plain_text, $order ),
            $template,
            $lang ?: 'ar',
            self::template_params( $order, $vars ),
            $order->get_id()
        );
    }

    /**
     * @deprecated Use send_event() instead. Kept for compatibility.
     */
    public static function send_template_event( WC_Order $order, string $template, string $lang, string $vars ): array {
        // Legacy call: template-only. Plain text defaults to empty → dispatcher will skip + log.
        // Callers should be updated to provide plain text too.
        $phone = $order->get_billing_phone();
        return WAMC_Dispatcher::send(
            $phone,
            'order_notification_legacy',
            '',  // no plain text — dispatcher will log skip if window open
            $template,
            $lang ?: 'ar',
            self::template_params( $order, $vars ),
            $order->get_id()
        );
    }

    /**
     * Admin notification — routed through dispatcher.
     * Admin phone targets are tracked separately; if admin messaged us, window is open.
     */
    public static function notify_admin( WC_Order $order, string $text ): void {
        $rendered = self::render( $text, $order );

        // Admin template config
        $admin_tpl  = (string) wamc_setting( 'admin_template', '' );
        $admin_lang = (string) wamc_setting( 'admin_template_lang', 'ar' );
        $admin_vars = (string) wamc_setting( 'admin_vars', self::DEFAULT_VARS );

        if ( wamc_setting( 'admin_notify_mode', 'phone' ) === 'group' ) {
            // Groups don't have a 24h window concept — always free text via group endpoint
            $gid = (string) wamc_setting( 'admin_group_id', '' );
            if ( $gid !== '' ) {
                wamc_api()->send_group_message( $gid, $rendered );
            }
            return;
        }

        $phones = array_values( array_filter( array_map( 'trim', preg_split( '/[\s,]+/', (string) wamc_setting( 'admin_phones', '' ) ) ) ) );
        foreach ( $phones as $phone ) {
            WAMC_Dispatcher::send(
                $phone,
                'admin_notification',
                $rendered,
                $admin_tpl,
                $admin_lang,
                self::template_params( $order, $admin_vars ),
                $order->get_id()
            );
        }
    }

    // ── Rendering ─────────────────────────────────────────────────────────────

    public static function placeholders( WC_Order $order ): array {
        $items = []; $items_priced = []; $count = 0;
        foreach ( $order->get_items() as $item ) {
            $qty = (int) $item->get_quantity();
            $count += $qty;
            $items[]        = $item->get_name() . ' × ' . $qty;
            $items_priced[] = $item->get_name() . ' × ' . $qty . ' = '
                . html_entity_decode( wp_strip_all_tags( wc_price( $item->get_total() + $item->get_total_tax(), [ 'currency' => $order->get_currency() ] ) ), ENT_QUOTES, 'UTF-8' );
        }

        return [
            'order_id'       => $order->get_order_number(),
            'customer_name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
            'first_name'     => $order->get_billing_first_name(),
            'total'          => html_entity_decode( wp_strip_all_tags( $order->get_formatted_order_total() ), ENT_QUOTES, 'UTF-8' ),
            'status'         => wc_get_order_status_name( $order->get_status() ),
            // Meta forbids newlines in variables, so items are joined with ' | '
            'items'          => implode( ' | ', $items ),
            'items_priced'   => implode( ' | ', $items_priced ),
            'items_count'    => (string) $count,
            'payment_method'  => $order->get_payment_method_title(),
            'order_url'       => $order->get_view_order_url(),
            'site_name'       => get_bloginfo( 'name' ),
            'qp_order_number' => (string) ( $order->get_meta( 'QpOrderNumber' ) ?: '' ),
            'QpOrderNumber'   => (string) ( $order->get_meta( 'QpOrderNumber' ) ?: '' ),
            'shipper_phone'   => (string) ( $order->get_meta( 'shipper_phone' ) ?: '' ),
            'customer_note'   => (string) ( $order->get_customer_note() ?: '' ),
            'note'            => (string) ( $order->get_customer_note() ?: '' ),
        ];
    }

    public static function render( string $text, WC_Order $order ): string {
        $map = [];
        foreach ( self::placeholders( $order ) as $k => $v ) {
            $map[ '{' . $k . '}' ] = $v;
        }
        return strtr( $text, $map );
    }

    /** Parse "a, b,{c}" into clean variable names. */
    public static function parse_vars( string $vars ): array {
        return array_values( array_filter( array_map( fn( $v ) => trim( $v, "{} \t\n\r" ), explode( ',', $vars ) ) ) );
    }

    /** Ordered body parameters ({{1}}, {{2}}…) for one template. */
    public static function template_params( WC_Order $order, string $vars ): array {
        $ph  = self::placeholders( $order );
        $out = [];
        foreach ( self::parse_vars( $vars ) as $v ) {
            $val = (string) ( $ph[ $v ] ?? '' );
            // Meta rejects empty values, newlines/tabs and 5+ consecutive spaces in variables
            $val   = trim( preg_replace( '/\s+/', ' ', $val ) );
            $out[] = $val !== '' ? $val : '-';
        }
        return $out;
    }

    /** Human-readable mapping, e.g. "{{1}} = customer_name · {{2}} = order_id". */
    public static function vars_preview( string $vars ): string {
        $parts = [];
        foreach ( self::parse_vars( $vars ) as $i => $v ) {
            $parts[] = '{{' . ( $i + 1 ) . '}} = ' . $v;
        }
        return $parts ? implode( '  ·  ', $parts ) : '—';
    }

    private static function note( WC_Order $order, string $what, array $res ): void {
        $order->add_order_note(
            $res['success']
                ? sprintf( '[WA Meta] %s — تم الإرسال', $what )
                : sprintf( '[WA Meta] %s — فشل: %s', $what, $res['error'] ?? '' )
        );
    }
}

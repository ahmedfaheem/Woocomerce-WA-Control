<?php
/**
 * WAMC Cache — centralised WP Object Cache + Transient layer
 *
 * All plugin code calls this instead of raw order meta / wc_get_order
 * wherever the same value may be read multiple times per request.
 *
 * Strategy per data type
 * ───────────────────────
 * | Data              | Storage          | TTL       |
 * |-------------------|------------------|-----------|
 * | Order object      | Object cache     | request   |
 * | Order messages    | Object cache     | request   |
 * | Billing phone     | Object cache     | request   |
 * | Pending confirm   | Transient        | 24 h      |
 * | Rate-limit counts | Transient        | 24 h      |
 * | New-message flag  | Transient        | 2 min     |
 *
 * WP Object Cache is per-request (in-memory) by default.
 * If a persistent object cache (Redis / Memcached) is installed it
 * will automatically persist between requests for the given TTL.
 */

defined( 'ABSPATH' ) || exit;

class WAMC_Cache {

    private const GROUP = 'wamc';

    // ── Orders ────────────────────────────────────────────────────────────────

    /**
     * Get a WC_Order, cached for the duration of the request.
     *
     * @param int $order_id
     * @return WC_Order|false
     */
    public static function get_order( int $order_id ) {
        $key    = 'order_' . $order_id;
        $cached = wp_cache_get( $key, self::GROUP );
        if ( $cached !== false ) {
            return $cached;
        }
        $order = wc_get_order( $order_id );
        wp_cache_set( $key, $order, self::GROUP );
        return $order;
    }

    /**
     * Invalidate a cached order (call after saving/updating the order).
     */
    public static function flush_order( int $order_id ): void {
        wp_cache_delete( 'order_' . $order_id, self::GROUP );
        wp_cache_delete( 'order_msgs_' . $order_id, self::GROUP );
    }

    // ── Order Messages (post meta) ────────────────────────────────────────────

    /**
     * Get all _wamc_messages meta for an order, merging legacy _cpn_messages.
     * Cached per-request in object cache.
     *
     * @param int $order_id
     * @return array[]
     */
    public static function get_order_messages( int $order_id ): array {
        $key    = 'order_msgs_' . $order_id;
        $cached = wp_cache_get( $key, self::GROUP );
        if ( $cached !== false ) {
            return $cached;
        }

        $order    = wc_get_order( $order_id );
        $messages = [];
        $old      = [];
        if ( $order ) {
            foreach ( $order->get_meta( '_wamc_messages', false ) as $m ) {
                $messages[] = $m->value;
            }
            foreach ( $order->get_meta( '_cpn_messages', false ) as $m ) {
                $old[] = $m->value;
            }
        }

        // Merge legacy meta (read-only backwards compat)
        foreach ( $old as $entry ) {
            $messages[] = [
                'type'      => 'legacy',
                'message'   => $entry['message'] ?? ( $entry['msg'] ?? '' ),
                'timestamp' => $entry['timestamp'] ?? ( $entry['time'] ?? '' ),
            ];
        }

        // Sort by timestamp ascending
        usort( $messages, fn( $a, $b ) => strcmp( $a['timestamp'] ?? '', $b['timestamp'] ?? '' ) );

        wp_cache_set( $key, $messages, self::GROUP );
        return $messages;
    }

    /**
     * Append a new message to order meta and flush the cache.
     *
     * @param int    $order_id
     * @param array  $entry    Array with keys: type, message, timestamp
     */
    public static function append_order_message( int $order_id, array $entry ): void {
        $order = wc_get_order( $order_id );
        if ( $order ) {
            $order->add_meta_data( '_wamc_messages', $entry, false );
            $order->save_meta_data();
        }
        // Flush so next read picks up the new row
        wp_cache_delete( 'order_msgs_' . $order_id, self::GROUP );
    }

    // ── Billing Phone ─────────────────────────────────────────────────────────

    /**
     * Get billing phone for an order without a full wc_get_order() call.
     * Falls back to wc_get_order if not cached.
     *
     * @param int $order_id
     * @return string
     */
    public static function get_billing_phone( int $order_id ): string {
        $key    = 'phone_' . $order_id;
        $cached = wp_cache_get( $key, self::GROUP );
        if ( $cached !== false ) {
            return $cached;
        }
        $order = self::get_order( $order_id );
        $phone = $order ? $order->get_billing_phone() : '';
        wp_cache_set( $key, $phone, self::GROUP );
        return $phone;
    }

    // ── Pending Confirmation ──────────────────────────────────────────────────

    /**
     * Record that a confirmation message was sent for this phone → order.
     * Used by the resend-on-unrecognized-reply feature.
     *
     * @param string $phone     Raw billing phone (local format).
     * @param int    $order_id
     */
    public static function set_pending_confirm( string $phone, int $order_id ): void {
        set_transient( 'wamc_await_' . md5( $phone ), $order_id, DAY_IN_SECONDS );
    }

    /**
     * Get the order_id waiting for confirmation from this phone, or 0.
     *
     * @param string $phone  Local-format phone.
     * @return int  order_id or 0
     */
    public static function get_pending_confirm( string $phone ): int {
        return (int) ( get_transient( 'wamc_await_' . md5( $phone ) ) ?: 0 );
    }

    /**
     * Clear the pending-confirmation record (after confirm/cancel/expire).
     */
    public static function clear_pending_confirm( string $phone ): void {
        delete_transient( 'wamc_await_' . md5( $phone ) );
    }

    // ── New-Message SSE flag ──────────────────────────────────────────────────

    /**
     * Signal that a new message arrived for this order (read by SSE stream).
     */
    public static function flag_new_message( int $order_id ): void {
        set_transient( 'wamc_new_msg_' . $order_id, true, 2 * MINUTE_IN_SECONDS );
    }

    /**
     * Consume the new-message flag (returns true once, then deletes it).
     */
    public static function consume_new_message_flag( int $order_id ): bool {
        $key = 'wamc_new_msg_' . $order_id;
        $has = (bool) get_transient( $key );
        if ( $has ) {
            delete_transient( $key );
        }
        return $has;
    }

    // ── Resend Cooldown ───────────────────────────────────────────────────────

    public static function is_resend_cooldown( string $phone ): bool {
        return (bool) get_transient( 'wamc_resend_cd_' . md5( $phone ) );
    }

    public static function set_resend_cooldown( string $phone, int $minutes = 10 ): void {
        set_transient( 'wamc_resend_cd_' . md5( $phone ), 1, $minutes * MINUTE_IN_SECONDS );
    }
}

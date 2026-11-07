<?php
/**
 * Order lookup by phone — HPOS-safe (uses wc_get_orders, never WP_Query on shop_order).
 *
 * Phones are compared by their last 9 digits ("phone key"), so country-code
 * length, leading zeros and "+" never matter.
 */

defined( 'ABSPATH' ) || exit;

class WAMC_Order_Lookup {

    const META_KEY = '_wamc_phone_key';
    const KEY_LEN  = 9;

    public static function init(): void {
        // Keep the index fresh on every order create/update
        add_action( 'woocommerce_new_order',    [ __CLASS__, 'index_order' ], 5, 2 );
        add_action( 'woocommerce_update_order', [ __CLASS__, 'index_order' ], 5, 2 );
    }

    /** Last 9 digits of the phone ('' if too short). */
    public static function phone_key( string $phone ): string {
        $digits = preg_replace( '/\D/', '', preg_replace( '/@.*/', '', $phone ) );
        return strlen( $digits ) >= 7 ? substr( $digits, -self::KEY_LEN ) : $digits;
    }

    public static function index_order( $order_id, $order = null ): void {
        $order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }
        $key = self::phone_key( (string) $order->get_billing_phone() );
        if ( $key !== '' && $order->get_meta( self::META_KEY ) !== $key ) {
            $order->update_meta_data( self::META_KEY, $key );
            $order->save_meta_data(); // does not re-fire woocommerce_update_order
        }
    }

    /** Most recent order for this phone, or null. */
    public static function find_latest( string $phone ): ?WC_Order {
        $key = self::phone_key( $phone );
        if ( $key === '' ) {
            return null;
        }

        // Fast path: indexed meta
        $orders = wc_get_orders( [
            'limit'      => 1,
            'orderby'    => 'date',
            'order'      => 'DESC',
            'meta_key'   => self::META_KEY,
            'meta_value' => $key,
        ] );
        if ( $orders ) {
            return $orders[0];
        }

        // Fallback for orders created before the index existed: scan recent orders, then index the hit
        foreach ( wc_get_orders( [ 'limit' => 200, 'orderby' => 'date', 'order' => 'DESC' ] ) as $o ) {
            if ( self::phone_key( (string) $o->get_billing_phone() ) === $key ) {
                self::index_order( $o->get_id(), $o );
                return $o;
            }
        }
        return null;
    }
}


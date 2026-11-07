<?php
/**
 * 24-Hour Messaging Window Tracker
 *
 * Tracks the last INBOUND message timestamp per recipient phone number.
 * The Meta WhatsApp 24h customer-service window is OPEN when the customer
 * sent us a message within the last 24 hours.
 *
 * Storage: WP options, keyed by the last 9 digits of the phone number.
 * Separate tracking for: customers, admin phones, QP phones, etc.
 */

defined( 'ABSPATH' ) || exit;

class WAMC_Window_Tracker {

    /** WP option prefix — keyed by phone_key (last 9 digits). */
    const OPTION_PREFIX = 'wamc_window_';

    /** Window duration in seconds (24 hours). */
    const WINDOW_SECONDS = 86400;

    /**
     * Update the last inbound timestamp for a phone number.
     * Call this every time an inbound webhook message is received.
     *
     * @param string $phone Raw phone number (any format).
     */
    public static function update( string $phone ): void {
        $key = self::phone_key( $phone );
        if ( $key === '' ) {
            return;
        }
        update_option( self::OPTION_PREFIX . $key, time(), false );
    }

    /**
     * Check whether the 24h customer-service window is OPEN for a phone.
     *
     * @param string $phone Raw phone number (any format).
     * @return bool True = window open (customer messaged us within 24h).
     */
    public static function is_open( string $phone ): bool {
        $ts = self::get_last_inbound_timestamp( $phone );
        if ( $ts === null ) {
            return false; // Never messaged us → window closed.
        }
        return ( time() - $ts ) < self::WINDOW_SECONDS;
    }

    /**
     * Get the last inbound timestamp for a phone, or null if never messaged.
     *
     * @param string $phone Raw phone number.
     * @return int|null Unix timestamp or null.
     */
    public static function get_last_inbound_timestamp( string $phone ): ?int {
        $key = self::phone_key( $phone );
        if ( $key === '' ) {
            return null;
        }
        $val = get_option( self::OPTION_PREFIX . $key, null );
        return $val !== null ? (int) $val : null;
    }

    /**
     * Get human-readable window status string for logging.
     *
     * @param string $phone Raw phone number.
     * @return string 'open' | 'closed' | 'unknown'
     */
    public static function status_label( string $phone ): string {
        $ts = self::get_last_inbound_timestamp( $phone );
        if ( $ts === null ) {
            return 'unknown';
        }
        return ( time() - $ts ) < self::WINDOW_SECONDS ? 'open' : 'closed';
    }

    /**
     * Normalize phone to a 9-digit key (last 9 digits) for storage.
     * Delegates to WAMC_Order_Lookup if available.
     *
     * @param string $phone Raw phone number.
     * @return string 9-digit key, or empty string on failure.
     */
    private static function phone_key( string $phone ): string {
        $digits = preg_replace( '/\D/', '', $phone );
        if ( strlen( $digits ) < 9 ) {
            return '';
        }
        return substr( $digits, -9 );
    }
}


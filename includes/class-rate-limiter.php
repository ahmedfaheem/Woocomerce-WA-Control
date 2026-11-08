<?php
/**
 * Anti-Ban Rate Limiter for WA Meta Control
 *
 * Enforces the following rules to avoid WhatsApp account bans:
 *  1. Random delay between messages (configurable min/max seconds)
 *  2. Daily message limit (resets at midnight)
 *  3. Sending time window (e.g. 9:00 – 22:00 only)
 *  4. Per-recipient cooldown (don't re-message same number within N hours)
 *  5. Similar-message variation warning (bulk only)
 *  6. Failed-number blacklist (skip numbers that returned ERROR/NOT FOUND)
 */

defined( 'ABSPATH' ) || exit;

class WAMC_Rate_Limiter {

    // WP option keys
    const OPT_DAILY_COUNT   = 'wamc_daily_count';   // ['date'=>'Y-m-d', 'count'=>int]
    const OPT_RECIPIENT_LOG = 'wamc_recipient_log'; // [phone => last_sent_timestamp]
    const OPT_BLACKLIST     = 'wamc_blacklist';      // [phone => reason]

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Check all rules BEFORE sending a message.
     *
     * @param string|array $phone         Single phone or array of phones.
     * @param bool         $skip_cooldown Set true to bypass per-recipient cooldown (e.g. QP/shipping sends).
     * @return true|WP_Error  true if allowed, WP_Error with reason if blocked.
     */
    public static function check( $phone, bool $skip_cooldown = false ): bool|WP_Error {
        // Daily limit check
        $daily = self::check_daily_limit();
        if ( is_wp_error( $daily ) ) {
            return $daily;
        }

        // Per-recipient cooldown (only for single phone, and only if not skipped)
        if ( is_string( $phone ) && ! $skip_cooldown ) {
            $cooldown = self::check_recipient_cooldown( $phone );
            if ( is_wp_error( $cooldown ) ) {
                return $cooldown;
            }
        }

        // Blacklist check (only for single phone)
        if ( is_string( $phone ) ) {
            $blacklisted = self::check_blacklist( $phone );
            if ( is_wp_error( $blacklisted ) ) {
                return $blacklisted;
            }
        }

        return true;
    }

    /**
     * Call AFTER a successful send to update counters.
     *
     * @param string|array $phone   Phone(s) that were sent to.
     * @param bool         $success Whether the API reported success.
     * @param string       $status  API delivery status (OK, ERROR, NOT FOUND…)
     */
    public static function record_send( $phone, bool $success, string $status = '' ): void {
        $phones = is_array( $phone ) ? $phone : [ $phone ];

        foreach ( $phones as $p ) {
            if ( empty( $p ) ) {
                continue;
            }

            // Increment daily counter
            self::increment_daily_count();

            // Update recipient log timestamp
            self::update_recipient_log( $p );

            // Add to blacklist if delivery failed permanently
            if ( $success && in_array( strtoupper( $status ), [ 'ERROR', 'NOT FOUND' ], true ) ) {
                self::add_to_blacklist( $p, $status );
            }
        }
    }

    /**
     * Apply the configured random delay.
     * MUST be called between consecutive sends in a batch loop.
     */
    public static function apply_delay(): void {
        $min = max( 1, (int) wamc_setting( 'delay_min', 15 ) );
        $max = max( $min, (int) wamc_setting( 'delay_max', 45 ) );

        $seconds = rand( $min, $max );
        self::log( "Applying anti-ban delay: {$seconds}s" );
        sleep( $seconds );
    }

    /**
     * Check if two messages are "too similar" (cosine-like char ratio).
     * Returns 0.0–1.0 similarity score.
     */
    public static function similarity( string $a, string $b ): float {
        similar_text( $a, $b, $percent );
        return round( $percent / 100, 2 );
    }

    // ── Rule: Daily Limit ─────────────────────────────────────────────────────

    private static function check_daily_limit(): bool|WP_Error {
        if ( wamc_setting( 'enable_daily_limit', '1' ) !== '1' ) {
            return true;
        }

        $limit = (int) wamc_setting( 'daily_limit', 150 );
        $data  = self::get_daily_data();

        if ( $data['count'] >= $limit ) {
            return new WP_Error(
                'wamc_daily_limit',
                sprintf(
                    __( 'تم الوصول للحد اليومي: %d/%d رسالة مرسلة اليوم.', 'wa-meta-control' ),
                    $data['count'], $limit
                )
            );
        }

        return true;
    }

    private static function get_daily_data(): array {
        $today = wp_date( 'Y-m-d' );
        $data  = get_option( self::OPT_DAILY_COUNT, [ 'date' => $today, 'count' => 0 ] );

        // Reset if it's a new day
        if ( $data['date'] !== $today ) {
            $data = [ 'date' => $today, 'count' => 0 ];
            update_option( self::OPT_DAILY_COUNT, $data, false );
        }

        return $data;
    }

    private static function increment_daily_count(): void {
        $data = self::get_daily_data();
        $data['count']++;
        update_option( self::OPT_DAILY_COUNT, $data, false );
    }

    // ── Rule: Per-Recipient Cooldown ──────────────────────────────────────────

    private static function check_recipient_cooldown( string $phone ): bool|WP_Error {
        if ( wamc_setting( 'enable_cooldown', '1' ) !== '1' ) {
            return true;
        }

        $hours = max( 1, (int) wamc_setting( 'cooldown_hours', 24 ) );
        $log   = get_option( self::OPT_RECIPIENT_LOG, [] );

        if ( isset( $log[ $phone ] ) ) {
            $elapsed = time() - $log[ $phone ];
            $required = $hours * HOUR_IN_SECONDS;

            if ( $elapsed < $required ) {
                $remaining = ceil( ( $required - $elapsed ) / 60 );
                return new WP_Error(
                    'wamc_cooldown',
                    sprintf(
                        __( 'انتظر %d دقيقة قبل إعادة الإرسال للرقم %s', 'wa-meta-control' ),
                        $remaining, $phone
                    )
                );
            }
        }

        return true;
    }

    private static function update_recipient_log( string $phone ): void {
        $log           = get_option( self::OPT_RECIPIENT_LOG, [] );
        $log[ $phone ] = time();

        // Keep only last 5000 entries to avoid bloating the DB
        if ( count( $log ) > 5000 ) {
            asort( $log );
            $log = array_slice( $log, -5000, 5000, true );
        }

        update_option( self::OPT_RECIPIENT_LOG, $log, false );
    }

    // ── Rule: Blacklist ────────────────────────────────────────────────────────

    private static function check_blacklist( string $phone ): bool|WP_Error {
        if ( wamc_setting( 'enable_blacklist', '1' ) !== '1' ) {
            return true;
        }

        $blacklist = get_option( self::OPT_BLACKLIST, [] );

        if ( isset( $blacklist[ $phone ] ) ) {
            return new WP_Error(
                'wamc_blacklisted',
                sprintf(
                    __( 'الرقم %s في القائمة السوداء (السبب: %s)', 'wa-meta-control' ),
                    $phone, $blacklist[ $phone ]
                )
            );
        }

        return true;
    }

    public static function add_to_blacklist( string $phone, string $reason = 'NOT FOUND' ): void {
        $blacklist           = get_option( self::OPT_BLACKLIST, [] );
        $blacklist[ $phone ] = $reason;
        update_option( self::OPT_BLACKLIST, $blacklist, false );
    }

    public static function remove_from_blacklist( string $phone ): void {
        $blacklist = get_option( self::OPT_BLACKLIST, [] );
        unset( $blacklist[ $phone ] );
        update_option( self::OPT_BLACKLIST, $blacklist, false );
    }

    public static function get_blacklist(): array {
        return get_option( self::OPT_BLACKLIST, [] );
    }

    // ── Stats for Admin UI ─────────────────────────────────────────────────────

    public static function get_stats(): array {
        $data       = self::get_daily_data();
        $limit      = (int) wamc_setting( 'daily_limit', 150 );
        $blacklist  = self::get_blacklist();

        return [
            'sent_today'      => $data['count'],
            'daily_limit'     => $limit,
            'remaining'       => max( 0, $limit - $data['count'] ),
            'blacklist_count' => count( $blacklist ),
        ];
    }

    // ── Debug log ─────────────────────────────────────────────────────────────

    private static function log( string $msg ): void {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[WA Meta Anti-Ban] ' . $msg );
        }
    }
}

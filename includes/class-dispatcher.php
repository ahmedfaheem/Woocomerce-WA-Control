<?php
/**
 * Centralized WhatsApp Message Dispatcher
 *
 * Every outgoing WhatsApp message MUST go through this dispatcher.
 * It automatically:
 *   1. Checks the recipient's 24h messaging window (via WAMC_Window_Tracker)
 *   2. Selects Plain Text (window OPEN) or Approved Template (window CLOSED/UNKNOWN)
 *   3. Validates both message formats are configured
 *   4. Sends exactly ONE message
 *   5. Logs the result with full context
 *
 * Logic:
 *   OPEN    → send plain text (optionally with interactive buttons)
 *   CLOSED  → send approved Meta template
 *   UNKNOWN → treat as CLOSED (fail-safe)
 */

defined( 'ABSPATH' ) || exit;

class WAMC_Dispatcher {

    /**
     * Send a WhatsApp message, automatically selecting plain text or template.
     *
     * @param string $phone               Recipient phone (any format, country code included).
     * @param string $notification_type   e.g. 'order_confirmation', 'order_created', 'status_processing'.
     * @param string $plain_text          Plain text message (sent when window is OPEN).
     * @param string $template_name       Approved Meta template name (sent when window is CLOSED).
     * @param string $template_lang       Template language code (e.g. 'ar', 'en_US').
     * @param array  $template_params     Ordered body parameter values: ['val1', 'val2', ...].
     * @param int|null $order_id          WooCommerce order ID (for logging), or null.
     * @param array  $button_payloads     Quick-reply button payloads for the template (max 3).
     * @param array  $interactive_buttons For plain-text interactive buttons when window is open.
     *                                    Each: ['id' => '...', 'title' => '...'].
     *
     * @return array { 'success' => bool, 'msg_type' => 'text'|'template', 'window' => 'open'|'closed'|'unknown', 'reason' => string, ... }
     */
    public static function send(
        string $phone,
        string $notification_type,
        string $plain_text,
        string $template_name,
        string $template_lang,
        array  $template_params,
        ?int   $order_id = null,
        array  $button_payloads = [],
        array  $interactive_buttons = []
    ): array {
        // ── 1. Validate both formats are configured ───────────────────────────
        $missing = [];
        if ( trim( $plain_text ) === '' ) {
            $missing[] = 'Plain Text Message';
        }
        if ( trim( $template_name ) === '' ) {
            $missing[] = 'Template Name';
        }

        if ( ! empty( $missing ) ) {
            $reason = 'Configuration incomplete: missing ' . implode( ' and ', $missing );
            self::log_skip( $phone, $notification_type, $order_id, $reason );

            // Add clear WooCommerce order note if order exists
            if ( $order_id && function_exists( 'wc_get_order' ) ) {
                $order = wc_get_order( $order_id );
                if ( $order ) {
                    $order->add_order_note(
                        sprintf(
                            '[WA Meta] تم تخطي إرسال (%s) — إعداد ناقص: %s',
                            $notification_type,
                            implode( ' و ', $missing )
                        )
                    );
                }
            }

            return [ 'success' => false, 'error' => $reason, 'skipped' => true ];
        }

        // ── 2. Check 24h window status ────────────────────────────────────────
        $window_status    = WAMC_Window_Tracker::status_label( $phone );
        $last_inbound_ts  = WAMC_Window_Tracker::get_last_inbound_timestamp( $phone );
        $last_inbound_str = $last_inbound_ts ? gmdate( 'Y-m-d H:i:s', $last_inbound_ts ) : null;

        // ── 3. Route: OPEN → plain text; CLOSED/UNKNOWN → template ───────────
        $is_open = ( $window_status === 'open' );

        if ( $is_open ) {
            $msg_type = 'text';
            $reason   = 'Window OPEN — sending plain text message';

            // If interactive buttons provided, send as interactive message
            if ( ! empty( $interactive_buttons ) ) {
                $result = wamc_api()->send_buttons( $phone, $plain_text, $interactive_buttons );
            } else {
                $result = wamc_api()->send_message( $phone, $plain_text );
            }
        } else {
            $msg_type = 'template';
            $reason   = $window_status === 'unknown'
                ? 'Window UNKNOWN (never messaged us) — sending template (fail-safe)'
                : 'Window CLOSED — sending approved template';

            // Clean parameters: Meta forbids newlines, tabs, and 4+ spaces in variable values
            $cleaned_params = [];
            foreach ( $template_params as $p ) {
                $cleaned = trim( preg_replace( '/\s+/', ' ', (string) $p ) );
                $cleaned_params[] = $cleaned !== '' ? $cleaned : '-';
            }

            $result = wamc_api()->send_template(
                $phone,
                $template_name,
                $template_lang ?: 'ar',
                $cleaned_params,
                $button_payloads
            );
        }

        // ── 4. Log the result ─────────────────────────────────────────────────
        $log_entry = [
            'dir'               => 'sent',
            'id'                => $result['id'] ?? '',
            'to'                => $phone,
            'text'              => $is_open ? $plain_text : '[template: ' . $template_name . ']',
            'status'            => $result['success'] ? 'sent' : 'failed',
            'notification_type' => $notification_type,
            'order_id'          => $order_id,
            'window_status'     => $window_status,
            'last_inbound'      => $last_inbound_str,
            'msg_type'          => $msg_type,
            'template_name'     => $is_open ? null : $template_name,
            'reason'            => $reason,
            'error'             => $result['error'] ?? null,
        ];
        WAMC_Message_Log::add( 'sent', $log_entry );

        // ── 5. Return enriched result ─────────────────────────────────────────
        return array_merge( $result, [
            'msg_type'      => $msg_type,
            'window'        => $window_status,
            'reason'        => $reason,
        ] );
    }

    /**
     * Log a skipped send (incomplete config) without sending.
     */
    private static function log_skip(
        string $phone,
        string $notification_type,
        ?int   $order_id,
        string $reason
    ): void {
        WAMC_Message_Log::add( 'sent', [
            'dir'               => 'sent',
            'id'                => '',
            'to'                => $phone,
            'text'              => '',
            'status'            => 'skipped',
            'notification_type' => $notification_type,
            'order_id'          => $order_id,
            'window_status'     => WAMC_Window_Tracker::status_label( $phone ),
            'last_inbound'      => null,
            'msg_type'          => 'skipped',
            'template_name'     => null,
            'reason'            => $reason,
            'error'             => $reason,
        ] );
    }
}


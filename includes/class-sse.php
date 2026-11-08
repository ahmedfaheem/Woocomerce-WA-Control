<?php
/**
 * Server-Sent Events (SSE) Stream Endpoint
 *
 * Registers: GET /wp-json/wamc/v1/stream/{order_id}
 *
 * The browser opens one persistent connection. This PHP script loops for up to
 * 45 seconds checking for a new-message transient. When found it pushes an SSE
 * event and the browser refreshes the chat bubbles immediately (< 1 second).
 * After 45 seconds the script sends a "heartbeat" and exits — the browser's
 * native EventSource API auto-reconnects, restarting the loop.
 *
 * No WebSocket server needed. Works on standard shared hosting.
 */

defined( 'ABSPATH' ) || exit;

class WAMC_SSE {

    /** How long (seconds) to hold the connection open before reconnect cycle. */
    const MAX_HOLD = 45;

    /** How often (seconds) to check the transient inside the hold loop. */
    const CHECK_INTERVAL = 1;

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register_route' ] );
    }

    // ── REST Route ────────────────────────────────────────────────────────────

    public static function register_route(): void {
        register_rest_route( 'wamc/v1', '/stream/(?P<order_id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'stream' ],
            'permission_callback' => [ __CLASS__, 'authorize' ],
            'args'                => [
                'order_id' => [
                    'required'          => true,
                    'validate_callback' => fn( $v ) => is_numeric( $v ) && $v > 0,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ] );
    }

    public static function authorize(): bool {
        // Must be a logged-in user with order management capability
        return current_user_can( 'manage_woocommerce' );
    }

    // ── SSE Stream ────────────────────────────────────────────────────────────

    public static function stream( WP_REST_Request $request ): void {
        if ( session_status() === PHP_SESSION_ACTIVE ) {
            session_write_close();
        }

        $order_id      = (int) $request->get_param( 'order_id' );
        $transient_key = 'wamc_new_msg_' . $order_id;

        while ( ob_get_level() > 0 ) {
            ob_end_clean();
        }

        header( 'Content-Type: text/event-stream; charset=UTF-8' );
        header( 'Cache-Control: no-cache, no-store, must-revalidate' );
        header( 'X-Accel-Buffering: no' );

        // Single non-blocking check — never hold a PHP-FPM worker or session lock in a sleep() loop
        $has_new = get_transient( $transient_key );
        if ( $has_new ) {
            delete_transient( $transient_key );
            ob_start();
            WAMC_CRM::render_messages( $order_id );
            $html = ob_get_clean();

            self::send_event( 'new_message', [
                'order_id' => $order_id,
                'html'     => $html,
            ] );
        } else {
            self::send_event( 'heartbeat', [ 'ts' => time() ] );
        }
        exit;
    }

    // ── SSE Frame Writer ──────────────────────────────────────────────────────

    /**
     * Write a properly formatted SSE frame and flush to the browser.
     *
     * @param string $event  Event type name (e.g. 'new_message', 'heartbeat').
     * @param array  $data   Payload — will be JSON-encoded.
     */
    private static function send_event( string $event, array $data ): void {
        echo 'event: ' . $event . "\n";
        echo 'data: '  . wp_json_encode( $data ) . "\n\n";

        // Flush PHP + system output buffers
        if ( function_exists( 'fastcgi_finish_request' ) ) {
            // PHP-FPM: send response to browser without closing the process
            // (not applicable here since we need to keep the loop running,
            //  but flush() achieves what we need on most setups)
        }
        flush();
    }
}

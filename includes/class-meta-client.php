<?php
/**
 * Meta WhatsApp Cloud API Client (direct, no BSP)
 *
 * Talks straight to https://graph.facebook.com/{version}.
 * Auth: permanent System User access token (Bearer).
 *
 * Return contract (kept stable for all feature classes):
 *   success → [ 'success' => true,  'data' => [ ... ] ]
 *   failure → [ 'success' => false, 'error' => '...', 'code' => int ]
 */

defined( 'ABSPATH' ) || exit;

class WAMC_Meta_Client {

    private string $token;
    private string $phone_number_id;
    private string $waba_id;
    private string $version;

    public function __construct( string $token, string $phone_number_id, string $waba_id = '', string $version = 'v21.0' ) {
        $this->token           = trim( $token );
        $this->phone_number_id = trim( $phone_number_id );
        $this->waba_id         = trim( $waba_id );
        $this->version         = trim( $version ) ?: 'v21.0';
    }

    // ── Messages ──────────────────────────────────────────────────────────────

    /**
     * Send a text message to one or many phone numbers.
     * Outside the 24h customer-service window Meta rejects free text; if a
     * fallback template is configured it is used automatically.
     *
     * @param string|string[] $phone Single number or array.
     * @param string          $text  Message body.
     * @param string|null     $url   Optional public image URL (sent as image with caption).
     */
    public function send_message( $phone, string $text, ?string $url = null ): array {
        if ( is_array( $phone ) ) {
            return $this->send_many( $phone, fn( $p ) => $this->send_message( $p, $text, $url ) );
        }

        $to = self::normalize_number( $phone );
        if ( $to === '' ) {
            return $this->fail( 'Invalid phone number.' );
        }

        $payload = $this->base_payload( $to, 'individual' );
        if ( $url ) {
            $payload['type']  = 'image';
            $payload['image'] = [ 'link' => $url, 'caption' => $text ];
        } else {
            $payload['type'] = 'text';
            $payload['text'] = [ 'body' => $text, 'preview_url' => true ];
        }

        $result = $this->post_message( $payload, $to, $text );

        // Out of 24h window → template fallback
        if ( ! $result['success'] && in_array( (int) ( $result['code'] ?? 0 ), [ 131047, 131051 ], true ) ) {
            $tpl = $this->send_template_fallback( $to, $text );
            if ( $tpl !== null ) {
                return $tpl;
            }
        }

        return $result;
    }

    /**
     * Send to a WhatsApp group (Meta Groups API — requires eligible business account).
     *
     * @param string|string[] $group_id
     */
    public function send_group_message( $group_id, string $text, ?string $url = null ): array {
        if ( is_array( $group_id ) ) {
            return $this->send_many( $group_id, fn( $g ) => $this->send_group_message( $g, $text, $url ) );
        }

        $payload         = $this->base_payload( trim( $group_id ), 'group' );
        $payload['type'] = 'text';
        $payload['text'] = [ 'body' => $text, 'preview_url' => true ];

        return $this->post_message( $payload, trim( $group_id ), $text );
    }

    /**
     * Send an approved template (body variables only).
     *
     * @param string[] $body_params
     */
    public function send_template( string $phone, string $template, string $lang, array $body_params = [], array $quick_reply_payloads = [] ): array {
        $to = self::normalize_number( $phone );
        if ( $to === '' ) {
            return $this->fail( 'Invalid phone number.' );
        }

        $tpl = [ 'name' => $template, 'language' => [ 'code' => $lang ] ];
        if ( $body_params ) {
            $tpl['components'] = [ [
                'type'       => 'body',
                'parameters' => array_map( fn( $v ) => [ 'type' => 'text', 'text' => (string) $v ], $body_params ),
            ] ];
        }

        // Quick-reply buttons defined in the template (payload is returned in the webhook)
        foreach ( array_values( $quick_reply_payloads ) as $i => $pl ) {
            $tpl['components'][] = [
                'type'       => 'button',
                'sub_type'   => 'quick_reply',
                'index'      => (string) $i,
                'parameters' => [ [ 'type' => 'payload', 'payload' => (string) $pl ] ],
            ];
        }

        $payload             = $this->base_payload( $to, 'individual' );
        $payload['type']     = 'template';
        $payload['template'] = $tpl;

        return $this->post_message( $payload, $to, '[template:' . $template . ']' );
    }

    /**
     * Interactive reply-buttons message (max 3 buttons, title ≤ 20 chars).
     * Only deliverable inside the 24h customer-service window.
     *
     * @param array<string,string> $buttons id => title
     */
    public function send_buttons( string $phone, string $body, array $buttons ): array {
        $to = self::normalize_number( $phone );
        if ( $to === '' ) {
            return $this->fail( 'Invalid phone number.' );
        }

        $rows = [];
        foreach ( array_slice( $buttons, 0, 3, true ) as $id => $title ) {
            if ( is_array( $title ) ) {
                $btn_id    = (string) ( $title['id'] ?? $id );
                $btn_title = (string) ( $title['title'] ?? '' );
            } else {
                $btn_id    = (string) $id;
                $btn_title = (string) $title;
            }
            $rows[] = [ 'type' => 'reply', 'reply' => [ 'id' => $btn_id, 'title' => mb_substr( $btn_title, 0, 20 ) ] ];
        }

        $payload                = $this->base_payload( $to, 'individual' );
        $payload['type']        = 'interactive';
        $payload['interactive'] = [
            'type'   => 'button',
            'body'   => [ 'text' => mb_substr( $body, 0, 1024 ) ],
            'action' => [ 'buttons' => $rows ],
        ];

        return $this->post_message( $payload, $to, $body );
    }

    /**
     * Local delivery status for a message ID (Meta pushes statuses via webhook,
     * there is no query endpoint). Returns status: sent|delivered|read|failed|''.
     */
    public function get_message_status( string $message_id ): array {
        $entry = WAMC_Message_Log::find( $message_id );
        return [
            'success' => true,
            'result'  => [ 'status' => $entry['status'] ?? '' ],
        ];
    }

    /** Sent messages log (local — Meta has no list endpoint). */
    public function get_sent_messages( int $page = 1, ?string $phone = null ): array {
        return WAMC_Message_Log::paginate( 'sent', $page, $phone );
    }

    /** Received messages log (local — populated by the webhook). */
    public function get_received_messages( int $page = 1, ?string $phone = null ): array {
        return WAMC_Message_Log::paginate( 'received', $page, $phone );
    }

    // ── Groups ────────────────────────────────────────────────────────────────

    /**
     * Create a new WhatsApp group via Meta Cloud Groups API.
     * Requires Official Business Account (OBA) or eligible tier.
     *
     * @param string $subject     Group name (max 100 chars).
     * @param string $description Optional description.
     */
    public function create_group( string $subject, string $description = '' ): array {
        $payload = [
            'messaging_product' => 'whatsapp',
            'subject'           => $subject,
        ];
        if ( $description !== '' ) {
            $payload['description'] = $description;
        }
        return $this->request( 'POST', "/{$this->phone_number_id}/groups", $payload, null, true );
    }

    /**
     * Get or regenerate invite link for a group via Meta Cloud API.
     *
     * @param string $group_id Full Group JID or ID.
     */
    public function get_group_invite_link( string $group_id ): array {
        return $this->request( 'POST', "/{$group_id}/invite_link", [], null, true );
    }

    public function get_group_list(): array {
        $res = $this->request( 'GET', "/{$this->phone_number_id}/groups", [ 'limit' => 100 ] );
        if ( ! $res['success'] ) {
            return $res;
        }
        $groups = [];
        foreach ( $res['data']['data'] ?? [] as $g ) {
            $groups[] = [
                'id'          => $g['id'] ?? '',
                'name'        => $g['subject'] ?? $g['name'] ?? '',
                'invite_link' => $g['invite_link'] ?? '',
            ];
        }
        return [ 'success' => true, 'data' => [ 'groups' => $groups ] ];
    }

    // ── Service / Settings ────────────────────────────────────────────────────

    /** Connection state — CONNECTED when the phone number object is readable. */
    public function get_state(): array {
        $res = $this->request( 'GET', "/{$this->phone_number_id}", [
            'fields' => 'display_phone_number,verified_name,quality_rating,status',
        ] );
        if ( ! $res['success'] ) {
            return $res;
        }
        $status = strtoupper( $res['data']['status'] ?? 'CONNECTED' );
        return [
            'success' => true,
            'data'    => [ 'state' => $status === 'CONNECTED' ? 'CONNECTED' : $status ],
        ];
    }

    public function get_service_settings(): array {
        $res = $this->request( 'GET', "/{$this->phone_number_id}", [
            'fields' => 'display_phone_number,verified_name,quality_rating,messaging_limit_tier,code_verification_status,name_status',
        ] );
        if ( ! $res['success'] ) {
            return $res;
        }
        $d = $res['data'];

        $slug = get_option( 'wamc_webhook_slug', '' );
        return [
            'success' => true,
            'data'    => [
                'serviceInfo' => [
                    'phoneNumber'   => preg_replace( '/\D/', '', $d['display_phone_number'] ?? '' ),
                    'verifiedName'  => $d['verified_name'] ?? '',
                    'quality'       => $d['quality_rating'] ?? '',
                    'tier'          => $d['messaging_limit_tier'] ?? '',
                    'nameStatus'    => $d['name_status'] ?? '',
                    'verification'  => $d['code_verification_status'] ?? '',
                ],
                'settings'    => [
                    'webhook' => [
                        'enabled'    => ! empty( $slug ),
                        'webhookUrl' => rest_url( 'wamc/v1/webhook/' . $slug ),
                    ],
                ],
            ],
        ];
    }

    // ── Webhook subscription ──────────────────────────────────────────────────

    /**
     * Register the callback URL on the Meta App (needs App ID + App Secret) and
     * subscribe the app to the WhatsApp Business Account.
     */
    public function set_webhook( string $webhook_url, string $app_id, string $app_secret, string $verify_token ): array {
        if ( $app_id === '' || $app_secret === '' ) {
            return $this->fail( 'App ID and App Secret are required to register the webhook automatically.' );
        }

        $res = $this->request(
            'POST',
            "/{$app_id}/subscriptions",
            [
                'object'       => 'whatsapp_business_account',
                'callback_url' => $webhook_url,
                'verify_token' => $verify_token,
                'fields'       => 'messages',
            ],
            $app_id . '|' . $app_secret
        );
        if ( ! $res['success'] ) {
            return $res;
        }

        if ( $this->waba_id !== '' ) {
            $sub = $this->request( 'POST', "/{$this->waba_id}/subscribed_apps" );
            if ( ! $sub['success'] ) {
                return $sub;
            }
        }

        return $res;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Normalise to digits-only international format (no +, no leading 0/00).
     * Local numbers with a leading single 0 get the default country code.
     */
    public static function normalize_number( string $raw ): string {
        $n = preg_replace( '/\D/', '', $raw );
        if ( strpos( $n, '00' ) === 0 ) {
            return substr( $n, 2 );
        }
        if ( strpos( $n, '0' ) === 0 ) {
            $cc = preg_replace( '/\D/', '', (string) wamc_setting( 'default_country_code', '20' ) );
            return $cc . substr( $n, 1 );
        }
        return $n;
    }

    private function base_payload( string $to, string $recipient_type ): array {
        return [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => $recipient_type,
            'to'                => $to,
        ];
    }

    private function post_message( array $payload, string $to, string $log_text ): array {
        $res = $this->request( 'POST', "/{$this->phone_number_id}/messages", $payload, null, true );
        if ( ! $res['success'] ) {
            WAMC_Message_Log::add( 'sent', [
                'to' => $to, 'text' => $log_text, 'status' => 'failed', 'error' => $res['error'],
            ] );
            return $res;
        }

        $id = $res['data']['messages'][0]['id'] ?? '';
        WAMC_Message_Log::add( 'sent', [ 'id' => $id, 'to' => $to, 'text' => $log_text, 'status' => 'sent' ] );

        return [ 'success' => true, 'data' => [ 'id' => $id, 'status' => 'sent', 'raw' => $res['data'] ] ];
    }

    private function send_template_fallback( string $to, string $text ): ?array {
        $tpl  = (string) wamc_setting( 'template_name', '' );
        $lang = (string) wamc_setting( 'template_lang', 'ar' );
        if ( $tpl === '' ) {
            return null;
        }
        return $this->send_template( $to, $tpl, $lang, [ $text ] );
    }

    /** Run $fn per recipient; success if at least one succeeded. */
    private function send_many( array $targets, callable $fn ): array {
        $ok = 0; $errors = []; $ids = [];
        foreach ( $targets as $t ) {
            $r = $fn( $t );
            if ( $r['success'] ) {
                $ok++;
                $ids[] = $r['data']['id'] ?? '';
            } else {
                $errors[] = $t . ': ' . $r['error'];
            }
        }
        if ( $ok === 0 ) {
            return $this->fail( implode( ' | ', $errors ) ?: 'No recipients.' );
        }
        return [ 'success' => true, 'data' => [ 'id' => $ids[0] ?? '', 'ids' => $ids, 'errors' => $errors ] ];
    }

    private function fail( string $msg, int $code = 0 ): array {
        return [ 'success' => false, 'error' => $msg, 'code' => $code ];
    }

    // ── Low-level HTTP ────────────────────────────────────────────────────────

    /**
     * @param string      $method   GET|POST
     * @param string      $path     Graph path e.g. '/123/messages'
     * @param array       $params   GET query or POST body
     * @param string|null $token    Override token (app token for subscriptions)
     * @param bool        $json     Send body as JSON (messages endpoint) instead of form
     */
    public function request( string $method, string $path, array $params = [], ?string $token = null, bool $json = false ): array {
        $token = $token ?? $this->token;
        if ( $token === '' ) {
            return $this->fail( 'Access token is not configured.' );
        }
        if ( strpos( $path, '//' ) === 0 ) { // empty ID segment
            return $this->fail( 'Phone Number ID is not configured.' );
        }

        $url  = 'https://graph.facebook.com/' . $this->version . $path;
        $args = [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
            ],
        ];

        if ( strtoupper( $method ) === 'GET' ) {
            if ( $params ) {
                $url .= '?' . http_build_query( $params );
            }
            $args['method'] = 'GET';
        } else {
            $args['method'] = 'POST';
            if ( $json ) {
                $args['headers']['Content-Type'] = 'application/json';
                $args['body']                    = wp_json_encode( $params );
            } else {
                $args['body'] = $params;
            }
        }

        $response = wp_remote_request( $url, $args );
        if ( is_wp_error( $response ) ) {
            return $this->fail( $response->get_error_message() );
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $data ) ) {
            return $this->fail( 'Invalid JSON response from Meta (HTTP ' . wp_remote_retrieve_response_code( $response ) . ').' );
        }

        if ( isset( $data['error'] ) ) {
            $e   = $data['error'];
            $msg = $e['error_data']['details'] ?? $e['message'] ?? 'Unknown Meta API error';
            return $this->fail( $msg, (int) ( $e['code'] ?? 0 ) );
        }

        return [ 'success' => true, 'data' => $data ];
    }
}

<?php
/**
 * Plugin Name:  WA Meta Control
 * Plugin URI:   https://www.facebook.com/a7medfaheem
 * Description:  Unified WhatsApp control panel using the Meta WhatsApp Cloud API directly — order confirmation, order chat, bulk messaging, shipping notifications, and a full WP Admin settings page.
 * Version:      3.0.0
 * Text Domain:  wa-meta-control
 * Author:       Charoon
 * Author URI:   https://www.facebook.com/a7medfaheem
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 */

defined( 'ABSPATH' ) || exit;

// ─── Constants ────────────────────────────────────────────────────────────────
define( 'WAMC_VERSION',    '3.0.0' );
define( 'WAMC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WAMC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WAMC_OPTION_KEY', 'wamc_settings' );

// ─── WooCommerce HPOS compatibility ───────────────────────────────────────────
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );

// ─── Auto-generate webhook slug on activation ─────────────────────────────────
register_activation_hook( __FILE__, 'wamc_on_activate' );
function wamc_on_activate(): void {
    // Generate a cryptographically-random webhook slug if one doesn't exist yet
    if ( ! get_option( 'wamc_webhook_slug' ) ) {
        update_option( 'wamc_webhook_slug', bin2hex( random_bytes( 24 ) ), false );
    }

    // Default verify token for Meta's webhook handshake
    $settings = get_option( WAMC_OPTION_KEY, [] );
    if ( empty( $settings['verify_token'] ) ) {
        $settings['verify_token'] = wp_generate_password( 24, false );
        update_option( WAMC_OPTION_KEY, $settings );
    }

    // Create CRM notifications table
    require_once WAMC_PLUGIN_DIR . 'includes/class-order-chat.php';
    WAMC_CRM::create_table();
}

// ─── Includes ─────────────────────────────────────────────────────────────────
require_once WAMC_PLUGIN_DIR . 'includes/class-meta-client.php';
require_once WAMC_PLUGIN_DIR . 'includes/class-message-log.php';
require_once WAMC_PLUGIN_DIR . 'includes/class-window-tracker.php';
require_once WAMC_PLUGIN_DIR . 'includes/class-dispatcher.php';
require_once WAMC_PLUGIN_DIR . 'includes/class-rate-limiter.php';
require_once WAMC_PLUGIN_DIR . 'includes/class-cache.php';
require_once WAMC_PLUGIN_DIR . 'includes/class-order-lookup.php';
require_once WAMC_PLUGIN_DIR . 'includes/class-settings.php';
require_once WAMC_PLUGIN_DIR . 'includes/class-admin-page.php';
require_once WAMC_PLUGIN_DIR . 'includes/class-order-confirmation.php';
require_once WAMC_PLUGIN_DIR . 'includes/class-order-notify.php';
require_once WAMC_PLUGIN_DIR . 'includes/class-order-chat.php';
require_once WAMC_PLUGIN_DIR . 'includes/class-sse.php';
require_once WAMC_PLUGIN_DIR . 'includes/class-shipping-notify.php';
require_once WAMC_PLUGIN_DIR . 'includes/class-bulk-sender.php';

// ─── Helper: get settings ─────────────────────────────────────────────────────
function wamc_setting( string $key, $default = '' ) {
    $settings = get_option( WAMC_OPTION_KEY, [] );
    return $settings[ $key ] ?? $default;
}

// ─── Helper: get a shared API client instance ─────────────────────────────────
function wamc_api(): WAMC_Meta_Client {
    static $instance = null;
    if ( $instance === null ) {
        $instance = new WAMC_Meta_Client(
            wamc_setting( 'api_key' ),
            wamc_setting( 'phone_number_id' ),
            wamc_setting( 'waba_id' ),
            wamc_setting( 'api_version', 'v21.0' )
        );
    }
    return $instance;
}

// ─── Bootstrap feature classes ────────────────────────────────────────────────
add_action( 'plugins_loaded', function () {
    WAMC_Settings::init();
    WAMC_Order_Lookup::init();
    add_action( 'wamc_process_incoming', [ 'WAMC_Confirm_Control', 'handle_incoming' ] );
    // 24h window: track last inbound per phone, called from handle_incoming via do_action
    WAMC_Admin_Page::init();
    WAMC_SSE::init();

    if ( wamc_setting( 'enable_confirm', '1' ) ) {
        WAMC_Confirm_Control::init();
    } else {
        // Webhook must stay reachable for Meta verification, statuses and the chat log
        add_action( 'rest_api_init', [ 'WAMC_Confirm_Control', 'register_webhook_route' ] );
    }
    if ( wamc_setting( 'enable_order_notify', '1' ) ) {
        WAMC_Order_Notify::init();
    }
    if ( wamc_setting( 'enable_crm', '1' ) ) {
        WAMC_CRM::init();
    }
    if ( wamc_setting( 'enable_qp_notify', '1' ) ) {
        WAMC_QP_Notify::init();
    }
    if ( wamc_setting( 'enable_bulk', '1' ) ) {
        WAMC_Bulk_Send::init();
    }
} );


// ─── Admin warning: webhook needs the App Secret ──────────────────────────────
add_action( 'admin_notices', function () {
    if ( ! current_user_can( 'manage_woocommerce' ) || wamc_setting( 'app_secret' ) !== '' ) {
        return;
    }
    echo '<div class="notice notice-warning"><p><strong>WA Meta Control:</strong> Meta App Secret is not set — incoming webhooks (replies, button taps, delivery statuses) are rejected until you add it in the plugin settings.</p></div>';
} );

<?php
/**
 * Plugin Name: AneePay Crypto Gateway for WooCommerce
 * Plugin URI:  https://aneepay.com/
 * Description: Accept stablecoin crypto payments (USDT, USDC, DAI) on Polygon & Amoy testnet via the non-custodial AneePay gateway. Compatible with HPOS (High-Performance Order Storage).
 * Version:     1.2.0
 * Author:      AneePay
 * Author URI:  https://aneepay.com/
 * License:     MIT
 * License URI: https://opensource.org/licenses/MIT
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 9.4
 * Text Domain: aneepay-crypto-gateway
 * Domain Path: /languages
 *
 * @package AneePay_Crypto_Gateway
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'ANEEPAY_VERSION' ) ) {
	define( 'ANEEPAY_VERSION', '1.2.0' );
}

if ( ! defined( 'ANEEPAY_PLUGIN_FILE' ) ) {
	define( 'ANEEPAY_PLUGIN_FILE', __FILE__ );
}

if ( ! defined( 'ANEEPAY_PLUGIN_URL' ) ) {
	define( 'ANEEPAY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

if ( ! defined( 'ANEEPAY_PLUGIN_DIR' ) ) {
	define( 'ANEEPAY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'ANEEPAY_API_BASE' ) ) {
	define( 'ANEEPAY_API_BASE', 'https://api.aneepay.com' );
}

if ( ! defined( 'ANEEPAY_PAYMENT_GATEWAY_ID' ) ) {
	define( 'ANEEPAY_PAYMENT_GATEWAY_ID', 'aneepay_crypto' );
}

require_once ANEEPAY_PLUGIN_DIR . 'includes/class-crypto-api-handler.php';
require_once ANEEPAY_PLUGIN_DIR . 'includes/class-usd-converter.php';

/**
 * Declare compatibility with High-Performance Order Storage (HPOS).
 *
 * @return void
 */
function aneepay_declare_hpos_compatibility() {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', ANEEPAY_PLUGIN_FILE, true );
	}
}
add_action( 'before_woocommerce_init', 'aneepay_declare_hpos_compatibility' );

/**
 * Register the gateway with WooCommerce.
 *
 * @param array $methods Registered payment gateway class names.
 * @return array
 */
function aneepay_add_gateway( $methods ) {
	$methods[] = 'WC_Gateway_AneePay_Crypto';
	return $methods;
}

/**
 * Load the gateway class only once WooCommerce is ready.
 *
 * The gateway extends `WC_Payment_Gateway`, which WooCommerce defines during
 * its own `plugins_loaded` bootstrap. Loading the class file earlier (e.g. at
 * plugin include time) would crash with "Class 'WC_Payment_Gateway' not found".
 *
 * @return void
 */
function aneepay_load_gateway_class() {
	if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}

	require_once ANEEPAY_PLUGIN_DIR . 'includes/class-wc-gateway-crypto.php';

	add_filter( 'woocommerce_payment_gateways', 'aneepay_add_gateway' );
}
add_action( 'plugins_loaded', 'aneepay_load_gateway_class', 20 );

/**
 * Show an admin notice if WooCommerce is not active.
 *
 * @return void
 */
function aneepay_missing_wc_notice() {
	if ( class_exists( 'WooCommerce' ) ) {
		return;
	}

	$message = sprintf(
		/* translators: %s: plugin name */
		__( 'AneePay Crypto Gateway requires WooCommerce to be installed and activated. Please install and activate WooCommerce to use the %s plugin.', 'aneepay-crypto-gateway' ),
		'<strong>AneePay Crypto Gateway for WooCommerce</strong>'
	);

	echo '<div class="notice notice-error"><p>' . wp_kses_post( $message ) . '</p></div>';
}
add_action( 'admin_notices', 'aneepay_missing_wc_notice' );

/**
 * Activation hook.
 *
 * @return void
 */
function aneepay_activate_plugin() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die( esc_html__( 'AneePay Crypto Gateway requires WooCommerce to be installed and activated. Plugin deactivated.', 'aneepay-crypto-gateway' ) );
	}

	aneepay_schedule_sync();
}
register_activation_hook( __FILE__, 'aneepay_activate_plugin' );

/**
 * Deactivation hook.
 *
 * @return void
 */
function aneepay_deactivate_plugin() {
	wp_clear_scheduled_hook( 'aneepay_sync_pending_orders' );
}
register_deactivation_hook( __FILE__, 'aneepay_deactivate_plugin' );

/**
 * Schedule (or re-schedule) the pending-orders sync using the current
 * configured interval.
 *
 * @return void
 */
function aneepay_schedule_sync() {
	wp_clear_scheduled_hook( 'aneepay_sync_pending_orders' );

	// Read the option directly — the gateway class may not be loaded yet
	// during activation (plugins_loaded has already fired at that point).
	$settings  = get_option( 'woocommerce_' . ANEEPAY_PAYMENT_GATEWAY_ID . '_settings', array() );
	$intervals = array( 'every_five_minutes', 'every_ten_minutes', 'every_fifteen_minutes', 'every_thirty_minutes' );
	$interval  = isset( $settings['sync_interval'] ) ? (string) $settings['sync_interval'] : 'every_five_minutes';

	if ( ! in_array( $interval, $intervals, true ) ) {
		$interval = 'every_five_minutes';
	}

	wp_schedule_event( time() + 60, $interval, 'aneepay_sync_pending_orders' );
}

/**
 * Register the custom cron intervals.
 *
 * @param array $schedules Cron schedule intervals.
 * @return array
 */
function aneepay_add_cron_interval( $schedules ) {
	$schedules['every_five_minutes']    = array(
		'interval' => 5 * MINUTE_IN_SECONDS,
		'display'  => __( 'Every five minutes', 'aneepay-crypto-gateway' ),
	);
	$schedules['every_ten_minutes']     = array(
		'interval' => 10 * MINUTE_IN_SECONDS,
		'display'  => __( 'Every ten minutes', 'aneepay-crypto-gateway' ),
	);
	$schedules['every_fifteen_minutes'] = array(
		'interval' => 15 * MINUTE_IN_SECONDS,
		'display'  => __( 'Every fifteen minutes', 'aneepay-crypto-gateway' ),
	);
	$schedules['every_thirty_minutes']  = array(
		'interval' => 30 * MINUTE_IN_SECONDS,
		'display'  => __( 'Every thirty minutes', 'aneepay-crypto-gateway' ),
	);
	return $schedules;
}
add_filter( 'cron_schedules', 'aneepay_add_cron_interval' );

/**
 * Enqueue frontend script used to poll the payment status.
 *
 * @return void
 */
function aneepay_enqueue_frontend_scripts() {
	if ( ! is_checkout() && ! is_wc_endpoint_url( 'order-pay' ) ) {
		return;
	}

	wp_enqueue_script(
		'aneepay-checkout',
		ANEEPAY_PLUGIN_URL . 'assets/js/aneepay-checkout.js',
		array(),
		ANEEPAY_VERSION,
		true
	);

	wp_localize_script(
		'aneepay-checkout',
		'aneepayParams',
		array(
			'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
			'nonce'         => wp_create_nonce( 'aneepay_status' ),
			'checkingLabel' => __( 'Checking...', 'aneepay-crypto-gateway' ),
		)
	);

	wp_register_style( 'aneepay-checkout', false, array(), ANEEPAY_VERSION );
	wp_add_inline_style(
		'aneepay-checkout',
		'.aneepay-checkout-breakdown{margin:6px 0 0;padding:8px 10px;background:#f6f7f7;border:1px solid #e0e0e0;border-radius:4px;font-size:13px;line-height:1.5;}' .
		'.aneepay-checkout-breakdown .aneepay-breakdown-title{font-weight:600;margin:0 0 4px;}' .
		'.aneepay-checkout-breakdown ul{margin:0;padding-left:18px;color:#50575e;}' .
		'.aneepay-checkout-breakdown li{margin-bottom:2px;}' .
		'.aneepay-checkout-breakdown .aneepay-breakdown-source{font-size:12px;color:#8c8f94;font-style:italic;}' .
		'.aneepay-result{max-width:640px;margin:40px auto;padding:0 16px;text-align:center;}' .
		'.aneepay-result-card{background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:32px 24px;}' .
		'.aneepay-result-icon{width:56px;height:56px;line-height:56px;font-size:28px;border-radius:50%;margin:0 auto 16px;}' .
		'.aneepay-result-icon.is-success{background:#e6f4ea;color:#008a20;}' .
		'.aneepay-result-icon.is-fail{background:#fde8e8;color:#d63638;}' .
		'.aneepay-result-title{margin:0 0 8px;}' .
		'.aneepay-result-text{margin:0 0 16px;color:#50575e;}' .
		'.aneepay-result-order{background:#f6f7f7;border:1px solid #e0e0e0;border-radius:6px;padding:12px 16px;margin:16px 0;text-align:left;font-size:14px;}' .
		'.aneepay-result-order p{margin:2px 0;}' .
		'.aneepay-result-actions{margin:16px 0 0;}' .
		'.aneepay-result-actions .button{margin:0 4px;}' .
		'.aneepay-payment-card{max-width:560px;margin:16px auto;background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:24px;text-align:center;}' .
		'.aneepay-payment-spinner{width:40px;height:40px;margin:0 auto 16px;border:4px solid #e0e0e0;border-top-color:#7f54b3;border-radius:50%;animation:aneepay-spin .8s linear infinite;}' .
		'@keyframes aneepay-spin{to{transform:rotate(360deg)}}' .
		'.aneepay-payment-title{margin:0 0 6px;font-size:16px;}' .
		'.aneepay-payment-sub{margin:0 0 12px;color:#50575e;}' .
		'.aneepay-payment-amount{margin:0 0 12px;}' .
		'.aneepay-payment-info{background:#f6f7f7;border:1px solid #e0e0e0;border-radius:6px;padding:10px 14px;margin:0 0 16px;text-align:left;font-size:13px;color:#50575e;}' .
		'.aneepay-payment-info p{margin:0;}' .
		'.aneepay-payment-actions .button{min-width:160px;}'
	);
	wp_enqueue_style( 'aneepay-checkout' );
}
add_action( 'wp_enqueue_scripts', 'aneepay_enqueue_frontend_scripts' );

/**
 * Register the webhook REST route and the status polling AJAX actions.
 *
 * @return void
 */
function aneepay_register_endpoints() {
	register_rest_route(
		'aneepay/v1',
		'/webhook',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'aneepay_handle_webhook',
			'permission_callback' => '__return_true',
		)
	);

	add_action( 'wp_ajax_aneepay_check_status', 'aneepay_ajax_check_status' );
	add_action( 'wp_ajax_nopriv_aneepay_check_status', 'aneepay_ajax_check_status' );

	// Admin-only: test the AneePay connection from the gateway settings page.
	add_action( 'wp_ajax_aneepay_check_connection', 'aneepay_ajax_check_connection' );

	// Admin-only: in-app request log panel.
	add_action( 'wp_ajax_aneepay_get_logs', 'aneepay_ajax_get_logs' );
	add_action( 'wp_ajax_aneepay_clear_logs', 'aneepay_ajax_clear_logs' );
}
add_action( 'rest_api_init', 'aneepay_register_endpoints' );

/**
 * Admin AJAX: return the in-app request log.
 *
 * @return void
 */
function aneepay_ajax_get_logs() {
	check_ajax_referer( 'aneepay_logs', 'nonce' );

	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'aneepay-crypto-gateway' ) ) );
	}

	$gateway = new WC_Gateway_AneePay_Crypto();
	$logs    = $gateway->api_handler->get_request_log();

	wp_send_json_success( array( 'logs' => $logs, 'count' => count( $logs ) ) );
}

/**
 * Admin AJAX: clear the in-app request log.
 *
 * @return void
 */
function aneepay_ajax_clear_logs() {
	check_ajax_referer( 'aneepay_logs', 'nonce' );

	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'aneepay-crypto-gateway' ) ) );
	}

	$gateway = new WC_Gateway_AneePay_Crypto();
	$gateway->api_handler->clear_request_log();

	wp_send_json_success( array( 'cleared' => true ) );
}

/**
 * Admin AJAX: verify the AneePay connection (Account ID, domain, safe status).
 *
 * @return void
 */
function aneepay_ajax_check_connection() {
	check_ajax_referer( 'aneepay_test_connection', 'nonce' );

	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'aneepay-crypto-gateway' ) ) );
	}

	$gateway = new WC_Gateway_AneePay_Crypto();
	$result  = $gateway->api_handler->test_connection();

	if ( ! empty( $result['ok'] ) ) {
		wp_send_json_success( $result );
	}

	wp_send_json_error( $result );
}

/**
 * Enqueue admin styles for the gateway settings page.
 *
 * @param string $hook_suffix Current admin page.
 * @return void
 */
function aneepay_enqueue_admin_styles( $hook_suffix ) {
	if ( false === strpos( $hook_suffix, 'wc-settings' ) ) {
		return;
	}

	wp_register_style( 'aneepay-admin', false, array(), ANEEPAY_VERSION );
	wp_add_inline_style(
		'aneepay-admin',
		'.aneepay-mode-badge{margin:8px 0;padding:8px 12px;background:#f6f7f7;border:1px solid #e0e0e0;border-radius:4px;font-size:13px;}' .
		'.aneepay-mode-pill{display:inline-block;padding:1px 8px;border-radius:10px;color:#fff;font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.02em;}' .
		'.aneepay-mode-live .aneepay-mode-pill{background:#008a20;}' .
		'.aneepay-mode-sandbox .aneepay-mode-pill{background:#c25600;}' .
		'.aneepay-setup-card{margin:8px 0 16px;padding:12px 16px;background:#eef6fb;border:1px solid #c3ddd9;border-radius:6px;}' .
		'.aneepay-setup-card ol{margin:6px 0 12px;padding-left:20px;}' .
		'.aneepay-endpoint{display:inline-block;word-break:break-all;}' .
		'.aneepay-test-connection{margin:8px 0 16px;padding:12px 16px;background:#f9f9f9;border:1px solid #ddd;border-radius:6px;}' .
		'.aneepay-conversion-example{margin:8px 0 16px;padding:12px 16px;background:#f9f9f9;border:1px solid #ddd;border-radius:6px;}' .
		'.aneepay-conversion-example ul{margin:6px 0 0;padding-left:18px;}' .
		'.aneepay-request-log{margin:8px 0 16px;padding:12px 16px;background:#f9f9f9;border:1px solid #ddd;border-radius:6px;}' .
		'.aneepay-request-log .aneepay-log-panel{margin-top:10px;overflow:auto;max-height:360px;}' .
		'.aneepay-log-panel table{font-size:12px;}' .
		'.aneepay-log-panel td{word-break:break-all;}' .
		'.aneepay-info-block{margin:8px 0 16px;padding:12px 16px;background:#f9f9f9;border:1px solid #ddd;border-radius:6px;}' .
		'.aneepay-info-block h3{margin:0 0 8px;}' .
		'.aneepay-info-block ul{margin:0;padding-left:18px;}'
	);
	wp_enqueue_style( 'aneepay-admin' );
}
add_action( 'admin_enqueue_scripts', 'aneepay_enqueue_admin_styles' );

/**
 * Register the success/fail query variable used by the store-side
 * confirmation pages.
 *
 * @param array $vars Public query variables.
 * @return array
 */
function aneepay_add_query_vars( $vars ) {
	$vars[] = 'aneepay';
	return $vars;
}
add_filter( 'query_vars', 'aneepay_add_query_vars' );

/**
 * Render the store-side success/fail confirmation pages.
 *
 * AneePay redirects the customer browser to the account-level SUCCESS_URL /
 * FAIL_URL configured in the AneePay panel. The plugin exposes two static
 * endpoints for that:
 *  - /?aneepay=success
 *  - /?aneepay=fail
 *
 * The relevant order is tracked in a cookie set when the customer was sent
 * to the hosted checkout, so the page can show per-order context.
 *
 * @return void
 */
function aneepay_render_result_page() {
	global $wp;

	$result = isset( $wp->query_vars['aneepay'] ) ? sanitize_key( $wp->query_vars['aneepay'] ) : '';

	if ( ! in_array( $result, array( 'success', 'fail' ), true ) ) {
		return;
	}

	// The cookie is signed and expiring (see aneepay_get_cookie_order()); an
	// unsigned/forged cookie yields no order context instead of leaking one.
	$order = function_exists( 'aneepay_get_cookie_order' ) ? aneepay_get_cookie_order() : null;

	if ( $order ) {
		aneepay_sync_order_from_api( $order );
		$status = $order->get_meta( '_aneepay_payment_status', true );
	} else {
		$status = '';
	}

	aneepay_render_result_html( $result, $order, ( 'success' === $status ) );

	exit;
}
add_action( 'template_redirect', 'aneepay_render_result_page' );

/**
 * Sync an order's payment status from the AneePay API before rendering a
 * confirmation page (the webhook may still be in flight when the customer
 * returns to the store).
 *
 * @param WC_Order $order Order object.
 * @return void
 */
function aneepay_sync_order_from_api( $order ) {
	if ( 'success' === $order->get_meta( '_aneepay_payment_status', true ) ) {
		return;
	}

	$payment_id = (string) $order->get_meta( '_aneepay_payment_id', true );

	if ( '' === $payment_id ) {
		return;
	}

	$gateway = new WC_Gateway_AneePay_Crypto();
	$data    = $gateway->api_handler->get_payment( $payment_id );

	if ( is_array( $data ) && ! empty( $data['status'] ) ) {
		aneepay_apply_payment_status( $order, sanitize_key( $data['status'] ) );
	}
}

/**
 * Output the store-side success/fail confirmation page.
 *
 * @param string        $result  success|fail.
 * @param WC_Order|null $order   Order object when known.
 * @param bool          $is_paid Whether the payment is already confirmed.
 * @return void
 */
function aneepay_render_result_html( $result, $order, $is_paid ) {
	// A webhook may have confirmed the payment right after AneePay redirected
	// the customer; always reflect the real state.
	if ( $is_paid ) {
		$result = 'success';
	}

	$args = array(
		'result'  => $result,
		'order'   => $order,
		'is_paid' => $is_paid,
	);

	if ( function_exists( 'wc_get_template' ) ) {
		wc_get_template( 'aneepay-result.php', $args, '', ANEEPAY_PLUGIN_DIR . 'templates/' );
	} else {
		extract( $args, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		include ANEEPAY_PLUGIN_DIR . 'templates/aneepay-result.php';
	}
}

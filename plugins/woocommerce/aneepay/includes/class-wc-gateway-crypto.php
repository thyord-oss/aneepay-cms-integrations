<?php
/**
 * AneePay Crypto payment gateway.
 *
 * @package AneePay_Crypto_Gateway
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_Gateway_AneePay_Crypto
 */
class WC_Gateway_AneePay_Crypto extends WC_Payment_Gateway {

	/**
	 * API handler instance.
	 *
	 * @var AneePay_Crypto_API_Handler
	 */
	protected $api_handler;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = ANEEPAY_PAYMENT_GATEWAY_ID;
		$this->icon               = apply_filters( 'aneepay_gateway_icon', '' );
		$this->has_fields         = false;
		$this->method_title       = __( 'AneePay Crypto', 'aneepay-crypto-gateway' );
		$this->method_description = __( 'Accept stablecoin payments (USDT, USDC, DAI) on Polygon and the Amoy testnet through the non-custodial AneePay gateway.', 'aneepay-crypto-gateway' );

		$this->supports = array(
			'products',
		);

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		$this->api_handler = new AneePay_Crypto_API_Handler( $this );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'render_payment' ), 10, 1 );
		add_action( 'woocommerce_view_order', array( $this, 'render_payment' ), 10, 1 );

		static $aneepay_description_filter_hooked = false;

		if ( ! $aneepay_description_filter_hooked ) {
			add_filter( 'woocommerce_gateway_description', array( $this, 'filter_gateway_description' ), 20, 2 );
			$aneepay_description_filter_hooked = true;
		}
	}

	/**
	 * Payment gateway settings fields.
	 *
	 * @return void
	 */
	public function init_form_fields() {
		$this->form_fields = array(

			// ------------------------------------------------------------------
			// Section: Basic
			// ------------------------------------------------------------------
			array(
				'title' => __( 'Basic', 'aneepay-crypto-gateway' ),
				'type'  => 'title',
				'desc'  => __( 'Enable the gateway and set how it appears to customers.', 'aneepay-crypto-gateway' ),
				'id'    => 'aneepay_section_basic',
			),
			'enabled'     => array(
				'title'       => __( 'Enable/Disable', 'aneepay-crypto-gateway' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable AneePay crypto payments', 'aneepay-crypto-gateway' ),
				'default'     => 'no',
			),
			'title'       => array(
				'title'       => __( 'Title', 'aneepay-crypto-gateway' ),
				'type'        => 'text',
				'description' => __( 'Payment method title shown to the customer during checkout.', 'aneepay-crypto-gateway' ),
				'default'     => __( 'Pay with Crypto (USDT/USDC/DAI)', 'aneepay-crypto-gateway' ),
				'desc_tip'    => true,
			),
			'description' => array(
				'title'       => __( 'Description', 'aneepay-crypto-gateway' ),
				'type'        => 'textarea',
				'description' => __( 'Payment method description shown to the customer during checkout.', 'aneepay-crypto-gateway' ),
				'default'     => __( 'Pay instantly with a stablecoin on the Polygon network.', 'aneepay-crypto-gateway' ),
				'desc_tip'    => true,
			),
			array(
				'type' => 'sectionend',
				'id'   => 'aneepay_section_basic',
			),

			// ------------------------------------------------------------------
			// Section: Connection
			// ------------------------------------------------------------------
			array(
				'title' => __( 'AneePay Connection', 'aneepay-crypto-gateway' ),
				'type'  => 'title',
				'desc'  => __( 'Your AneePay account credentials and the webhook signature used to verify incoming payment notifications.', 'aneepay-crypto-gateway' ),
				'id'    => 'aneepay_section_connection',
			),
			'account_id'  => array(
				'title'             => __( 'Account ID', 'aneepay-crypto-gateway' ),
				'type'              => 'text',
				'description'       => __( 'Your AneePay account UUID. Required — sent in the X-Account-Id header for every API request.', 'aneepay-crypto-gateway' ),
				'default'           => '',
				'desc_tip'          => true,
				'custom_attributes' => array(
					'pattern' => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}',
					'class'   => 'regular-text',
				),
			),
			'webhook_secret' => array(
				'title'       => __( 'Webhook Secret', 'aneepay-crypto-gateway' ),
				'type'        => 'password',
				'description' => __( 'Your AneePay webhook secret. Used to verify the X-AneePay-Signature (HMAC-SHA256) on webhook calls. Required to confirm payments.', 'aneepay-crypto-gateway' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'site_domain' => array(
				'title'       => __( 'Domain', 'aneepay-crypto-gateway' ),
				'type'        => 'text',
				'description' => __( 'Your verified merchant domain. Sent in the Origin header. Leave empty to use your site host automatically.', 'aneepay-crypto-gateway' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'test_mode'   => array(
				'title'       => __( 'Test/Sandbox Mode', 'aneepay-crypto-gateway' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable sandbox mode (Amoy testnet)', 'aneepay-crypto-gateway' ),
				'description' => __( 'When enabled, payments are created on the Polygon Amoy testnet with USDC and the is_safe check is bypassed.', 'aneepay-crypto-gateway' ),
				'default'     => 'no',
				'desc_tip'    => true,
			),
			array(
				'type' => 'sectionend',
				'id'   => 'aneepay_section_connection',
			),

			// ------------------------------------------------------------------
			// Section: Coin & Blockchain
			// ------------------------------------------------------------------
			array(
				'title' => __( 'Coin & Blockchain', 'aneepay-crypto-gateway' ),
				'type'  => 'title',
				'desc'  => __( 'Select the stablecoin your customers pay in and the blockchain (network) used to settle the funds.', 'aneepay-crypto-gateway' ),
				'id'    => 'aneepay_section_coin',
			),
			'token'       => array(
				'title'       => __( 'Cryptocurrency', 'aneepay-crypto-gateway' ),
				'type'        => 'select',
				'description' => __( 'The stablecoin used to charge customers. Funds go directly to your wallet.', 'aneepay-crypto-gateway' ),
				'default'     => 'usdt',
				'desc_tip'    => true,
				'options'     => array(
					'usdt' => 'USDT (Tether)',
					'usdc' => 'USDC (USD Coin)',
					'dai'  => 'DAI',
				),
			),
			'network'     => array(
				'title'       => __( 'Blockchain', 'aneepay-crypto-gateway' ),
				'type'        => 'select',
				'description' => __( 'Polygon (Mainnet) for live payments. Amoy (Testnet) is used automatically in Test Mode.', 'aneepay-crypto-gateway' ),
				'default'     => 'polygon',
				'desc_tip'    => true,
				'options'     => array(
					'polygon' => 'Polygon (Mainnet)',
					'amoy'    => 'Amoy (Testnet)',
				),
			),
			'rate_source' => array(
				'title'       => __( 'Exchange Rate Source', 'aneepay-crypto-gateway' ),
				'type'        => 'select',
				'description' => __( 'How the store currency is converted to USD. Auto uses the live Frankfurter (ECB) rate and falls back to the manual rate below.', 'aneepay-crypto-gateway' ),
				'default'     => 'auto',
				'desc_tip'    => true,
				'options'     => array(
					'auto'   => __( 'Auto (Frankfurter/ECB) + manual fallback', 'aneepay-crypto-gateway' ),
					'manual' => __( 'Manual rate only', 'aneepay-crypto-gateway' ),
				),
			),
			'exchange_rate' => array(
				'title'             => __( 'Manual Exchange Rate', 'aneepay-crypto-gateway' ),
				'type'              => 'number',
				'description'       => __( 'Used when the auto rate is unavailable. How many units of the store currency make one token (≈1 USD). Example for USD: 1, for EUR: 0.85.', 'aneepay-crypto-gateway' ),
				'default'           => '',
				'desc_tip'          => true,
				'custom_attributes' => array(
					'step'  => '0.000001',
					'min'   => '0',
				),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'aneepay_section_coin',
			),

			// ------------------------------------------------------------------
			// Section: Advanced
			// ------------------------------------------------------------------
			array(
				'title' => __( 'Advanced', 'aneepay-crypto-gateway' ),
				'type'  => 'title',
				'desc'  => __( 'Troubleshooting options.', 'aneepay-crypto-gateway' ),
				'id'    => 'aneepay_section_advanced',
			),
			'debug'       => array(
				'title'       => __( 'Debug Log', 'aneepay-crypto-gateway' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable logging', 'aneepay-crypto-gateway' ),
				'description' => __( 'Log AneePay API requests and responses. Logs can be found in WooCommerce > Status > Logs (source: aneepay).', 'aneepay-crypto-gateway' ),
				'default'     => 'no',
				'desc_tip'    => true,
			),
			'sync_interval' => array(
				'title'       => __( 'Status Sync Interval', 'aneepay-crypto-gateway' ),
				'type'        => 'select',
				'description' => __( 'How often the plugin polls AneePay for pending orders (fallback to the webhook).', 'aneepay-crypto-gateway' ),
				'default'     => 'every_five_minutes',
				'desc_tip'    => true,
				'options'     => array(
					'every_five_minutes'    => __( 'Every 5 minutes', 'aneepay-crypto-gateway' ),
					'every_ten_minutes'     => __( 'Every 10 minutes', 'aneepay-crypto-gateway' ),
					'every_fifteen_minutes' => __( 'Every 15 minutes', 'aneepay-crypto-gateway' ),
					'every_thirty_minutes'  => __( 'Every 30 minutes', 'aneepay-crypto-gateway' ),
				),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'aneepay_section_advanced',
			),
		);
	}

	/**
	 * Validate settings before they are saved.
	 *
	 * Enforces the required connection fields (Account ID + Webhook Secret) and
	 * validates the manual exchange rate. Returns false (and shows errors)
	 * without saving when validation fails.
	 *
	 * @return bool
	 */
	public function process_admin_options() {
		$failures = array();

		// Account ID: required + UUID.
		$account_id = (string) $this->posted_value( 'account_id' );

		if ( '' === $account_id ) {
			$failures[] = __( 'AneePay Account ID is required.', 'aneepay-crypto-gateway' );
		} elseif ( ! $this->is_valid_uuid( $account_id ) ) {
			$failures[] = __( 'AneePay Account ID must be a valid UUID.', 'aneepay-crypto-gateway' );
		}

		// Webhook Secret: required. A blank field on re-save keeps the
		// previously stored secret, so the effective value is used.
		$posted_secret   = (string) $this->posted_value( 'webhook_secret' );
		$effective_secret = '' !== $posted_secret ? $posted_secret : (string) $this->get_option( 'webhook_secret' );

		if ( '' === $effective_secret ) {
			$failures[] = __( 'AneePay Webhook Secret is required to verify payment confirmations.', 'aneepay-crypto-gateway' );
		}

		// Manual exchange rate: when provided, must be a positive number.
		$rate_value = (string) $this->posted_value( 'exchange_rate' );

		if ( '' !== $rate_value ) {
			$rate = (float) $rate_value;

			if ( ! is_finite( $rate ) || $rate <= 0 ) {
				$failures[] = __( 'Manual Exchange Rate must be a positive number.', 'aneepay-crypto-gateway' );
			}
		}

		if ( ! empty( $failures ) ) {
			foreach ( $failures as $failure ) {
				WC_Admin_Settings::add_error( $failure );
			}

			return false;
		}

		$result = parent::process_admin_options();

		if ( $result ) {
			aneepay_schedule_sync();
		}

		return $result;
	}

	/**
	 * Read a raw posted setting value by its field key.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	protected function posted_value( $key ) {
		$field_key = $this->get_field_key( $key );

		return isset( $_POST[ $field_key ] ) ? wc_clean( wp_unslash( $_POST[ $field_key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}

	/**
	 * Whether a string is a valid versionless UUID.
	 *
	 * @param string $id Value to check.
	 * @return bool
	 */
	protected function is_valid_uuid( $id ) {
		return (bool) preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', trim( (string) $id ) );
	}

	/**
	 * Gateway settings page. Appends the webhook endpoint hint.
	 *
	 * @return void
	 */
	public function admin_options() {
		$secret     = (string) $this->get_option( 'webhook_secret' );
		$account_id = (string) $this->get_option( 'account_id' );

		$webhook_url = rest_url( 'aneepay/v1/webhook' );
		$success_url = home_url( '/?aneepay=success' );
		$fail_url    = home_url( '/?aneepay=fail' );

		// Mode badge (SANDBOX / LIVE + token + network) at the top.
		$this->render_mode_badge();

		if ( '' === $account_id || '' === $secret ) {
			$this->render_quick_setup( $account_id, $secret, $webhook_url, $success_url, $fail_url );
		}

		if ( '' === $secret ) {
			echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'AneePay webhook secret is not set.', 'aneepay-crypto-gateway' ) . '</strong> ' . esc_html__( 'Payment confirmations cannot be verified and webhook calls will be rejected (HTTP 401). Copy the Webhook Secret from your AneePay account panel into the field below.', 'aneepay-crypto-gateway' ) . '</p></div>';
		}

		if ( '' === $account_id ) {
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'AneePay Account ID is not set.', 'aneepay-crypto-gateway' ) . '</strong> ' . esc_html__( 'Payments cannot be created until you enter your account UUID below.', 'aneepay-crypto-gateway' ) . '</p></div>';
		}

		if ( 'yes' === $this->get_option( 'test_mode' ) ) {
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Sandbox mode is enabled.', 'aneepay-crypto-gateway' ) . '</strong> ' . esc_html__( 'Payments are created on the Amoy testnet with USDC and are not real. Disable Test Mode for live payments.', 'aneepay-crypto-gateway' ) . '</p></div>';
		}

		$this->render_test_connection( $account_id );

		$this->render_conversion_example();

		$this->render_request_log();

		parent::admin_options();

		$this->render_endpoints( $webhook_url, $success_url, $fail_url );

		$this->render_info_blocks();
	}

	/**
	 * Print the SANDBOX / LIVE badge with token and network.
	 *
	 * @return void
	 */
	protected function render_mode_badge() {
		$sandbox  = 'yes' === $this->get_option( 'test_mode' );
		$label    = $sandbox ? __( 'SANDBOX', 'aneepay-crypto-gateway' ) : __( 'LIVE', 'aneepay-crypto-gateway' );
		$class    = $sandbox ? 'aneepay-mode-sandbox' : 'aneepay-mode-live';
		$token    = strtoupper( $this->api_handler->get_token() );
		$network  = $this->api_handler->get_network();
		$mode     = $this->get_option( 'enabled' );

		echo '<p class="aneepay-mode-badge ' . esc_attr( $class ) . '">';
		echo '<span class="aneepay-mode-pill">' . esc_html( $label ) . '</span>';
		echo ' &middot; ' . esc_html( $token ) . ' &middot; ' . esc_html( $network );
		if ( ! $this->enabled ) {
			echo ' &middot; <strong>' . esc_html__( 'disabled', 'aneepay-crypto-gateway' ) . '</strong>';
		}
		echo '</p>';
	}

	/**
	 * Print the step-by-step quick-setup helper block.
	 *
	 * @param string $account_id Account ID value.
	 * @param string $secret     Webhook secret value.
	 * @param string $webhook_url STATUS_URL.
	 * @param string $success_url SUCCESS_URL.
	 * @param string $fail_url    FAIL_URL.
	 * @return void
	 */
	protected function render_quick_setup( $account_id, $secret, $webhook_url, $success_url, $fail_url ) {
		echo '<div class="aneepay-setup-card">';
		echo '<h3>' . esc_html__( 'Quick setup', 'aneepay-crypto-gateway' ) . '</h3>';
		echo '<ol>';
		echo '<li>' . esc_html__( 'Create or open your account in the AneePay dashboard.', 'aneepay-crypto-gateway' ) . '</li>';
		echo '<li>' . esc_html__( 'Set your wallet address, domain and the three URLs below in the account panel.', 'aneepay-crypto-gateway' ) . '</li>';
		echo '<li>' . esc_html__( 'Copy the Account ID and Webhook Secret into the fields below.', 'aneepay-crypto-gateway' ) . '</li>';
		echo '<li>' . esc_html__( 'Save the settings and press "Test connection".', 'aneepay-crypto-gateway' ) . '</li>';
		echo '</ol>';

		echo '<p><strong>' . esc_html__( 'Set these URLs in the AneePay account panel:', 'aneepay-crypto-gateway' ) . '</strong></p>';
		echo '<p><strong>' . esc_html__( 'STATUS_URL (webhook)', 'aneepay-crypto-gateway' ) . '</strong><br><code>' . esc_url( $webhook_url ) . '</code></p>';
		echo '<p><strong>' . esc_html__( 'SUCCESS_URL', 'aneepay-crypto-gateway' ) . '</strong><br><code>' . esc_url( $success_url ) . '</code></p>';
		echo '<p><strong>' . esc_html__( 'FAIL_URL', 'aneepay-crypto-gateway' ) . '</strong><br><code>' . esc_url( $fail_url ) . '</code></p>';
		echo '</div>';
	}

	/**
	 * Print the "Test connection" button and the endpoints block.
	 *
	 * @param string $account_id Account ID value.
	 * @return void
	 */
	protected function render_test_connection( $account_id ) {
		$nonce  = wp_create_nonce( 'aneepay_test_connection' );
		$has_id = '' !== $account_id;

		echo '<div class="aneepay-test-connection">';
		echo '<button type="button" id="aneepay-test-connection" class="button" data-nonce="' . esc_attr( $nonce ) . '" ' . ( $has_id ? '' : 'disabled' ) . '>' . esc_html__( 'Test connection', 'aneepay-crypto-gateway' ) . '</button>';
		echo ' <span id="aneepay-test-result" style="margin-left:8px;"></span>';
		echo '</div>';
		?>
		<script type="text/javascript">
		(function () {
			'use strict';
			var btn = document.getElementById('aneepay-test-connection');
			var out = document.getElementById('aneepay-test-result');
			if (!btn) { return; }
			btn.addEventListener('click', function () {
				btn.disabled = true;
				out.textContent = '<?php echo esc_js( __( 'Checking...', 'aneepay-crypto-gateway' ) ); ?>';
				var body = new URLSearchParams();
				body.append('action', 'aneepay_check_connection');
				body.append('nonce', btn.getAttribute('data-nonce'));
				fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: body })
					.then(function (r) { return r.json(); })
					.then(function (d) {
						btn.disabled = false;
						if (d && d.success) {
							out.textContent = (d.data && d.data.message) || 'OK';
							out.style.color = 'green';
						} else {
							var m = (d && d.data && d.data.message) || 'Connection failed';
							out.textContent = m;
							out.style.color = 'red';
						}
					})
					.catch(function (e) {
						btn.disabled = false;
						out.textContent = 'Connection failed';
						out.style.color = 'red';
					});
			});
		})();
		</script>
		<?php
	}

	/**
	 * Print a live conversion example based on the current settings.
	 *
	 * @return void
	 */
	protected function render_conversion_example() {
		$sample   = 150.0;
		$currency = (string) get_woocommerce_currency();
		$token    = strtoupper( $this->api_handler->get_token() );

		$converter = new AneePay_USD_Converter( $this );
		$source    = '';
		$rate      = $converter->peek_fiat_per_usd( $currency, $source );

		echo '<div class="aneepay-conversion-example">';
		echo '<strong>' . esc_html__( 'Example conversion', 'aneepay-crypto-gateway' ) . '</strong>';

		if ( null === $rate || $rate <= 0 ) {
			echo '<p class="description">' . esc_html__( 'No rate is available yet. The auto rate is resolved at checkout, or set a manual exchange rate to see a preview.', 'aneepay-crypto-gateway' ) . '</p>';
		} else {
			$usd_amount  = $sample / $rate;
			$token_amount = ceil( $usd_amount * 100 ) / 100;
			$usd_per_fiat = 1 / $rate;

			echo '<ul>';
			echo '<li>' . sprintf(
				/* translators: %1$s: sample amount, %2$s: currency */
				esc_html__( 'Order: %s', 'aneepay-crypto-gateway' ),
				wp_kses_post( wc_price( $sample, array( 'currency' => $currency ) ) )
			) . '</li>';

			if ( 'USD' !== $currency ) {
				echo '<li>' . sprintf(
					/* translators: %1$s: currency, %2$s: rate */
					esc_html__( '1 %1$s = %2$s USD', 'aneepay-crypto-gateway' ),
					esc_html( $currency ),
					esc_html( number_format( $usd_per_fiat, 4, '.', '' ) )
				) . '</li>';
			}

			echo '<li>' . sprintf(
				/* translators: %1$s: USD amount, %2$s: token, %3$s: token amount */
				esc_html__( 'You pay: %1$s → %3$s %2$s', 'aneepay-crypto-gateway' ),
				wp_kses_post( wc_price( $usd_amount, array( 'currency' => 'USD' ) ) ),
				esc_html( $token ),
				esc_html( number_format( $token_amount, 2, '.', '' ) )
			) . '</li>';

			echo '<li class="description">' . ( 'manual' === $source ? esc_html__( 'Rate from shop settings.', 'aneepay-crypto-gateway' ) : esc_html__( 'Rate: ECB / Frankfurter (cached).', 'aneepay-crypto-gateway' ) ) . '</li>';
			echo '</ul>';
		}

		echo '</div>';
	}

	/**
	 * Print the collapsible "Request log" panel with a load + clear action.
	 *
	 * @return void
	 */
	protected function render_request_log() {
		$nonce = wp_create_nonce( 'aneepay_logs' );

		echo '<div class="aneepay-request-log">';
		echo '<div class="aneepay-request-log-toolbar">';
		echo '<button type="button" id="aneepay-toggle-log" class="button" data-nonce="' . esc_attr( $nonce ) . '" data-ajax="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '">' . esc_html__( 'Request log', 'aneepay-crypto-gateway' ) . '</button>';
		echo ' <button type="button" id="aneepay-clear-log" class="button" style="display:none;">' . esc_html__( 'Clear', 'aneepay-crypto-gateway' ) . '</button>';
		echo ' <span id="aneepay-log-count" class="description"></span>';
		echo '</div>';
		echo '<div id="aneepay-log-panel" class="aneepay-log-panel" style="display:none;"></div>';
		echo '</div>';
		?>
		<script type="text/javascript">
		(function () {
			'use strict';
			var toggle = document.getElementById('aneepay-toggle-log');
			var panel = document.getElementById('aneepay-log-panel');
			var clear = document.getElementById('aneepay-clear-log');
			var countEl = document.getElementById('aneepay-log-count');
			if (!toggle || !panel) { return; }
			var ajaxUrl = toggle.getAttribute('data-ajax');
			var nonce = toggle.getAttribute('data-nonce');
			var loaded = false;

			function esc(s) {
				return String(s == null ? '' : s);
			}

			function render(logs) {
				if (countEl) {
					countEl.textContent = logs.length ? ('— ' + logs.length + ' requests') : '';
				}
				if (!logs.length) {
					panel.innerHTML = '<p class="description">No requests logged yet.</p>';
					if (clear) { clear.style.display = 'none'; }
					return;
				}
				var table = document.createElement('table');
				table.className = 'widefat striped';
				var thead = document.createElement('thead');
				var hr = document.createElement('tr');
				['Time', 'Method', 'Endpoint', 'Code', 'Latency', 'Body'].forEach(function (h) {
					var th = document.createElement('th'); th.textContent = h; hr.appendChild(th);
				});
				thead.appendChild(hr); table.appendChild(thead);
				var tbody = document.createElement('tbody');
				logs.forEach(function (l) {
					var tr = document.createElement('tr');
					[[ String(new Date(l.time * 1000).toLocaleString()), l.method, l.endpoint, String(l.code), (l.latency + ' ms'), l.body ].forEach(function (v) {
						var td = document.createElement('td'); td.textContent = v; tr.appendChild(td);
					})];
					tbody.appendChild(tr);
				});
				table.appendChild(tbody);
				panel.innerHTML = '';
				panel.appendChild(table);
				if (clear) { clear.style.display = 'inline-block'; }
			}

			function load() {
				var body = new URLSearchParams();
				body.append('action', 'aneepay_get_logs');
				body.append('nonce', nonce);
				fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
					.then(function (r) { return r.json(); })
					.then(function (d) {
						if (d && d.success) { render((d.data && d.data.logs) || []); }
						else { panel.innerHTML = '<p class="description">Failed to load log.</p>'; }
					})
					.catch(function () { panel.innerHTML = '<p class="description">Failed to load log.</p>'; });
			}

			toggle.addEventListener('click', function () {
				var visible = panel.style.display !== 'none';
				if (visible) {
					panel.style.display = 'none';
				} else {
					panel.style.display = 'block';
					if (!loaded) { loaded = true; load(); }
				}
			});

			if (clear) {
				clear.addEventListener('click', function () {
					if (!window.confirm('Clear the request log?')) { return; }
					var body = new URLSearchParams();
					body.append('action', 'aneepay_clear_logs');
					body.append('nonce', nonce);
					fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
						.then(function (r) { return r.json(); })
						.then(function () { load(); });
				});
			}
		})();
		</script>
		<?php
	}

	/**
	 * Print the AneePay endpoints block (STATUS / SUCCESS / FAIL URLs).
	 *
	 * @param string $webhook_url STATUS_URL.
	 * @param string $success_url SUCCESS_URL.
	 * @param string $fail_url    FAIL_URL.
	 * @return void
	 */
	protected function render_endpoints( $webhook_url, $success_url, $fail_url ) {
		$this->render_endpoint_row( __( 'STATUS_URL (webhook)', 'aneepay-crypto-gateway' ), $webhook_url, __( 'AneePay pushes the transaction result here to keep order statuses in sync.', 'aneepay-crypto-gateway' ) );
		$this->render_endpoint_row( __( 'SUCCESS_URL', 'aneepay-crypto-gateway' ), $success_url, __( 'Customers land here after a successful payment.', 'aneepay-crypto-gateway' ) );
		$this->render_endpoint_row( __( 'FAIL_URL', 'aneepay-crypto-gateway' ), $fail_url, __( 'Customers land here when the payment is cancelled or fails.', 'aneepay-crypto-gateway' ) );

		?>
		<script type="text/javascript">
		(function () {
			'use strict';
			document.querySelectorAll('.aneepay-copy').forEach(function (btn) {
				btn.addEventListener('click', function () {
					var code = btn.closest('p') && btn.closest('p').querySelector('.aneepay-endpoint');
					if (!code) { return; }
					var text = code.getAttribute('data-url');
					if (navigator.clipboard && navigator.clipboard.writeText) {
						navigator.clipboard.writeText(text);
					}
					btn.textContent = '<?php echo esc_js( __( 'Copied', 'aneepay-crypto-gateway' ) ); ?>';
					setTimeout(function () { btn.textContent = '<?php echo esc_js( __( 'Copy', 'aneepay-crypto-gateway' ) ); ?>'; }, 1500);
				});
			});
		})();
		</script>
		<?php
	}

	/**
	 * Print a single endpoint row with a copy button.
	 *
	 * @param string $label Label.
	 * @param string $url   URL.
	 * @param string $desc  Description.
	 * @return void
	 */
	protected function render_endpoint_row( $label, $url, $desc ) {
		echo '<p>';
		echo '<strong>' . esc_html( $label ) . '</strong><br>';
		echo '<code class="aneepay-endpoint" data-url="' . esc_attr( $url ) . '">' . esc_html( $url ) . '</code>';
		echo ' <button type="button" class="button button-small aneepay-copy">' . esc_html__( 'Copy', 'aneepay-crypto-gateway' ) . '</button>';
		echo '<br><span class="description">' . esc_html( $desc ) . '</span>';
		echo '</p>';
	}

	/**
	 * Print the "How it works" and webhook-fields note blocks.
	 *
	 * @return void
	 */
	protected function render_info_blocks() {
		echo '<div class="aneepay-info-block">';
		echo '<h3>' . esc_html__( 'How it works', 'aneepay-crypto-gateway' ) . '</h3>';
		echo '<ul>';
		echo '<li>' . esc_html__( 'Non-custodial: funds go directly from the customer to the smart contract and then to your wallet.', 'aneepay-crypto-gateway' ) . '</li>';
		echo '<li>' . esc_html__( 'A fixed 0.5% fee is deducted automatically by the contract from each payment.', 'aneepay-crypto-gateway' ) . '</li>';
		echo '<li>' . esc_html__( 'No KYC is required to start accepting payments.', 'aneepay-crypto-gateway' ) . '</li>';
		echo '<li>' . esc_html__( 'Your customers pay in USDT, USDC or DAI on Polygon (live) or Amoy (testnet).', 'aneepay-crypto-gateway' ) . '</li>';
		echo '</ul>';
		echo '</div>';

		echo '<div class="aneepay-info-block">';
		echo '<h3>' . esc_html__( 'Webhook note', 'aneepay-crypto-gateway' ) . '</h3>';
		echo '<p>' . esc_html__( 'The webhook currently only contains operation_id, status and timestamp. Fields such as fee, net_amount and tx_hash are not part of the payload yet — use API polling to fetch detailed payment data.', 'aneepay-crypto-gateway' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Process the payment on checkout.
	 *
	 * Creates the payment via the AneePay API and redirects the customer to
	 * the hosted checkout page. SUCCESS_URL / FAIL_URL / STATUS_URL are
	 * configured in the AneePay account panel.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 * @throws Exception When the payment cannot be created.
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			throw new Exception( __( 'Order not found.', 'aneepay-crypto-gateway' ) );
		}

		$amount = $order->get_total();

		if ( $amount <= 0 ) {
			throw new Exception( __( 'Order total must be greater than zero.', 'aneepay-crypto-gateway' ) );
		}

		// Idempotency gate: never create a second AneePay payment for an order
		// that is still awaiting the same hosted payment. This protects against
		// double-clicks / reloads of the order-pay page (would otherwise orphan
		// a payment or risk a double charge).
		$existing_url    = (string) $order->get_meta( '_aneepay_checkout_url', true );
		$existing_status = (string) $order->get_meta( '_aneepay_payment_status', true );

		if ( '' !== $existing_url && in_array( $existing_status, array( '', 'pending' ), true ) ) {
			$order->update_status( 'pending', __( 'AneePay: reusing the existing pending payment.', 'aneepay-crypto-gateway' ) );
			wc_setcookie( 'aneepay_order', (string) $order_id, time() + HOUR_IN_SECONDS, is_ssl() );

			return array(
				'result'   => 'success',
				'redirect' => $existing_url,
			);
		}

		// Convert the order total (store currency) into the token amount.
		$converter = new AneePay_USD_Converter( $this );
		$converted = $converter->convert( $amount, $order->get_currency() );

		$token_amount = (string) number_format( $converted['token_amount'], 2, '.', '' );

		// If the API call fails/times out, the exception propagates and the
		// order is left untouched (no meta, no status change) — it never
		// looks "half-created".
		$created = $this->api_handler->create_payment( $token_amount, $order );

		$this->attach_payment_to_order( $order, $created, $converted, $token_amount, $amount );

		$order->save();

		$order->update_status(
			'pending',
			sprintf(
				/* translators: %s: payment id */
				__( 'Awaiting crypto payment. AneePay payment ID: %s', 'aneepay-crypto-gateway' ),
				$created['payment_id']
			)
		);

		wc_reduce_stock_levels( $order_id );

		// Track the order so the store-side success/fail pages (configured in
		// the AneePay panel as SUCCESS_URL / FAIL_URL) can identify it.
		wc_setcookie( 'aneepay_order', (string) $order_id, time() + HOUR_IN_SECONDS, is_ssl() );

		return array(
			'result'   => 'success',
			'redirect' => $created['checkout_url'],
		);
	}

	/**
	 * Store the payment and conversion context on the order.
	 *
	 * @param WC_Order $order       Order object.
	 * @param array    $created     AneePay create-payment response.
	 * @param array    $converted   Conversion result (token_amount, fiat_per_usd, source, ...).
	 * @param string   $token_amount Token amount string.
	 * @param float    $order_total  Original store total.
	 * @return void
	 */
	protected function attach_payment_to_order( $order, $created, $converted, $token_amount, $order_total ) {
		$order->add_meta_data( '_aneepay_payment_id', $created['payment_id'], true );
		$order->add_meta_data( '_aneepay_operation_id', $created['operation_id'], true );
		$order->add_meta_data( '_aneepay_token', $this->api_handler->get_token(), true );
		$order->add_meta_data( '_aneepay_network', $this->api_handler->get_network(), true );
		$order->add_meta_data( '_aneepay_sandbox', $this->api_handler->is_sandbox() ? 'yes' : 'no', true );
		$order->add_meta_data( '_aneepay_payment_status', 'pending', true );
		$order->add_meta_data( '_aneepay_checkout_url', $created['checkout_url'], true );

		// Store the conversion context for display and reconciliation.
		$order->add_meta_data( '_aneepay_token_amount', $token_amount, true );
		$order->add_meta_data( '_aneepay_order_currency', $converted['currency'], true );
		$order->add_meta_data( '_aneepay_order_total', (string) $order_total, true );
		$order->add_meta_data( '_aneepay_usd_amount', (string) number_format( $converted['usd_amount'], 2, '.', '' ), true );
		$order->add_meta_data( '_aneepay_fiat_per_usd', (string) $converted['fiat_per_usd'], true );
		$order->add_meta_data( '_aneepay_rate_source', $converted['source'], true );
	}

	/**
	 * Render the payment status block on the thank-you / view-order page.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function render_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order || $order->get_payment_method() !== $this->id ) {
			return;
		}

		$status = $order->get_meta( '_aneepay_payment_status', true );

		if ( in_array( $status, array( 'success', 'cancelled', 'failed' ), true ) ) {
			return;
		}

		$args = array( 'order' => $order );

		if ( function_exists( 'wc_get_template' ) ) {
			wc_get_template( 'aneepay-payment.php', $args, '', ANEEPAY_PLUGIN_DIR . 'templates/' );
		} else {
			include ANEEPAY_PLUGIN_DIR . 'templates/aneepay-payment.php';
		}
	}

	/**
	 * Append the checkout conversion breakdown to the method description.
	 *
	 * @param string $description Gateway description.
	 * @param string $gateway_id   Payment gateway id.
	 * @return string
	 */
	public function filter_gateway_description( $description, $gateway_id ) {
		if ( $this->id !== $gateway_id ) {
			return $description;
		}

		$breakdown = $this->get_checkout_breakdown_html();

		return '' === $breakdown ? $description : $description . $breakdown;
	}

	/**
	 * Build a transparent shop-currency -> token conversion breakdown for the
	 * payment method card on the checkout page.
	 *
	 * Returns an empty string when there is nothing to show (no cart, zero
	 * total, or the rate cannot be resolved).
	 *
	 * @return string
	 */
	public function get_checkout_breakdown_html() {
		if ( ! function_exists( 'WC' ) || null === WC()->cart ) {
			return '';
		}

		$total = (float) WC()->cart->get_total( 'raw' );

		if ( $total <= 0 ) {
			return '';
		}

		$currency = (string) get_woocommerce_currency();

		try {
			$converted = ( new AneePay_USD_Converter( $this ) )->convert( $total, $currency );
		} catch ( Exception $e ) {
			return '';
		}

		$token        = strtoupper( $this->api_handler->get_token() );
		$fiat_per_usd = (float) $converted['fiat_per_usd'];
		$usd_per_fiat = $fiat_per_usd > 0 ? 1 / $fiat_per_usd : 0;
		$usd_amount   = (float) $converted['usd_amount'];
		$token_amount = (float) $converted['token_amount'];

		$store_amount = wc_price( $total, array( 'currency' => $currency ) );
		$usd_label    = wc_price( $usd_amount, array( 'currency' => 'USD' ) );

		if ( 'manual' === $converted['source'] ) {
			$rate_source = __( 'Rate from shop settings', 'aneepay-crypto-gateway' );
		} else {
			$rate_source = __( 'Rate: ECB / Frankfurter', 'aneepay-crypto-gateway' );
		}

		ob_start();
		?>
		<div class="aneepay-checkout-breakdown">
			<p class="aneepay-breakdown-title"><?php printf( esc_html__( 'You will pay %s %s', 'aneepay-crypto-gateway' ), esc_html( $token_amount ), esc_html( $token ) ); ?></p>
			<ul>
				<li><?php printf( esc_html__( 'Order: %s (%s)', 'aneepay-crypto-gateway' ), wp_kses_post( $store_amount ), esc_html( $currency ) ); ?></li>
				<li><?php printf( esc_html__( 'USD equivalent: %s', 'aneepay-crypto-gateway' ), wp_kses_post( $usd_label ) ); ?></li>
				<?php if ( $usd_per_fiat > 0 && 'USD' !== $currency ) : ?>
					<li><?php printf( esc_html__( '1 %1$s = %2$s USD', 'aneepay-crypto-gateway' ), esc_html( $currency ), esc_html( number_format( $usd_per_fiat, 4, '.', '' ) ) ); ?></li>
				<?php endif; ?>
				<li class="aneepay-breakdown-source"><?php echo esc_html( $rate_source ); ?></li>
			</ul>
		</div>
		<?php
		return ob_get_clean();
	}
}

/**
 * Verify the HMAC-SHA256 signature of an incoming webhook.
 *
 * The signature (X-AneePay-Signature) is the hex digest of the RAW request
 * body signed with the webhook_secret. Verification fails closed: a missing
 * secret, missing signature, or a mismatch all return false.
 *
 * @param string $signature Value of the X-AneePay-Signature header.
 * @param string $body      Raw request body.
 * @param string $secret    Configured webhook_secret.
 * @return bool
 */
function aneepay_verify_webhook_signature( $signature, $body, $secret ) {
	if ( '' === (string) $secret || empty( $signature ) ) {
		return false;
	}

	$expected = hash_hmac( 'sha256', (string) $body, (string) $secret );

	return hash_equals( $expected, (string) $signature );
}

/**
 * Handle an incoming status notification.
 *
 * AneePay pushes this payload to the configured STATUS_URL after a payment
 * is confirmed on-chain (only for success):
 * {
 *   "operation_id": 1025366559013960798969014288963858567168, // int(payment_id)
 *   "status": "success",
 *   "timestamp": 1755262800.0
 * }
 *
 * The signature (X-AneePay-Signature) is an HMAC-SHA256 hex digest of the
 * raw request body using the webhook_secret. Signature verification is
 * mandatory: without a valid signature the webhook is rejected with 401.
 * Payloads sent by the legacy dApp confirm endpoint
 * (payment_id / order_id / description / operationId) are still accepted for
 * compatibility, but only when signed correctly.
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response
 */
function aneepay_handle_webhook( $request ) {
	$gateway = new WC_Gateway_AneePay_Crypto();
	$secret  = (string) $gateway->get_option( 'webhook_secret' );

	$signature = $request->get_header( 'X-AneePay-Signature' );
	$body      = (string) $request->get_body();

	if ( ! aneepay_verify_webhook_signature( $signature, $body, $secret ) ) {
		return new WP_REST_Response( array( 'success' => false ), 401 );
	}

	// JSON_BIGINT_AS_STRING keeps operation_id (a 128-bit integer) exact.
	$params = json_decode( $body, true, 512, JSON_BIGINT_AS_STRING );

	if ( ! is_array( $params ) ) {
		return new WP_REST_Response( array( 'success' => false ), 400 );
	}

	$status = isset( $params['status'] ) ? sanitize_key( $params['status'] ) : '';

	if ( ! in_array( $status, array( 'pending', 'success', 'failed', 'cancelled' ), true ) ) {
		return new WP_REST_Response( array( 'success' => false ), 400 );
	}

	// Reject replayed webhooks: the payload timestamp must not be older than 24h.
	if ( isset( $params['timestamp'] ) ) {
		$ts = (float) $params['timestamp'];

		if ( $ts > 0 && ( time() - $ts ) > DAY_IN_SECONDS ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'error'   => 'stale_timestamp',
				),
				400
			);
		}
	}

	$order = aneepay_resolve_order( $params );

	if ( ! $order ) {
		return new WP_REST_Response( array( 'success' => false ), 404 );
	}

	aneepay_apply_payment_status( $order, $status, $params );

	return new WP_REST_Response(
		array(
			'success' => true,
			'status'  => $status,
		),
		200
	);
}

/**
 * Resolve a WC_Order from a webhook payload.
 *
 * Lookup order:
 * 1. explicit order_id
 * 2. payment_id (UUID) matching _aneepay_payment_id
 * 3. operation_id (int(payment_id)) matching _aneepay_operation_id
 * 4. operation_id decoded back to the payment UUID
 * 5. description "Order #N" parsing
 *
 * @param array $params Webhook payload.
 * @return WC_Order|null
 */
function aneepay_resolve_order( $params ) {
	if ( isset( $params['order_id'] ) ) {
		$candidate = wc_get_order( absint( $params['order_id'] ) );
		if ( $candidate && $candidate->get_payment_method() === ANEEPAY_PAYMENT_GATEWAY_ID ) {
			return $candidate;
		}
	}

	if ( isset( $params['payment_id'] ) ) {
		$payment_id = sanitize_text_field( $params['payment_id'] );
		$found      = aneepay_find_order_by_meta( '_aneepay_payment_id', $payment_id );
		if ( $found ) {
			return $found;
		}
	}

	$operation_id = '';

	if ( isset( $params['operation_id'] ) ) {
		$operation_id = sanitize_text_field( (string) $params['operation_id'] );
	} elseif ( isset( $params['operationId'] ) ) {
		$operation_id = sanitize_text_field( (string) $params['operationId'] );
	}

	if ( '' !== $operation_id ) {
		$found = aneepay_find_order_by_meta( '_aneepay_operation_id', $operation_id );
		if ( $found ) {
			return $found;
		}

		$uuid = aneepay_operation_id_to_uuid( $operation_id );

		if ( null !== $uuid ) {
			$found = aneepay_find_order_by_meta( '_aneepay_payment_id', $uuid );
			if ( $found ) {
				return $found;
			}
		}
	}

	if ( ! empty( $params['description'] ) ) {
		$description = sanitize_text_field( $params['description'] );

		if ( preg_match( '/#\s*(\d+)/', $description, $matches ) ) {
			$candidate = wc_get_order( absint( $matches[1] ) );
			if ( $candidate && $candidate->get_payment_method() === ANEEPAY_PAYMENT_GATEWAY_ID ) {
				return $candidate;
			}
		}
	}

	return null;
}

/**
 * Decode an AneePay operation_id back to the payment UUID.
 *
 * operation_id is the payment UUID encoded as a 128-bit unsigned integer
 * (int(payment.id)); the mapping is bijective:
 * uuid.UUID(int=operation_id) === payment_id.
 *
 * @param string|int $operation_id Decimal operation id.
 * @return string|null Payment UUID, or null when not decodable.
 */
function aneepay_operation_id_to_uuid( $operation_id ) {
	$decimal = trim( (string) $operation_id );

	if ( '' === $decimal || ! preg_match( '/^\d+$/', $decimal ) ) {
		return null;
	}

	$hex = '';
	$num = ltrim( $decimal, '0' );

	if ( '' === $num ) {
		$num = '0';
	}

	while ( '0' !== $num ) {
		$remainder = 0;
		$quotient  = '';

		for ( $i = 0, $len = strlen( $num ); $i < $len; $i++ ) {
			$current   = ( $remainder * 10 ) + (int) $num[ $i ];
			$remainder = $current % 16;
			$quotient .= (int) ( $current / 16 );
		}

		$hex = dechex( $remainder ) . $hex;
		$num = ltrim( $quotient, '0' );

		if ( '' === $num ) {
			$num = '0';
		}
	}

	$hex = str_pad( $hex, 32, '0', STR_PAD_LEFT );

	return sprintf(
		'%s-%s-%s-%s-%s',
		substr( $hex, 0, 8 ),
		substr( $hex, 8, 4 ),
		substr( $hex, 12, 4 ),
		substr( $hex, 16, 4 ),
		substr( $hex, 20, 12 )
	);
}

/**
 * Find an order by meta key/value (HPOS compatible).
 *
 * @param string $meta_key   Meta key.
 * @param string $meta_value Meta value.
 * @return WC_Order|null
 */
function aneepay_find_order_by_meta( $meta_key, $meta_value ) {
	$query = wc_get_orders(
		array(
			'limit'      => 1,
			'meta_key'   => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value' => $meta_value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'return'     => 'ids',
		)
	);

	if ( empty( $query ) ) {
		return null;
	}

	$candidate = wc_get_order( reset( $query ) );

	if ( $candidate && $candidate->get_payment_method() === ANEEPAY_PAYMENT_GATEWAY_ID ) {
		return $candidate;
	}

	return null;
}

/**
 * AJAX handler: poll the AneePay API for a single order and update it.
 *
 * @return void
 */
function aneepay_ajax_check_status() {
	check_ajax_referer( 'aneepay_status', 'nonce' );

	$order_id = isset( $_POST['orderId'] ) ? absint( $_POST['orderId'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
	$order    = wc_get_order( $order_id );

	if ( ! $order || $order->get_payment_method() !== ANEEPAY_PAYMENT_GATEWAY_ID ) {
		wp_send_json_error( array( 'message' => 'Invalid order' ) );
	}

	$gateway = new WC_Gateway_AneePay_Crypto();

	if ( 'success' === $order->get_meta( '_aneepay_payment_status', true ) ) {
		wp_send_json_success( array( 'status' => 'success' ) );
	}

	$payment_id = (string) $order->get_meta( '_aneepay_payment_id', true );

	if ( empty( $payment_id ) ) {
		wp_send_json_success( array( 'status' => $order->get_meta( '_aneepay_payment_status', true ) ) );
	}

	$data = $gateway->api_handler->get_payment( $payment_id );

	if ( null === $data || empty( $data['status'] ) ) {
		wp_send_json_success( array( 'status' => $order->get_meta( '_aneepay_payment_status', true ) ) );
	}

	aneepay_apply_payment_status( $order, $data['status'] );

	wp_send_json_success( array( 'status' => $order->get_meta( '_aneepay_payment_status', true ) ) );
}

/**
 * WP-Cron handler: sync all pending AneePay orders.
 *
 * @return void
 */
function aneepay_cron_sync_pending_orders() {
	// No-overlap guard: never run two sync batches concurrently.
	if ( get_transient( 'aneepay_cron_lock' ) ) {
		return;
	}

	set_transient( 'aneepay_cron_lock', 1, 5 * MINUTE_IN_SECONDS );

	try {
		$query = wc_get_orders(
			array(
				'limit'        => 20,
				'status'       => array( 'pending', 'on-hold' ),
				'payment_method' => ANEEPAY_PAYMENT_GATEWAY_ID,
				'return'       => 'ids',
			)
		);

		foreach ( $query as $order_id ) {
			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				continue;
			}

			$status = $order->get_meta( '_aneepay_payment_status', true );

			if ( in_array( $status, array( 'success', 'failed', 'cancelled' ), true ) ) {
				continue;
			}

			$payment_id = (string) $order->get_meta( '_aneepay_payment_id', true );

			if ( empty( $payment_id ) ) {
				continue;
			}

			$gateway = new WC_Gateway_AneePay_Crypto();
			$data    = $gateway->api_handler->get_payment( $payment_id );

			if ( null === $data || empty( $data['status'] ) ) {
				continue;
			}

			aneepay_apply_payment_status( $order, $data['status'] );
		}
	} catch ( Exception $e ) {
		aneepay_log_sync_error( $e );
	} finally {
		delete_transient( 'aneepay_cron_lock' );
	}
}
add_action( 'aneepay_sync_pending_orders', 'aneepay_cron_sync_pending_orders' );

/**
 * Log a message during the sync run (only when the gateway debug is enabled).
 *
 * @param Exception $e Exception to log.
 * @return void
 */
function aneepay_log_sync_error( $e ) {
	$gateway = new WC_Gateway_AneePay_Crypto();

	if ( isset( $gateway->api_handler ) && is_callable( array( $gateway->api_handler, 'log' ) ) ) {
		$gateway->api_handler->log( 'cron_sync', $e->getMessage(), 'error' );
	}
}

/**
 * Update an order based on a known payment status.
 *
 * @param string $payment_id AneePay payment UUID.
 * @param string $status     New payment status.
 * @param int    $order_id   Optional order ID for faster lookup.
 * @return bool
 */
function aneepay_update_order_by_status( $payment_id, $status, $order_id = 0 ) {
	$params = array();

	if ( ! empty( $order_id ) ) {
		$params['order_id'] = $order_id;
	}

	if ( ! empty( $payment_id ) ) {
		$params['payment_id'] = $payment_id;
	}

	$order = aneepay_resolve_order( $params );

	if ( ! $order ) {
		return false;
	}

	aneepay_apply_payment_status( $order, $status );

	return true;
}

/**
 * Apply a payment status to an order and mark it in meta.
 *
 * @param WC_Order $order  Order object.
 * @param string   $status Payment status (success|failed|cancelled|pending).
 * @param array    $data   Optional webhook payload for extra context.
 * @return void
 */
function aneepay_apply_payment_status( $order, $status, $data = array() ) {
	$status = sanitize_key( $status );

	// Unknown statuses are never applied.
	if ( ! in_array( $status, array( 'pending', 'success', 'failed', 'cancelled' ), true ) ) {
		return;
	}

	$current = (string) $order->get_meta( '_aneepay_payment_status', true );

	if ( $status === $current ) {
		return;
	}

	// "success" is terminal: never downgrade a paid order.
	if ( 'success' === $current ) {
		return;
	}

	switch ( $status ) {
		case 'success':
			if ( ! $order->is_paid() ) {
				$note = __( 'AneePay: payment confirmed.', 'aneepay-crypto-gateway' );

				if ( ! empty( $data['operation_id'] ) ) {
					$note .= ' ' . sprintf(
						/* translators: %s: AneePay operation id */
						__( 'Operation ID: %s', 'aneepay-crypto-gateway' ),
						sanitize_text_field( (string) $data['operation_id'] )
					);
				}

				$order->payment_complete();
				$order->add_order_note( $note );
			}
			break;

		case 'failed':
			$note = __( 'AneePay: payment failed.', 'aneepay-crypto-gateway' );

			if ( ! empty( $data['error'] ) ) {
				$note .= ' ' . sprintf(
					/* translators: %s: error message */
					__( 'Reason: %s', 'aneepay-crypto-gateway' ),
					sanitize_text_field( $data['error'] )
				);
			}

			$order->update_status( 'failed', $note );
			// Restore stock that was reserved when the hosted payment started.
			// Guarded so failed <-> cancelled transitions cannot double-restock.
			if ( ! $order->get_meta( '_aneepay_stock_restored', true ) ) {
				wc_increase_stock_levels( $order->get_id() );
				$order->update_meta_data( '_aneepay_stock_restored', 1 );
			}
			break;

		case 'cancelled':
			$order->update_status( 'cancelled', __( 'AneePay: payment cancelled by the customer.', 'aneepay-crypto-gateway' ) );
			// Restore stock that was reserved when the hosted payment started.
			// Guarded so failed <-> cancelled transitions cannot double-restock.
			if ( ! $order->get_meta( '_aneepay_stock_restored', true ) ) {
				wc_increase_stock_levels( $order->get_id() );
				$order->update_meta_data( '_aneepay_stock_restored', 1 );
			}
			break;

		case 'pending':
		default:
			$order->update_status( 'pending', __( 'AneePay: awaiting payment.', 'aneepay-crypto-gateway' ) );
			break;
	}

	$order->update_meta_data( '_aneepay_payment_status', $status );
	$order->save();
}

<?php
/**
 * AneePay Crypto Gateway (USDT/USDC/DAI on Polygon + Amoy).
 *
 * @author AneePay
 */
if (!defined('_PS_VERSION_')) {
	exit;
}

require_once _PS_MODULE_DIR_ . 'ps_aneepay/classes/AneePayApi.php';
require_once _PS_MODULE_DIR_ . 'ps_aneepay/classes/AneePayUsdConverter.php';
require_once _PS_MODULE_DIR_ . 'ps_aneepay/classes/AneePayOrder.php';
require_once _PS_MODULE_DIR_ . 'ps_aneepay/classes/AneePayWebhook.php';

class Ps_Aneepay extends PaymentModule {
	public function __construct() {
		$this->name                  = 'ps_aneepay';
		$this->tab                   = 'payments_gateways';
		$this->version               = '1.2.0';
		$this->author                = 'AneePay';
		$this->need_instance         = 0;
		$this->bootstrap             = true;
		$this->ps_versions_compliancy = array('min' => '1.7.0.0', 'max' => _PS_VERSION_);

		parent::__construct();

		$this->displayName    = $this->l('AneePay Crypto Gateway');
		$this->description    = $this->l('Accept stablecoin payments (USDT, USDC, DAI) on Polygon (live) or Amoy (testnet). Non-custodial, 0.5% fee, no KYC.');
		$this->confirmUninstall = $this->l('Are you sure you want to uninstall AneePay?');
	}

	public function install() {
		return parent::install()
			&& $this->registerHook('paymentOptions')
			&& $this->installDb()
			&& $this->installConfig();
	}

	public function uninstall() {
		return $this->uninstallDb() && parent::uninstall();
	}

	public function installDb() {
		$sql = array();

		$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'aneepay_order` (
			`id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
			`id_order` INT(11) UNSIGNED NOT NULL,
			`id_cart` INT(11) UNSIGNED NOT NULL DEFAULT 0,
			`payment_id` VARCHAR(64) NOT NULL DEFAULT \'\',
			`operation_id` VARCHAR(64) NOT NULL DEFAULT \'\',
			`checkout_url` TEXT NOT NULL,
			`token` VARCHAR(16) NOT NULL DEFAULT \'\',
			`network` VARCHAR(16) NOT NULL DEFAULT \'\',
			`sandbox` TINYINT(1) NOT NULL DEFAULT 0,
			`payment_status` VARCHAR(16) NOT NULL DEFAULT \'pending\',
			`token_amount` VARCHAR(32) NOT NULL DEFAULT \'\',
			`order_currency` VARCHAR(8) NOT NULL DEFAULT \'\',
			`order_total` VARCHAR(32) NOT NULL DEFAULT \'\',
			`usd_amount` VARCHAR(32) NOT NULL DEFAULT \'\',
			`fiat_per_usd` VARCHAR(32) NOT NULL DEFAULT \'\',
			`rate_source` VARCHAR(16) NOT NULL DEFAULT \'\',
			`stock_restored` TINYINT(1) NOT NULL DEFAULT 0,
			`date_added` DATETIME NOT NULL,
			PRIMARY KEY (`id`),
			KEY `id_order` (`id_order`),
			KEY `payment_id` (`payment_id`),
			KEY `operation_id` (`operation_id`)
		) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci';

		$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'aneepay_log` (
			`log_id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
			`context` VARCHAR(64) NOT NULL DEFAULT \'\',
			`message` TEXT NOT NULL,
			`level` VARCHAR(16) NOT NULL DEFAULT \'info\',
			`date_added` DATETIME NOT NULL,
			PRIMARY KEY (`log_id`),
			KEY `date_added` (`date_added`)
		) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci';

		foreach ($sql as $stmt) {
			if (!Db::getInstance()->execute($stmt)) {
				return false;
			}
		}

		return true;
	}

	public function uninstallDb() {
		Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'aneepay_order`');
		Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'aneepay_log`');

		return true;
	}

	public function installConfig() {
		$defaults = array(
			'ANEEPAY_ACCOUNT_ID'      => '',
			'ANEEPAY_WEBHOOK_SECRET'  => '',
			'ANEEPAY_SITE_DOMAIN'     => '',
			'ANEEPAY_TEST_MODE'       => '1',
			'ANEEPAY_TOKEN'           => 'usdt',
			'ANEEPAY_NETWORK'         => 'polygon',
			'ANEEPAY_RATE_SOURCE'     => 'auto',
			'ANEEPAY_EXCHANGE_RATE'   => '',
			'ANEEPAY_PENDING_STATE'   => (string) Configuration::get('PS_OS_CHEQUE'),
			'ANEEPAY_PAID_STATE'      => (string) Configuration::get('PS_OS_PAYMENT'),
			'ANEEPAY_FAILED_STATE'    => (string) Configuration::get('PS_OS_ERROR'),
			'ANEEPAY_CANCELLED_STATE' => (string) Configuration::get('PS_OS_CANCELED'),
		);

		foreach ($defaults as $key => $value) {
			if (!Configuration::updateValue($key, $value)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Build the API client from the current configuration.
	 *
	 * The Origin header must always identify the storefront. When the
	 * merchant has not set ANEEPAY_SITE_DOMAIN we fall back to the shop's
	 * canonical host so the header is never empty (P0-8).
	 *
	 * @param string $host Origin host override (optional).
	 * @return AneePayApi
	 */
	public function getApi($host = '') {
		if ('' === $host) {
			$host = (string) $this->shopHost();
		}

		return new AneePayApi(array(
			'account_id'     => (string) Configuration::get('ANEEPAY_ACCOUNT_ID'),
			'webhook_secret' => (string) Configuration::get('ANEEPAY_WEBHOOK_SECRET'),
			'site_domain'    => (string) Configuration::get('ANEEPAY_SITE_DOMAIN'),
			'host'           => (string) $host,
			'token'          => (string) Configuration::get('ANEEPAY_TOKEN', 'usdt'),
			'network'        => (string) Configuration::get('ANEEPAY_NETWORK', 'polygon'),
			'test_mode'      => (bool) Configuration::get('ANEEPAY_TEST_MODE'),
			'rate_source'    => (string) Configuration::get('ANEEPAY_RATE_SOURCE', 'auto'),
			'exchange_rate'  => (string) Configuration::get('ANEEPAY_EXCHANGE_RATE', ''),
		), Db::getInstance());
	}

	/**
	 * Canonical storefront host used as the Origin fallback.
	 *
	 * Prefers the Link::getShopDomain() value, then the shop/base URL
	 * constants. Only the host is returned (no scheme, port or path).
	 *
	 * @return string Host, or '' when it cannot be resolved.
	 */
	public function shopHost() {
		if (isset($this->context->link)) {
			$host = parse_url((string) $this->context->link->getShopDomain(), PHP_URL_HOST);

			if (is_string($host) && '' !== $host) {
				return $host;
			}
		}

		foreach (array('PS_SHOP_URL', 'PS_SHOP_BASE_URI') as $const) {
			if (!defined($const)) {
				continue;
			}

			$host = parse_url((string) constant($const), PHP_URL_HOST);

			if (is_string($host) && '' !== $host) {
				return $host;
			}
		}

		return '';
	}

	/**
	 * Map payment statuses to PrestaShop order states.
	 *
	 * @return array
	 */
	public function getStateMap() {
		return array(
			'pending'   => (int) Configuration::get('ANEEPAY_PENDING_STATE'),
			'success'   => (int) Configuration::get('ANEEPAY_PAID_STATE'),
			'failed'    => (int) Configuration::get('ANEEPAY_FAILED_STATE'),
			'cancelled' => (int) Configuration::get('ANEEPAY_CANCELLED_STATE'),
		);
	}

	/**
	 * Store-currency units per 1 USD, resolved from the PrestaShop Currency table.
	 *
	 * @param string $currencyIso ISO-4217 code.
	 * @return float
	 */
	public function getStoreRate($currencyIso) {
		$currencyIso = strtoupper((string) $currencyIso);

		if ('USD' === $currencyIso) {
			return 1.0;
		}

		$currency = Currency::getCurrencyInstance((int) Currency::getIdByIsoCode($currencyIso));

		if (!$currency || !(float) $currency->conversion_rate) {
			return 0.0;
		}

		// conversion_rate = 1 EUR? PS stores the rate of the currency relative to
		// the default shop currency. To get USD per 1 unit, we compare with USD's rate.
		$usd = Currency::getCurrencyInstance((int) Currency::getIdByIsoCode('USD'));

		if (!$usd || !(float) $usd->conversion_rate) {
			return 0.0;
		}

		$usd_per_fiat = (float) $currency->conversion_rate / (float) $usd->conversion_rate;

		return $usd_per_fiat > 0 ? 1 / $usd_per_fiat : 0.0;
	}

	/**
	 * checkout PaymentOption.
	 *
	 * @param array $params
	 * @return array
	 */
	public function hookPaymentOptions($params) {
		if (!$this->active) {
			return array();
		}

		$links = $this->context->link;

		$option = $this->newPaymentOption();
		$option->setModuleName($this->name)
			->setCallToActionText($this->l('Pay with crypto (USDT/USDC/DAI)'))
			->setAction($links->getModuleLink($this->name, 'payment', array(), true));

		$breakdown = $this->getCheckoutBreakdown();

		if (null !== $breakdown) {
			$this->context->smarty->assign('aneepay_breakdown', $breakdown);
			$option->setAdditionalInformation($this->fetch('module:ps_aneepay/views/templates/hook/paymentOptions-additionalInformation.tpl'));
		}

		return array($option);
	}

	/**
	 * Checkout conversion breakdown ("You will pay X USDT") data for the
	 * payment method card. Returns null when there is no cart, a zero total,
	 * or the rate cannot be resolved without a network request (avoid
	 * blocking checkout).
	 *
	 * @return array|null
	 */
	protected function getCheckoutBreakdown() {
		$cart = $this->context->cart;

		if (!$cart || !(int) $cart->id) {
			return null;
		}

		$total = (float) $cart->getOrderTotal(true, Cart::BOTH);

		if ($total <= 0) {
			return null;
		}

		$currency = new Currency((int) $cart->id_currency);

		if (!Validate::isLoadedObject($currency)) {
			$currency = $this->context->currency;
		}

		if (!Validate::isLoadedObject($currency)) {
			return null;
		}

		$iso = (string) $currency->iso_code;

		$api = $this->getApi();
		$api->set_config('store_rate', $this->getStoreRate($iso));

		$source = '';
		$rate   = (new AneePayUsdConverter($api))->peek_fiat_per_usd($iso, $source);

		if (null === $rate || $rate <= 0) {
			return null;
		}

		$usd_amount   = $total / $rate;
		$token_amount = ceil($usd_amount * 100) / 100;
		$usd          = new Currency((int) Currency::getIdByIsoCode('USD'));
		$usd_label    = Validate::isLoadedObject($usd)
			? Tools::displayPrice($usd_amount, $usd)
			: '$' . number_format($usd_amount, 2, '.', '');

		if ('manual' === $source) {
			$rate_source = $this->l('Rate from shop settings.');
		} elseif ('store' === $source) {
			$rate_source = $this->l('Rate: PrestaShop currency table.');
		} else {
			$rate_source = $this->l('Rate: ECB / Frankfurter (cached).');
		}

		return array(
			'token_amount' => number_format($token_amount, 2, '.', ''),
			'token'        => strtoupper($api->get_token()),
			'order_label'  => Tools::displayPrice($total, $currency),
			'currency'     => $iso,
			'usd_label'    => $usd_label,
			'show_rate'    => 'USD' !== $iso,
			'usd_per_fiat' => number_format(1 / $rate, 4, '.', ''),
			'rate_source'  => $rate_source,
		);
	}

	/**
	 * PaymentOption moved into a namespace in PS 1.7.4+; the legacy global
	 * class only exists on older 1.7.x. Resolve whichever is available.
	 *
	 * @return PaymentOption|\PaymentOption
	 */
	protected function newPaymentOption() {
		$class = 'PrestaShop\\PrestaShop\\Core\\Payment\\PaymentOption';

		if (!class_exists($class)) {
			$class = 'PaymentOption';
		}

		return new $class();
	}

	/**
	 * Admin configuration page.
	 *
	 * @return string
	 */
	public function getContent() {
		$output = '';

		if (Tools::isSubmit('submitAneepay')) {
			$output .= $this->postProcess();
		} elseif (Tools::isSubmit('submitAneepayTest')) {
			$output .= $this->renderTestConnectionResult();
		} elseif (Tools::isSubmit('submitAneepayClearLog')) {
			$this->getApi()->clear_request_log();
			$output .= $this->displayConfirmation($this->l('Request log cleared.'));
		}

		$account_id = (string) Configuration::get('ANEEPAY_ACCOUNT_ID');
		$secret     = (string) Configuration::get('ANEEPAY_WEBHOOK_SECRET');

		$html  = $this->adminStyles();
		$html .= $this->renderModeBadge();
		$html .= $output;

		if ('' === trim($account_id) || '' === trim($secret)) {
			$html .= $this->renderQuickSetup();
		}

		$html .= $this->renderNotices($account_id, $secret);
		$html .= $this->renderTestConnectionForm();
		$html .= $this->renderConversionExample();
		$html .= $this->renderRequestLog();
		$html .= $this->renderForm();
		$html .= $this->renderEndpoints();
		$html .= $this->renderInfoBlocks();

		return $html;
	}

	protected function adminStyles() {
		return '<style>'
			. '.aneepay-mode-badge{margin:8px 0;padding:8px 12px;background:#f6f7f7;border:1px solid #e0e0e0;border-radius:4px;font-size:13px;}'
			. '.aneepay-mode-pill{display:inline-block;padding:1px 8px;border-radius:10px;color:#fff;font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.02em;}'
			. '.aneepay-mode-live .aneepay-mode-pill{background:#008a20;}'
			. '.aneepay-mode-sandbox .aneepay-mode-pill{background:#c25600;}'
			. '.aneepay-setup-card{margin:8px 0 16px;padding:12px 16px;background:#eef6fb;border:1px solid #c3ddd9;border-radius:6px;}'
			. '.aneepay-setup-card ol{margin:6px 0 12px;padding-left:20px;}'
			. '.aneepay-endpoint{display:inline-block;word-break:break-all;}'
			. '</style>';
	}

	protected function renderModeBadge() {
		$sandbox = (bool) Configuration::get('ANEEPAY_TEST_MODE');
		$api     = $this->getApi();

		$label = $sandbox ? $this->l('SANDBOX') : $this->l('LIVE');
		$class = $sandbox ? 'aneepay-mode-sandbox' : 'aneepay-mode-live';

		$html  = '<div class="aneepay-mode-badge ' . $class . '">';
		$html .= '<span class="aneepay-mode-pill">' . Tools::safeOutput($label) . '</span>';
		$html .= ' &middot; ' . Tools::safeOutput(strtoupper($api->get_token()));
		$html .= ' &middot; ' . Tools::safeOutput($api->get_network());

		if (!$this->active) {
			$html .= ' &middot; <strong>' . $this->l('disabled') . '</strong>';
		}

		$html .= '</div>';

		return $html;
	}

	protected function renderQuickSetup() {
		$html  = '<div class="aneepay-setup-card">';
		$html .= '<h4>' . $this->l('Quick setup') . '</h4>';
		$html .= '<ol>';
		$html .= '<li>' . $this->l('Create or open your account in the AneePay dashboard.') . '</li>';
		$html .= '<li>' . $this->l('Set your wallet address, domain and the URLs below in the account panel.') . '</li>';
		$html .= '<li>' . $this->l('Copy the Account ID and Webhook Secret into the fields below.') . '</li>';
		$html .= '<li>' . $this->l('Save the settings and press "Test connection".') . '</li>';
		$html .= '</ol>';
		$html .= '</div>';

		return $html;
	}

	protected function renderNotices($account_id, $secret) {
		$html = '';

		if ('' === trim((string) $secret)) {
			$html .= '<div class="alert alert-danger">' . $this->l('AneePay webhook secret is not set. Payment confirmations cannot be verified and webhook calls will be rejected (HTTP 401).') . '</div>';
		}

		if ('' === trim((string) $account_id)) {
			$html .= '<div class="alert alert-warning">' . $this->l('AneePay Account ID is not set. Payments cannot be created until you enter your account UUID below.') . '</div>';
		}

		if ((bool) Configuration::get('ANEEPAY_TEST_MODE')) {
			$html .= '<div class="alert alert-warning">' . $this->l('Sandbox mode is enabled. Payments are created on the Amoy testnet with USDC and are not real. Disable Test Mode for live payments.') . '</div>';
		}

		return $html;
	}

	protected function renderTestConnectionForm() {
		$has_id = '' !== trim((string) Configuration::get('ANEEPAY_ACCOUNT_ID'));

		$html  = '<div class="panel"><div class="panel-heading"><i class="icon-plug"></i> ' . $this->l('Connection check') . '</div><div class="panel-body">';
		$html .= '<form method="post" action="">';
		$html .= '<button type="submit" name="submitAneepayTest" value="1" class="btn btn-default"' . ($has_id ? '' : ' disabled') . '>';
		$html .= '<i class="icon-refresh"></i> ' . $this->l('Test connection') . '</button>';
		$html .= ' <span class="help-block" style="display:inline-block;margin-left:8px;">' . $this->l('Calls GET /accounts/{id}/payments to verify the Account ID, domain and mode.') . '</span>';
		$html .= '</form>';
		$html .= '</div></div>';

		return $html;
	}

	protected function renderTestConnectionResult() {
		$result = $this->getApi()->test_connection();

		if (!empty($result['ok'])) {
			return $this->displayConfirmation($this->l('Connection OK. Account exists and the domain matches.'));
		}

		$message = isset($result['message']) ? (string) $result['message'] : $this->l('Connection failed.');

		return $this->displayError($this->l('Connection failed:') . ' ' . $message);
	}

	protected function renderConversionExample() {
		$currency = $this->context->currency;

		if (!Validate::isLoadedObject($currency)) {
			$currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));
		}

		$iso = (string) $currency->iso_code;

		$api = $this->getApi();
		$api->set_config('store_rate', $this->getStoreRate($iso));

		$converter = new AneePayUsdConverter($api);
		$source    = '';
		$rate      = $converter->peek_fiat_per_usd($iso, $source);

		$sample = 150.0;
		$token  = strtoupper($api->get_token());

		$html  = '<div class="panel"><div class="panel-heading"><i class="icon-calculator"></i> ' . $this->l('Example conversion') . '</div><div class="panel-body">';

		if (null === $rate || $rate <= 0) {
			$html .= '<p class="help-block">' . $this->l('No rate is available yet. The auto rate is resolved at checkout, or set a manual exchange rate to see a preview.') . '</p>';
		} else {
			$usd_amount   = $sample / $rate;
			$token_amount = ceil($usd_amount * 100) / 100;

			$html .= '<ul>';
			$html .= '<li>' . sprintf($this->l('Order: %s'), Tools::displayPrice($sample, $currency)) . '</li>';

			if ('USD' !== $iso) {
				$html .= '<li>' . sprintf($this->l('1 %1$s = %2$s USD'), $iso, number_format(1 / $rate, 4, '.', '')) . '</li>';
			}

			$html .= '<li>' . sprintf($this->l('You pay: %1$s → %3$s %2$s'), '$' . number_format($usd_amount, 2, '.', ''), $token, number_format($token_amount, 2, '.', '')) . '</li>';

			if ('manual' === $source) {
				$html .= '<li class="help-block">' . $this->l('Rate from shop settings.') . '</li>';
			} elseif ('store' === $source) {
				$html .= '<li class="help-block">' . $this->l('Rate: PrestaShop currency table.') . '</li>';
			} else {
				$html .= '<li class="help-block">' . $this->l('Rate: ECB / Frankfurter (cached).') . '</li>';
			}
			$html .= '</ul>';
		}

		$html .= '</div></div>';

		return $html;
	}

	protected function renderRequestLog() {
		$logs = $this->getApi()->get_request_log(50);

		$html  = '<div class="panel"><div class="panel-heading"><i class="icon-list"></i> ' . $this->l('Request log') . '</div><div class="panel-body">';

		if (empty($logs)) {
			$html .= '<p class="help-block">' . $this->l('No requests logged yet.') . '</p>';
		} else {
			$html .= '<table class="table table-striped table-condensed"><thead><tr>';

			foreach (array($this->l('Time'), $this->l('Method'), $this->l('Endpoint'), $this->l('Code'), $this->l('Latency'), $this->l('Body')) as $th) {
				$html .= '<th>' . $th . '</th>';
			}

			$html .= '</tr></thead><tbody>';

			foreach ($logs as $log) {
				$html .= '<tr>';
				$html .= '<td>' . Tools::safeOutput($log['time']) . '</td>';
				$html .= '<td>' . Tools::safeOutput($log['method']) . '</td>';
				$html .= '<td>' . Tools::safeOutput($log['endpoint']) . '</td>';
				$html .= '<td>' . (int) $log['code'] . '</td>';
				$html .= '<td>' . (int) $log['latency'] . ' ms</td>';
				$html .= '<td><code>' . Tools::safeOutput($log['body']) . '</code></td>';
				$html .= '</tr>';
			}

			$html .= '</tbody></table>';
		}

		$html .= '<form method="post" action="" style="margin-top:8px;">';
		$html .= '<button type="submit" name="submitAneepayRefreshLog" value="1" class="btn btn-default btn-sm"><i class="icon-refresh"></i> ' . $this->l('Refresh') . '</button> ';

		if (!empty($logs)) {
			$confirm = Tools::safeOutput($this->l('Clear the request log?'), true);
			$html .= '<button type="submit" name="submitAneepayClearLog" value="1" class="btn btn-default btn-sm" onclick="return confirm(\'' . $confirm . '\');"><i class="icon-trash"></i> ' . $this->l('Clear') . '</button>';
		}

		$html .= '</form>';
		$html .= '</div></div>';

		return $html;
	}

	protected function renderEndpoints() {
		$rows = array(
			array($this->l('STATUS_URL (webhook)'), $this->moduleLink('webhook'), $this->l('AneePay pushes the transaction result here to keep order statuses in sync.')),
			array($this->l('SUCCESS_URL'), $this->moduleLink('success'), $this->l('Customers land here after a successful payment.')),
			array($this->l('FAIL_URL'), $this->moduleLink('fail'), $this->l('Customers land here when the payment is cancelled or fails.')),
			array($this->l('Cron URL'), $this->moduleLink('cron'), $this->l('Optional: poll pending payments on a schedule.')),
		);

		$html  = '<div class="panel"><div class="panel-heading"><i class="icon-link"></i> ' . $this->l('Set these URLs in the AneePay account panel:') . '</div><div class="panel-body">';

		foreach ($rows as $row) {
			$url = Tools::safeOutput($row[1], true);

			$html .= '<p><strong>' . $row[0] . '</strong><br>';
			$html .= '<code class="aneepay-endpoint">' . $url . '</code> ';
			$html .= '<button type="button" class="btn btn-default btn-xs aneepay-copy" data-url="' . $url . '">' . $this->l('Copy') . '</button>';
			$html .= '<br><span class="help-block">' . $row[2] . '</span></p>';
		}

		$html .= '<script>' . $this->copyJs() . '</script>';
		$html .= '</div></div>';

		return $html;
	}

	protected function copyJs() {
		return 'document.querySelectorAll(\'.aneepay-copy\').forEach(function(b){b.addEventListener(\'click\',function(){'
			. 'var t=b.getAttribute(\'data-url\');'
			. 'if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(t);}'
			. 'var o=b.innerHTML;'
			. 'b.innerHTML=\'' . addslashes($this->l('Copied')) . '\';'
			. 'setTimeout(function(){b.innerHTML=o;},1500);'
			. '});});';
	}

	protected function renderInfoBlocks() {
		$html  = '<div class="panel"><div class="panel-heading"><i class="icon-info-sign"></i> ' . $this->l('How it works') . '</div><div class="panel-body">';
		$html .= '<ul>';
		$html .= '<li>' . $this->l('Non-custodial: funds go straight to your wallet.') . '</li>';
		$html .= '<li>' . $this->l('The merchant absorbs the 0.5% fee; the buyer pays exactly the token amount.') . '</li>';
		$html .= '<li>' . $this->l('No KYC required.') . '</li>';
		$html .= '</ul>';
		$html .= '<p class="help-block">' . $this->l('The webhook does not carry fee/net_amount/tx_hash yet — those are taken from the API polling.') . '</p>';
		$html .= '</div></div>';

		return $html;
	}

	protected function postProcess() {
		$errors = array();

		$valueMap = array(
			'ANEEPAY_ACCOUNT_ID'      => trim((string) Tools::getValue('ANEEPAY_ACCOUNT_ID')),
			'ANEEPAY_WEBHOOK_SECRET'  => (string) Tools::getValue('ANEEPAY_WEBHOOK_SECRET'),
			'ANEEPAY_SITE_DOMAIN'     => (string) Tools::getValue('ANEEPAY_SITE_DOMAIN'),
			'ANEEPAY_TOKEN'           => (string) Tools::getValue('ANEEPAY_TOKEN'),
			'ANEEPAY_NETWORK'         => (string) Tools::getValue('ANEEPAY_NETWORK'),
			'ANEEPAY_RATE_SOURCE'     => (string) Tools::getValue('ANEEPAY_RATE_SOURCE'),
			'ANEEPAY_EXCHANGE_RATE'   => trim((string) Tools::getValue('ANEEPAY_EXCHANGE_RATE')),
			'ANEEPAY_PENDING_STATE'   => (string) (int) Tools::getValue('ANEEPAY_PENDING_STATE'),
			'ANEEPAY_PAID_STATE'      => (string) (int) Tools::getValue('ANEEPAY_PAID_STATE'),
			'ANEEPAY_FAILED_STATE'    => (string) (int) Tools::getValue('ANEEPAY_FAILED_STATE'),
			'ANEEPAY_CANCELLED_STATE' => (string) (int) Tools::getValue('ANEEPAY_CANCELLED_STATE'),
		);

		if ('' !== $valueMap['ANEEPAY_ACCOUNT_ID']
			&& !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $valueMap['ANEEPAY_ACCOUNT_ID'])
		) {
			$errors[] = $this->l('Account ID must be a valid UUID.');
		}

		if ('' !== $valueMap['ANEEPAY_EXCHANGE_RATE']
			&& (!is_numeric($valueMap['ANEEPAY_EXCHANGE_RATE']) || (float) $valueMap['ANEEPAY_EXCHANGE_RATE'] <= 0)
		) {
			$errors[] = $this->l('Manual exchange rate must be a positive number.');
		}

		if (!empty($errors)) {
			$output = '';

			foreach ($errors as $error) {
				$output .= $this->displayError($error);
			}

			return $output;
		}

		// Empty webhook secret on re-save keeps the previously stored value.
		if ('' === $valueMap['ANEEPAY_WEBHOOK_SECRET']) {
			$valueMap['ANEEPAY_WEBHOOK_SECRET'] = (string) Configuration::get('ANEEPAY_WEBHOOK_SECRET');
		}

		$valueMap['ANEEPAY_TEST_MODE'] = Tools::getValue('ANEEPAY_TEST_MODE') ? '1' : '0';

		foreach ($valueMap as $key => $value) {
			Configuration::updateValue($key, $value);
		}

		return $this->displayConfirmation($this->l('Settings updated.'));
	}

	protected function renderForm() {
		$fields = array();

		$state_options = array();

		foreach (OrderState::getOrderStates((int) $this->context->language->id) as $state) {
			$state_options[] = array('id' => (int) $state['id_order_state'], 'name' => $state['name']);
		}

		// Build the form using the PrestaShop 1.7 helper form structure.
		$fields[0]['form'] = array(
			'legend' => array('title' => $this->l('Connection')),
			'input'  => array(
				array(
					'type'     => 'text',
					'label'    => $this->l('Account ID'),
					'name'     => 'ANEEPAY_ACCOUNT_ID',
					'desc'     => $this->l('Your AneePay account UUID. Sent in the X-Account-Id header.'),
					'required' => true,
				),
				array(
					'type'     => 'password',
					'label'    => $this->l('Webhook Secret'),
					'name'     => 'ANEEPAY_WEBHOOK_SECRET',
					'desc'     => $this->l('Used to verify the X-AneePay-Signature (HMAC-SHA256).'),
				),
				array(
					'type'     => 'text',
					'label'    => $this->l('Domain'),
					'name'     => 'ANEEPAY_SITE_DOMAIN',
					'desc'     => $this->l('Your verified merchant domain (Origin header). Empty = use your shop host.'),
				),
				array(
					'type'     => 'switch',
					'label'    => $this->l('Test / Sandbox Mode'),
					'name'     => 'ANEEPAY_TEST_MODE',
					'is_bool'  => true,
					'values'   => array(
						array('id' => 'active_on', 'value' => 1, 'label' => $this->l('Enabled')),
						array('id' => 'active_off', 'value' => 0, 'label' => $this->l('Disabled')),
					),
					'desc'     => $this->l('When enabled, payments are created on the Amoy testnet with USDC.'),
				),
			),
			'submit' => array('title' => $this->l('Save')),
		);

		$fields[1]['form'] = array(
			'legend' => array('title' => $this->l('Coin & Blockchain')),
			'input'  => array(
				array(
					'type'     => 'select',
					'label'    => $this->l('Cryptocurrency'),
					'name'     => 'ANEEPAY_TOKEN',
					'options'  => array(
						'query' => array(
							array('id' => 'usdt', 'name' => 'USDT (Tether)'),
							array('id' => 'usdc', 'name' => 'USDC (USD Coin)'),
							array('id' => 'dai',  'name' => 'DAI'),
						),
						'id'   => 'id',
						'name' => 'name',
					),
				),
				array(
					'type'     => 'select',
					'label'    => $this->l('Blockchain'),
					'name'     => 'ANEEPAY_NETWORK',
					'options'  => array(
						'query' => array(
							array('id' => 'polygon', 'name' => 'Polygon (Mainnet)'),
							array('id' => 'amoy',    'name' => 'Amoy (Testnet)'),
						),
						'id'   => 'id',
						'name' => 'name',
					),
				),
				array(
					'type'     => 'select',
					'label'    => $this->l('Exchange Rate Source'),
					'name'     => 'ANEEPAY_RATE_SOURCE',
					'options'  => array(
						'query' => array(
							array('id' => 'auto',   'name' => 'Auto (Frankfurter/ECB) + manual fallback'),
							array('id' => 'manual', 'name' => 'Manual rate only'),
						),
						'id'   => 'id',
						'name' => 'name',
					),
				),
				array(
					'type' => 'text',
					'label' => $this->l('Manual Exchange Rate'),
					'name'  => 'ANEEPAY_EXCHANGE_RATE',
					'desc'  => $this->l('1 token (≈1 USD) = X store currency. Used when the auto rate is unavailable.'),
				),
			),
			'submit' => array('title' => $this->l('Save')),
		);

		$fields[2]['form'] = array(
			'legend' => array('title' => $this->l('Order statuses')),
			'input'  => array(
				array(
					'type'    => 'select',
					'label'   => $this->l('Pending payment'),
					'name'    => 'ANEEPAY_PENDING_STATE',
					'options' => array('query' => $state_options, 'id' => 'id', 'name' => 'name'),
					'desc'    => $this->l('Applied when the buyer is redirected to AneePay.'),
				),
				array(
					'type'    => 'select',
					'label'   => $this->l('Paid'),
					'name'    => 'ANEEPAY_PAID_STATE',
					'options' => array('query' => $state_options, 'id' => 'id', 'name' => 'name'),
					'desc'    => $this->l('Applied when the webhook confirms the payment.'),
				),
				array(
					'type'    => 'select',
					'label'   => $this->l('Payment failed'),
					'name'    => 'ANEEPAY_FAILED_STATE',
					'options' => array('query' => $state_options, 'id' => 'id', 'name' => 'name'),
					'desc'    => $this->l('Applied when the payment fails.'),
				),
				array(
					'type'    => 'select',
					'label'   => $this->l('Cancelled'),
					'name'    => 'ANEEPAY_CANCELLED_STATE',
					'options' => array('query' => $state_options, 'id' => 'id', 'name' => 'name'),
					'desc'    => $this->l('Applied when the payment is cancelled.'),
				),
			),
			'submit' => array('title' => $this->l('Save')),
		);

		$helper = new HelperForm();
		$helper->module = $this;
		$helper->name_controller = 'ps_aneepay';
		$helper->token = Tools::getAdminTokenLite('AdminModules');
		$helper->currentIndex = AdminController::$currentIndex;
		$helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
		$helper->allow_employee_form_lang = (int) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
		$helper->title = $this->displayName;
		$helper->submit_action = 'submitAneepay';

		$helper->fields_value = array(
			'ANEEPAY_ACCOUNT_ID'      => Tools::getValue('ANEEPAY_ACCOUNT_ID', (string) Configuration::get('ANEEPAY_ACCOUNT_ID')),
			'ANEEPAY_SITE_DOMAIN'     => (string) Configuration::get('ANEEPAY_SITE_DOMAIN'),
			'ANEEPAY_TEST_MODE'       => (bool) Configuration::get('ANEEPAY_TEST_MODE'),
			'ANEEPAY_TOKEN'           => (string) Configuration::get('ANEEPAY_TOKEN', 'usdt'),
			'ANEEPAY_NETWORK'         => (string) Configuration::get('ANEEPAY_NETWORK', 'polygon'),
			'ANEEPAY_RATE_SOURCE'     => (string) Configuration::get('ANEEPAY_RATE_SOURCE', 'auto'),
			'ANEEPAY_EXCHANGE_RATE'   => (string) Configuration::get('ANEEPAY_EXCHANGE_RATE', ''),
			'ANEEPAY_WEBHOOK_SECRET'  => '',
			'ANEEPAY_PENDING_STATE'   => (int) Configuration::get('ANEEPAY_PENDING_STATE'),
			'ANEEPAY_PAID_STATE'      => (int) Configuration::get('ANEEPAY_PAID_STATE'),
			'ANEEPAY_FAILED_STATE'    => (int) Configuration::get('ANEEPAY_FAILED_STATE'),
			'ANEEPAY_CANCELLED_STATE' => (int) Configuration::get('ANEEPAY_CANCELLED_STATE'),
		);

		$output = $helper->generateForm($fields);

		return $helper->generateForm($fields);
	}

	/**
	 * Full storefront URL to a front controller of this module.
	 *
	 * @param string $controller Controller name.
	 * @return string
	 */
	public function moduleLink($controller) {
		return $this->context->link->getModuleLink($this->name, $controller, array(), true);
	}

	/**
	 * Signed, expiring token binding an order to this browser.
	 *
	 * The result/check controllers used to trust a bare integer cookie, which
	 * anyone could forge to probe another buyer's order (IDOR). Signing it
	 * with the shop's cookie secret makes a forged value useless.
	 *
	 * @param int $id_order Order id.
	 * @return string "<id_order>.<expiry>.<hmac>"
	 */
	public function orderToken($id_order) {
		$payload = (int) $id_order . '.' . (time() + 3600);

		return $payload . '.' . $this->signOrderPayload($payload);
	}

	/**
	 * Read the signed order cookie and return the validated order id.
	 *
	 * @return int Order id, or 0 when the cookie is missing/invalid/forged.
	 */
	public function resultOrderId() {
		$raw = isset($this->context->cookie->aneepay_order_id) ? (string) $this->context->cookie->aneepay_order_id : '';

		if ('' === $raw) {
			return 0;
		}

		$parts = explode('.', $raw);

		if (3 !== count($parts)) {
			return 0;
		}

		list($id_order, $expiry, $signature) = $parts;

		if (!ctype_digit($id_order) || !ctype_digit($expiry)) {
			return 0;
		}

		if ((int) $expiry < time()) {
			return 0;
		}

		$payload = $id_order . '.' . $expiry;

		if (!hash_equals($this->signOrderPayload($payload), (string) $signature)) {
			return 0;
		}

		return (int) $id_order;
	}

	/**
	 * @param string $payload Payload to sign.
	 * @return string
	 */
	protected function signOrderPayload($payload) {
		return hash_hmac('sha256', (string) $payload, $this->orderCookieSecret());
	}

	/**
	 * Secret used to sign the order cookie. Generated once and persisted.
	 *
	 * @return string
	 */
	protected function orderCookieSecret() {
		$secret = (string) Configuration::get('ANEEPAY_COOKIE_SECRET');

		if ('' === $secret) {
			$secret = bin2hex(random_bytes(16));
			Configuration::updateValue('ANEEPAY_COOKIE_SECRET', $secret);
		}

		return $secret;
	}

	/**
	 * Secret required to invoke the public cron poller.
	 *
	 * Generated once and persisted. cron.php refuses to run without it so the
	 * endpoint cannot be triggered by arbitrary visitors.
	 *
	 * @return string
	 */
	public function cronSecret() {
		$secret = (string) Configuration::get('ANEEPAY_CRON_SECRET');

		if ('' === $secret) {
			$secret = bin2hex(random_bytes(16));
			Configuration::updateValue('ANEEPAY_CRON_SECRET', $secret);
		}

		return $secret;
	}

	/**
	 * Data for the success/fail result page.
	 *
	 * @param bool $is_success Whether it is the success page.
	 * @return array
	 */
	public function resultData($is_success) {
		$order_id = $this->resultOrderId();

		$data = array(
			'is_success'   => (bool) $is_success,
			'order_id'     => $order_id,
			'token'        => '',
			'payment_id'   => '',
			'tx_hash'      => '',
			'checkout_url' => '',
			'retry_url'    => $this->moduleLink('payment'),
			'check_url'    => $this->moduleLink('check'),
			'shop_url'     => $this->context->link->getPageLink('index'),
			'pay_now'      => '',
		);

		if ($order_id) {
			$order = new Order((int) $order_id);

			if (Validate::isLoadedObject($order)) {
				$data['order_total'] = (string) $order->total_paid;
				$data['currency']    = (string) (new Currency((int) $order->id_currency))->iso_code;
			}

			$row = (new AneePayOrder())->getByOrder((int) $order_id);

			if ($row) {
				$data['token']        = strtoupper((string) $row['token']);
				$data['payment_id']   = (string) $row['payment_id'];
				$data['checkout_url'] = (string) $row['checkout_url'];
				$data['pay_now']      = trim((string) $row['token_amount']) . ' ' . strtoupper((string) $row['token']);
			}
		}

		return $data;
	}
}

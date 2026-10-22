<?php
/**
 * Payment entry point: validates the cart into an order, creates the AneePay
 * payment and shows the pending screen with the hosted-checkout link.
 */
class Ps_AneepayPaymentModuleFrontController extends ModuleFrontController {
	public $ssl = true;

	public function initContent() {
		parent::initContent();

		$cart     = $this->context->cart;
		$customer = $this->context->customer;

		$home = $this->context->link->getPageLink('index');

		if (!$this->module->active || !$cart->id || $cart->id_customer != (int) $customer->id) {
			Tools::redirect($home);
		}

		$order_model = new AneePayOrder();

		// Idempotency gate: reuse an existing pending hosted payment for this cart.
		$existing = $order_model->getByCart((int) $cart->id);
		if ($existing && !empty($existing['checkout_url']) && in_array((string) $existing['payment_status'], array('', 'pending'), true)) {
			$this->context->cookie->__set('aneepay_order_id', (int) $existing['id_order'], time() + 3600);

			$this->renderPending($existing);

			return;
		}

		// Idempotency gate 2: the cookie may point at a pending order started
		// before the cart was consumed (e.g. retry from the fail page). Reuse
		// its hosted payment instead of creating a duplicate order + payment.
		$cookie_order = isset($this->context->cookie->aneepay_order_id) ? (int) $this->context->cookie->aneepay_order_id : 0;

		if ($cookie_order > 0) {
			$by_cookie = $order_model->getByOrder($cookie_order);

			if ($by_cookie && !empty($by_cookie['checkout_url']) && in_array((string) $by_cookie['payment_status'], array('', 'pending'), true)) {
				$this->renderPending($by_cookie);

				return;
			}
		}

		$total    = (float) $cart->getOrderTotal(true, Cart::BOTH);
		$currency = new Currency((int) $cart->id_currency);

		if ($total <= 0) {
			Tools::redirect($home);
		}

		// Create the order FIRST so the payment references the real order id
		// and a failed validateOrder() cannot leave an orphan payment behind.
		$pendingState = (int) Configuration::get('ANEEPAY_PENDING_STATE');

		$this->module->validateOrder(
			(int) $cart->id,
			$pendingState,
			$total,
			$this->module->displayName,
			null,
			array(),
			null,
			false,
			$cart->secure_key
		);

		$id_order = (int) $this->module->currentOrder;

		if ($id_order <= 0) {
			$this->redirectWithNotice($home, 'error', 'Unable to create the order. Please try again.');

			return;
		}

		$api = $this->module->getApi();
		$api->set_config('store_rate', $this->module->getStoreRate((string) $currency->iso_code));

		try {
			$converter = new AneePayUsdConverter($api);
			$converted = $converter->convert($total, (string) $currency->iso_code);
			$created   = $api->create_payment($converted['token_amount'], 'Order #' . $id_order);
		} catch (Exception $e) {
			// Compensate: the order exists but no payment does — cancel it and
			// release the stock validateOrder() just reserved.
			$cancelledState = (int) Configuration::get('ANEEPAY_CANCELLED_STATE');

			if ($cancelledState > 0) {
				$history           = new OrderHistory();
				$history->id_order = $id_order;

				$history->changeIdOrderState($cancelledState, new Order($id_order), true);
				$history->add();
			}

			AneePayWebhook::restoreOrderStock($id_order);

			$this->redirectWithNotice($home, 'error', $e->getMessage());

			return;
		}

		$mapping = array(
			'id_cart'        => (int) $cart->id,
			'payment_id'     => $created['payment_id'],
			'operation_id'   => $created['operation_id'],
			'checkout_url'   => $created['checkout_url'],
			'token'          => $api->get_token(),
			'network'        => $api->get_network(),
			'sandbox'        => $api->is_sandbox() ? 1 : 0,
			'payment_status' => 'pending',
			'token_amount'   => number_format((float) $converted['token_amount'], 2, '.', ''),
			'order_currency' => (string) $currency->iso_code,
			'order_total'    => (string) $total,
			'usd_amount'     => number_format((float) $converted['usd_amount'], 2, '.', ''),
			'fiat_per_usd'   => (string) $converted['fiat_per_usd'],
			'rate_source'    => (string) $converted['source'],
		);

		$order_model->saveMapping($id_order, $mapping);

		$this->context->cookie->__set('aneepay_order_id', $id_order, time() + 3600);

		$this->renderPending(array_merge(array('id_order' => $id_order), $mapping));
	}

	protected function renderPending($row) {
		$this->context->smarty->assign(array(
			'order_id'     => (int) $row['id_order'],
			'payment_id'   => (string) $row['payment_id'],
			'token'        => strtoupper((string) $row['token']),
			'token_amount' => (string) $row['token_amount'],
			'pay_now'      => trim((string) $row['token_amount']) . ' ' . strtoupper((string) $row['token']),
			'checkout_url' => (string) $row['checkout_url'],
			'network'      => (string) $row['network'],
			'sandbox'      => (bool) $row['sandbox'],
			'check_url'    => $this->module->moduleLink('check'),
			'success_url'  => $this->module->moduleLink('success'),
			'fail_url'     => $this->module->moduleLink('fail'),
			'shop_url'     => $this->context->link->getPageLink('index'),
		));

		$this->setTemplate('module:ps_aneepay/views/templates/front/payment_pending.tpl');
	}

	protected function redirectWithNotice($url, $type, $message) {
		$this->context->cookie->__set('aneepay_error', $message, time() + 600);

		Tools::redirect($url);
	}
}

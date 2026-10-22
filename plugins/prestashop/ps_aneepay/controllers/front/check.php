<?php
/**
 * AJAX: check the status of an AneePay order ("I already paid" / polling).
 */
class Ps_AneepayCheckModuleFrontController extends ModuleFrontController {
	public $ssl = true;

	public function initContent() {
		parent::initContent();

		$module = Module::getInstanceByName('ps_aneepay');
		$api    = $module->getApi();

		$order_id = (int) Tools::getValue('id_order');

		if (!$order_id) {
			$this->respond(400, array('status' => 'invalid'));
		}

		// Ownership: the order must match the signed cookie set at checkout.
		// Without it any visitor could poll / trigger a status transition for
		// an arbitrary order id (IDOR).
		if ($module->resultOrderId() !== $order_id) {
			$this->respond(403, array('status' => 'forbidden'));
		}

		$order_model = new AneePayOrder();
		$row         = $order_model->getByOrder((int) $order_id);

		if (!$row) {
			$this->respond(404, array('status' => 'not_found'));
		}

		if (!empty($row['payment_status']) && !in_array((string) $row['payment_status'], array('', 'pending'), true)) {
			$this->respond(200, array('status' => (string) $row['payment_status']));
		}

		// Rate limit (per order): a short-lived cookie throttles how often this
		// browser can force an API re-check, so the endpoint cannot be used to
		// hammer AneePay for a known order.
		$throttleKey = 'aneepay_check_' . $order_id;

		if (!isset($this->context->cookie->{$throttleKey})) {
			$this->context->cookie->__set($throttleKey, time() + 3, time() + 3);
		} elseif ((int) $this->context->cookie->{$throttleKey} > time()) {
			$this->respond(200, array('status' => (string) $row['payment_status']));
		} else {
			$this->context->cookie->__set($throttleKey, time() + 3, time() + 3);
		}

		try {
			$data = $api->get_payment((string) $row['payment_id']);

			if (null === $data || empty($data['status'])) {
				$this->respond(200, array('status' => (string) $row['payment_status']));
			}

			$webhook = new AneePayWebhook($api, $order_model, $module->getStateMap());
			$webhook->apply((int) $order_id, (string) $data['status']);

			$this->respond(200, array('status' => (string) $data['status']));
		} catch (Exception $e) {
			$this->respond(200, array('status' => 'pending'));
		}
	}

	protected function respond($code, $data) {
		http_response_code((int) $code);
		header('Content-Type: application/json');
		echo json_encode($data);

		exit;
	}
}

<?php
/**
 * Webhook endpoint (STATUS_URL). Verifies the HMAC-SHA256 signature and applies
 * the payment status to the matching order.
 */
class Ps_AneepayWebhookModuleFrontController extends ModuleFrontController {
	public function init() {
		$module = Module::getInstanceByName('ps_aneepay');

		if (!Validate::isLoadedObject($module)) {
			$this->respond(500, array('success' => false));
		}

		$api = $module->getApi();

		$body      = (string) Tools::file_get_contents('php://input');
		$signature = isset($_SERVER['HTTP_X_ANEEPAY_SIGNATURE']) ? (string) $_SERVER['HTTP_X_ANEEPAY_SIGNATURE'] : '';

		if (!$api->verify_signature($body, $signature)) {
			$this->respond(401, array('success' => false));
		}

		$params = json_decode($body, true, 512, JSON_BIGINT_AS_STRING);

		if (!is_array($params) || empty($params['status'])) {
			$this->respond(400, array('success' => false));
		}

		$status = (string) $params['status'];

		if (!isset($module->getStateMap()[$status])) {
			$this->respond(400, array('success' => false));
		}

		// Reject replayed webhooks: the payload timestamp must not be older than 24h.
		if (isset($params['timestamp'])) {
			$ts = (float) $params['timestamp'];

			if ($ts > 0 && (time() - $ts) > 86400) {
				$this->respond(400, array('success' => false, 'error' => 'stale_timestamp'));
			}
		}

		$webhook = new AneePayWebhook($api, new AneePayOrder(), $module->getStateMap());
		$id_order = $webhook->resolveOrderId($params);

		if (!$id_order) {
			$this->respond(404, array('success' => false));
		}

		$webhook->apply((int) $id_order, (string) $params['status'], $this->note((string) $params['status'], $params));

		$this->respond(200, array('success' => true, 'status' => (string) $params['status']));
	}

	/**
	 * Order note for a status.
	 *
	 * @param string $status Payment status.
	 * @param array  $params Webhook payload.
	 * @return string
	 */
	protected function note($status, $params) {
		$map = array(
			'success'   => 'AneePay: payment confirmed.',
			'failed'    => 'AneePay: payment failed.',
			'cancelled' => 'AneePay: payment cancelled by the customer.',
			'pending'   => 'AneePay: awaiting payment.',
		);

		$note = isset($map[$status]) ? $map[$status] : 'AneePay: ' . $status . '.';

		if (!empty($params['operation_id'])) {
			$note .= ' Operation ID: ' . (string) $params['operation_id'];
		}

		return $note;
	}

	/**
	 * Send a JSON response and stop.
	 *
	 * @param int   $code HTTP status code.
	 * @param array $data Payload.
	 * @return void
	 */
	protected function respond($code, $data) {
		http_response_code((int) $code);
		header('Content-Type: application/json');
		echo json_encode($data);

		exit;
	}
}

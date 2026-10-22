<?php
/**
 * Poll pending AneePay orders (cron entry point).
 */
class Ps_AneepayCronModuleFrontController extends ModuleFrontController {
	public function init() {
		$module = Module::getInstanceByName('ps_aneepay');

		if (!Validate::isLoadedObject($module)) {
			$this->respond(500, array('success' => false, 'message' => 'module not loaded'));
		}

		// The poller is a public front controller, so it must be gated by a
		// secret: otherwise any visitor could drive status transitions for
		// every pending order. The secret is generated once and persisted.
		$provided = (string) Tools::getValue('secret');

		if ('' === $provided || !hash_equals($module->cronSecret(), $provided)) {
			$this->respond(403, array('success' => false, 'message' => 'forbidden'));
		}

		// No-overlap guard. A lock older than 10 minutes is considered stale
		// (a previous run crashed) and is taken over.
		$lock = _PS_CACHE_DIR_ . 'aneepay_cron_lock';

		if (file_exists($lock) && (time() - (int) file_get_contents($lock)) < 600) {
			$this->respond(200, array('success' => false, 'message' => 'locked'));
		}

		file_put_contents($lock, (string) time());

		$payload = array('success' => true, 'processed' => 0);

		try {
			$api       = $module->getApi();
			$webhook   = new AneePayWebhook($api, new AneePayOrder(), $module->getStateMap());
			$processed = 0;

			foreach ((new AneePayOrder())->getPendingOrderIds() as $id_order) {
				$row = (new AneePayOrder())->getByOrder((int) $id_order);

				if (!$row || empty($row['payment_id'])) {
					continue;
				}

				$data = $api->get_payment((string) $row['payment_id']);

				if (null === $data || empty($data['status'])) {
					continue;
				}

				if ($webhook->apply((int) $id_order, (string) $data['status'])) {
					$processed++;
				}
			}

			$payload['processed'] = $processed;
		} catch (Exception $e) {
			$payload = array('success' => false, 'message' => $e->getMessage());
		}

		// Release the lock before responding: respond() exits the script, so a
		// finally block would never run.
		if (file_exists($lock)) {
			unlink($lock);
		}

		$this->respond(200, $payload);
	}

	protected function respond($code, $data) {
		http_response_code((int) $code);
		header('Content-Type: application/json');
		echo json_encode($data);

		exit;
	}
}

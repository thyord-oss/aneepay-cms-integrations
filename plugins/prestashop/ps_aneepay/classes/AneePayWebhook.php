<?php
/**
 * AneePay webhook handling for PrestaShop.
 *
 * Resolves an order from a webhook payload and applies the payment status to the
 * PrestaShop order via OrderHistory.
 */
class AneePayWebhook {
	/** @var AneePayApi */
	protected $client;

	/** @var AneePayOrder */
	protected $order_model;

	/** @var array Status map: payment_status => order state id. */
	protected $state_map;

	/**
	 * @param AneePayApi   $client      API client.
	 * @param AneePayOrder $order_model Order mapping model.
	 * @param array        $state_map   Payment status => order state id.
	 */
	public function __construct($client, $order_model, $state_map) {
		$this->client      = $client;
		$this->order_model = $order_model;
		$this->state_map   = $state_map;
	}

	/**
	 * Resolve an order id from a webhook payload.
	 *
	 * Lookup order: order_id -> payment_id -> operation_id -> operation_id
	 * decoded to a payment UUID -> description "Order #N".
	 *
	 * @param array $params Webhook payload.
	 * @return int|null
	 */
	public function resolveOrderId($params) {
		if (isset($params['order_id'])) {
			$id = (int) $params['order_id'];

			if ($id > 0 && $this->uses_aneepay($id)) {
				return $id;
			}
		}

		if (isset($params['payment_id'])) {
			$row = $this->order_model->getByPaymentId((string) $params['payment_id']);

			if ($row) {
				return (int) $row['id_order'];
			}
		}

		$operation_id = '';

		if (isset($params['operation_id'])) {
			$operation_id = (string) $params['operation_id'];
		} elseif (isset($params['operationId'])) {
			$operation_id = (string) $params['operationId'];
		}

		if ('' !== $operation_id) {
			$row = $this->order_model->getByOperationId($operation_id);

			if ($row) {
				return (int) $row['id_order'];
			}

			$uuid = $this->client->operation_id_to_uuid($operation_id);

			if (null !== $uuid) {
				$row = $this->order_model->getByPaymentId($uuid);

				if ($row) {
					return (int) $row['id_order'];
				}
			}
		}

		if (!empty($params['description']) && preg_match('/#\s*(\d+)/', (string) $params['description'], $matches)) {
			$id = (int) $matches[1];

			if ($id > 0 && $this->uses_aneepay($id)) {
				return $id;
			}
		}

		return null;
	}

	/**
	 * Apply a payment status to an order (idempotent).
	 *
	 * @param int    $id_order PrestaShop order id.
	 * @param string $status   Payment status (pending|success|failed|cancelled).
	 * @param string $note     Optional order note.
	 * @return bool
	 */
	public function apply($id_order, $status, $note = '') {
		// Unknown statuses are never applied.
		if (!isset($this->state_map[$status])) {
			return false;
		}

		$row = $this->order_model->getByOrder($id_order);

		$current = ($row && isset($row['payment_status'])) ? (string) $row['payment_status'] : '';

		if ($status === $current) {
			return false;
		}

		// "success" is terminal: never downgrade a paid order.
		if ('success' === $current) {
			return false;
		}

		$state_id = isset($this->state_map[$status]) ? (int) $this->state_map[$status] : 0;

		if ($state_id > 0) {
			$order = new Order($id_order);

			// Webhook/cron/check run without the front-office bootstrap that
			// normally populates Context::$currency; changeIdOrderState() needs
			// it when it generates the invoice (computing precision).
			$context = Context::getContext();

			if (Validate::isLoadedObject($order) && !Validate::isLoadedObject($context->currency)) {
				$context->currency = new Currency((int) $order->id_currency);
			}

			$history           = new OrderHistory();
			$history->id_order = (int) $order->id;

			$history->changeIdOrderState($state_id, $order, true);
			$history->add();
		}

		return $this->order_model->updateStatus($id_order, $status);
	}

	/**
	 * Whether an order was paid via AneePay.
	 *
	 * @param int $id_order Order id.
	 * @return bool
	 */
	protected function uses_aneepay($id_order) {
		return null !== $this->order_model->getByOrder($id_order);
	}
}

<?php
/**
 * AneePay webhook handling for OpenCart.
 *
 * Resolves an order from a webhook payload and applies the payment status to
 * the OpenCart order history, mirroring the WooCommerce reference behaviour.
 */
class AneePay_Webhook {
	/**
	 * @var object OpenCart registry.
	 */
	protected $registry;

	/**
	 * @var AneePay_Client
	 */
	protected $client;

	/**
	 * Status map: payment status => OpenCart order status id.
	 *
	 * @var array
	 */
	protected $status_map;

	/**
	 * @param object         $registry   OC registry.
	 * @param AneePay_Client $client     API client.
	 * @param array          $status_map Status map.
	 */
	public function __construct($registry, $client, $status_map) {
		$this->registry   = $registry;
		$this->client     = $client;
		$this->status_map = $status_map;
	}

	/**
	 * Resolve an OpenCart order id from a webhook payload.
	 *
	 * Lookup order: order_id → payment_id → operation_id → operation_id decoded
	 * to a payment UUID → description "Order #N".
	 *
	 * @param array $params Webhook payload.
	 * @return int|null Order id, or null.
	 */
	public function resolve_order_id($params) {
		$registry = $this->registry;

		if (isset($params['order_id'])) {
			$order_id = (int) $params['order_id'];

			if ($order_id > 0 && $this->order_uses_aneepay($order_id)) {
				return $order_id;
			}
		}

		$model = $registry->get('model_extension_payment_aneepay');

		if (isset($params['payment_id'])) {
			$row = $model->getByPaymentId((string) $params['payment_id']);

			if ($row) {
				return (int) $row['order_id'];
			}
		}

		$operation_id = '';

		if (isset($params['operation_id'])) {
			$operation_id = (string) $params['operation_id'];
		} elseif (isset($params['operationId'])) {
			$operation_id = (string) $params['operationId'];
		}

		if ('' !== $operation_id) {
			$row = $model->getByOperationId($operation_id);

			if ($row) {
				return (int) $row['order_id'];
			}

			$uuid = $this->client->operation_id_to_uuid($operation_id);

			if (null !== $uuid) {
				$row = $model->getByPaymentId($uuid);

				if ($row) {
					return (int) $row['order_id'];
				}
			}
		}

		if (!empty($params['description']) && preg_match('/#\s*(\d+)/', (string) $params['description'], $matches)) {
			$order_id = (int) $matches[1];

			if ($order_id > 0 && $this->order_uses_aneepay($order_id)) {
				return $order_id;
			}
		}

		return null;
	}

	/**
	 * Apply a payment status to an order.
	 *
	 * Idempotent: does nothing if the mapping already has the same status.
	 *
	 * @param int    $order_id Order id.
	 * @param string $status   Payment status (pending|success|failed|cancelled).
	 * @param string $note     Order history comment.
	 * @return bool Whether the status changed.
	 */
	public function apply($order_id, $status, $note = '') {
		$registry = $this->registry;
		$model    = $registry->get('model_extension_payment_aneepay');

		// Unknown statuses are never applied.
		if (!isset($this->status_map[$status])) {
			return false;
		}

		$row = $model->getByOrderId($order_id);

		$current = ($row && isset($row['payment_status'])) ? (string) $row['payment_status'] : '';

		if ($status === $current) {
			return false;
		}

		// "success" is terminal: never downgrade a paid order.
		if ('success' === $current) {
			return false;
		}

		$status_id = isset($this->status_map[$status]) ? (int) $this->status_map[$status] : 0;

		if ($status_id > 0) {
			$registry->get('model_checkout_order')->addOrderHistory($order_id, $status_id, $note, true);
		}

		$model->updatePaymentStatus($order_id, $status);

		// Restore reserved stock on failure/cancellation exactly once.
		if (in_array($status, array('failed', 'cancelled'), true) && !$model->isStockRestored($order_id)) {
			$this->restore_stock($order_id);
			$model->markStockRestored($order_id);
		}

		return true;
	}

	/**
	 * Add the order's product quantities back to stock (mirrors OC's own
	 * reduction in model_checkout_order::addOrderHistory).
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	protected function restore_stock($order_id) {
		$registry = $this->registry;

		$registry->get('load')->model('sale/order');

		$order_products = $registry->get('model_sale_order')->getOrderProducts($order_id);

		foreach ($order_products as $product) {
			$registry->get('db')->query(
				"UPDATE `" . DB_PREFIX . "product` SET `quantity` = `quantity` + " . (int) $product['quantity'] .
				" WHERE `product_id` = " . (int) $product['product_id']
			);
		}
	}

	/**
	 * Whether an order was paid via AneePay.
	 *
	 * @param int $order_id Order id.
	 * @return bool
	 */
	protected function order_uses_aneepay($order_id) {
		return null !== $this->registry->get('model_extension_payment_aneepay')->getByOrderId($order_id);
	}
}

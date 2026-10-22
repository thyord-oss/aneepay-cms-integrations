<?php
/**
 * AneePay order mapping (ps_aneepay_order table).
 */
class AneePayOrder {
	const TABLE = 'aneepay_order';

	/**
	 * Store the payment + conversion context for an order.
	 *
	 * @param int   $id_order Order id.
	 * @param array $data     Payment and conversion context.
	 * @return bool
	 */
	public function saveMapping($id_order, $data) {
		$db = Db::getInstance();

		$db->delete(self::TABLE, 'id_order = ' . (int) $id_order);

		return $db->insert(self::TABLE, array(
			'id_order'       => (int) $id_order,
			'id_cart'        => isset($data['id_cart']) ? (int) $data['id_cart'] : 0,
			'payment_id'     => isset($data['payment_id']) ? pSQL($data['payment_id']) : '',
			'operation_id'   => isset($data['operation_id']) ? pSQL($data['operation_id']) : '',
			'checkout_url'   => isset($data['checkout_url']) ? pSQL($data['checkout_url']) : '',
			'token'          => isset($data['token']) ? pSQL($data['token']) : '',
			'network'        => isset($data['network']) ? pSQL($data['network']) : '',
			'sandbox'        => isset($data['sandbox']) ? (int) $data['sandbox'] : 0,
			'payment_status' => isset($data['payment_status']) ? pSQL($data['payment_status']) : 'pending',
			'token_amount'   => isset($data['token_amount']) ? pSQL($data['token_amount']) : '',
			'order_currency' => isset($data['order_currency']) ? pSQL($data['order_currency']) : '',
			'order_total'    => isset($data['order_total']) ? pSQL($data['order_total']) : '',
			'usd_amount'     => isset($data['usd_amount']) ? pSQL($data['usd_amount']) : '',
			'fiat_per_usd'   => isset($data['fiat_per_usd']) ? pSQL($data['fiat_per_usd']) : '',
			'rate_source'    => isset($data['rate_source']) ? pSQL($data['rate_source']) : '',
			'date_added'     => date('Y-m-d H:i:s'),
		));
	}

	/** @return array|null */
	public function getByOrder($id_order) {
		$db  = Db::getInstance();
		$row = $db->getRow('SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `id_order` = ' . (int) $id_order);

		return $row ? $row : null;
	}

	/** @return array|null */
	public function getByCart($id_cart) {
		$db  = Db::getInstance();
		$row = $db->getRow('SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `id_cart` = ' . (int) $id_cart);

		return $row ? $row : null;
	}

	/** @return array|null */
	public function getByPaymentId($payment_id) {
		$db  = Db::getInstance();
		$row = $db->getRow('SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `payment_id` = \'' . pSQL((string) $payment_id) . '\'');

		return $row ? $row : null;
	}

	/** @return array|null */
	public function getByOperationId($operation_id) {
		$db  = Db::getInstance();
		$row = $db->getRow('SELECT * FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `operation_id` = \'' . pSQL((string) $operation_id) . '\'');

		return $row ? $row : null;
	}

	/**
	 * @return bool
	 */
	public function updateStatus($id_order, $status) {
		return (bool) Db::getInstance()->update(self::TABLE, array('payment_status' => pSQL((string) $status)), 'id_order = ' . (int) $id_order);
	}

	/**
	 * Whether stock was already restored for the order (idempotency guard).
	 *
	 * @param int $id_order Order id.
	 * @return bool
	 */
	public function isStockRestored($id_order) {
		$row = Db::getInstance()->getRow(
			'SELECT `stock_restored` FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `id_order` = ' . (int) $id_order
		);

		return $row && !empty($row['stock_restored']);
	}

	/**
	 * Mark stock as restored so a later failed/cancelled event cannot double-restore.
	 *
	 * @param int $id_order Order id.
	 * @return bool
	 */
	public function markStockRestored($id_order) {
		return (bool) Db::getInstance()->update(self::TABLE, array('stock_restored' => 1), 'id_order = ' . (int) $id_order);
	}

	/** @return array<int> */
	public function getPendingOrderIds() {
		$db  = Db::getInstance();
		$ids = array();

		$rows = $db->executeS(
			'SELECT `id_order` FROM `' . _DB_PREFIX_ . self::TABLE . '`
			 WHERE `payment_status` IN (\'\', \'pending\') ORDER BY `id_order` ASC'
		);

		foreach ($rows as $row) {
			$ids[] = (int) $row['id_order'];
		}

		return $ids;
	}
}

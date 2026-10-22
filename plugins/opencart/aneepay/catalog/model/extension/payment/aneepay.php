<?php
/**
 * AneePay order mapping model (catalog).
 *
 * Stores payment context per order in the aneepay_order table: payment_id,
 * operation_id, checkout_url, token, network, sandbox, token_amount and the
 * conversion context, plus the resolved payment_status.
 */
class ModelExtensionPaymentAneePay extends Model {
	/**
	 * Store the payment + conversion context for an order.
	 *
	 * @param int   $order_id Order id.
	 * @param array $data     Payment and conversion data.
	 * @return void
	 */
	public function addMapping($order_id, $data) {
		$this->db->query("DELETE FROM `" . DB_PREFIX . "aneepay_order` WHERE `order_id` = '" . (int) $order_id . "'");

		$this->db->query("INSERT INTO `" . DB_PREFIX . "aneepay_order` SET
			`order_id` = '" . (int) $order_id . "',
			`store_id` = '" . (int) $this->config->get('config_store_id') . "',
			`payment_id` = '" . $this->db->escape(isset($data['payment_id']) ? $data['payment_id'] : '') . "',
			`operation_id` = '" . $this->db->escape(isset($data['operation_id']) ? $data['operation_id'] : '') . "',
			`checkout_url` = '" . $this->db->escape(isset($data['checkout_url']) ? $data['checkout_url'] : '') . "',
			`token` = '" . $this->db->escape(isset($data['token']) ? $data['token'] : '') . "',
			`network` = '" . $this->db->escape(isset($data['network']) ? $data['network'] : '') . "',
			`sandbox` = '" . (isset($data['sandbox']) ? (int) $data['sandbox'] : 0) . "',
			`payment_status` = '" . $this->db->escape(isset($data['payment_status']) ? $data['payment_status'] : 'pending') . "',
			`token_amount` = '" . $this->db->escape(isset($data['token_amount']) ? $data['token_amount'] : '') . "',
			`order_currency` = '" . $this->db->escape(isset($data['order_currency']) ? $data['order_currency'] : '') . "',
			`order_total` = '" . $this->db->escape(isset($data['order_total']) ? $data['order_total'] : '') . "',
			`usd_amount` = '" . $this->db->escape(isset($data['usd_amount']) ? $data['usd_amount'] : '') . "',
			`fiat_per_usd` = '" . $this->db->escape(isset($data['fiat_per_usd']) ? $data['fiat_per_usd'] : '') . "',
			`rate_source` = '" . $this->db->escape(isset($data['rate_source']) ? $data['rate_source'] : '') . "',
			`date_added` = NOW()");
	}

	/**
	 * Fetch the mapping for an order.
	 *
	 * @param int $order_id Order id.
	 * @return array|null
	 */
	public function getByOrderId($order_id) {
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "aneepay_order` WHERE `order_id` = '" . (int) $order_id . "' LIMIT 1");

		return $query->num_rows ? $query->row : null;
	}

	/**
	 * Fetch a mapping by AneePay payment UUID.
	 *
	 * @param string $payment_id Payment uuid.
	 * @return array|null
	 */
	public function getByPaymentId($payment_id) {
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "aneepay_order` WHERE `payment_id` = '" . $this->db->escape((string) $payment_id) . "' LIMIT 1");

		return $query->num_rows ? $query->row : null;
	}

	/**
	 * Fetch a mapping by AneePay operation_id (128-bit integer as string).
	 *
	 * @param string $operation_id Operation id.
	 * @return array|null
	 */
	public function getByOperationId($operation_id) {
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "aneepay_order` WHERE `operation_id` = '" . $this->db->escape((string) $operation_id) . "' LIMIT 1");

		return $query->num_rows ? $query->row : null;
	}

	/**
	 * Update the stored payment status of an order.
	 *
	 * @param int    $order_id Order id.
	 * @param string $status   Payment status.
	 * @return void
	 */
	public function updatePaymentStatus($order_id, $status) {
		$this->db->query("UPDATE `" . DB_PREFIX . "aneepay_order` SET `payment_status` = '" . $this->db->escape((string) $status) . "' WHERE `order_id` = '" . (int) $order_id . "'");
	}

	/**
	 * Whether stock was already restored for the order (idempotency guard).
	 *
	 * @param int $order_id Order id.
	 * @return bool
	 */
	public function isStockRestored($order_id) {
		$query = $this->db->query("SELECT `stock_restored` FROM `" . DB_PREFIX . "aneepay_order` WHERE `order_id` = '" . (int) $order_id . "' LIMIT 1");

		return $query->num_rows && !empty($query->row['stock_restored']);
	}

	/**
	 * Mark stock as restored so a later failed/cancelled event cannot double-restore.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public function markStockRestored($order_id) {
		$this->db->query("UPDATE `" . DB_PREFIX . "aneepay_order` SET `stock_restored` = 1 WHERE `order_id` = '" . (int) $order_id . "'");
	}

	/**
	 * All orders still awaiting a hosted payment (for the cron poller).
	 *
	 * @return array<int> Order ids.
	 */
	public function getPendingOrderIds() {
		$query = $this->db->query("SELECT `order_id` FROM `" . DB_PREFIX . "aneepay_order` WHERE `payment_status` IN ('', 'pending') ORDER BY `order_id` ASC");

		$ids = array();

		foreach ($query->rows as $row) {
			$ids[] = (int) $row['order_id'];
		}

		return $ids;
	}
}

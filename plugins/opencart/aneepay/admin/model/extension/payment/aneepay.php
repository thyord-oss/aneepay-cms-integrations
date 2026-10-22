<?php
/**
 * AneePay admin model: table install/uninstall + request log access.
 */
class ModelExtensionPaymentAneePay extends Model {
	/**
	 * Create the extension tables.
	 *
	 * @return void
	 */
	public function install() {
		$this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "aneepay_order` (
			`id` INT(11) NOT NULL AUTO_INCREMENT,
			`order_id` INT(11) NOT NULL,
			`store_id` INT(11) NOT NULL DEFAULT 0,
			`payment_id` VARCHAR(64) NOT NULL DEFAULT '',
			`operation_id` VARCHAR(64) NOT NULL DEFAULT '',
			`checkout_url` TEXT NOT NULL,
			`token` VARCHAR(16) NOT NULL DEFAULT '',
			`network` VARCHAR(16) NOT NULL DEFAULT '',
			`sandbox` TINYINT(1) NOT NULL DEFAULT 0,
			`payment_status` VARCHAR(16) NOT NULL DEFAULT 'pending',
			`token_amount` VARCHAR(32) NOT NULL DEFAULT '',
			`order_currency` VARCHAR(8) NOT NULL DEFAULT '',
			`order_total` VARCHAR(32) NOT NULL DEFAULT '',
			`usd_amount` VARCHAR(32) NOT NULL DEFAULT '',
			`fiat_per_usd` VARCHAR(32) NOT NULL DEFAULT '',
			`rate_source` VARCHAR(16) NOT NULL DEFAULT '',
			`stock_restored` TINYINT(1) NOT NULL DEFAULT 0,
			`date_added` DATETIME NOT NULL,
			PRIMARY KEY (`id`),
			KEY `order_id` (`order_id`),
			KEY `payment_id` (`payment_id`),
			KEY `operation_id` (`operation_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

		$this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "aneepay_log` (
			`log_id` INT(11) NOT NULL AUTO_INCREMENT,
			`context` VARCHAR(64) NOT NULL DEFAULT '',
			`message` TEXT NOT NULL,
			`level` VARCHAR(16) NOT NULL DEFAULT 'info',
			`date_added` DATETIME NOT NULL,
			PRIMARY KEY (`log_id`),
			KEY `date_added` (`date_added`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
	}

	/**
	 * Drop the extension tables.
	 *
	 * @return void
	 */
	public function uninstall() {
		// Preserve order/log data by default. The DROP only runs when the
		// merchant explicitly opts in to deletion via the keep-data setting,
		// so a reinstall or audit can still read the tables.
		if ($this->config->get('payment_aneepay_keep_data') !== '0') {
			return;
		}

		$this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "aneepay_order`");
		$this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "aneepay_log`");
	}

	/**
	 * The most recent request log entries (newest first), ring buffer.
	 *
	 * @return array
	 */
	public function getLogs() {
		$query = $this->db->query("SELECT `context`, `message`, `level`, `date_added` FROM `" . DB_PREFIX . "aneepay_log` WHERE `context` = 'request' ORDER BY `log_id` DESC LIMIT 50");

		return $query->rows;
	}

	/**
	 * Clear the request log.
	 *
	 * @return void
	 */
	public function clearLogs() {
		$this->db->query("DELETE FROM `" . DB_PREFIX . "aneepay_log` WHERE `context` = 'request'");
	}
}

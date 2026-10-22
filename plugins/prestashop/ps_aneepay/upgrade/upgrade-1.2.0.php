<?php
if (!defined('_PS_VERSION_')) {
	exit;
}

/**
 * Add the stock_restored flag to existing installations and keep data by default.
 *
 * @param Module $module
 * @return bool
 */
function upgrade_module_1_2_0($module) {
	$table = _DB_PREFIX_ . 'aneepay_order';

	$exists = Db::getInstance()->executeS("SHOW TABLES LIKE '" . pSQL($table) . "'");

	if (empty($exists)) {
		return true;
	}

	$columns = Db::getInstance()->executeS('SHOW COLUMNS FROM `' . $table . "` LIKE 'stock_restored'");

	if (empty($columns) && !Db::getInstance()->execute('ALTER TABLE `' . $table . '` ADD COLUMN `stock_restored` TINYINT(1) NOT NULL DEFAULT 0')) {
		return false;
	}

	if ('' === (string) Configuration::get('ANEEPAY_KEEP_DATA')) {
		Configuration::updateValue('ANEEPAY_KEEP_DATA', '1');
	}

	return true;
}

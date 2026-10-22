<?php
/**
 * AneePay fiat -> USD -> token converter for OpenCart.
 *
 * Stablecoins (USDT/USDC/DAI) are pegged to USD, so charging a token amount is
 * equivalent to charging a USD amount. This class converts the store total
 * (in the store currency) into the token amount requested from the AneePay API.
 *
 * USD rate resolution priority:
 *   1. Frankfurter/ECB automatic rate (cached).
 *   2. Store-native per-USD rate passed by the caller.
 *   3. Merchant-provided manual rate.
 *   If none are available, an exception is thrown — the amount is never guessed.
 */
class AneePay_Usd_Converter {
	/**
	 * @var AneePay_Client
	 */
	protected $client;

	/**
	 * @param AneePay_Client $client API client.
	 */
	public function __construct($client) {
		$this->client = $client;
	}

	/**
	 * Whether the automatic rate source (Frankfurter/ECB) is enabled.
	 *
	 * @return bool
	 */
	public function is_auto_enabled() {
		return 'manual' !== (string) $this->client->get('rate_source', 'auto');
	}

	/**
	 * Merchant-provided manual rate: "1 token (~$1) = X store currency".
	 *
	 * @return float|null Null when not configured.
	 */
	protected function get_manual_rate() {
		$rate = (float) $this->client->get('exchange_rate', 0);

		return $rate > 0 ? $rate : null;
	}

	/**
	 * Resolve the number of store-currency units per one USD.
	 *
	 * @param string $currency Store currency code.
	 * @param string $source   (out) Resolved source: auto|store|manual.
	 * @return float|null Units of store currency per 1 USD, or null.
	 */
	public function get_fiat_per_usd($currency, &$source) {
		$currency = strtoupper(trim((string) $currency));

		if ('USD' === $currency) {
			$source = 'auto';
			return 1.0;
		}

		if ($this->is_auto_enabled()) {
			$rate = $this->fetch_auto_rate($currency);

			if (null !== $rate) {
				$source = 'auto';
				return $rate;
			}
		}

		$store = (float) $this->client->get('store_rate', 0);

		if ($store > 0) {
			$source = 'store';
			return $store;
		}

		$manual = $this->get_manual_rate();

		if (null !== $manual && $manual > 0) {
			$source = 'manual';
			return $manual;
		}

		return null;
	}

	/**
	 * Convert a store-total into the token amount to request.
	 *
	 * @param float|string $store_total Order total in the store currency.
	 * @param string       $currency    Store currency code.
	 * @return array{token_amount:float, usd_amount:float, fiat_per_usd:float, currency:string, source:string}
	 * @throws Exception When no USD rate can be resolved.
	 */
	public function convert($store_total, $currency) {
		$store_total = (float) $store_total;

		if ($store_total <= 0) {
			throw new Exception('Order total must be greater than zero.');
		}

		$source = 'auto';
		$rate   = $this->get_fiat_per_usd($currency, $source);

		if (null === $rate || $rate <= 0) {
			throw new Exception('Unable to resolve a USD exchange rate. Set a manual rate in the AneePay settings.');
		}

		$usd_amount  = $store_total / $rate;
		$token_amount = ceil($usd_amount * 100) / 100;

		return array(
			'token_amount' => $token_amount,
			'usd_amount'   => $usd_amount,
			'fiat_per_usd' => $rate,
			'currency'     => $currency,
			'source'       => $source,
		);
	}

	/**
	 * Fetch and cache the automatic Frankfurter/ECB rate using the store DB
	 * cache table (oc_cache) so it survives across requests.
	 *
	 * @param string $currency Store currency code.
	 * @return float|null Units of store currency per 1 USD, or null.
	 */
	protected function fetch_auto_rate($currency) {
		$cache = $this->cache_get('aneepay_rate_' . strtolower($currency));

		if (is_array($cache) && isset($cache['rate']) && (float) $cache['rate'] > 0) {
			return (float) $cache['rate'];
		}

		$url = 'https://api.frankfurter.app/latest?from=USD&to=' . rawurlencode($currency);

		$ch = curl_init();

		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
		curl_setopt($ch, CURLOPT_TIMEOUT, 10);

		$body = curl_exec($ch);
		$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

		curl_close($ch);

		if (200 !== $code) {
			return null;
		}

		$data = json_decode((string) $body, true);

		$rate = isset($data['rates'][$currency]) ? (float) $data['rates'][$currency] : 0.0;

		if ($rate <= 0) {
			return null;
		}

		$this->cache_set('aneepay_rate_' . strtolower($currency), array('rate' => $rate, 'time' => time()));

		return $rate;
	}

	/**
	 * Read a value from the OC cache table (caches codes).
	 *
	 * @param string $key Cache key (must match [0-9a-z_\-]+).
	 * @return mixed
	 */
	protected function cache_get($key) {
		if (!preg_match('/^[0-9a-z_\-]+$/', $key)) {
			return null;
		}

		if (!$this->client_db()) {
			return null;
		}

		$query = $this->client_db()->query("SELECT `data` FROM `" . DB_PREFIX . "cache` WHERE `code` = '" . $this->client_db()->escape($key) . "'");

		if ($query->num_rows) {
			$value = json_decode((string) $query->row['data'], true);

			return is_array($value) ? $value : null;
		}

		return null;
	}

	/**
	 * Write a value to the OC cache table.
	 *
	 * @param string $key   Cache key.
	 * @param mixed  $value Value.
	 * @return void
	 */
	protected function cache_set($key, $value) {
		if (!preg_match('/^[0-9a-z_\-]+$/', $key) || !$this->client_db()) {
			return;
		}

		$data = json_encode($value);

		$this->client_db()->query("DELETE FROM `" . DB_PREFIX . "cache` WHERE `code` = '" . $this->client_db()->escape($key) . "'");
		$this->client_db()->query("INSERT INTO `" . DB_PREFIX . "cache` SET `code` = '" . $this->client_db()->escape($key) . "', `data` = '" . $this->client_db()->escape($data) . "', `date_added` = NOW()");
	}

	/**
	 * Expose the client DB adapter to cache helpers.
	 *
	 * @return object|null
	 */
	protected function client_db() {
		return method_exists($this->client, 'db') ? $this->client->db() : null;
	}
}

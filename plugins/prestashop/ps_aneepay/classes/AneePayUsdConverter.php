<?php
/**
 * AneePay fiat -> USD -> token converter for PrestaShop.
 *
 * Stablecoins are pegged to USD, so charging a token amount is equivalent to
 * charging a USD amount. Converts the store total (in the store currency) into
 * the token amount requested from the AneePay API.
 *
 * Rate priority: Frankfurter/ECB (cached) -> store Currency -> manual rate.
 * If none are available an exception is thrown — the amount is never guessed.
 */
class AneePayUsdConverter {
	/** @var AneePayApi */
	protected $client;

	/**
	 * @param AneePayApi $client API client (holds rate_source/exchange_rate).
	 */
	public function __construct($client) {
		$this->client = $client;
	}

	public function is_auto_enabled() {
		return 'manual' !== (string) $this->client->get('rate_source', 'auto');
	}

	protected function get_manual_rate() {
		$rate = (float) $this->client->get('exchange_rate', 0);

		return $rate > 0 ? $rate : null;
	}

	/**
	 * Resolve store-currency units per 1 USD.
	 *
	 * @param string $currency Currency code.
	 * @param string $source   (out) auto|store|manual.
	 * @return float|null
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
	 * Resolve a rate WITHOUT making a network request (used for the admin
	 * preview). Checks the cached automatic rate, then the store rate, then
	 * the manual fallback.
	 *
	 * @param string $currency Currency code.
	 * @param string $source   (out) auto|store|manual.
	 * @return float|null
	 */
	public function peek_fiat_per_usd($currency, &$source) {
		$currency = strtoupper(trim((string) $currency));

		if ('USD' === $currency) {
			$source = 'auto';
			return 1.0;
		}

		if ($this->is_auto_enabled()) {
			$rate = $this->read_cached_rate($currency);

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
	 * Convert a store-total into the token amount.
	 *
	 * @param float|string $store_total Order total in the store currency.
	 * @param string       $currency    Currency code.
	 * @return array{token_amount:float, usd_amount:float, fiat_per_usd:float, currency:string, source:string}
	 * @throws Exception When no rate can be resolved.
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
	 * Fetch and cache the Frankfurter/ECB rate (PrestaShop file cache).
	 *
	 * @param string $currency Currency code.
	 * @return float|null
	 */
	protected function fetch_auto_rate($currency) {
		$cached = $this->read_cached_rate($currency);

		if (null !== $cached) {
			return $cached;
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

		file_put_contents($this->cache_file($currency), json_encode(array('rate' => $rate, 'time' => time())));

		return $rate;
	}

	/**
	 * Read the cached Frankfurter/ECB rate without touching the network.
	 *
	 * @param string $currency Currency code.
	 * @return float|null
	 */
	protected function read_cached_rate($currency) {
		$cache_file = $this->cache_file($currency);

		if (!is_file($cache_file)) {
			return null;
		}

		$cached = json_decode((string) file_get_contents($cache_file), true);

		if (is_array($cached)
			&& isset($cached['rate'], $cached['time'])
			&& (float) $cached['rate'] > 0
			&& (time() - (int) $cached['time']) < 3600
		) {
			return (float) $cached['rate'];
		}

		return null;
	}

	/**
	 * Cache file path for a currency rate.
	 *
	 * @param string $currency Currency code.
	 * @return string
	 */
	protected function cache_file($currency) {
		return _PS_CACHE_DIR_ . 'aneepay_rate_' . strtolower((string) $currency) . '.json';
	}
}

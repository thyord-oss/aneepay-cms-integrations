<?php
/**
 * AneePay API client for OpenCart.
 *
 * Wraps all outbound HTTP requests to the AneePay API (https://api.aneepay.com),
 * records a ring-buffer request log (oc_aneepay_log) and provides the
 * HMAC-SHA256 webhook signature verification.
 */
class AneePay_Client {
	const API_BASE = 'https://api.aneepay.com';

	/**
	 * Settings array, keyed by the raw setting name (account_id, webhook_secret,
	 * site_domain, token, network, test_mode, rate_source, exchange_rate, debug).
	 *
	 * @var array
	 */
	protected $settings;

	/**
	 * Database adapter (used to write the request log).
	 *
	 * @var object
	 */
	protected $db;

	/**
	 * @param array  $settings Extension settings.
	 * @param object $db       OpenCart DB adapter (for the request log).
	 */
	public function __construct($settings, $db) {
		$this->settings = $settings;
		$this->db       = $db;
	}

	/**
	 * Resolve a setting with a fallback default.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public function get($key, $default = '') {
		return isset($this->settings[$key]) ? $this->settings[$key] : $default;
	}

	/**
	 * Set a runtime setting (e.g. the resolved store->USD rate).
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Value.
	 * @return void
	 */
	public function set_config($key, $value) {
		$this->settings[$key] = $value;
	}

	/**
	 * The DB adapter used for the request log / cache.
	 *
	 * @return object|null
	 */
	public function db() {
		return $this->db;
	}

	/**
	 * Merchant account UUID (X-Account-Id header).
	 *
	 * @return string
	 */
	public function get_account_id() {
		return (string) $this->get('account_id', '');
	}

	/**
	 * Whether the gateway runs in sandbox/test mode.
	 *
	 * @return bool
	 */
	public function is_sandbox() {
		return (bool) $this->get('test_mode', false);
	}

	/**
	 * Merchant domain, sent in the Origin header.
	 *
	 * @return string
	 */
	public function get_origin() {
		$configured = (string) $this->get('site_domain', '');

		if ('' !== $configured) {
			return $configured;
		}

		if (isset($this->settings['host'])) {
			return (string) $this->settings['host'];
		}

		return '';
	}

	/**
	 * Network name expected by the API (polygon|amoy). Sandbox forces amoy.
	 *
	 * @return string
	 */
	public function get_network() {
		if ($this->is_sandbox()) {
			return 'amoy';
		}

		$network = strtolower((string) $this->get('network', 'polygon'));

		return in_array($network, array('polygon', 'amoy'), true) ? $network : 'polygon';
	}

	/**
	 * Token symbol expected by the API (usdt|usdc|dai). Sandbox forces usdc.
	 *
	 * @return string
	 */
	public function get_token() {
		if ($this->is_sandbox()) {
			return 'usdc';
		}

		$token = strtolower((string) $this->get('token', 'usdt'));

		return in_array($token, array('usdt', 'usdc', 'dai'), true) ? $token : 'usdt';
	}

	/**
	 * Build a full API URL for a path, appending the sandbox query when enabled.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	public function api_url($path) {
		$url = rtrim(self::API_BASE, '/') . '/' . ltrim($path, '/');

		if ($this->is_sandbox()) {
			$url .= (strpos($url, '?') === false ? '?' : '&') . 'sandbox=yes';
		}

		return $url;
	}

	/**
	 * Create a standard payment.
	 *
	 * @param float  $amount      Token amount.
	 * @param string $description Order description.
	 * @return array{payment_id:string, operation_id:string, status:string, checkout_url:string}
	 * @throws Exception When the account id is missing or the request fails.
	 */
	public function create_payment($amount, $description) {
		$account_id = $this->get_account_id();

		if ('' === $account_id) {
			throw new Exception('AneePay Account ID is not configured.');
		}

		$amount = number_format((float) $amount, 2, '.', '');

		$payload = array(
			'amount'      => $amount,
			'token'       => $this->get_token(),
			'network'     => $this->get_network(),
			'description' => (string) $description,
		);

		$response = $this->request('POST', $this->api_url('payments/'), $payload);

		$json = $this->decode_response($response, 200);

		$payment_id   = isset($json['id']) ? (string) $json['id'] : '';
		$operation_id = isset($json['operation_id']) ? (string) $json['operation_id'] : '';
		$checkout_url = isset($json['checkout_url']) ? (string) $json['checkout_url'] : '';
		$status       = isset($json['status']) ? (string) $json['status'] : '';

		if ('' === $payment_id || '' === $operation_id || '' === $checkout_url) {
			throw new Exception('AneePay did not return a valid checkout URL.');
		}

		return array(
			'payment_id'   => $payment_id,
			'operation_id' => $operation_id,
			'status'       => $status,
			'checkout_url' => $checkout_url,
		);
	}

	/**
	 * Fetch payment details.
	 *
	 * @param string $payment_id Payment UUID.
	 * @return array|null Payment data, or null on failure.
	 */
	public function get_payment($payment_id) {
		$response = $this->request('GET', $this->api_url('payments/' . rawurlencode($payment_id)), null);

		return $this->decode_response($response, 200);
	}

	/**
	 * Validate the merchant connection (Account ID, domain, safe status).
	 *
	 * @return array{ok:bool, code:int, error_code:string, message:string}
	 */
	public function test_connection() {
		$account_id = $this->get_account_id();

		if ('' === $account_id) {
			return array('ok' => false, 'code' => 0, 'error_code' => '', 'message' => 'Account ID is not set.');
		}

		$response = $this->request('GET', $this->api_url('accounts/' . rawurlencode($account_id) . '/payments'), null);

		if (200 === (int) $response['code']) {
			return array('ok' => true, 'code' => 200, 'error_code' => '', 'message' => 'Connection OK. Account exists and the domain matches.');
		}

		$data = $this->json($response['body']);

		return array(
			'ok'         => false,
			'code'       => (int) $response['code'],
			'error_code' => isset($data['code']) ? (string) $data['code'] : '',
			'message'    => $this->extract_error_message((int) $response['code'], (string) $response['body']),
		);
	}

	/**
	 * Verify the HMAC-SHA256 signature of an incoming webhook.
	 *
	 * Signature verification fails closed.
	 *
	 * @param string $body      Raw request body.
	 * @param string $signature X-AneePay-Signature header value.
	 * @return bool
	 */
	public function verify_signature($body, $signature) {
		$secret = (string) $this->get('webhook_secret', '');

		if ('' === $secret || '' === (string) $signature) {
			return false;
		}

		$expected = hash_hmac('sha256', (string) $body, $secret);

		return hash_equals($expected, (string) $signature);
	}

	/**
	 * Decode an AneePay operation_id back to the payment UUID.
	 *
	 * operation_id is the payment UUID encoded as a 128-bit unsigned integer
	 * (int(payment.id)). The mapping is bijective.
	 *
	 * @param string $operation_id Decimal operation id.
	 * @return string|null Payment UUID, or null when not decodable.
	 */
	public function operation_id_to_uuid($operation_id) {
		$decimal = trim((string) $operation_id);

		if ('' === $decimal || !preg_match('/^\d+$/', $decimal)) {
			return null;
		}

		$hex = '';
		$num = ltrim($decimal, '0');

		if ('' === $num) {
			$num = '0';
		}

		while ('0' !== $num) {
			$remainder = 0;
			$quotient  = '';

			for ($i = 0, $len = strlen($num); $i < $len; $i++) {
				$current   = ($remainder * 10) + (int) $num[$i];
				$remainder = $current % 16;
				$quotient .= (int) ($current / 16);
			}

			$hex = dechex($remainder) . $hex;
			$num = ltrim($quotient, '0');

			if ('' === $num) {
				$num = '0';
			}
		}

		$hex = str_pad($hex, 32, '0', STR_PAD_LEFT);

		return sprintf(
			'%s-%s-%s-%s-%s',
			substr($hex, 0, 8),
			substr($hex, 8, 4),
			substr($hex, 12, 4),
			substr($hex, 16, 4),
			substr($hex, 20, 12)
		);
	}

	/**
	 * Perform an HTTP request via cURL and record it in the request log.
	 *
	 * @param string $method GET|POST.
	 * @param string $url    Full URL.
	 * @param array|null $body JSON body for POST.
	 * @return array{code:int, body:string, error:string}
	 */
	protected function request($method, $url, $body) {
		$start = microtime(true);

		$headers   = array('Accept: application/json', 'X-Account-Id: ' . $this->get_account_id());
		$origin    = $this->get_origin();

		if ('' !== $origin) {
			$headers[] = 'Origin: ' . $origin;
		}

		$ch = curl_init();

		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);

		if ('POST' === strtoupper($method)) {
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
			$headers[] = 'Content-Type: application/json';
		}

		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

		$response = curl_exec($ch);
		$code     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$error    = curl_error($ch);

		curl_close($ch);

		$latency_ms = (int) round((microtime(true) - $start) * 1000);

		$this->record_request($method, $url, $code, (string) $response, $latency_ms);

		if ('' !== $error) {
			$this->write_log('request', $url . ' -> ' . $error, 'error');
		}

		return array(
			'code'  => $code,
			'body'  => (string) $response,
			'error' => $error,
		);
	}

	/**
	 * Decode a response into an array, throwing on non-success / invalid JSON.
	 *
	 * @param array $response Response array.
	 * @param int   $ok_code  Accepted success code.
	 * @return array
	 * @throws Exception On error.
	 */
	protected function decode_response($response, $ok_code) {
		if ('' !== (string) $response['error']) {
			throw new Exception('Unable to connect to the AneePay payment service: ' . $response['error']);
		}

		$code = (int) $response['code'];

		if ($code !== $ok_code) {
			throw new Exception($this->extract_error_message($code, (string) $response['body']));
		}

		$data = $this->json((string) $response['body']);

		if (null === $data) {
			throw new Exception('AneePay returned an invalid response.');
		}

		return $data;
	}

	/**
	 * Build a user-facing error message from a failed API response.
	 *
	 * @param int    $code HTTP status code.
	 * @param string $body Response body.
	 * @return string
	 */
	protected function extract_error_message($code, $body) {
		$data = $this->json($body);

		if (is_array($data)) {
			if (isset($data['detail'])) {
				if (is_array($data['detail'])) {
					$messages = array();

					foreach ($data['detail'] as $item) {
						if (is_array($item) && isset($item['msg'])) {
							$messages[] = (string) $item['msg'];
						}
					}

					if (!empty($messages)) {
						return implode('; ', $messages);
					}
				} else {
					return (string) $data['detail'];
				}
			}

			if (isset($data['code'])) {
				$error_code = (string) $data['code'];

				if (!empty($data['message'])) {
					return $error_code . ': ' . (string) $data['message'];
				}

				if (!empty($data['error'])) {
					return $error_code . ': ' . (string) $data['error'];
				}

				return $error_code;
			}

			if (!empty($data['message'])) {
				return (string) $data['message'];
			}
		}

		return 'AneePay returned an unexpected response (HTTP ' . $code . ').';
	}

	/**
	 * JSON-decode a string keeping big integers (operation_id) exact.
	 *
	 * @param string $body Raw body.
	 * @return mixed
	 */
	protected function json($body) {
		return json_decode((string) $body, true, 512, JSON_BIGINT_AS_STRING);
	}

	/**
	 * Append a request record to the oc_aneepay_log table (newest kept).
	 *
	 * @param string $method     HTTP method.
	 * @param string $url        Request URL.
	 * @param int    $code       Response code.
	 * @param string $body       Response body (truncated).
	 * @param int    $latency_ms Latency in ms.
	 * @return void
	 */
	protected function record_request($method, $url, $code, $body, $latency_ms) {
		$this->write_log('request', json_encode(array(
			'method'   => strtoupper($method),
			'endpoint' => $this->shorten_url($url),
			'code'     => (int) $code,
			'latency'  => (int) $latency_ms,
			'body'     => $this->truncate($body, 500),
		)), 'info');
	}

	/**
	 * Write a log entry to the oc_aneepay_log table.
	 *
	 * @param string $context Context label.
	 * @param string $message Message.
	 * @param string $level   Level (info|error).
	 * @return void
	 */
	public function write_log($context, $message, $level = 'info') {
		if (!$this->db) {
			return;
		}

		$this->db->query("INSERT INTO `" . DB_PREFIX . "aneepay_log` SET `context` = '" . $this->db->escape($context) . "', `message` = '" . $this->db->escape((string) $message) . "', `level` = '" . $this->db->escape($level) . "', `date_added` = NOW()");
	}

	/**
	 * Reduce a URL to path + query for compact display.
	 *
	 * @param string $url Full URL.
	 * @return string
	 */
	protected function shorten_url($url) {
		$parts = parse_url($url);

		if (false === $parts) {
			return (string) $url;
		}

		$path = isset($parts['path']) ? $parts['path'] : '';
		$query = isset($parts['query']) ? '?' . $parts['query'] : '';

		return $path . $query;
	}

	/**
	 * Truncate a string to a maximum length.
	 *
	 * @param string $text Text.
	 * @param int    $max  Max length.
	 * @return string
	 */
	protected function truncate($text, $max) {
		if (function_exists('mb_substr')) {
			return mb_substr($text, 0, $max, 'UTF-8');
		}

		return substr($text, 0, $max);
	}
}

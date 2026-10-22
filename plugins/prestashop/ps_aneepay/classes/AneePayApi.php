<?php
/**
 * AneePay API client for PrestaShop.
 *
 * Wraps all outbound HTTP requests to the AneePay API (https://api.aneepay.com),
 * records a request log (ps_aneepay_log) and verifies the HMAC-SHA256 webhook
 * signature.
 */
class AneePayApi {
	const API_BASE = 'https://api.aneepay.com';

	/** @var array Settings (account_id, webhook_secret, site_domain, token, network, test_mode, rate_source, exchange_rate, host). */
	protected $settings;

	/** @var object|null Database adapter for the request log. */
	protected $db;

	/**
	 * @param array  $settings Extension settings.
	 * @param object $db       Legacy DB adapter (Db instance) for the request log.
	 */
	public function __construct($settings, $db = null) {
		$this->settings = $settings;
		$this->db       = $db;
	}

	public function get($key, $default = '') {
		return isset($this->settings[$key]) ? $this->settings[$key] : $default;
	}

	public function set_config($key, $value) {
		$this->settings[$key] = $value;
	}

	public function get_account_id() {
		return (string) $this->get('account_id', '');
	}

	public function is_sandbox() {
		return (bool) $this->get('test_mode', false);
	}

	public function get_origin() {
		$configured = (string) $this->get('site_domain', '');

		if ('' !== $configured) {
			return $configured;
		}

		return (string) $this->get('host', '');
	}

	public function get_network() {
		if ($this->is_sandbox()) {
			return 'amoy';
		}

		$network = strtolower((string) $this->get('network', 'polygon'));

		return in_array($network, array('polygon', 'amoy'), true) ? $network : 'polygon';
	}

	public function get_token() {
		if ($this->is_sandbox()) {
			return 'usdc';
		}

		$token = strtolower((string) $this->get('token', 'usdt'));

		return in_array($token, array('usdt', 'usdc', 'dai'), true) ? $token : 'usdt';
	}

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
	 * @throws Exception
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
		$json     = $this->decode_response($response, 200);

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
	 * Validate the merchant connection.
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

		$data = $this->json((string) $response['body']);

		return array(
			'ok'         => false,
			'code'       => (int) $response['code'],
			'error_code' => isset($data['code']) ? (string) $data['code'] : '',
			'message'    => $this->extract_error_message((int) $response['code'], (string) $response['body']),
		);
	}

	/**
	 * Read the most recent request-log entries (newest first).
	 *
	 * @param int $limit Maximum number of rows.
	 * @return array<int, array{time:string, method:string, endpoint:string, code:int, latency:int, body:string}>
	 */
	public function get_request_log($limit = 50) {
		if (!$this->db || !method_exists($this->db, 'executeS')) {
			return array();
		}

		$limit = max(1, (int) $limit);

		$rows = $this->db->executeS(
			'SELECT `message`, `date_added` FROM `' . _DB_PREFIX_ . 'aneepay_log`
			 WHERE `context` = \'request\' ORDER BY `log_id` DESC LIMIT ' . $limit
		);

		$logs = array();

		foreach ((array) $rows as $row) {
			$data = $this->json((string) $row['message']);

			if (!is_array($data)) {
				continue;
			}

			$logs[] = array(
				'time'     => (string) $row['date_added'],
				'method'   => isset($data['method']) ? (string) $data['method'] : '',
				'endpoint' => isset($data['endpoint']) ? (string) $data['endpoint'] : '',
				'code'     => isset($data['code']) ? (int) $data['code'] : 0,
				'latency'  => isset($data['latency']) ? (int) $data['latency'] : 0,
				'body'     => isset($data['body']) ? (string) $data['body'] : '',
			);
		}

		return $logs;
	}

	/**
	 * Delete the request-log entries.
	 *
	 * @return bool
	 */
	public function clear_request_log() {
		if (!$this->db || !method_exists($this->db, 'delete')) {
			return false;
		}

		return (bool) $this->db->delete('aneepay_log', 'context = \'request\'');
	}

	/**
	 * Verify the HMAC-SHA256 signature of an incoming webhook (fail-closed).
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
	 * Decode operation_id (128-bit int) back to the payment UUID.
	 *
	 * @param string $operation_id Decimal operation id.
	 * @return string|null Payment UUID, or null.
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
	 * Perform an HTTP request (cURL) and record it in the request log.
	 *
	 * @param string     $method GET|POST.
	 * @param string     $url    Full URL.
	 * @param array|null $body   JSON body for POST.
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

		$this->record_request($method, $url, $code, (string) $response, (int) round((microtime(true) - $start) * 1000));

		if ('' !== $error) {
			$this->write_log('request', $url . ' -> ' . $error, 'error');
		}

		return array('code' => $code, 'body' => (string) $response, 'error' => $error);
	}

	/** @return array @throws Exception */
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

	/** @return mixed */
	protected function json($body) {
		return json_decode((string) $body, true, 512, JSON_BIGINT_AS_STRING);
	}

	protected function record_request($method, $url, $code, $body, $latency) {
		$this->write_log('request', json_encode(array(
			'method'   => strtoupper($method),
			'endpoint' => $this->shorten_url($url),
			'code'     => (int) $code,
			'latency'  => (int) $latency,
			'body'     => $this->truncate($body, 500),
		)), 'info');
	}

	public function write_log($context, $message, $level = 'info') {
		if (!$this->db || !method_exists($this->db, 'insert')) {
			return;
		}

		$this->db->insert('aneepay_log', array(
			'context'    => pSQL($context),
			'message'    => pSQL((string) $message),
			'level'      => pSQL($level),
			'date_added' => date('Y-m-d H:i:s'),
		));
	}

	protected function shorten_url($url) {
		$parts = parse_url($url);

		if (false === $parts) {
			return (string) $url;
		}

		return (isset($parts['path']) ? $parts['path'] : '') . (isset($parts['query']) ? '?' . $parts['query'] : '');
	}

	protected function truncate($text, $max) {
		return function_exists('mb_substr') ? mb_substr((string) $text, 0, $max, 'UTF-8') : substr((string) $text, 0, $max);
	}
}

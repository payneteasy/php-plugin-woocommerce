<?php

namespace Payneteasy\Classes\Api;

(defined('ABSPATH') || PHP_SAPI == 'cli') or die('Restricted access');

use \Payneteasy\Classes\Exception\PayneteasyException;

class PaynetApi {
	private const URL = 'paynet/api/v2/';
	private string $login;
	private string $gate;
	private string $control_key;
	private string $endpoint;
	private bool $is_direct;

	public function __construct(string $gate, string $login, string $control_key, string $endpoint, bool $is_direct) {
		$this->gate = $gate;
		$this->login = $login;
		$this->control_key = $control_key;
		$this->endpoint = $endpoint;
		$this->is_direct = $is_direct;
	}

	private function signed(array $data, string $str=null, bool $add_login=false): array {
		if (isset($str) || $add_login)
			$data['login'] = $this->login;

		$data['control'] = sha1($str ?? $this->endpoint.$data['client_orderid'].($data['amount'] * 100).$data['email'].$this->control_key);
		return $data;
	}

	public function is_direct(): bool
		{ return $this->is_direct; }

	public function sale(array $data): array
		{ return $this->execute($this->is_direct ? 'sale' : 'sale-form', $this->signed($data)); }

	public function return(array $data): array
		{ return $this->execute('return', $this->signed($data, null, true)); }

	public function status(array $data): array
		{ return $this->execute('status', $this->signed($data, $this->login.$data['client_orderid'].$data['orderid'].$this->control_key)); }

	private function execute(string $action, array $data): array {
		$curl = curl_init($this->gate . self::URL . "$action/{$this->endpoint}");

		curl_setopt_array($curl, [
			CURLOPT_HEADER					=> 0,
			CURLOPT_USERAGENT				=> 'Payneteasy-Client/1.0',
			CURLOPT_SSL_VERIFYHOST	=> 0,
			CURLOPT_SSL_VERIFYPEER	=> 0,
			CURLOPT_POST						=> 1,
			CURLOPT_RETURNTRANSFER	=> 1,
			CURLOPT_POSTFIELDS			=> http_build_query($data) ]);

		$response = curl_exec($curl);

		if (curl_errno($curl))
			list($error_code, $error_message) = [ curl_errno($curl), 'Error occurred: ' . curl_error($curl) ];
		elseif (curl_getinfo($curl, CURLINFO_HTTP_CODE) != 200)
			list($error_code, $error_message) = [ curl_getinfo($curl, CURLINFO_HTTP_CODE), "Error occurred. HTTP code: '{$error_code}'" ];

		curl_close($curl);

		if (!empty($error_message))
			throw new PayneteasyException($error_message, [ 'response' => $error_code ]);

		if (empty($response))
			throw new PayneteasyException('Card processing response is empty', [ 'response' => $response ]);

		parse_str($response, $result);
		foreach ($result as $k => $v)
			$result[$k] = rtrim($v);

		if ($result['type'] == 'validation-error')
			throw new PayneteasyException("Card processing reports error: {$result['error-message']}", [ 'response' => $response ]);

		return $result;
	}
}

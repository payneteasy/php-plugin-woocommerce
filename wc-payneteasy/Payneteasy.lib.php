<?php
/**
 *  @author    Payneteasy
 *  @copyright 2007-2026 Payneteasy
 *  @license   Property of Payneteasy
 */

declare(strict_types=1);

namespace Payneteasy {
	function trace($arg, string $prefix='', array &$json=null): void {
		if (is_object($arg))
			$arg = get_object_vars($arg);

		if (!count($arg))
			return;

		ksort($arg);
		foreach ($arg as $key => $value) {
			if ($key == 'cvv2')
				$arg[$key] = str_repeat('*', strlen($value));
			elseif ($key == 'credit_card_number')
				$arg[$key] = (strlen($value) > 4) ? str_repeat('*', strlen($value)-4).substr($value, -4) : $value;

			error_log("$prefix'$key' => '{$arg[$key]}'");
		}

		if (isset($json))
			$json[] = $arg;
	}

	class PneException extends \Exception {
		public function __construct(string $message, $a1=null, $a2=null, $hdr=null, int $code=0) {
			parent::__construct($message, $code, null);

			error_log("{$this->message} in {$this->file}:{$this->line}");

			$json = [$this->message];

			if (isset($a1)) {
				if (isset($hdr))
					trace($hdr, ' -- ', $json);

				trace($a1, isset($a2) ? ' --> ' : ' -- ', $json);

				if (isset($a2)) {
					trace($a2, ' <-- ', $json);
				}
			}

			PneApi::call_logger($json);
		}
	}

	class PneConfigException extends \Exception {
		public function __construct(string $message, bool $verbose) {
			parent::__construct($message, 0, null);

			if ($verbose)
				error_log("{$this->message} in {$this->file}:{$this->line}");
		}
	}

	class PneConfig {
		private const HIDDEN = true;

		private bool $changed = false;
		private $on_save, $on_input_key, $on_uninstall, $cfg = [
			# [ value, regexp, shown name for exceptions, is_hidden  ]
			'IS_LIVE'             => [ '0' ], # must be 1st for proper regex checks later
			'SANDBOX_URL'         => [ '', '|^https?://(?:\\w+(?:-\\w+)*\\.)+\\w+/\\w+$|', 'Sandbox URL' ],
			'SANDBOX_END_POINT'   => [ '', '/^\d+$/', 'Sandbox Endpoint ID' ],
			'SANDBOX_LOGIN'       => [ '', '/^[a-z][\\w-]*\\w$/i', 'Sandbox Login' ],
			'SANDBOX_CONTROL_KEY' => [ '', '/^[\da-f]{8}(?:-[\da-f]{4}){3}-[\da-f]{12}$/i', 'Sandbox Control key' ],
			'LIVE_URL'            => [ '', '|^https?://(?:\\w+(?:-\\w+)*\\.)+\\w+/\\w+$|', 'Live URL' ],
			'LIVE_END_POINT'      => [ '', '/^\d+$/', 'Live Endpoint ID' ],
			'LIVE_LOGIN'          => [ '', '/^[a-z][\\w-]*\\w$/i', 'Live Login' ],
			'LIVE_CONTROL_KEY'    => [ '', '/^[\da-f]{8}(?:-[\da-f]{4}){3}-[\da-f]{12}$/i', 'Live Control key' ],
			'IS_MULTICURR'        => [ '0' ],
			'IS_FORM'             => [ '0' ],
			'IS_PREAUTH'          => [ '0' ],
			'IS_CAPTURE_MANUAL'   => [ '0' ],
			'IS_SSN_REQUIRED'     => [ '0' ],
			'IS_DEBUG_TRACE'      => [ '0' ],
			'IS_DEBUG_FAKE'       => [ '0' ] ];

		private function __construct() {}

		# config storage mostly handled by environment, so need only key fetches for mapping
		public static function fetchkey_only(callable $on_fetch_key): PneConfig {
			$new = new self();

			foreach (array_keys($new->cfg) as $k)
				$new->cfg[$k][0] = (string)$on_fetch_key($k);

			return $new;
		}

		# config storage weakly handled by environment
		public static function handler(callable $on_load, callable $on_save, callable $on_input_key, callable $on_uninstall, array $extra=[], array $hidden=[]): PneConfig {
			$new = new self();

			[ $new->on_save, $new->on_input_key, $new->on_uninstall ]  = [ $on_save, $on_input_key, $on_uninstall ];

			foreach ($extra as $k)
				$new->cfg[ $new->allowed_key($k, true) ] = [ '' ];

			foreach ($hidden as $k)
				$new->cfg[ $new->allowed_key($k, true) ] = [ '', null, null, self::HIDDEN ];

			$loaded = @unserialize($on_load() ?: '');
			if (is_array($loaded))
				foreach ($loaded as $k => $v)
					$new->cfg[$k][0] = $v;

			return $new;
		}

		public function __get(string $k)
			{ return $this->cfg[ $this->allowed_key($k) ][0] ?? ''; }

		public function __set(string $k, string $v) {
			if (null != ($re = ($this->cfg[ $this->allowed_key($k) ][1] ?? null)))
				if ($this->is_regex_check_failed($k, $v, $re))
					throw new PneConfigException($this->cfg[$k][2].' has invalid format', false);

			if ($this->cfg[$k][0] != (string)$v)
				[ $this->cfg[$k][0], $this->changed ] = [ (string)$v, true ];
		}

		# checks for existence or dups
		private function allowed_key(string $k, bool $check_dup=false): string {
			if ($check_dup == isset($this->cfg[$k]))
				throw new PneConfigException(($check_dup ? 'duplicate' : 'inallowed')." config key '$k'", true);

			return $k;
		}

		private function is_regex_check_failed(string $k, string $v, string $re): bool
			{ return strstr($k, '_', true) == ($this->IS_LIVE ? 'LIVE' : 'SANDBOX') && !preg_match($re, $v); }

		public function save(): bool {
			if (!$this->changed)
				return false;

			foreach ($this->cfg as $k => $v)
				$saving[$k] = $v[0];

			($this->on_save)(serialize($saving));

			$this->changed = false;

			return true;
		}

		public function uninstall(): void
			{ ($this->on_uninstall)(); }

		public function form_keys(): array
			{ return array_keys($this->cfg); }

		public function form_values(): array
			{ return array_reduce(array_map(fn($k) => [ $k => $this->cfg[$k][0] ], array_keys($this->cfg)), 'array_merge', []); }

		public function save_input(&$has_changes): array {
			$errors = [];

			foreach ($this->cfg as $k => $v)
				if (!($v[3] ?? false)) # skip HIDDENs
					try
						{ $this->$k = (string)($this->on_input_key)($k); }
					catch (PneConfigException $E)
						{ $errors[] = $E->getMessage(); }

			$has_changes = $this->save();

			return $errors;
		}

		public function value_error($k, $v): string {
			if (isset($this->cfg[$k]) && null != ($re = ($this->cfg[$k][1] ?? null)))
				if ($this->is_regex_check_failed($k, $v, $re))
					return $this->cfg[$k][2].' has invalid format';

			return '';
		}
	}

	class PneLogger {
		# cuz no enums
		public const AS_ERROR = true;
		public const AS_INFO = false;

		private $on_log_error, $on_log_info;

		private function __construct() {}

		public static function as_plaintext(callable $on_log_error, callable $on_log_info=null): PneLogger {
			$new = new self();

			[ $new->on_log_error, $new->on_log_info ] = [ $on_log_error, $on_log_info ?? $on_log_error ];

			return $new;
		}

		public function send_log_entry($arg, bool $as_error): void
			{ ($as_error ? $this->on_log_error : $this->on_log_info)($arg); }
	}

	class PneApi {
		# these are debug mode flags in admin section
		public const DEBUG_TRACE_REQUESTS = 0b01;
		public const DEBUG_FAKE_REQUESTS = 0b10;

		private const USERAGENT = 'Payneteasy-Client/2.0';
		private const DEBUG_MODE = false; # this is used to show admin controls (or do SetEnv DEBUG_HOST something) in devel environment

		private string $gate, $login, $control_key, $end_point;
		private bool $is_form, $is_multicurr, $is_preauth;
		private int $debug_flags;
		private static ?PneLogger $Logger;

		public function __construct(PneConfig $Cfg, PneLogger $Logger=null) {
			[ $this->gate, $this->login, $this->control_key, $this->end_point ]
				= array_map(fn($k) => $Cfg->{($Cfg->IS_LIVE ? 'LIVE_' : 'SANDBOX_').$k}, ['URL','LOGIN','CONTROL_KEY','END_POINT']);

			[ $this->is_form, $this->is_multicurr, $this->is_preauth, $this->debug_flags ]
				= [ (bool)$Cfg->IS_FORM, (bool)$Cfg->IS_MULTICURR, (bool)$Cfg->IS_PREAUTH,
					self::is_debug_mode() ? ((int)$Cfg->IS_DEBUG_TRACE + (int)$Cfg->IS_DEBUG_FAKE * self::DEBUG_FAKE_REQUESTS) : 0 ];

			self::$Logger = $Logger;
		}

		public static function is_debug_mode(): bool
			{ return self::DEBUG_MODE || ($_SERVER['DEBUG_HOST'] ?? false); }

		public static function debug_host(): ?string
			{ return $_SERVER['DEBUG_HOST'] ?? null; }

		public static function call_logger($arg, bool $as_error=true): void {
			if (isset(self::$Logger) && !empty($arg))
				self::$Logger->send_log_entry(is_array($arg) ? json_encode($arg, JSON_INVALID_UTF8_SUBSTITUTE) : $arg, $as_error);
		}

		public static function got_upgrade(string $repo, string $curr_ver, string $stored_ver_date, callable $on_upd): bool {
			if ($stored_ver_date == "$curr_ver ".date('Y-m-d')) # check is daily
				return false;

			[ $stored_ver ] = explode(' ', $stored_ver_date ?: '0');

			if ($stored_ver && $stored_ver != $curr_ver)
				return true;

			$Curl = curl_init($url = sprintf('https://api.github.com/repos/%s/releases/latest', $repo));
			curl_setopt_array($Curl, [ CURLOPT_USERAGENT => self::USERAGENT, CURLOPT_RETURNTRANSFER => 1, CURLOPT_CONNECTTIMEOUT => 10 ]);

			$response = curl_exec($Curl);

			if ($err = curl_error($Curl))
				$errmsg = "Version request error, CURL message: $err";
			elseif (($err = curl_getinfo($Curl, CURLINFO_HTTP_CODE)) != 200)
				$errmsg = "Version request error, HTTP code: '{$err}'";

			if (!empty($errmsg))
				throw new PneException($errmsg);
			elseif (empty($response))
				throw new PneException('Version response is empty');

			curl_close($Curl);

			if (!preg_match('/\bv?(?:\d+\.){1,2}\d+$/', ($tag = array_reverse(preg_split('/ +/', (json_decode($response, true)['name'])))[0]), $match))
				throw new PneException("Version tag is malformed: '$tag'");

			$on_upd($match[0].' '.date('Y-m-d'));

			return version_compare($match[0], $curr_ver, '>');
		}

		public function is_auth_valid(): bool
			{ return 'approved' == ($this->status([ 'client_orderid' => 1, 'orderid' => 1 ]))['status']; }

		public function is_form(): bool
			{ return (bool)$this->is_form; }

		public function log_error($arg): void
			{ self::call_logger($arg, PneLogger::AS_ERROR); }

		public function log_info($arg): void
			{ self::call_logger($arg, PneLogger::AS_INFO); }

		public function verify_callback_signature(array $params): bool
			{ return hash_equals(sha1(($params['status'] ?? '').($params['orderid'] ?? '').($params['client_orderid'] ?? '').$this->control_key), $params['control'] ?? ''); }

		public function sale(array $data, string $browser_info=''): array {
			if (defined('PNE_SEND_BROWSER_INFO')) {
				$data[($prefix = 'customer_browser_').'info'] = 'true';

				foreach ([ 'ACCEPT' => 'accept_header', 'USER_AGENT' => 'user_agent', 'ACCEPT_LANGUAGE' => 'accept_language' ] as $in => $out)
					$data[$prefix.$out] = substr($_SERVER["HTTP_$in"], 0, $in == 'ACCEPT_LANGUAGE' ? 8 : 2048);

				$data += array_combine(array_map(fn($k) => $prefix.$k, explode(' ', 'javascript_enabled java_enabled color_depth screen_height screen_width time_zone')),
					json_decode($browser_info));
			}

			$action = ($this->is_preauth ? 'preauth' : 'sale').($this->is_form ? '-form' : '');

			return $this->execute($action,
				$this->signed($data, $this->end_point.$data['client_orderid'].($data['amount'] * 100).$data['email'].$this->control_key, false));
		}

		public function void(array $data): array
			{ return $this->execute('void', $this->signed($data)); }

		public function return(array $data): array
			{ return $this->execute('return', $this->signed($data, $this->login.$data['client_orderid'].$data['orderid'].($data['amount'] * 100).$data['currency'].$this->control_key)); }

		public function capture(array $data): array {
			return $this->execute('capture', $this->signed($data,
				isset($data['amount']) ? $this->login.$data['client_orderid'].$data['orderid'].($data['amount'] * 100).$data['currency'].$this->control_key : null));
		}

		public function make_rebill(array $data): array {
			return $this->execute('make-rebill-'.($this->is_preauth ? 'preauth' : 'sale'),
				$this->signed($data += [ 'recurrent_scenario' => 'REGULAR', 'recurrent_initiator' => 'MERCHANT' ],
					$this->login.$data['client_orderid'].$data['cardrefid'].($data['amount'] * 100).$data['currency'].$this->control_key));
		}

		public function get_card_info(array $data): array
			{ return $this->execute('get-card-info', $this->signed($data, $this->login.$data['cardrefid'].$this->control_key)); }

		public function status(array $data): array
			{ return $this->execute('status', $this->signed($data)); }

		public function create_card_ref(array $data): array
			{ return $this->execute('create-card-ref', $this->signed($data)); }

		private function signed(array $data, string $str=null, bool $add_login=true): array {
			if ($add_login)
				$data['login'] = $this->login;

			return array_merge($data, ['control' => sha1($str ?? $this->login.$data['client_orderid'].$data['orderid'].$this->control_key)]);
		}

		private function fake_callback(string $url, array $params): void {
			$params['control'] = sha1($params['status'].$params['orderid'].$params['client_orderid'].$this->control_key);

			$Curl = curl_init("$url&".http_build_query($params));
			curl_setopt_array($Curl, [ CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 5 ]);
			curl_exec($Curl);
			curl_close($Curl);
		}

		private function fake_response(string $action, array $data): array {
			if ((string)($data['cvv2'] ?? '') != '321') {
				# 0/1/2/3
				$forced_type = [null,'reversal','chargeback','void'][substr((string)($data['orderid'] ?? ''), -4, 1)] ?? null;

				# test-orderid suffix picks the fake response, like a gateway's test card numbers:
				# 995/996/997 => 3DS processing (redirect / html / plugin's own ticker fallback)
				$suffix = substr((string)($data['orderid'] ?? ''), -3);
				$fake_status = [
						'989' => 'error',
						'990' => 'filtered',
						'991' => 'unknown',
						'992' => 'error',
						'993' => 'declined',
						'994' => 'chain_declined',
						'995' => 'processing',
						'996' => 'processing',
						'997' => 'processing' ][$suffix]
					?? 'approved';

				if ($suffix === '992')
					throw new PneException('Fake gateway error (test orderid suffix 992)', $data, [], [], 12);

				$fake = [
					'return|void' => [ 'status' => $fake_status ],
					'sale-form|preauth-form' => [ 'type' => 'async-form-response' ],
					'make-rebill-sale|make-rebill-preauth|preauth|capture|sale' => [ 'type' => 'async-response' ],

					'create-card-ref' => [ 'type' => 'create-card-ref-response', 'card-ref-id' => 'fake-card-ref-id' ],

					'get-card-info'    => [
						'bin'               => '444455',
						'type'              => 'get-card-info-response',
						'expire-year'       => (string)(date('Y') + 2),
						'expire-month'      => '12',
						'last-four-digits'  => '1111',
						'card-printed-name' => 'FAKE TEST CARD' ],

					'status' => array_filter([
						'status'           => $fake_status,
						'html'             => $suffix == '996' ? '<div>fake 3ds html</div>' : null,
						'card-type'        => $fake_status == 'approved' ? 'VISA' : null,
						'redirect-to'      => $suffix == '997' ? 'https://example.test/fake-3ds-redirect' : null,
						'approval-code'    => $fake_status == 'approved' ? 'FAKE01' : null,
						'processor-rrn'    => $fake_status == 'approved' ? 'fake-rrn-123456' : null,
						'last-four-digits' => $fake_status == 'approved' ? '1111' : null,
						'transaction-type' => $forced_type ?? ($this->is_preauth ? 'preauth' : 'sale').($this->is_form ? '-form' : '') ]) ];
			}
			else
				$fake = [
					'sale' => [ 'type' => 'async-response' ],
					'status' => [
						'status'           => 'approved',
						'card-type'        => 'VISA',
						'approval-code'    => 'FAKE01',
						'processor-rrn'    => 'fake-rrn-123456',
						'transaction-type' => 'sale',
						'last-four-digits' => '1111' ] ];

			$response = array_merge(self::array_pick($fake, $action), [
				'merchant-order-id' => $data['client_orderid'],
				'serial-number'     => '00000000-0000-0000-0000-000000000000',
				'paynet-order-id'   => $paynet_id = (int)substr_replace((string)time(), '0', -4, 1) ]);

			if (($response['type'] ?? '') == 'async-response' && !empty($data['server_callback_url']))
				register_shutdown_function(fn() => $this->fake_callback($data['server_callback_url'], [
					'orderid'        => $paynet_id,
					'status'         => $fake_status ?? 'approved',
					'client_orderid' => $data['client_orderid'],
					'type'           => [ 'preauth' => 'preauth', 'capture' => 'capture', 'make-rebill-preauth' => 'preauth' ][$action] ?? 'sale' ]));

			return $response;
		}

		private function execute(string $action, array $data, string $api='/api/v2'): array {
			$url = $this->gate."$api/$action".($this->is_multicurr ? '/group/' : '/').$this->end_point;

			if ($this->debug_flags & self::DEBUG_TRACE_REQUESTS) {
				trace([ 'REQUEST'.($this->debug_flags & self::DEBUG_FAKE_REQUESTS ? '-FAKE' : '') => $url ], ' -- ');
				trace($data, ' -> ');
			}

			if ($this->debug_flags & self::DEBUG_FAKE_REQUESTS)
				return $this->fake_response($action, $data);

			$Curl = curl_init($url);
			curl_setopt_array($Curl, [
				CURLOPT_HEADER					=> 0,
				CURLOPT_POST						=> 1,
				CURLOPT_RETURNTRANSFER	=> 1,
				CURLOPT_USERAGENT				=> self::USERAGENT,
				CURLOPT_SSL_VERIFYHOST	=> self::is_debug_mode() ? 0 : 2,
				CURLOPT_SSL_VERIFYPEER	=> self::is_debug_mode() ? 0 : 1,
				CURLOPT_POSTFIELDS			=> http_build_query($data) ]);

			if (self::is_debug_mode())
				curl_setopt($Curl, CURLOPT_CONNECTTIMEOUT, 10);

			$response = curl_exec($Curl);

			if ($err = curl_error($Curl))
				$errmsg = "Card processing error, CURL error: '$err'";
			elseif (($err = curl_getinfo($Curl, CURLINFO_HTTP_CODE)) != 200)
				$errmsg = "Card processing error, HTTP code: '$err'";

			curl_close($Curl);

			if (!empty($errmsg))
				throw new PneException($errmsg, $data, [], [ 'REQUEST' => $url ]);
			elseif (empty($response))
				throw new PneException('Card processing response is empty', $data);

			parse_str($response, $result);
			array_walk($result, fn(&$v) => $v = rtrim($v));

			if ($this->debug_flags & self::DEBUG_TRACE_REQUESTS) {
				trace([ 'RESULT' => $action ], ' -- ');
				trace($result, ' <- ');
			}

			$success_types = [
				'status' => 'status-response',
				'preauth-form|sale-form' => 'async-form-response',
				'make-rebill-sale|make-rebill-preauth|return|void|sale|preauth|capture' => 'async-response',

				'create-card-ref'     => 'create-card-ref-response',
				'get-card-info'       => 'get-card-info-response' ];

			if (in_array(($type = $result['type'] ?? ''), [ 'validation-error', 'error' ]) || ($result['status'] ?? '') == 'error')
				throw new PneException('Card processing returned error: "'.($result['error_message'] ?? $result['error-message'] ?? '').'"',
					$data, $result, [ 'URL' => $url ],
					(int)($result['error_code'] ?? $result['error-code'] ?? 0));

			if ($type !== ($action_type = self::array_pick($success_types, $action)))
				throw new PneException("Card processing returned unexpected response type: '$type', expected '$action_type'", $data, $result, [ 'URL' => $url ]);

			return $result;
		}

		private static function array_pick(array $arr, string $key) {
			if (array_key_exists($key, $arr))
				return $arr[$key];

			foreach ($arr as $k => $v)
				if (false !== strpos($k, '|') && in_array($key, explode('|', $k)))
					return $v;
		}
	}
}

<?php
/**
	* Plugin Name: Payneteasy payment system
	* Plugin URI: https://github.com/payneteasy/php-plugin-woocommerce
	* Description: Allows you to use payment system Payneteasy with the WooCommerce.
	* Version: 1.6.2
	* Author: Payneteasy
	* Author URI: https://payneteasy.com/
	* Text Domain: wc-payneteasy
	* Requires at least: 6.2
	* Requires PHP: 7.4
	* Requires Plugins: woocommerce/woocommerce
	*
	* @package Payneteasy_WooCommerce
	*/

declare(strict_types=1);

if (!defined('ABSPATH')) exit;

define('PNE_PLUGIN_CLASS', 'WC_Payneteasy');
define('PNE_PLUGIN_VERSION', get_file_data(__FILE__, ['version' => 'Version'])['version']);
define('PNE_PLUGIN_URL', plugin_dir_url(__FILE__));
define('PNE_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('PNE_PLUGIN_SLUG', plugin_basename(__DIR__));
define('PNE_PLUGIN_ID', 'wc_payneteasy');

include_once 'Payneteasy.lib.php';
use Payneteasy\PneApi;
use Payneteasy\PneConfig;
use Payneteasy\PneException;
use Payneteasy\PneLogger;
use Payneteasy\PneTransportException;

register_uninstall_hook(__FILE__, 'hook_uninstall_wc_paynet_payment_gateway');

add_action('plugins_loaded', 'hook_init_wc_paynet_payment_gateway');
add_action('woocommerce_blocks_loaded', 'hook_register_wc_payneteasy_blocks_support');
add_filter('woocommerce_payment_gateways', fn(array $methods) => array_merge($methods, [PNE_PLUGIN_CLASS]));
add_action('before_woocommerce_init', function(): void {
	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__);
	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__); });

function hook_init_wc_paynet_payment_gateway(): void {
	if (!class_exists('WC_Payment_Gateway') || class_exists(PNE_PLUGIN_CLASS))
		return;

	add_filter('plugin_action_links_'.PNE_PLUGIN_BASENAME, [PNE_PLUGIN_CLASS, 'hook_plugin_action_links']);
	add_filter('wc_order_statuses', ['WC_Payneteasy_OrderStatusExpander', 'hook_order_statuses']);
	add_filter('woocommerce_register_shop_order_post_statuses', ['WC_Payneteasy_OrderStatusExpander', 'hook_register_custom_statuses']);
	add_filter('woocommerce_order_is_paid_statuses', ['WC_Payneteasy_OrderStatusExpander', 'hook_paid_statuses']);
	add_filter('woocommerce_valid_order_statuses_for_payment_complete', ['WC_Payneteasy_OrderStatusExpander', 'hook_payment_complete_from'], 10, 2);
	add_filter('woocommerce_payment_complete_order_status', ['WC_Payneteasy_OrderStatusExpander', 'hook_payment_complete_to'], 10, 3);
	add_action('woocommerce_order_status_paid', 'wc_downloadable_product_permissions');

	foreach (explode(' ', 'pending on-hold failed authorized') as $from)
		add_action("woocommerce_order_status_{$from}_to_paid", ['WC_Payneteasy_OrderStatusExpander', 'hook_email_paid'], 10, 2);

	trait PneString { private static function _T(string $str, ...$args): string { $t = __($str, PNE_PLUGIN_SLUG); return $args ? sprintf($t, ...$args) : $t; } }

	if (is_admin()) {
		require_once 'WC_Payneteasy/SelfUpdater.php';
		require_once 'WC_Payneteasy/LogViewer.php';
	}

	class WC_Payneteasy extends WC_Payment_Gateway { use PneString;
		private $Api, $Cfg, $OrderStatus;
		private string $notify_url;

		private const DO_RECHECK_STATUS = 'do_recheck_status';

		function __construct() {
			$this->id = PNE_PLUGIN_ID;
			$this->icon = apply_filters('woocommerce_payneteasy_icon', PNE_PLUGIN_URL.'assets/img/payneteasy.png');
			$this->method_title = self::_T('Payneteasy online card payment system');
			$this->method_description = self::_T('Allows you to use online card payment system by Payneteasy with the WooCommerce.');
			$this->has_fields = false;
			$this->supports = [
				'products', # avoid potential incompatibility
				'refunds'];

			$this->Api = new PneApi($this->Cfg = $this->init_config(),
				PneLogger::as_plaintext(
					fn($text) => wc_get_logger()->error($text, [ 'source' => PNE_PLUGIN_ID ]),
					fn($text) => wc_get_logger()->info($text, [ 'source' => PNE_PLUGIN_ID ])));

			$this->OrderStatus = new WC_Payneteasy_OrderStatusHandler();
			$this->form_fields = WC_Payneteasy_SettingsForm::init($this->Cfg);

			add_action("woocommerce_update_options_payment_gateways_{$this->id}", [$this, 'process_admin_options']);
			add_action('admin_enqueue_scripts', [$this, 'hook_enqueue_admin_scripts']);
			add_action('wp_enqueue_scripts', [$this, 'hook_enqueue_checkout_assets']);
			add_action('admin_notices', [$this, 'hook_admin_notice_info']);
			add_action("woocommerce_api_{$this->id}_return", [$this, 'hook_return_handler']);
			add_action("woocommerce_api_{$this->id}_webhook", [$this, 'hook_webhook_handler']);
			add_action("woocommerce_api_{$this->id}_ajax", [$this, 'hook_ajax_handler']);
			add_action('woocommerce_order_status_changed', [$this, 'hook_auto_capture'], 10, 4);
			add_action('woocommerce_email_order_details', [$this, 'hook_email_payment_details'], 10, 4);
		}

		public static function hook_plugin_action_links(array $links): array
			{ return array_merge([ 'settings' => '<a href="'.admin_url('admin.php?page=wc-settings&tab=checkout&section=wc_payneteasy').'">Settings</a>' ], $links); }

		public static function test_card(string $how='as array'): array {
			$values = [ '4444 5555 6666 1111', date('Y')+2, 12, 'Test Name' ];

			return $how == 'as array' ? $values : array_combine(explode(' ', 'ccn year month name'), $values);
		}

		public function hook_enqueue_admin_scripts($hook): void {
			if ('woocommerce_page_wc-settings' == $hook) {
				wp_enqueue_script('payneteasy_admin_settings', PNE_PLUGIN_URL.'assets/js/admin_settings.js', ['jquery'], PNE_PLUGIN_VERSION, true);
				wp_localize_script('payneteasy_admin_settings', 'pneAdminSettings', [ 'field_prefix' => 'woocommerce_wc_payneteasy_' ]);
				wp_enqueue_style('payneteasy_admin_settings', PNE_PLUGIN_URL.'assets/css/admin_settings.css', [], PNE_PLUGIN_VERSION);
			}

			if (($screen = get_current_screen())
					&& $screen->id == \Automattic\WooCommerce\Utilities\OrderUtil::get_order_admin_screen()
					&& ($O = wc_get_order($_GET['id'] ?? $_GET['post'] ?? ''))) {
				wp_enqueue_script('payneteasy-order-page-script', plugins_url('/assets/js/admin_order.js', __FILE__), ['jquery'], PNE_PLUGIN_VERSION, true);

				$is_order_payneteasy = $O->get_payment_method() == $this->id && !in_array($O->get_status(), ['refunded','chargeback','cancelled']);

				wp_localize_script('payneteasy-order-page-script', 'payneteasy_ajax_var', array_merge([
					'nonce' => wp_create_nonce('payneteasy-ajax-nonce'),
					'api_url' => home_url('/wc-api/wc_payneteasy_ajax'),
					'order_id' => $O->get_id(),
					'is_order_payneteasy' => $is_order_payneteasy ],
					$is_order_payneteasy ? [
						'show_capture' => $O->get_status() == 'authorized' && $this->Cfg->IS_CAPTURE_MANUAL,
						'capture_amount' => $O->get_total(),
						'paynet_order_id' => $this->paynet_id($O) ] : []));
			}

			if ($screen && in_array($screen->id, [\Automattic\WooCommerce\Utilities\OrderUtil::get_order_admin_screen(), 'edit-shop_order'], true)) {
				wp_enqueue_style('payneteasy-admin-order', PNE_PLUGIN_URL.'assets/css/admin_order.css', [], PNE_PLUGIN_VERSION);
				wp_add_inline_script('jquery-core', 'jQuery($ => $(".wp-list-table .check-column").addClass("no-link"))');
			}
		}

		public function hook_enqueue_checkout_assets(): void {
			if (is_checkout()) {
				wp_enqueue_style('payneteasy-checkout', PNE_PLUGIN_URL.'assets/css/checkout.css', [], PNE_PLUGIN_VERSION);
				wp_enqueue_script('payneteasy-checkout', PNE_PLUGIN_URL.'assets/js/checkout.js', ['jquery'], PNE_PLUGIN_VERSION, true);
				wp_localize_script('payneteasy-checkout', 'pneCheckoutSettings', [
					'box' => "#wc-{$this->id}-cc-form",
					'row' => '.form-row',
					'invalid' => 'woocommerce-invalid',
					'submit' => [ [ 'form.checkout', "checkout_place_order_{$this->id}" ], [ '#order_review', 'click', '#place_order' ] ] ]);
			}
		}

		public function hook_admin_notice_info(): void {
			if ($notice = get_option('pne_admin_notice_info')) {
				delete_option('pne_admin_notice_info');

				[ $type, $message ] = $notice;
				echo "<div class='notice notice-$type'><p>".esc_html($message).'</p></div>';
			}
		}

		public function hook_ajax_handler(): void {
			try {
				if (empty($action = $_POST['action'])
						|| !wp_verify_nonce($_POST['nonce'] ?? '', 'payneteasy-ajax-nonce')
						|| !current_user_can('manage_woocommerce'))
					throw new \Exception('Invalid call.');

				$O = $this->order($_POST['order_id']);

				switch ($action) {
					case 'check_status':
						if (($before = $O->get_status()) != ($after = $this->OrderStatus->set($O, $this->check_PaymentStatus($O)))) {
							$O->add_order_note(self::_T('Manual "Check status" changed order status from %s to %s.', $before, $after));
							self::admin_notice('Order status updated.');
						}
						else
							throw new \Exception('Order status not changed.', -1);

						break;

					case 'capture':
						if ($this->process_capture($O, isset($_POST['amount']) ? (float)$_POST['amount'] : null))
							self::admin_notice('Capture completed.');
						else
							throw new \Exception('Capture failed.');

						break;
				}

				wp_send_json([ 'success' => true ]); # page reloaded
			}
			catch (\Exception $e) {
				if ($e instanceof PneException)
					$this->order_exception_note($O, $e);

				$clause = -1 == $e->getCode() ? 'info_text' : 'error_text';
				wp_send_json([ 'success' => false, $clause => self::_T($e->getMessage()) ]); # page not reloaded, show text
			}
		}

		public function hook_return_handler(): void {
			try {
				if (!hash_equals(($O = $this->order($_GET['orderId']))->get_order_key(), (string)($_GET['key'] ?? '')))
					throw new \Exception(self::_T('Invalid order key.'));

				$this->OrderStatus->set($O, $Pr = $this->check_PaymentStatus($O));

				if ($Pr->is_processing() && $Pr->redirect_to)
					wp_redirect($Pr->redirect_to);
				elseif ($Pr->is_processing())
					$this->print_page($Pr->html ?: $this->ticker_html($O));
				else
					wp_redirect($O->get_checkout_order_received_url());

				exit;
			}
			catch (\Exception $e) {
				if ($e instanceof PneException)
					$this->order_exception_note($O, $e);

				wp_die( $e->getMessage() );
			}
		}

		public function hook_webhook_handler(): void {
			$in = array_map('wp_unslash', $_GET);
			[ $order_id, $paynet_id, $type, $status ] = [ $in['client_orderid'], $in['orderid'], $in['type'], $in['status'] ];

			try {
				if (!$this->Api->verify_callback_signature($in))
					throw new \Exception(self::_T('Invalid callback signature.'));

				if (!$this->OrderStatus->is($O = $this->order($order_id), "$type/$status")) {
					if (!$this->paynet_id($O)) {
						$O->set_transaction_id($paynet_id);
						$O->save();
					}

					$this->OrderStatus->set($O, $this->check_PaymentStatus($O, $paynet_id), false);
				}

				PneApi::log_info("$type/$status orderid=$paynet_id client_orderid=$order_id");

				exit('ok');
			}
			catch (\Exception $e) {
				if ($e instanceof PneTransportException)
					status_header(500);
				elseif ($e instanceof PneException)
					$this->order_exception_note($O, $e);

				PneApi::log_error("$type/$status orderid=$paynet_id client_orderid=$order_id &rarr; ".$e->getMessage());

				die($e->getMessage());
			}
		}

		public function hook_auto_capture($order_id, $status_from, $status_to, $order): void {
			if ($order->get_payment_method() != $this->id || !$this->Cfg->IS_PREAUTH || $this->Cfg->IS_CAPTURE_MANUAL
					|| $status_from != 'authorized' || !in_array($status_to, [ is_admin() ? 'paid' : '', 'processing', 'completed' ]))
				return;

			$this->process_capture($order);
		}

		public function hook_email_payment_details($order, $sent_to_admin, $plain_text, $email): void {
			if ($plain_text
					|| !($order instanceof \WC_Order)
					|| $this->id != $order->get_payment_method()
					|| !($details = $order->get_meta('_payneteasy_payment_details')))
				return;

			$country_code = strstr($cc = get_option('woocommerce_default_country'), ':', true) ?: $cc;

			$rows = array_filter([
				self::_T('Card type') => $details['card_type'] ?? null,
				self::_T('Merchant Name') => get_bloginfo('name'),
				self::_T('Merchant Country') => WC()->countries->countries[$country_code] ?? '',
				self::_T('Authorization code') => $details['approval_code'] ?? null,
				self::_T('Customer service contact') => get_option('woocommerce_email_from_address'),
				self::_T('Retrieval Reference Number') => $details['rrn'] ?? null,
				self::_T('Card number, the final 4 digits') => $details['card_last4'] ?? null ]);

			$links = array_filter([
				self::_T('Refund and returns policy') => 'publish' == get_post_status($page_id = wc_get_page_id('refund_returns')) ? get_permalink($page_id) : null,
				self::_T('Merchant online address') => home_url() ]);

			echo '<h3>'.self::_T('Payment details').'</h3><ul>'
				.implode('', array_map(fn($k, $v) => '<li><b>'.esc_html($k).'</b>: '.esc_html($v).'</li>', array_keys($rows), $rows))
				.implode('', array_map(fn($k, $v) => '<li><b>'.esc_html($k).'</b>: <a href="'.esc_url($v).'">'.esc_html($v).'</a></li>', array_keys($links), $links))
				.'</ul>';
		}

		public function payment_fields(): void
			{ WC_Payneteasy_CheckoutForm::show($this->id, $this->description, $this->Cfg); }

		public function validate_fields(): void {
			foreach (WC_Payneteasy_CheckoutForm::LABELS as $f => $label)
				if (empty($_POST[$f]) && ('ssn' == $f ? $this->Cfg->IS_SSN_REQUIRED : !$this->Cfg->IS_FORM))
					wc_add_notice(self::_T('%s is a required field.', '<strong>'.esc_html(self::_T($label)).'</strong>'), 'error');
		}

		public function validate_text_field($k, $v) {
			if ($error = $this->Cfg->value_error($k, $v)) {
				WC_Admin_Settings::add_error($error);
				return $this->settings[$k];
			}

			return wc_clean(wp_unslash($v ?? ''));
		}

		public function process_payment($order_id): array {
			try {
				if (($O = $this->order($order_id))->get_payment_method() != $this->id)
					throw new \Exception(self::_T('Payment method is not "Payneteasy Payment System".'));

				$sale = $this->make_sale($O);

				if (isset($sale['redirect-url']))
					$O->add_order_note(self::_T('Payment link generated: %s', $sale['redirect-url']));

				return [ 'result' => 'success', 'redirect' => $sale['redirect-url'] ?? $this->return_url($O) ];
			}
			catch (\Exception $e) {
				if ($e instanceof PneException)
					$this->order_exception_note($O, $e);

				if (isset($O))
					$O->update_status('failed', $e->getMessage());

				wc_add_notice($e->getMessage(), 'error');
				return [ 'result' => 'failure' ];
			}
		}

		public function process_refund($order_id, $amount = null, $reason = '') {
			try {
				$O = $this->order($order_id);

				$this->Api->return([
					'amount' => $amount ?? $O->get_total(),
					'comment' => $reason ?: 'Order cancel',
					'orderid' => $this->paynet_id($O),
					'currency' => $O->get_currency(),
					'client_orderid' => $order_id ]);

				return true;
			}
			catch (\Exception $e) {
				if ($e instanceof PneException && 'refunded' == $this->order_exception_note($O, $e, self::DO_RECHECK_STATUS))
					return true;

				return new \WP_Error('payneteasy_refund', $e->getMessage());
			}
		}

		private function process_capture(object $O, ?float $amount = null): bool {
			try {
				$data = [ 'client_orderid' => $O->get_id(), 'orderid' => $this->paynet_id($O) ];

				if (isset($amount))
					$data += [ 'amount' => $amount, 'currency' => $O->get_currency() ];

				$this->Api->capture($data);

				$O->update_status('paid', self::_T('Payment captured successfully.'));
				$O->payment_complete();

				return true;
			}
			catch (\Exception $e) {
				if ($e instanceof PneException)
					return 'paid' == $this->order_exception_note($O, $e, self::DO_RECHECK_STATUS);

				return false;
			}
		}

		private function order($order_id=null): object {
			if (empty($order_id))
				throw new \Exception(self::_T('Order ID is empty.'));

			if (!preg_match('/^\d+$/', $order_id = (string)$order_id))
				throw new \Exception(self::_T('Order ID is invalid: %s', esc_html($order_id)));

			if ($O = wc_get_order($order_id))
				return $O;
			else
				throw new \Exception(self::_T('Order #%d not found.', $order_id));
		}

		private function init_config(): PneConfig {
			foreach (explode(' ', 'notify_url title description enabled') as $k)
				$this->$k = $this->get_option($k);

			return PneConfig::fetchkey_only(fn($k) => (strpos($k, 'IS_') === 0) ? 'yes' == $this->get_option($k) : ($this->get_option($k) ?? ''));
		}

		private function return_url(object $O): string
			{ return home_url("?wc-api={$this->id}_return&orderId={$O->get_id()}&key=".$O->get_order_key()); }

		private function ticker_html(object $O): string {
			ob_start();

			?>
			<div style="width: 100%; text-align: center"><div><h1>Your payment is being processed.</h1></div>
			<div><a href="<?= $this->return_url($O) ?>" id="ticker">Check status</a></div></div>
			<script>let t_el = document.getElementById("ticker");let t_s=t_el.innerHTML,t_pos=0,t_iv=setInterval(() => {
				if (++t_pos <= t_s.length) { if (t_s[t_pos] == " ") t_pos++; t_el.innerHTML = "<span style='color:#09C'>"+t_s.slice(0, t_pos)+"</span>"+t_s.slice(t_pos) }
				else { clearInterval(t_iv); t_el.click() } }, 300)</script>
			<?php

			return ob_get_clean();
		}

		# themes without classic header.php/footer.php (all block/FSE themes) fall back to a deprecated ~2005 core template if wrapped, so only wrap when the theme actually provides both
		private function print_page(string $content): void {
			if ((file_exists(get_stylesheet_directory().'/header.php') || file_exists(get_template_directory().'/header.php'))
					&& (file_exists(get_stylesheet_directory().'/footer.php') || file_exists(get_template_directory().'/footer.php'))) {
				get_header();
				print($content);
				get_footer();
			}
			else
				print('<!DOCTYPE html><html><head><meta charset="'.esc_attr(get_bloginfo('charset')).'"><title>'.esc_html(get_bloginfo('name')).'</title></head><body>'.$content.'</body></html>');
		}

		private function make_sale(object $O): array {
			[ $order_id, $email, $total, $return_url ] = [ $O->get_id(), $O->get_billing_email(), $O->get_total(), $this->return_url($O) ];

			$response = $this->Api->sale([
				'client_orderid' => $order_id,
				'order_desc' => self::order_description($O),
				'amount' => $total,
				'currency' => $O->get_currency(),
				'address1' => $O->get_billing_address_1(),
				'city' => $O->get_billing_city(),
				'zip_code' => $O->get_billing_postcode(),
				'country' => $O->get_billing_country(),
				'state' => $O->get_billing_state(),
				'phone' => $O->get_billing_phone(),
				'email' => $email,
				'ipaddress' => PneApi::is_debug_mode() ? '127.0.0.1' : $_SERVER['REMOTE_ADDR'],
				'cvv2' => $_POST['cvv2'] ?? '',
				'ssn' => $_POST['ssn'] ?? '',
				'credit_card_number' => preg_replace('/\D+/', '', $_POST['credit_card_number'] ?? ''),
				'card_printed_name' => $_POST['card_printed_name'] ?? '',
				'expire_month' => $_POST['expire_month'] ?? '',
				'expire_year' => $_POST['expire_year'] ?? '',
				'first_name' => $O->get_shipping_first_name() ?: $O->get_billing_first_name(),
				'last_name'  => $O->get_shipping_last_name() ?: $O->get_billing_last_name(),
				'redirect_url' => $return_url,
				'notify_url' => $this->Cfg->IS_SSN_REQUIRED ? $this->notify_url : '',
				'server_callback_url' => home_url('?wc-api=wc_payneteasy_webhook') ]);

			$O->set_transaction_id($response['paynet-order-id']);
			$O->save();

			return $response;
		}

		private function check_PaymentStatus(object $O, $paynet_id = null): Payneteasy_PaymentStatus
			{ return new Payneteasy_PaymentStatus($this->Api->status([ 'client_orderid' => $O->get_id(), 'orderid' => $paynet_id ?: $this->paynet_id($O) ])); }

		private function paynet_id(object $O): string
			{ return (string)$O->get_transaction_id(); }

		private function order_exception_note(object $O, PneException $e, string $extra_action=''): string {
			$O->add_order_note(self::_T('Payneteasy gateway error #%d: %s', $e->getCode(), $e->getMessage()));

			if (self::DO_RECHECK_STATUS == $extra_action) {
				try {
					if ($O->get_status() != ($after = $this->OrderStatus->set($O, $this->check_PaymentStatus($O))))
						return $after;
				}
				catch (\Exception $e) {}
			}

			return '';
		}

		private static function order_description(object $O): string {
			$currency = $O->get_currency();

			return implode(";\n", array_map(fn($item) =>
				$item instanceof \WC_Order_Item_Product
					? 'item #'.($item->get_variation_id() ?: $item->get_product_id())." \"{$item->get_name()}\" x {$item->get_quantity()}: {$item->get_total()} $currency"
					: ($item instanceof \WC_Order_Item_Coupon
						? "\"{$item->get_code()}\" {$item->get_type()}, discount: {$item->get_discount()} $currency"
						: "\"{$item->get_name()}\" {$item->get_type()}: {$item->get_total()} $currency"),
				$O->get_items(['line_item', 'fee', 'shipping', 'coupon'])));
		}

		private static function admin_notice(string $message, ...$args): void
			{ update_option('pne_admin_notice_info', [ 'success', self::_T($message, ...$args) ]); }
	}

	class WC_Payneteasy_SettingsForm { use PneString;
		public static function init(PneConfig $Cfg): array {
			$endpointid_label = $Cfg->IS_MULTICURR ? 'Endpoint Group ID' : 'Endpoint ID';

			$form_fields = [
				[ 'enabled?', 'Enable/Disable', null, 'Enable Payment system Payneteasy' ],
				[ 'title', 'Title', 'This controls the title which the user sees during checkout' ],
				[ 'description', 'Customer Message', 'The message which you want it to appear to the customer in the checkout page' ],
				[ 'IS_LIVE?', 'Live mode', [ 'Sandbox mode is on', 'Live mode is on' ], 'Enable live mode' ],
				[ 'IS_MULTICURR?', 'Multiple currencies', [ 'Single currency mode', 'Multicurrency mode' ], 'Support multiple currencies' ],
				[ 'SANDBOX_URL*', 'Gateway url (SANDBOX)', 'Sandbox\'s API URL, e.g. https://sandbox.payneteasy.com/paynet', null, 'Enter sandbox url' ],
				[ 'SANDBOX_END_POINT*',
					[ '<span class="pne-is-multi pne-endpointid-label">%s</span>', $endpointid_label ],
					[ '<span class="pne-endpointid-label">%s</span>', "Sandbox's $endpointid_label is required to call the API" ], null, 'Enter sandbox Endpoint ID or Endpoint Group ID' ],
				[ 'SANDBOX_LOGIN*', 'Login', 'Sandbox\'s Login is required to call the API', null, 'Enter sandbox Login' ],
				[ 'SANDBOX_CONTROL_KEY*', 'Control key', 'Sandbox\'s Control key is required to call the API', null, 'Enter sandbox Control key' ],
				[ 'LIVE_URL*', 'Gateway url (LIVE)', 'Merchant\'s API URL, e.g. https://gate.payneteasy.com/paynet', null, 'Enter live url' ],
				[ 'LIVE_END_POINT*',
					[ '<span class="pne-is-multi pne-endpointid-label">%s</span>', $endpointid_label ],
					[ '<span class="pne-endpointid-label">%s</span>', "Merchant's $endpointid_label is required to call the API" ], null, 'Enter live Endpoint ID or Endpoint Group ID' ],
				[ 'LIVE_LOGIN*', 'Login', 'Merchant\'s Login is required to call the API', null, 'Enter live Login' ],
				[ 'LIVE_CONTROL_KEY*', 'Control key', 'Merchant\'s Control key is required to call the API', null, 'Enter live Control key' ],
				[ 'IS_FORM?', 'Integration method', [ 'Direct mode', 'Form mode' ], 'Enable form mode' ],
				[ 'IS_PREAUTH?', 'Authorize only', [ 'Sale mode', 'Preauth + capture mode' ], 'Enable two-stage payments (preauth + capture)', null, 'IS_CAPTURE_MANUAL' ],
				[ 'IS_CAPTURE_MANUAL?', 'Manual capture', [ 'Automatic capture', 'Manual capture' ], 'Enable manual capture' ],
				[ 'IS_SSN_REQUIRED?', 'Require CPF', [ 'CPF is not required', 'Show CPF input field at checkout page' ], 'Require Document Number (CPF)', null, 'notify_url' ],
				[ 'notify_url', 'Notify url', 'Notify gate url', null, 'Enter notify gate url' ],
				[ 'maintenance' ],
				[ 'delete_data_on_uninstall?', 'Remove data on uninstall', 'Requires define(\'WC_PAYNETEASY_UNINSTALL_DATA\', true) in wp-config.php', 'Delete plugin settings and logs' ] ];

			if (PneApi::is_debug_mode())
				array_push($form_fields,
					[ 'debug' ],
					[ 'IS_DEBUG_TRACE?', 'Trace requests', 'Write requests and responses trace to server errorlog' ],
					[ 'IS_DEBUG_FAKE?', 'Fake requests', 'Countermands responses trace', 'Imitate server requests and responses' ]);

			return array_reduce(array_map(fn(array $def) => self::form_field($Cfg, $def), $form_fields), 'array_merge', []);
		}

		private static function form_field(PneConfig $Cfg, array $def): array {
			[ $key, $title, $descr, $label, $placeholder, $dependant ] = array_pad($def, 6, null);

			$field = [];

			if ('*' == substr($key, -1))
				[ $key, $field['custom_attributes'] ] = [ chop($key, '*'), [ 'required' => 'required' ] ];
			elseif (isset($dependant))
				$field['custom_attributes'] = [ 'data-toggle-dependant' => $dependant ];

			if (count($def) == 1)
				[ $type, $title, $field['class'] ] = [ 'title', ucfirst($key), 'pne-section-title' ];
			elseif ('?' == substr($key, -1)) {
				[ $type, $key, $field['default'] ] = [ 'checkbox', chop($key, '?'), $key == 'enabled' ? 'yes' : 'no' ];
				if (is_array($descr)) {
					[ $off, $on ] = $Cfg->$key ? [ ' hidden', '' ] : [ '', ' hidden' ];
					$descr = [ "<span{$off} id=\"pne-{$key}-desc-off\">%s</span><span{$on} id=\"pne-{$key}-desc-on\">%s</span>", ...$descr ];
				}
				elseif (!isset($label))
					[ $label, $descr ] = [ $descr, null ];
			}
			elseif ('description' == $key)
				[ $type, $field['default'] ] = [ 'textarea', self::_T('Pay with Payneteasy payment') ];
			else
				$type = 'text';

			return [ $key => array_filter(array_merge($field,
				[ 'type' => $type, 'title' => self::_U($title), 'label' => self::_U($label), 'description' => self::_U($descr), 'placeholder' => self::_U($placeholder) ])) ];
		}

		private static function _U($arg): ?string {
			if (is_array($arg)) {
				$mask = array_shift($arg);
				return sprintf($mask, ...array_map(fn($s) => self::_T($s), $arg));
			}

			return isset($arg) ? self::_T($arg) : null;
		}
	}

	class WC_Payneteasy_CheckoutForm { use PneString;
		public const LABELS = [
			'credit_card_number' => 'Card Number',
			'cvv2'               => 'CVC',
			'expire_month'       => 'Expiry month',
			'expire_year'        => 'Expiry year',
			'card_printed_name'  => 'Printed name',
			'ssn'                => 'Document Number (CPF)' ];

		public static function show(string $id, string $description, PneConfig $Cfg): void {
			if (!empty($description))
				echo wpautop(wptexturize($description));

			echo '<fieldset id="wc-'.esc_attr($id).'-cc-form" class="wc-credit-card-form wc-payment-form" style="background:transparent">';
			do_action('woocommerce_credit_card_form_start', $id);

			[ $cc, $year, $mon, $name ] = $Cfg->IS_LIVE
				? [ '', '', '', '' ]
				: WC_Payneteasy::test_card();

			if ($Cfg->IS_FORM) {
				if ($Cfg->IS_SSN_REQUIRED)
					echo self::payment_form_field('ssn', [ 'autocomplete' => 'off' ]);
			}
			else {
				$months = array_combine($mm = array_map(fn($n) => sprintf('%02d', $n), range(1, 12)), $mm);
				$years = array_combine($yy = array_map('strval', range(date('Y'), date('Y')+15)), $yy);

				echo self::payment_form_row(
						self::payment_form_field('credit_card_number', [ 'autocomplete' => 'cc-number', 'custom_attributes' => [ 'inputmode' => 'numeric' ] ], $cc),
						self::payment_form_field('cvv2', [ 'autocomplete' => 'off', 'minlength' => 3, 'maxlength' => 4 ], '', 'password'))
					.self::payment_form_row(
						self::payment_form_field('expire_month', [ 'options' => ['' => 'MM'] + $months, 'input_class' => ['input-text'] ], "$mon"),
						self::payment_form_field('expire_year', [ 'options' => ['' => 'YYYY'] + $years, 'input_class' => ['input-text'] ], "$year"))
					.self::payment_form_field('card_printed_name', [ 'autocomplete' => 'cc-name', 'placeholder' => 'Printed name' ], $name);

				if ($Cfg->IS_SSN_REQUIRED)
					echo self::payment_form_field('ssn', [ 'autocomplete' => 'off' ]);
			}

			do_action('woocommerce_credit_card_form_end', $id);
			echo '<div class="clear"></div></fieldset>';
		}

		private static function payment_form_row(string ...$fields): string
			{ return '<div>'.implode('', $fields).'</div>'; }

		private static function payment_form_field(string $key, array $extra, string $value='', string $type='text'): string {
			return woocommerce_form_field($key, array_merge([
				'type' => isset($extra['options']) ? 'select' : $type,
				'label' => self::_T(self::LABELS[$key]),
				'class' => ['form-row-wide'],
				'return' => true,
				'required' => true ], $extra), $value);
		}
	}

	class WC_Payneteasy_OrderStatusExpander {
		private const CUSTOM_STATUSES = [ 'wc-chargeback' => 'Chargeback', 'wc-authorized' => 'Authorized', 'wc-void' => 'Voided', 'wc-paid' => 'Paid' ];

		public static function hook_order_statuses(array $statuses): array
			{ return array_merge($statuses, array_map(fn($label) => _x($label, 'Order status', 'woocommerce'), self::CUSTOM_STATUSES)); }

		public static function hook_register_custom_statuses(array $statuses): array {
			return array_merge($statuses, array_map(fn($label) => [
				'public' => false,
				'exclude_from_search' => false,
				'show_in_admin_all_list' => true,
				'show_in_admin_status_list' => true,
				'label' => _x($label, 'Order status', PNE_PLUGIN_SLUG),
				'label_count' => _n_noop("$label <span class=\"count\">(%s)</span>", "$label <span class=\"count\">(%s)</span>", PNE_PLUGIN_SLUG) ],
			self::CUSTOM_STATUSES));
		}

		public static function hook_paid_statuses(array $statuses): array
			{ return array_merge($statuses, ['paid']); }

		public static function hook_payment_complete_from(array $statuses, $order = null): array
			{ return $order && PNE_PLUGIN_ID == $order->get_payment_method() ? array_merge($statuses, ['paid']) : $statuses; }

		public static function hook_payment_complete_to(string $status, $order_id = null, $order = null): string
			{ return $order && PNE_PLUGIN_ID == $order->get_payment_method() ? 'paid' : $status; }

		public static function hook_email_paid($order_id, $order): void
			{ WC()->mailer()->get_emails()['WC_Email_Customer_Processing_Order']->trigger($order_id, $order); }
	}

	class WC_Payneteasy_OrderStatusHandler { use PneString;
		private array $_status_map;

		function __construct() {
			$this->_status_map = [
				'sale/approved' => 'paid',
				'sale/processing' => 'on-hold',
				'sale/unknown,chain_declined' => '!on-hold',

				'preauth/approved' => 'authorized',
				'preauth/processing' => 'on-hold',
				'preauth/unknown,chain_declined' => '!on-hold',

				'cancel/approved' => 'cancelled',
				'void/approved'   => 'void',

				'chargeback/approved' => 'chargeback',
				'chargeback/*'        => '!chargeback_rejected',

				'reversal/approved'   => 'refunded',
				'reversal/*'          => '!refund_rejected',

				'capture/approved'    => 'paid',
				'capture/*'           => '!capture_rejected' ];
		}

		private function status_map(string $payment_status): string {
			if (is_null($entry = ($map = $this->_status_map)[$lookup = $payment_status] ?? $map[($t = strstr($lookup, '/', true)).'/*'] ?? null)
					&& ($pick = array_key_first(array_filter($map,
						fn($k) => "$t/" == substr($k, 0, 1+strlen($t)) && in_array(substr($lookup, 1+strlen($t)), explode(',', substr($k, 1+strlen($t)))), ARRAY_FILTER_USE_KEY))))
				$entry = $map[$pick];

			return $entry ?? '';
		}

		public function is(object $O, string $payment_status): bool {
			return ($entry = $this->status_map($payment_status))
				? $O->get_status() == $entry
				: $O->get_status() == 'failed';
		}

		public function set(object $O, Payneteasy_PaymentStatus $Pr, bool $make_notes=true): string {
			$action = $this->status_map("$Pr") ?: 'unpaid';

			switch ($action) {
				case '!refund_rejected':
					foreach ($O->get_refunds() as $Refund)
						if (!($amount ??= (float)$Pr->total_reversal_amount) || round((float)$Refund->get_amount(), 2) == round($amount, 2)) {
							$O->add_order_note(self::_T('Gateway declined the reversal, local refund of %s removed.', $Refund->get_amount()));
							$Refund->delete(true);

							if ($O->get_status() == 'refunded') # full refunds only
								$O->update_status('paid', self::_T('Order status restored, the gateway declined the reversal.'));

							break;
						}

				case '!chargeback_rejected':
				case '!capture_rejected':
					return $O->get_status();

				case '!on-hold':
					if ($make_notes)
						$O->add_order_note(self::_T('Returned status: %s', "$Pr"));

					$action = 'on-hold';

					break;

				case 'refunded':
					$delta = round((float)($Pr->total_reversal_amount ?? $O->get_total()) - $O->get_total_refunded(), 2);

					if ($delta > 0)
						wc_create_refund([ 'order_id' => $O->get_id(), 'amount' => $delta, 'reason' => self::_T('Reversal reported by gateway.') ]);

					if ($O->get_total_refunded() < $O->get_total())
						$action = 'partially_refunded';
			}

			$map = [
				'on-hold'    => 'Payment is being processed.',
				'cancelled'  => 'Payment cancelled.',
				'void'       => 'Void of payment.',
				'refunded'   => 'Refund of payment.',
				'chargeback' => 'Chargeback of payment.',
				'unpaid'     => [ 'Payment not paid.', 'failed' ],
				'authorized' => [ 'Payment authorized, awaiting capture.', null, true ],
				'paid'       => [ 'Payment completed successfully.', null, true ],
				'partially_refunded' => [ 'Partial refund of payment.', 'paid', null, true ] ];

			[ $text, $status, $extra_actions, $at_least ] = array_pad(is_array($map[$action]) ? $map[$action] : [$map[$action]], 4, null);

			if ($O->get_status() != ($set = $status ?? $action)) {
				$fn_upd = fn() => $O->update_status($set, self::_T($text));

				isset($extra_actions)
					? $this->{'extra_actions_for_'.$action}($O, $fn_upd, [ 'details' => $Pr->payment_details() ])
					: $fn_upd();
			}
			elseif ($at_least ?? false)
				$O->add_order_note(self::_T($text));

			return $set;
		}

		private function extra_actions_for_authorized(object $O, callable $fn_upd, array $extra): void {
			$O->update_meta_data('_payneteasy_payment_details', $extra['details']);
			$fn_upd();
		}

		private function extra_actions_for_paid(object $O, callable $fn_upd, array $extra): void {
			$this->extra_actions_for_authorized($O, $fn_upd, $extra);
			$O->payment_complete();
		}
	}

	class Payneteasy_PaymentStatus {
		private array $response;

		function __construct(array $response)
			{ $this->response = $response; }

		public function __toString(): string
			{ return $this->response['transaction-type'].'/'.$this->response['status']; }

		public function __get(string $key): ?string
			{ return $this->response[str_replace('_', '-', $key)] ?? null; }

		public function is_processing(): bool
			{ return 'processing' == ($this->response['status'] ?? ''); }

		public function payment_details(): array {
			return array_filter([
				'rrn' => $this->response['processor-rrn'] ?? null,
				'card_type' => $this->response['card-type'] ?? null,
				'card_last4' => $this->response['last-four-digits'] ?? null,
				'approval_code' => $this->response['approval-code'] ?? null ]);
		}
	}
}

function hook_uninstall_wc_paynet_payment_gateway(): void {
	if ('yes' == (get_option('woocommerce_wc_payneteasy_settings')['delete_data_on_uninstall'] ?? '') && true === Payneteasy\def_val('WC_PAYNETEASY_UNINSTALL_DATA')) {
		[ $prefix, $suffix ] = [ PNE_PLUGIN_ID.'-', '-'.wp_hash(PNE_PLUGIN_ID).'.log' ];

		foreach ((scandir(WC_LOG_DIR) ?: []) as $f)
			if (substr($f, 0, strlen($prefix)) === $prefix && substr($f, -strlen($suffix)) === $suffix)
				@unlink(WC_LOG_DIR.$f);

		delete_option('woocommerce_wc_payneteasy_settings');
	}
}

function hook_register_wc_payneteasy_blocks_support(): void {
	if (!class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType') || class_exists('WC_Payneteasy_Blocks_Support'))
		return;

	final class WC_Payneteasy_Blocks_Support extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {
		protected $name = PNE_PLUGIN_ID;

		public function initialize(): void
			{ $this->settings = get_option('woocommerce_wc_payneteasy_settings', []); }

		public function is_active(): bool
			{ return filter_var($this->get_setting('enabled', false), FILTER_VALIDATE_BOOLEAN); }

		public function get_payment_method_script_handles(): array {
			wp_register_script('payneteasy-checkout', PNE_PLUGIN_URL.'assets/js/checkout.js', ['jquery'], PNE_PLUGIN_VERSION, true);
			wp_register_script('wc-payneteasy-blocks', PNE_PLUGIN_URL.'assets/js/blocks.js', ['wc-blocks-registry', 'wc-settings', 'wp-element', 'payneteasy-checkout'], PNE_PLUGIN_VERSION, true);
			return ['wc-payneteasy-blocks'];
		}

		public function get_payment_method_data(): array {
			return [
				'icon' => PNE_PLUGIN_URL.'assets/img/payneteasy.png',
				'title' => $this->get_setting('title'),
				'IS_FORM' => $this->get_setting('IS_FORM') == 'yes',
				'supports' => $this->get_supported_features(),
				'description' => $this->get_setting('description'),
				'IS_SSN_REQUIRED' => $this->get_setting('IS_SSN_REQUIRED') == 'yes',
				'testCard' => $this->get_setting('IS_LIVE') == 'yes' ? null : WC_Payneteasy::test_card('as hash') ];
		}
	}

	add_action('woocommerce_blocks_payment_method_type_registration', fn($registry) => $registry->register(new WC_Payneteasy_Blocks_Support()));
}

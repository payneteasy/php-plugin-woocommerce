<?php
/**
	* Plugin Name: Payneteasy payment system
	* Plugin URI: https://github.com/payneteasy/php-plugin-woocommerce
	* Description: Allows you to use payment system Payneteasy with the WooCommerce.
	* Version: 1.6.0
	* Author: Payneteasy
	* Author URI: https://payneteasy.com/
	* Text Domain: wc-payneteasy
	* Requires PHP: 7.4
	* Requires Plugins: woocommerce/woocommerce
	*
	* @package Payneteasy_WooCommerce
	*/

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

register_activation_hook(__FILE__, 'hook_activate_wc_paynet_payment_gateway');
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

	trait PneString { private static function _T(string $str, ...$args): string { $t = __($str, PNE_PLUGIN_SLUG); return $args ? sprintf($t, ...$args) : $t; } }

	add_filter('plugin_action_links_'.PNE_PLUGIN_BASENAME, [PNE_PLUGIN_CLASS, 'hook_plugin_action_links']);
	add_filter('wc_order_statuses', ['WC_Payneteasy_OrderStatusExpander', 'hook_order_statuses']);
	add_filter('woocommerce_register_shop_order_post_statuses', ['WC_Payneteasy_OrderStatusExpander', 'hook_register_custom_statuses']);

	if (is_admin()) {
		require_once __DIR__.'/WC_Payneteasy/SelfUpdater.php';
		add_filter('plugins_api', ['WC_Payneteasy\SelfUpdater', 'hook_plugin_update_info'], 20, 3);
		add_filter('pre_set_site_transient_update_plugins', ['WC_Payneteasy\SelfUpdater', 'hook_plugin_check_version']);

		if (('wc-status' == ($_GET['page'] ?? '')) || (wp_doing_ajax() && in_array($_REQUEST['action'] ?? '', ['pne_log_viewer_fetch', 'pne_log_viewer_counts'], true))) {
			require_once __DIR__.'/WC_Payneteasy/LogViewer.php';
			add_filter('woocommerce_admin_status_tabs', ['WC_Payneteasy\LogViewer', 'hook_status_tabs']);
			add_action('woocommerce_admin_status_content_pne-log', ['WC_Payneteasy\LogViewer', 'show']);
			add_action('wp_ajax_pne_log_viewer_fetch', ['WC_Payneteasy\LogViewer', 'ajax_fetch']);
			add_action('wp_ajax_pne_log_viewer_counts', ['WC_Payneteasy\LogViewer', 'ajax_counts']);
		}
	}

	class WC_Payneteasy extends WC_Payment_Gateway { use PneString;
		private $Api, $Cfg, $OrderStatus;
		private string $notify_url;

		function __construct() {
			$this->id = PNE_PLUGIN_ID;
			$this->icon = apply_filters('woocommerce_payneteasy_icon', PNE_PLUGIN_URL.'payneteasy.png');
			$this->method_title = self::_T('Payneteasy online card payment system');
			$this->method_description = self::_T('Allows you to use online card payment system by Payneteasy with the WooCommerce.');
			$this->has_fields = false;
			$this->supports = [
				'products', # avoid potential incompatibility
				'refunds',
				'subscriptions'];

			$this->Api = new PneApi($this->Cfg = $this->init_config(),
				PneLogger::as_plaintext(
					fn($text) => wc_get_logger()->error($text, [ 'source' => PNE_PLUGIN_ID ]),
					fn($text) => wc_get_logger()->info($text, [ 'source' => PNE_PLUGIN_ID ])));

			$this->OrderStatus = new WC_Payneteasy_OrderStatusHandler($this->get_option('transaction_end'), $this->Cfg->IS_PREAUTH);
			$this->form_fields = WC_Payneteasy_SettingsForm::init($this->Cfg);

			add_action("woocommerce_update_options_payment_gateways_{$this->id}", [$this, 'process_admin_options']);
			add_action('admin_enqueue_scripts', [$this, 'hook_enqueue_admin_scripts']);
			add_action('admin_notices', [$this, 'hook_admin_notice_info']);
			add_action("woocommerce_api_{$this->id}_return", [$this, 'hook_return_handler']);
			add_action("woocommerce_api_{$this->id}_webhook", [$this, 'hook_webhook_handler']);
			add_action("woocommerce_api_{$this->id}_ajax", [$this, 'hook_ajax_handler']);
			add_action("scheduled_subscription_payment_{$this->id}", [$this, 'process_subscription_payment'], 10, 2);
			add_action('woocommerce_order_status_changed', [$this, 'hook_auto_capture'], 10, 4);
		}

		public static function hook_plugin_action_links(array $links): array
			{ return array_merge([ 'settings' => '<a href="'.admin_url('admin.php?page=wc-settings&tab=checkout&section=wc_payneteasy').'">Settings</a>' ], $links); }

		public static function test_card(string $how='as array'): array {
			$values = [ 4444_5555_6666_1111, date('Y')+2, 12, 'Test Name' ];

			return $how == 'as array' ? $values : array_combine(explode(' ', 'ccn year month name'), $values);
		}

		public function hook_enqueue_admin_scripts($hook): void {
			if ('woocommerce_page_wc-settings' == $hook) {
				wp_enqueue_script('payneteasy_admin_settings', PNE_PLUGIN_URL.'assets/js/admin_settings.js', ['jquery'], PNE_PLUGIN_VERSION, true);
				wp_localize_script('payneteasy_admin_settings', 'pneAdminSettings', [ 'field_prefix' => 'woocommerce_wc_payneteasy_' ]);
				wp_enqueue_style('payneteasy_admin_settings', PNE_PLUGIN_URL.'assets/css/admin_settings.css', [], PNE_PLUGIN_VERSION);
			}

			if ('woocommerce_page_wc-status' == $hook && ($_GET['tab'] ?? '') == 'pne-log') {
				wp_enqueue_script('payneteasy-log-viewer', PNE_PLUGIN_URL.'assets/js/admin_log_viewer.js', ['jquery'], PNE_PLUGIN_VERSION, true);
				wp_localize_script('payneteasy-log-viewer', 'pneLogViewer', [ 'ajaxUrl' => admin_url('admin-ajax.php?payneteasy_logviewer_poll'), 'nonce' => wp_create_nonce('pne_log_viewer') ]);
			}

			if (($screen = get_current_screen()) && $screen->id == \Automattic\WooCommerce\Utilities\OrderUtil::get_order_admin_screen()) {
				if (($O = wc_get_order($_GET['id'] ?? $_GET['post'] ?? '')) && $O->get_payment_method() == $this->id && !in_array($O->get_status(), ['failed','refunded','cancelled'])) {
					wp_enqueue_script('payneteasy-order-page-script', plugins_url('/assets/js/admin_order.js', __FILE__), ['jquery'], PNE_PLUGIN_VERSION, true);

					wp_localize_script('payneteasy-order-page-script', 'payneteasy_ajax_var', [
						'nonce' => wp_create_nonce('payneteasy-ajax-nonce'),
						'api_url' => home_url('/wc-api/wc_payneteasy_ajax'),
						'order_id' => $O->get_id(),
						'paynet_order_id' => $this->paynet_order_id($O),
						'show_capture' => $O->get_status() == 'authorized' && $this->Cfg->IS_CAPTURE_MANUAL,
						'capture_amount' => $O->get_total() ]);
				}
			}

			if ($screen && in_array($screen->id, [\Automattic\WooCommerce\Utilities\OrderUtil::get_order_admin_screen(), 'edit-shop_order'], true))
				wp_enqueue_style('payneteasy-admin-order', PNE_PLUGIN_URL.'assets/css/admin_order.css', [], PNE_PLUGIN_VERSION);

			# WC Subscriptions
			if ($screen && $screen->id == 'shop_subscription'
					&& ($O = wc_get_order($_GET['id'] ?? $_GET['post'] ?? '')) && $O->get_meta('_payneteasy_card_ref_id')) {
				wp_enqueue_script('payneteasy-subscription-page-script', plugins_url('/assets/js/admin_subscription.js', __FILE__), ['jquery'], PNE_PLUGIN_VERSION, true);
				wp_localize_script('payneteasy-subscription-page-script', 'payneteasy_ajax_var',
					[ 'nonce' => wp_create_nonce('payneteasy-ajax-nonce'), 'api_url' => home_url('/wc-api/wc_payneteasy_ajax'), 'order_id' => $O->get_id() ]);
			}
		}

		public function hook_admin_notice_info(): void {
			if ($message = get_option('pne_admin_notice_info')) {
				delete_option('pne_admin_notice_info');
				echo '<div class="notice notice-info is-dismissible"><p>'.esc_html($message).'</p></div>';
			}
		}

		public function hook_ajax_handler(): void {
			try {
				if (empty($action = $_POST['action']))
					throw new \Exception(self::_T('Required action not specified.'));

				if (!wp_verify_nonce($_POST['nonce'] ?? '', 'payneteasy-ajax-nonce'))
					throw new \Exception(self::_T('Failed ajax validation.'));

				$O = $this->order($_POST['order_id']);

				if ($action == 'check_status') {
					if (($before = $O->get_status()) != ($after = $this->OrderStatus->set($O, $Pr = $this->PaymentResponse($O)))) {
						$O->add_order_note(self::_T('Manual "Check status" changed order status from %s to %s.', $before, $after));
						self::admin_notice_success($message = self::_T('Order status updated.'));
					}
					else
						self::admin_notice_info($message = self::_T('Order status not changed'));
				}
				elseif ($action == 'card_info') {
					if (empty($card_ref_id = $O->get_meta('_payneteasy_card_ref_id')))
						throw new \Exception(self::_T('No saved card reference for this subscription.'));

					$info = $this->Api->get_card_info([ 'cardrefid' => $card_ref_id ]);
					$message = self::_T('%s, card ending %s, expires %02d/%d.',
						$info['card-printed-name'], $info['last-four-digits'], $info['expire-month'], $info['expire-year']);
				}
				elseif ($action == 'capture') {
					if (!$this->process_capture($O, isset($_POST['amount']) ? (float)$_POST['amount'] : null))
						throw new \Exception(self::_T('Capture failed, see the order notes for details.'));

					self::admin_notice_success($message = self::_T('Capture successful.'));
				}

				wp_send_json([ 'success' => true, 'message' => $message ]);
			}
			catch (PneException $e) {
				self::add_exception_note($O, $e);
				wp_send_json([ 'success' => false, 'message' => $e->getMessage() ]);
			}
			catch (\Exception $e)
				{ wp_send_json([ 'success' => false, 'message' => $e->getMessage() ]); }
		}

		public function hook_return_handler(): void {
			try {
				if (!hash_equals(($O = $this->order($_GET['orderId']))->get_order_key(), (string)($_GET['key'] ?? '')))
					throw new \Exception(self::_T('Invalid order key.'));

				$this->OrderStatus->set($O, $Pr = $this->PaymentResponse($O));
				$this->store_card_ref($O, $Pr);

				if ($Pr->is_processing() && $Pr->redirect())
					wp_redirect($Pr->redirect());
				elseif ($Pr->is_processing())
					$this->print_page($Pr->three_d_html() ?: '<div style="width: 100%; text-align: center"><div><h1>Your payment is being processed.</h1></div>
							<div><a href="'.$this->return_url($O).'" id="ticker">Check status</a></div></div>
							<script>let t_el = document.getElementById("ticker");let t_s=t_el.innerHTML,t_pos=0,t_iv=setInterval(() => {
							if (++t_pos <= t_s.length) { if (t_s[t_pos] == " ") t_pos++; t_el.innerHTML = "<span style=\'color:#09C\'>"+t_s.slice(0, t_pos)+"</span>"+t_s.slice(t_pos) }
							else { clearInterval(t_iv); t_el.click() } }, 300)</script>');
				else
					wp_redirect($O->get_checkout_order_received_url());

				exit;
			}
			catch (PneException $e) {
				self::add_exception_note($O, $e);
				wp_die( $e->getMessage() );
			}
			catch (\Exception $e)
				{ wp_die( $e->getMessage() ); }
		}

		public function hook_webhook_handler(): void {
			$in = array_map('wp_unslash', $_GET);
			[ $order_id, $type, $paynet_id, $status ] = [ $in['client_orderid'], $in['type'], $in['orderid'], $in['status'] ];

			try {
				if (!$this->Api->verify_callback($in))
					throw new \Exception(self::_T('Invalid callback signature.'));

				$this->Api->log_info("$type/$status orderid=$paynet_id client_orderid=$order_id");

				if ($this->OrderStatus->is($O = $this->order($order_id), "$type/$status"))
					exit('OK');

				$this->OrderStatus->set($O, $Pr = $this->PaymentResponse($O, $paynet_id));
				$this->store_card_ref($O, $Pr);

				exit;
			}
			catch (PneException $e) {
				self::add_exception_note($O, $e);
				die($e->getMessage());
			}
			catch (\Exception $e) {
				$this->Api->log_error("$type/$status orderid=$paynet_id client_orderid=$order_id &rarr; ".$e->getMessage());
				die($e->getMessage());
			}
		}

		public function hook_auto_capture($order_id, $status_from, $status_to, $order): void {
			if ($order->get_payment_method() != $this->id || !$this->Cfg->IS_PREAUTH || $this->Cfg->IS_CAPTURE_MANUAL
					|| $status_from != 'authorized' || $status_to != 'processing')
				return;

			$this->process_capture($order);
		}

		public function payment_fields(): void
			{ WC_Payneteasy_CheckoutForm::show($this->id, $this->description, $this->Cfg); }

		public function validate_fields(): void {
			if (!$this->Cfg->IS_FORM)
				foreach (explode(' ', 'credit_card_number card_printed_name expire_year expire_month cvv2') as $f)
					if (empty($_POST[$f]))
						wc_add_notice("$f is required!", 'error');

			if (empty($_POST['ssn']) && $this->Cfg->IS_SSN_REQUIRED)
				wc_add_notice('CPF is required!', 'error');
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
					$O->update_status('pending', self::_T('Payment link generated:').$sale['redirect-url']);

				return [ 'result' => 'success', 'redirect' => $sale['redirect-url'] ?? $this->return_url($O) ];
			}
			catch (PneException $e) {
				self::add_exception_note($O, $e);
				wc_add_notice($e->getMessage(), 'error');
				return [ 'result' => 'failure' ];
			}
			catch (\Exception $e) {
				wc_add_notice($e->getMessage(), 'error');
				return [ 'result' => 'failure' ];
			}
		}

		public function process_subscription_payment($amount_to_charge, object $renewal_order): void {
			try {
				$subscriptions = function_exists('wcs_get_subscriptions_for_renewal_order') ? wcs_get_subscriptions_for_renewal_order($renewal_order) : [];
				$card_ref_id = ($subscription = reset($subscriptions)) ? $subscription->get_meta('_payneteasy_card_ref_id') : null;

				if (empty($card_ref_id))
					throw new \Exception(self::_T('No saved card reference for this subscription.'));

				$response = $this->Api->make_rebill([
					'amount' => $amount_to_charge,
					'currency' => $renewal_order->get_currency(),
					'cardrefid' => $card_ref_id,
					'ipaddress' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
					'client_orderid' => $renewal_order->get_id() ]);

				$this->try_record_trans_id([ 'paynet_order_id' => $response['paynet-order-id'], 'merchant_order_id' => $renewal_order->get_id() ]);

				$renewal_order->update_status('on-hold', self::_T('Rebill request sent, awaiting confirmation.'));
			}
			catch (PneException $e) {
				self::add_exception_note($renewal_order, $e);
				$renewal_order->update_status('failed', $e->getMessage());
			}
			catch (\Exception $e)
				{ $renewal_order->update_status('failed', $e->getMessage()); }
		}

		public function process_refund($order_id, $amount = null, $reason = ''): bool {
			try {
				$O = $this->order($order_id);

				$this->Api->return([
					'amount' => $amount ?? $O->get_total(),
					'comment' => $reason ?: 'Order cancel',
					'orderid' => $this->paynet_order_id($O),
					'currency' => $O->get_currency(),
					'client_orderid' => $order_id ]);

				return true;
			}
			catch (PneException $e) {
				self::add_exception_note($O, $e);
				return false;
			}
			catch (\Exception $e)
				{ return false; }
		}

		public function process_capture(object $O, ?float $amount = null): bool {
			try {
				$data = [ 'client_orderid' => $O->get_id(), 'orderid' => $this->paynet_order_id($O) ];

				if (isset($amount))
					$data += [ 'amount' => $amount, 'currency' => $O->get_currency() ];

				$this->Api->capture($data);

				$O->update_status($this->OrderStatus->completed_status(), self::_T('Payment captured successfully.'));
				$O->payment_complete();
				wc_reduce_stock_levels($O->get_id());

				return true;
			}
			catch (PneException $e) {
				self::add_exception_note($O, $e);
				return false;
			}
			catch (\Exception $e)
				{ return false; }
		}

		private function order($order_id=null): object {
			if (empty($order_id))
				throw new \Exception(self::_T('Order ID is empty.'));

			if (!preg_match('/^\d+$/', $order_id = (string)$order_id))
				throw new \Exception((self::_T('Order ID is invalid: ').esc_html($order_id)));

			if ($O = wc_get_order($order_id))
				return $O;
			else
				throw new \Exception(self::_T('Order #%d not found.', $order_id));
		}

		private function init_config(): PneConfig {
			foreach (explode(' ', 'notify_url title description enabled') as $k)
				$this->$k = $this->get_option($k);

			return PneConfig::fetchkey_only(fn($k) => (strpos($k, 'IS_') === 0) ? (bool)($this->get_option($k) == 'yes') : ($this->get_option($k) ?? ''));
		}

		private function return_url(object $O): string
			{ return home_url("?wc-api={$this->id}_return&orderId={$O->get_id()}&key=".$O->get_order_key()); }

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
				'order_desc' => "Order #$order_id",
				'amount' => $total,
				'currency' => $O->get_currency(),
				'address1' => $O->get_billing_address_1(),
				'city' => $O->get_billing_city(),
				'zip_code' => $O->get_billing_postcode(),
				'country' => $O->get_billing_country(),
				'state' => $O->get_billing_state() ?? '',
				'phone' => $O->get_billing_phone(),
				'email' => $email,
				'ipaddress' => $_SERVER['REMOTE_ADDR'],
				'cvv2' => $_POST['cvv2'] ?? '',
				'ssn' => $_POST['ssn'] ?? '',
				'credit_card_number' => $_POST['credit_card_number'] ?? '',
				'card_printed_name' => $_POST['card_printed_name'] ?? '',
				'expire_month' => $_POST['expire_month'] ?? '',
				'expire_year' => $_POST['expire_year'] ?? '',
				'first_name' => $O->get_shipping_first_name() ?: $O->get_billing_first_name(),
				'last_name'  => $O->get_shipping_last_name() ?: $O->get_billing_last_name(),
				'redirect_url' => $return_url,
				'server_callback_url' => home_url('?wc-api=wc_payneteasy_webhook'),
				'notify_url' => $this->Cfg->IS_SSN_REQUIRED ? $this->notify_url : '' ],
				wp_unslash($_POST['pne_browser_info']));

			$this->try_record_trans_id([ 'paynet_order_id' => $response['paynet-order-id'], 'merchant_order_id' => $response['merchant-order-id'] ]);

			return $response;
		}

		private function store_card_ref(object $O, Payneteasy_PaymentResponse $Pr): void {
			if ('sale/approved' != $Pr
					|| !function_exists('wcs_get_subscriptions_for_order')
					|| !($subscriptions = wcs_get_subscriptions_for_order($O, [ 'order_type' => 'any' ])))
				return;

			try {
				$response = $this->Api->create_card_ref([ 'client_orderid' => $O->get_id(), 'orderid' => $this->paynet_order_id($O) ]);

				foreach ($subscriptions as $subscription) {
					$subscription->update_meta_data('_payneteasy_card_ref_id', $response['card-ref-id']);
					$subscription->save();
				}
			}
			catch (PneException $e)
				{ self::add_exception_note($O, $e); }
		}

		private function PaymentResponse(object $O, $paynet_id = null): Payneteasy_PaymentResponse
			{ return new Payneteasy_PaymentResponse($this->Api->status([ 'client_orderid' => $O->get_id(), 'orderid' => $paynet_id ?: $this->paynet_order_id($O) ])); }

		private function paynet_order_id(object $O): string {
			global $wpdb;
			return $wpdb->get_var("SELECT paynet_order_id FROM {$wpdb->prefix}payneteasy_payments WHERE merchant_order_id={$O->get_id()} ORDER BY id DESC LIMIT 1");
		}

		private function try_record_trans_id(array $row): void {
			global $wpdb;

			if (false === $wpdb->insert("{$wpdb->prefix}payneteasy_payments", $row))
				throw new PneException(self::_T('Failed to record the gateway transaction id.'), $row);
		}

		private static function admin_notice_success($message)
			{ WC_Admin_Notices::add_custom_notice('payneteasy_ajax', $message); }

		private static function admin_notice_info($message)
			{ update_option('pne_admin_notice_info', $message); }

		private static function add_exception_note(object $O, PneException $e): void
			{ $O->add_order_note(self::_T('Payneteasy gateway error #%d: %s', $e->getCode(), $e->getMessage())); }

		private static function parse_amount($amount): float
			{ return floatval(str_replace([' ', ','], ['', '.'], $amount)); }
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
				[ 'transaction_end', 'Successful transaction order status', 'Select the order status to be displayed after successful payment' ],
				[ 'maintenance' ],
				[ 'delete_data_on_uninstall?', 'Remove data on uninstall', 'Delete plugin data, including logs. Requires define(\'WC_PAYNETEASY_UNINSTALL_DATA\', true) in wp-config.php.' ] ];

			if (PneApi::is_debug_mode())
				array_push($form_fields,
					[ 'debug' ],
					[ 'IS_DEBUG_TRACE?', 'Trace requests', 'Write requests and responses trace to server errorlog' ],
					[ 'IS_DEBUG_FAKE?', 'Fake requests', 'Imitate server requests and responses (countermands responses trace)' ]);

			return array_reduce(array_map(fn(array $def) => self::form_field($Cfg, $def), $form_fields), 'array_merge', []);
		}

		private static function form_field(PneConfig $Cfg, array $def): array {
			[ $key, $title, $descr, $label, $placeholder, $dependant ] = array_pad($def, 6, null);

			$field = [];

			if ('*' == substr($key, -1))
				[ $key, $tail, $field['custom_attributes'] ] = [ chop($key, '*'), ' <span style="color:red">*</span>', [ 'required' => 'required' ] ];
			elseif (isset($dependant))
				$field['custom_attributes'] = [ 'data-toggle-row' => $dependant ];

			if (count($def) == 1)
				[ $type, $title, $field['class'] ] = [ 'title', ucfirst($key), 'pne-section-title' ];
			elseif ('?' == substr($key, -1)) {
				[ $type, $key, $field['default'] ] = [ 'checkbox', chop($key, '?'), $key == 'enabled' ? 'yes' : 'no' ];
				if (is_array($descr)) {
					[ $off, $on ] = $Cfg->$key ? [ ' style="display:none"', '' ] : [ '', ' style="display:none"' ];
					$descr = [ "<span{$off} id=\"pne-{$key}-desc-off\">%s</span><span{$on} id=\"pne-{$key}-desc-on\">%s</span>", ...$descr ];
				}
				elseif (!isset($label))
					[ $label, $descr ] = [ $descr, null ];
			}
			elseif ('description' == $key)
				[ $type, $field['default'] ] = [ 'textarea', self::_T('Pay with Payneteasy payment') ];
			elseif ('transaction_end' == $key)
				[ $type, $field['default'], $field['options'] ] = [ 'select', 'wc-processing', wc_get_order_statuses() ];
			else
				$type = 'text';

			return [ $key => array_filter(array_merge($field,
				[ 'type' => $type, 'title' => self::_U($title, $tail ?? ''), 'label' => self::_U($label), 'description' => self::_U($descr), 'placeholder' => self::_U($placeholder) ])) ];
		}

		private static function _U($arg, string $tail=''): ?string {
			if (is_array($arg)) {
				$mask = array_shift($arg);
				return sprintf($mask.$tail, ...array_map(fn($s) => self::_T($s), $arg));
			}

			return isset($arg) ? self::_T($arg).$tail : null;
		}
	}

	class WC_Payneteasy_CheckoutForm {
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
					echo self::payment_form_field('ssn', 'Document Number (CPF)', [ 'autocomplete' => 'off' ]);
			}
			else {
				$months = array_combine($mm = array_map(fn($n) => sprintf('%02d', $n), range(1, 12)), $mm);
				$years = array_combine($yy = array_map('strval', range(date('Y'), date('Y')+15)), $yy);

				echo '<script>function checkLuhn(ccnS) {
					let sum = 0; const parity = ccnS.length % 2
					for (let i = 0; i < ccnS.length; i += 1) { let digit = Number(ccnS[i]); if (i % 2 == parity) { digit *= 2
					if (digit > 9) { digit -= 9 } } sum += digit }
					document.getElementById("place_order").disabled = Number(sum % 10) != 0 }</script>
					<style>
						#credit_card_number_field{max-width:200px;width:auto}
						#cvv2_field{width:90px;flex:none}
						#expire_month_field{width:140px;flex:none}
						#expire_year_field{width:140px;flex:none}
						#expire_month_field label,#expire_year_field label{white-space:nowrap}
						#card_printed_name_field,#ssn_field{max-width:320px}
						#expire_month_field select,#expire_year_field select{
							-webkit-appearance:none;-moz-appearance:none;appearance:none;
							padding-right:24px;
							background-image:linear-gradient(45deg, transparent 50%, currentColor 50%), linear-gradient(135deg, currentColor 50%, transparent 50%);
							background-position: calc(100% - 14px) 55%, calc(100% - 9px) 55%;
							background-size:5px 5px, 5px 5px;
							background-repeat:no-repeat;
						}</style><input type="hidden" name="pne_browser_info" id="pne-browser-info">
						<script>document.getElementById("pne-browser-info").value = JSON.stringify(
							[ "true", navigator.javaEnabled?.() ? "true" : "false", window.screen.colorDepth, window.screen.height, window.screen.width, new Date().getTimezoneOffset() ])</script>'
					.self::payment_form_row(12,
						self::payment_form_field('credit_card_number', 'Card Number', [ 'autocomplete' => 'cc-number', 'custom_attributes' => ['onkeyup' => 'checkLuhn(this.value)'] ], $cc),
						self::payment_form_field('cvv2', 'CVC', [ 'autocomplete' => 'off', 'minlength' => 3, 'maxlength' => 4 ], '', 'password'))
					.self::payment_form_row(20,
						self::payment_form_field('expire_month', 'Expiry month', [ 'options' => ['' => 'MM'] + $months, 'input_class' => ['input-text'] ], (string)$mon),
						self::payment_form_field('expire_year', 'Expiry year', [ 'options' => ['' => 'YYYY'] + $years, 'input_class' => ['input-text'] ], (string)$year))
					.self::payment_form_field('card_printed_name', 'Printed name', [ 'autocomplete' => 'cc-name', 'placeholder' => 'Printed name' ], $name);

				if ($Cfg->IS_SSN_REQUIRED)
					echo self::payment_form_field('ssn', 'Document Number (CPF)', [ 'autocomplete' => 'off' ]);
			}

			do_action('woocommerce_credit_card_form_end', $id);
			echo '<div class="clear"></div></fieldset>';
		}

		private static function payment_form_row(int $gap, string ...$fields): string
			{ return '<div style="display:flex;gap:'.$gap.'px">'.implode('', $fields).'</div>'; }

		private static function payment_form_field(string $key, string $label, array $extra, string $value='', string $type='text'): string {
			return woocommerce_form_field($key, array_merge([
				'type' => isset($extra['options']) ? 'select' : $type,
				'label' => $label,
				'class' => ['form-row-wide'],
				'return' => true,
				'required' => true ], $extra), $value);
		}
	}

	class WC_Payneteasy_OrderStatusExpander {
		private const CUSTOM_STATUSES = [ 'wc-chargeback' => 'Chargeback', 'wc-authorized' => 'Authorized', 'wc-void' => 'Voided' ];

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
	}

	class WC_Payneteasy_OrderStatusHandler { use PneString;
		private string $completed_status;
		private array $status_map;

		function __construct(string $transaction_end, bool $is_preauth = false) {
			$this->completed_status = str_replace('wc-', '', $transaction_end);

			$sale_type = $is_preauth ? 'preauth' : 'sale';
			$this->status_map = [
				"$sale_type/approved"       => $is_preauth ? [ 'authorized', 'authorized' ] : [ 'paid', $this->completed_status ],
				"$sale_type/processing"     => [ 'hold', 'on-hold' ],
				"$sale_type/unknown"        => [ 'hold', 'on-hold' ],
				"$sale_type/chain_declined" => [ 'hold', 'on-hold' ],
				'chargeback/approved'       => [ 'chargeback', 'chargeback' ],
				'reversal/approved'         => [ 'refunded', 'refunded' ],
				'void/approved'             => [ 'void', 'void' ],
				'capture/approved'          => [ 'paid', $this->completed_status ] ];
		}

		public function completed_status(): string
			{ return $this->completed_status; }

		public function is(object $O, string $payment_status): bool
				{ return $O->get_status() == ($this->status_map[$payment_status] ?? [ null, 'failed' ])[1]; }

		public function set(object $O, Payneteasy_PaymentResponse $Pr): string {
			$action = $this->status_map[(string)$Pr][0] ?? 'unpaid';

			if ($action == 'refunded') {
				$delta = round(($Pr->total_reversal_amount() ?? $O->get_total()) - $O->get_total_refunded(), 2);

				if ($delta > 0)
					wc_create_refund([ 'order_id' => $O->get_id(), 'amount' => $delta, 'reason' => self::_T('Reversal reported by gateway.') ]);

				if ($O->get_total_refunded() < $O->get_total())
					$action = 'partially_refunded';
			}

			$map = [
				'paid'       => [ 'Payment completed successfully.', $this->completed_status, true ],
				'hold'       => [ 'Payment is being processed.', 'on-hold' ],
				'unpaid'     => [ 'Payment not paid.', 'failed', null, 'Payment not paid. Your order has been canceled.', 'error' ],
				'authorized' => [ 'Payment authorized, awaiting capture.' ],
				'void'       => [ 'Void of payment.', null, null, 'The payment was voided.' ],
				'refunded'   => [ 'Refund of payment.', null, null, 'The payment was refunded.' ],
				'chargeback' => [ 'Chargeback of payment.', null, null, 'The payment was charged back.' ],
				'partially_refunded'
				             => [ 'Partial refund of payment.', $this->completed_status, null, 'The payment was partially refunded.' ] ];

			[ $text, $status, $extra_actions, $notice, $notice_t ] = array_pad($map[$action], 5, null);

			if ($O->get_status() != ($set = $status ?? $action)) {
				$O->update_status($set, self::_T($text));

				if (isset($extra_actions))
					$this->{'extra_actions_for_'.$action}($O);
			}

			if (isset($notice))
				wc_add_notice(self::_T($notice), $notice_t ?? 'notice');

			return $set;
		}

		private function extra_actions_for_paid(object $O): void {
			$O->payment_complete();
			wc_reduce_stock_levels($O->get_id());
		}
	}

	class Payneteasy_PaymentResponse {
		private array $response;

		function __construct(array $response)
			{ $this->response = $response; }

		public function __toString(): string
			{ return $this->response['transaction-type'].'/'.$this->response['status']; }

		public function redirect(): ?string
			{ return $this->response['redirect-to'] ?? null; }

		public function three_d_html(): ?string
			{ return $this->response['html'] ?? null; }

		public function total_reversal_amount(): ?float
			{ return isset($this->response['total-reversal-amount']) ? (float)$this->response['total-reversal-amount'] : null; }

		public function is_processing(): bool
			{ return '/processing' == substr($this, -11); }
	}
}

function hook_activate_wc_paynet_payment_gateway(): void {
	global $wpdb;

	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$wpdb->prefix}payneteasy_payments (
		`id` int(11) NOT NULL AUTO_INCREMENT,
		`paynet_order_id` int(11) NOT NULL,
		`merchant_order_id` int(11) NOT NULL,
		PRIMARY KEY (id),
		KEY merchant_order_id (merchant_order_id)
	) $charset_collate";

	require_once(ABSPATH.'wp-admin/includes/upgrade.php');
	dbDelta($sql);
}

function hook_uninstall_wc_paynet_payment_gateway(): void {
	if ((get_option('woocommerce_wc_payneteasy_settings')['delete_data_on_uninstall'] ?? '') == 'yes'
			&& defined('WC_PAYNETEASY_UNINSTALL_DATA')
			&& true === WC_PAYNETEASY_UNINSTALL_DATA) {
		[ $prefix, $suffix ] = [ PNE_PLUGIN_ID.'-', '-'.wp_hash(PNE_PLUGIN_ID).'.log' ];

		foreach ((scandir(WC_LOG_DIR) ?: []) as $f)
			if (substr($f, 0, strlen($prefix)) === $prefix && substr($f, -strlen($suffix)) === $suffix)
				@unlink(WC_LOG_DIR.$f);

		global $wpdb;
		$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}payneteasy_payments");
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
			wp_register_script('wc-payneteasy-blocks', PNE_PLUGIN_URL.'assets/js/blocks.js', ['wc-blocks-registry', 'wc-settings', 'wp-element'], PNE_PLUGIN_VERSION, true);
			return ['wc-payneteasy-blocks'];
		}

		public function get_payment_method_data(): array {
			return [
				'icon' => PNE_PLUGIN_URL.'payneteasy.png',
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

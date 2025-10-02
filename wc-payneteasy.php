<?php
/**
	* Plugin Name: Payment system PAYNETEASY
	* Description: Allows you to use Payment system PAYNETEASY with the WooCommerce plugin.
	* Version: 1.0.0
	* Author: Payneteasy
	* Author URI: https:#payneteasy.com/
	* Text Domain: wc-payneteasy
	* Domain Path: /languages/
	* Requires PHP: 7.4
	*
	* @package Payneteasy
	* @version 1.0.3
	*/

if (!defined('ABSPATH')) exit; # Exit if accessed directly

require __DIR__.'/vendor/autoload.php';
require __DIR__.'/hooks.php';

use \Payneteasy\Classes\Api\PaynetApi,
	\Payneteasy\Classes\Exception\PayneteasyException;

add_action('plugins_loaded', 'init_wc_paynet_payment_gateway');

function init_wc_paynet_payment_gateway(): void {
	if (!class_exists('WC_Payment_Gateway') || class_exists('WC_Payneteasy'))
		return;

	class WC_Payneteasy extends WC_Payment_Gateway {
		private bool $require_ssn;
		private string $transaction_end;
		private string $notify_url;

		private PaynetApi $api;
		private object $order;

		function __construct() {
			$this->id = 'wc_payneteasy';
			$this->icon = apply_filters('woocommerce_payneteasy_icon', plugin_dir_url(__FILE__).'payneteasy.png');
			$this->method_title = __('Payment system PAYNETEASY', 'wc-payneteasy');
			$this->method_description = __('Plugin "PAYNET Payment System" for WooCommerce, which allows you to integrate online payments.', 'wc-payneteasy');
			$this->has_fields = false;

			$s = $this->init_payment_settings();
			$this->api = new PaynetApi($s['gate'], $s['login'], $s['control_key'], $s['endpoint_id'], $s['is_direct']);

			$this->init_form_fields();
			$this->init_settings();

			add_filter('wc_order_statuses', [$this, 'order_statuses']);

			add_action('woocommerce_update_options_payment_gateways_'.$this->id, [$this, 'process_admin_options']);
			add_action('woocommerce_api_'.$this->id.'_return', [$this, 'return_handler']);
			add_action('woocommerce_api_'.$this->id.'_webhook', [$this, 'webhook_handler']);
			add_action('woocommerce_api_'.$this->id.'_ajax', [$this, 'ajax_handler']);
		}

		public function order_statuses(array $statuses): array {
			return array_merge($statuses, [ 'wc-chargeback' => _x('Chargeback', 'Order status', 'woocommerce') ]);
		}

		private function init_payment_settings(): array {
			foreach (explode(' ', 'endpoint_id login control_key payment_method require_ssn sandbox live_url sandbox_url notify_url transaction_end'
					.' title description enabled') as $k) {
				$s[$k] = in_array($k, ['sandbox', 'require_ssn'])
					? ($this->get_option($k) == 'yes')
					: $this->get_option($k);

				if (property_exists($this, $k))
					$this->$k = $s[$k];
			}

			$s['is_direct'] = $s['payment_method'] == 'direct';
			$s['gate'] = $s['sandbox'] ? $s['sandbox_url'] : $s['live_url'];

			return $s;
		}

		public function init_form_fields(): void
			{ $this->form_fields = include 'form_fields.php'; }

		private function set_order(int $order_id): void {
			if ($order = wc_get_order($order_id))
				$this->order = $order;
			else
				throw new \Exception(__('Order not found.', 'wc-payneteasy'));
		}

		public function process_payment($order_id): ?array {
			try {
				$this->set_order($order_id);

				if ($this->order->get_payment_method() != $this->id)
					throw new \Exception(__('Payment method is not "Payneteasy Payment System".', 'wc-payneteasy'));

				$sale = $this->make_sale();

				if (isset($sale['redirect-url']))
					$this->order->update_status('pending', __('Payment link generated:', 'wc-payneteasy').$sale['redirect-url']);

				return $this->api->is_direct()
					? [ 'result' => 'success', 'redirect' => home_url("?wc-api={$this->id}_return&orderId=$order_id") ]
					: [ 'result' => 'success', 'redirect' => $sale['redirect-url'] ];
			}
			catch (\Exception | PayneteasyException $e) {
				wc_add_notice($e->getMessage(), 'error');
				return null;
			}
		}

		private function make_sale(): array {
			[ $order_id, $email, $total ] = [ $this->order->get_id(), $this->order->get_billing_email(), $this->order->get_total() ];

			$return_url = home_url("?wc-api={$this->id}_return&orderId=$order_id");

			$response = $this->api->sale([
				'client_orderid' => $order_id,
				'order_desc' => "Order # $order_id",
				'amount' => $total,
				'currency' => $this->order->get_currency(),
				'address1' => $this->order->get_shipping_address_1() ?: $this->order->get_billing_address_1(),
				'city' => $this->order->get_shipping_city() ?: $this->order->get_billing_city(),
				'zip_code' => $this->order->get_shipping_postcode() ?: $this->order->get_billing_postcode(),
				'country' => $this->order->get_shipping_country() ?: $this->order->get_billing_country(),
				'phone' => $this->order->get_shipping_phone() ?: $this->order->get_billing_phone(),
				'email' => $email,
				'ipaddress' => $_SERVER['REMOTE_ADDR'],
				'cvv2' => "{$_POST['cvv2']}",
				'ssn' => "{$_POST['ssn']}",
				'credit_card_number' => "{$_POST['credit_card_number']}",
				'card_printed_name' => "{$_POST['card_printed_name']}",
				'expire_month' => "{$_POST['expire_month']}",
				'expire_year' => "{$_POST['expire_year']}",
				'first_name' => $this->order->get_shipping_first_name() ?: $this->order->get_billing_first_name(),
				'last_name'  => $this->order->get_shipping_last_name() ?: $this->order->get_billing_last_name(),
				'redirect_success_url' => $return_url,
				'redirect_fail_url' => $return_url, # wc_get_cart_url(),
				'redirect_url' => $return_url,
				'server_callback_url' => home_url('?wc-api=wc_payneteasy_webhook'), # $return_url,
				'notify_url' => sprintf($this->notify_url, $order_id) ]);

			global $wpdb;
			$wpdb->insert("{$wpdb->prefix}payneteasy_payments",
				[ 'paynet_order_id' => $response['paynet-order-id'], 'merchant_order_id' => $response['merchant-order-id'] ]);

			return $response;
		}

		private static function form_cell(?array $cell, string $class): string {
			return $cell !== null
				? "<div class='form-row-$class'><label>{$cell[0]} <span class='required'>*</span></label>"
					."<input id='{$cell[1]}' name='{$cell[1]}' type='text'"
					.($cell[2] ? " autocomplete='{$cell[2]}'" : '')
					.' '.($cell[3] ?? '').'></div>'
				: '';
		}

		private static function form_row(array $cell1, ?array $cell2): string
			{ return '<div class="form-row">'.self::form_cell($cell1, 'first').self::form_cell($cell2, 'last').'</div>'; }

		# отображение описания платежной системы PAYNET при оформлении заказа
		public function payment_fields(): void {
			if (!empty($this->description))
				echo wpautop(wptexturize($this->description));

			echo '<fieldset id="wc-'.esc_attr($this->id).'-cc-form" class="wc-credit-card-form wc-payment-form" style="background:transparent">';
			do_action('woocommerce_credit_card_form_start', $this->id);

			echo $this->api->is_direct()
				? self::form_row(
						['Card Number', 'credit_card_number', 'cc-number'],
						['Printed name', 'card_printed_name', 'cc-name', 'placeholder="Printed name"'])
					.self::form_row(
						['Expiry month', 'expire_month', 'off', 'minlength="2" maxlength="2" placeholder="MM" style="max-width:50%"'],
						['Expiry year', 'expire_year', 'off', 'minlength="4" maxlength="4" placeholder="YYYY" style="max-width: 50%"'])
					.self::form_row(
						['CVC', 'cvv2', 'off', 'minlength="3" maxlength="4" type="password" style="max-width:50%"'],
						$this->require_ssn ? ['Document Number (CPF)', 'ssn', 'off'] : null)
				: ($this->require_ssn ? self::form_cell(['Document Number (CPF)', 'ssn', 'off'], 'wide') : '');

			do_action('woocommerce_credit_card_form_end', $this->id);
			echo '<div class="clear"></div></fieldset>';
		}

		public function validate_fields(): void {
			if ($this->api->is_direct())
				foreach (explode(' ', 'credit_card_number card_printed_name expire_year expire_month cvv2') as $f)
					if (empty($_POST[$f]))
						wc_add_notice("$f is required!", 'error');

			if (empty($_POST['ssn']) && $this->require_ssn)
				wc_add_notice('CPF is required!', 'error');
		}

		# обработчик return, вызываемый при переходе на страницу return_url, после попытки оплаты.
		public function return_handler(): void {
			$order_id = $_GET['orderId'] ?? null;

			try {
				if (empty($order_id))
					throw new \Exception(__('Order ID is empty.', 'wc-payneteasy'));

				$this->set_order($order_id);
				$this->change_payment_status($payment_status = $this->get_payment_status($three_d_html));

				WC()->cart->empty_cart(); # иначе продолжает слать всё тот же ордер_ид, и гейт отдаёт один и тот же запрос

				switch ($payment_status) {
					case 'sale/processing':
						echo $three_d_html;
						die();
					case 'sale/approved':
						echo '<div style="width: 100%; text-align: center"><div><h1>Your payment was approved. Thank you.</h1></div><div><a href="'.get_site_url().'">Return homepage</a></div></div>';
						die();
					case 'sale/error':
						echo '<div style="width: 100%; text-align: center"><div><h1>Your payment was not completed because of an error. Could you try again.</h1></div><div><a href="'.get_site_url().'">Return homepage</a></div></div>';
						die();
					case 'sale/declined':
						echo '<div style="width: 100%; text-align: center"><div><h1>Your payment was declined. Could you try again.</h1></div><div><a href="'.get_site_url().'">Return homepage</a></div></div>';
						die();
					default:
						echo '<div style="width: 100%; text-align: center"><div><h1>Transaction is declined but something went wrong, please inform your account manager, final status</h1></div><div><a href="'.get_site_url().'">Return homepage</a></div></div>';
						die();
				}
			}
			catch (\Exception | PayneteasyException $e)
				{ wp_die( $e->getMessage() ); }
		}

		public function webhook_handler(): void {
			[ $order_id, $type, $paynet_id ] = [ $_GET['client_orderid'], $_GET['type'], $_GET['orderid'] ];

			try {
				if (empty($order_id))
					throw new \Exception(__('Order ID is empty.', 'wc-payneteasy'));

				$this->set_order($order_id);

				if ($this->order->get_status() === $type)
					die('OK');

				$this->change_payment_status( $this->get_payment_status($dummy, $paynet_id) );

				exit;
			}
			catch (\Exception | PayneteasyException $e)
				{ wp_die( $e->getMessage() ); }
		}

		private function change_payment_status(string $payment_status): void {
			$method = 'actions_for_'.([
					'sale/approved' => 'paid',
					'sale/processing' => 'hold',
					'chargeback/approved' => 'chargeback',
					'reversal/approved' => 'refunded'
				][$payment_status] ?? 'unpaid')
				.'_order';

			$this->$method();
		}

		# проверяем статус платежа, и если он approved, то устанавливаем заказ в CMS как оплаченный.
		public function ajax_handler(): void {
			[ $order_id, $action ] = [ $_POST['order_id'], $_POST['action'] ];

			try {
				if (!wp_verify_nonce($_POST['nonce'] ?? '', 'payneteasy-ajax-nonce'))
					throw new \Exception(__('Failed ajax validation.', 'wc-payneteasy'));

				if (empty($order_id))
					throw new \Exception(__('Order ID is empty.', 'wc-payneteasy'));

				if (empty($action))
					throw new \Exception(__('Required action not specified.', 'wc-payneteasy'));

				$this->set_order($order_id);

				if ($action == 'refund') { # XXX why action is refund but make_chargeback being called
					$this->make_chargeback();
					$message = __('Payment refunded.', 'wc-payneteasy');
				}
				elseif ($action == 'check_status') {
					$this->change_payment_status($this->get_payment_status());
					$message = __('Status updated.', 'wc-payneteasy');
				}

				wp_send_json([ 'success' => true, 'message' => $message ]);
			}
			catch (\Exception | PayneteasyException $e)
				{ wp_send_json([ 'success' => false, 'message' => $e->getMessage() ]); }
		}

		private function make_chargeback(): array { # XXX result not used ever
			$order_id = $this->order->get_id();

			$amount = $_POST['refund_amount'] ?? 0;

			if (empty($amount))
				throw new \Exception(__('Refund amount not specified.', 'wc-payneteasy'));

			$amount = self::parse_amount($amount);
			$email = $this->order->get_billing_email();

			return $this->api->return([ 'client_orderid' => $order_id, 'orderid' => $this->paynet_order_id(), 'comment' => 'Order cancel ' ]);
		}

		private function preparing_items_for_refunds(array $items): array { # XXX function not used ever ?
			$refund_order_item_qty = $_POST['refund_order_item_qty'] ?? [];
			$refund_line_total = $_POST['refund_line_total'] ?? [];

			if (empty($refund_order_item_qty) || empty($refund_line_total))
				throw new \Exception(__('The required data for a refund was not transmitted.', 'wc-payneteasy'));

			$prepared_items = [];

			foreach ($items as $item) {
				$item_id = explode('-', $item['code'])[0] ?? '';
				$quantity = $refund_order_item_qty[$item_id] ?? 0;
				$amount = $refund_line_total[$item_id] ?? 0;

				if (empty($quantity) && empty($amount))
					continue;

				if (!empty($quantity))
					$item['quantity'] = (int) $quantity;

				$item['amount'] = empty($amount)
					? (int) $quantity * $item['price']
					: self::parse_amount($amount);

				$item['price'] = $item['amount'] / $item['quantity'];

				$prepared_items[] = $item;
			}

			return $prepared_items;
		}

		private static function parse_amount($amount): float
			{ return floatval(str_replace([' ', ','], ['', '.'], $amount)); }

		private function actions_for_paid_order(): void {
			$completed_status = str_replace('wc-', '', $this->transaction_end);

			if ($this->order->get_status() !== $completed_status) {
				$this->order->update_status($completed_status, __('Payment completed successfully.', 'wc-payneteasy'));
				$this->order->payment_complete();
				wc_reduce_stock_levels($this->order->get_id());
			}
		}

		private function actions_for_hold_order(): void {
			if ($this->order->get_status() !== 'on-hold')
				$this->order->update_status('on-hold', __('Payment received but not confirmed.', 'wc-payneteasy'));
		}

		private function actions_for_unpaid_order(): void {
			if ($this->order->get_status() !== 'failed')
				$this->order->update_status('failed', __('Payment not paid.', 'wc-payneteasy'));

			wc_add_notice(__('Payment not paid. Your order has been canceled.', 'wc-payneteasy'), 'error');
		}

		private function actions_for_chargeback_order(): void {
			if ($this->order->get_status() !== 'chargeback')
				$this->order->update_status('chargeback', __('Chargeback of payment.', 'wc-payneteasy'));

			wc_add_notice(__('The payment was charged back.', 'wc-payneteasy'), 'notice');
		}

		private function actions_for_refunded_order(): void {
			if ($this->order->get_status() !== 'refunded')
				$this->order->update_status('refunded', __('Refund of payment.', 'wc-payneteasy'));

			wc_add_notice(__('The payment was refunded.', 'wc-payneteasy'), 'notice');
		}

		private function actions_for_partially_refunded_order(): void {
			$completed_status = str_replace('wc-', '', $this->transaction_end);

			if ($this->order->get_status() !== $completed_status)
				$this->order->update_status($completed_status, __('Partial refund of payment.', 'wc-payneteasy'));

			wc_add_notice(__('The payment was partial refunded.', 'wc-payneteasy'), 'notice');
		}

		private function get_payment_status(&$three_d_html = null, $paynet_id = null): string {
			$response = $this->api->status([ 'client_orderid' => $this->order->get_id(), 'orderid' => $paynet_id ?: $this->paynet_order_id() ]);

			$three_d_html = $response['html'] ?? null;
			return "{$response['transaction-type']}/{$response['status']}";
		}

		private function paynet_order_id(): string {
			global $wpdb;
			return $wpdb->get_var("SELECT paynet_order_id FROM {$wpdb->prefix}payneteasy_payments WHERE (merchant_order_id='".$this->order->get_id()."')");
		}
	}
}

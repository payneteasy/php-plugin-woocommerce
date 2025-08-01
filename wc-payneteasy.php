<?php
/**
 * Plugin Name: Payment system PAYNETEASY
 * Description: Allows you to use Payment system PAYNETEASY with the WooCommerce plugin.
 * Version: 1.0.0
 * Author: Payneteasy
 * Author URI: https://payneteasy.com/
 * Text Domain: wc-payneteasy
 * Domain Path: /languages/
 * Requires PHP: 7.4
 *
 * @package Payneteasy
 * @version 1.0.0
 */

if (!defined('ABSPATH')) exit; // Exit if accessed directly

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/hooks.php';

use \Payneteasy\Classes\Api\PaynetApi,
	\Payneteasy\Classes\Common\PayneteasyLogger,
	\Payneteasy\Classes\Exception\PayneteasyException;


add_action('plugins_loaded', 'init_wc_paynet_payment_gateway');

/**
 * Initialize Payment system PAYNETEASY.
 */
function init_wc_paynet_payment_gateway(): void {
	// if the WC payment gateway class is not available, do nothing
	if (!class_exists('WC_Payment_Gateway') || class_exists('WC_Payneteasy'))
		return;

	class WC_Payneteasy extends WC_Payment_Gateway {
		private string $endpoint_id;
		private string $login;
		private string $control_key;
		private string $payment_method;
		private bool $sandbox;
		private bool $logging;
		private bool $three_d_secure;
		private string $transaction_end;
		private string $live_url;
		private string $sandbox_url;
		private string $notify_url;
		private bool $require_ssn;

		private object $logger;
		private object $order;

		function __construct() {
			$plugin_dir = plugin_dir_url(__FILE__);

			$this->id = 'wc_payneteasy';
			$this->icon = apply_filters('woocommerce_payneteasy_icon', $plugin_dir . 'payneteasy.png');
			$this->method_title = __('Payment system PAYNETEASY', 'wc-payneteasy');
			$this->method_description = __('Plugin "PAYNET Payment System" for WooCommerce, which allows you to integrate online payments.', 'wc-payneteasy');
			$this->has_fields = false;

			// Load the settings
			$this->init_form_fields();
			$this->init_settings();

			// Define user set variables.
			$this->title        = $this->get_option('title');
			$this->description  = $this->get_option('description');
			$this->instructions = $this->get_option('instructions');
			$this->enabled      = $this->get_option('enabled');

			// Initialize settings
			$this->init_payment_settings();

			$this->set_payneteasy_logger();

			//process settings with parent method
			add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
			add_action('woocommerce_api_wc_payneteasy_return', [$this, 'return_handler']);
			add_action('woocommerce_api_wc_payneteasy_webhook', [$this, 'webhook_handler']);
			// handler for checking and editing order status.
			add_action('woocommerce_api_' . $this->id . '_ajax', [$this, 'ajax_handler']);


		}

		/**
         * Инициализация настроек
         *
         * @return void
         */
		private function init_payment_settings(): void {
			// Settings
			$settings = [ 'login', 'control_key', 'endpoint_id', 'payment_method', 'three_d_secure', 'live_url', 'sandbox_url', 'notify_url', 'transaction_end' ];
			foreach ($settings as $setting)
				$this->$setting = $this->get_option($setting);

			// Booleans
			$booleanSettings = [ 'sandbox', 'logging', 'three_d_secure', 'require_ssn' ];
			foreach ($booleanSettings as $setting) {
				$this->$setting = $this->get_option($setting) === 'yes';
			}
		}


		public function init_form_fields(): void
			{ $this->form_fields = include 'form_fields.php'; }

		private function set_order(int $order_id): self {
			if ($order = wc_get_order($order_id)) {
				$this->order = $order;
				return $this;
			}
			else
				throw new \Exception(__('Order not found.', 'wc-payneteasy'));
			
		}

		/**
         * Обработка оплаты заказа в WooCommerce с помощью платежной системы PAYNET.
         * должно быть совместимо с WC_Payment_Gateway::process_payment($order_id)
         *
         * @param int $order_id Идентификатор заказа.
         *
         * @return array|null Массив, содержащий результат и URL перенаправления, или null, если произошла ошибка.
         */
//        public function process_payment($order_id): ?array
//        {
//            $this->logger->setOption('additionalCommonText', 'payment-' . rand(1111, 9999));
//
//            try {
//                $this->set_order($order_id);
//
//                if ($this->order->get_payment_method() != $this->id) {
//                    throw new \Exception(__('Payment method not "PAYNET Payment System".', 'wc-payneteasy'));
//                }
//
//                $response = $this->get_pay_url();
//                $status = $this->get_payment_status();
//                $reset_cart = false;
//                if (trim($status['status']) == 'processing') {
//                    $reset_cart = true;
					//Set order status pending payment
//                    $this->order->update_status('pending', __('Payment link generated:', 'wc-payneteasy') . $response);
//                WC()->cart->empty_cart();
//                    if ($this->payment_method == 'form') {
//                        return [
//                            'result' => 'success',
//                            'redirect' => $response
//                        ];
//                    } elseif ($this->payment_method == 'direct') {
//                        if ($this->three_d_secure) {
//                            print $status['html'];
//                        }
//                    }
//                } elseif (trim($status['status']) == 'approved') {
//                    $reset_cart = true;
//                    return [
//                        'result' => 'success',
//                        'redirect' => home_url(sprintf('/wc-api/%s_return', $this->id))
//                    ];
//                } elseif (trim($status['status']) == 'error') {
//                    $reset_cart = true;
//                } elseif (trim($status['status']) == 'declined') {
//                    $reset_cart = true;
//                }

				//once the order is updated clear the cart and reduce the stock
//                if ($reset_cart)
//                WC()->cart->empty_cart();

//            } catch (\Exception | PayneteasyException $e) {
//                // Handle exception and log error
//                $context = [
//                    'file_exception' => $e->getFile(),
//                    'line_exception' => $e->getLine(),
//                ];
//                if (method_exists($e, 'getContext')) $context = array_merge($e->getContext(), $context);
//
//                $this->logger->error(sprintf(
//    								__FUNCTION__ . ' > Payneteasy exception : %s; Order id: %s;',
//    								$e->getMessage(),
//    								$order_id ?: ''
//    						), $context);
//
//                wc_add_notice($e->getMessage(), 'error');
//                return null;
//            }
//        }

		public function process_payment($order_id): ?array {
			$this->logger->setOption('additionalCommonText', 'payment-' . rand(1111, 9999));

			try {
				$this->set_order($order_id);

				if ($this->order->get_payment_method() != $this->id)
					throw new \Exception(__('Payment method not "VTB Payment System".', 'wc-vtbpay'));
				

				$pay_url = $this->get_pay_url();

				//Set order status pending payment
				$this->order->update_status('pending', __('Payment link generated:', 'wc-vtbpay') . $pay_url['redirect-url']?:'');

				//once the order is updated clear the cart and reduce the stock
				WC()->cart->empty_cart();

				if ($this->payment_method == 'form') {
					return [
                        'result' => 'success',
                        'redirect' => $pay_url['redirect-url']
					];
				} elseif ($this->payment_method == 'direct') {
                        return [
                            'result' => 'success',
                            'redirect' => get_site_url() . sprintf('?wc-api=%s_return', $this->id) . '&orderId=' . $order_id
                        ];
				}
			} catch (\Exception | VtbPayException $e) {
				// Handle exception and log error
				$context = [
					'file_exception' => $e->getFile(),
					'line_exception' => $e->getLine(),
				];
				if (method_exists($e, 'getContext')) $context = array_merge($e->getContext(), $context);

				$this->logger->error(sprintf(
					__FUNCTION__ . ' > VtbPay exception : %s; Order id: %s;',
					$e->getMessage(),
					$order_id ?: ''
				), $context);

				wc_add_notice($e->getMessage(), 'error');
				return null;
			}
		}

		private function get_pay_url() {
			$order_id = $this->order->get_id();
			$email = $this->order->get_billing_email();
			$total = $this->order->get_total();
			$return_url = home_url(sprintf('/wc-api/%s_return', $this->id)); // Get return URL

			$payneteasy_card_number       = $_POST[ 'credit_card_number' ]?:'';
			$payneteasy_card_expiry_month = $_POST[ 'expire_month' ]?:'';
			$payneteasy_card_expiry_year  = $_POST[ 'expire_year' ]?:'';
			$payneteasy_card_name         = $_POST[ 'card_printed_name' ]?:'';
			$payneteasy_card_cvv          = $_POST[ 'cvv2' ]?:'';
			$payneteasy_ssn								= $_POST[ 'ssn' ]?:'';

			$card_data = [
				'credit_card_number' => $payneteasy_card_number?:'',
				'card_printed_name' => $payneteasy_card_name?:'',
				'expire_month' => $payneteasy_card_expiry_month?:'',
				'expire_year' => $payneteasy_card_expiry_year?:'',
				'cvv2' => $payneteasy_card_cvv?:'',
				'ssn' => $payneteasy_ssn
			];

			$wc_api_return_url = get_option('permalink_structure') == ''
							? get_site_url() . sprintf('?wc-api=%s_return', $this->id) . '&orderId=' . $order_id
              : get_site_url() . sprintf('/wc-api/%s_return', $this->id) . '&orderId=' . $order_id;

			$data = [
				'client_orderid' => (string)$order_id,
				'order_desc' => 'Order # ' . $order_id,
				'amount' => $total,
				'currency' => $this->order->get_currency(),
				'address1' => $this->order->get_shipping_address_1()?:$this->order->get_billing_address_1(),
				'city' => $this->order->get_shipping_city()?:$this->order->get_billing_city(),
				'zip_code' => $this->order->get_shipping_postcode()?:$this->order->get_billing_postcode(),
				'country' => $this->order->get_shipping_country()?:$this->order->get_billing_country(),
				'phone'      => $this->order->get_shipping_phone()?:$this->order->get_billing_phone(),
				'email'      => $email,
				'ipaddress' => $_SERVER['REMOTE_ADDR'],
				'cvv2' => $card_data['cvv2'],
				'ssn' => $card_data['ssn'],
				'credit_card_number' => $card_data['credit_card_number'],
				'card_printed_name' => $card_data['card_printed_name'],
				'expire_month' => $card_data['expire_month'],
				'expire_year' => $card_data['expire_year'],
				'first_name' => $this->order->get_shipping_first_name()?:$this->order->get_billing_first_name(),
				'last_name'  => $this->order->get_shipping_last_name()?:$this->order->get_billing_last_name(),
				'redirect_success_url' => $wc_api_return_url, # $this->get_return_url($this->order),
				'redirect_fail_url' => $wc_api_return_url, # wc_get_cart_url(),
				'redirect_url' => $wc_api_return_url,
				'notify_url' => sprintf($this->notify_url, $order_id),
				'server_callback_url' => $wc_api_return_url,
			];

			$data['control'] = $this->signPaymentRequest($data, $this->endpoint_id, $this->control_key);

			// Logging input
			$this->logger->debug(
				__FUNCTION__ . ' > getOrderLink - INPUT: ', [
				'arguments' => [
					'order_id' => $order_id,
					'email' => $email,
					'time' => time(),
					'total' => $total,
					'return_url' => $return_url,
				]
			]);

			$action_url = $this->live_url;
			if ($this->sandbox)
				$action_url = $this->sandbox_url;


			if ($this->payment_method == 'form') {
				$response = $this->get_paynet_api()->saleForm(
					$data,
					$this->payment_method,
					$this->sandbox,
					$action_url,
					$this->endpoint_id
				);
			} elseif ($this->payment_method == 'direct') {
				$response = $this->get_paynet_api()->saleDirect(
					$data,
					$this->payment_method,
					$this->sandbox,
					$action_url,
					$this->endpoint_id
				);
			}

			global $wpdb;
			$wpdb->insert(
				$wpdb->prefix.'payneteasy_payments',
				array(
					'paynet_order_id' => $response['paynet-order-id'],
					'merchant_order_id' => $response['merchant-order-id'],
				)
			);

    				// Logging output
    				$this->logger->debug(
				__FUNCTION__ . ' > getOrderLink - OUTPUT: ', [
    						'response' => $response
    				]);

			return $response;
		}


		/**
         * Отображение описания платежной системы PAYNET при оформлении заказа.
         *
         * @return void
         */
		public function payment_fields(): void {
			if ($this->payment_method == 'direct') {
				if (!empty($this->description))
					echo wpautop(wptexturize($this->description));
				
				echo '<fieldset id="wc-' . esc_attr( $this->id ) . '-cc-form" class="wc-credit-card-form wc-payment-form" style="background:transparent;">';
				do_action( 'woocommerce_credit_card_form_start', $this->id );
				echo '<div class="form-row form-row-wide"><label>Card Number <span class="required">*</span></label>
		<input id="credit_card_number" name="credit_card_number" type="text" autocomplete="cc-number">
		</div>
		<div class="form-row form-row-wide"><label>Printed name <span class="required">*</span></label>
		<input id="card_printed_name" name="card_printed_name" type="text" autocomplete="cc-name" placeholder="Printed name">
		</div>
		<div class="form-row form-row-first">
			<label>Expiry month <span class="required">*</span></label>
			<input minlength="2" maxlength="2" name="expire_month" id="expire_month" type="text" autocomplete="off" placeholder="MM" style="max-width: 50%">
		</div>
		<div class="form-row form-row-last">
			<label>Expiry year <span class="required">*</span></label>
			<input minlength="4" maxlength="4" name="expire_year" id="expire_year" type="text" autocomplete="off" placeholder="YYYY" style="max-width: 50%">
		</div>
		<div class="form-row form-row-first">
			<label>CVC <span class="required">*</span></label>
			<input minlength="3" maxlength="4" name="cvv2" id="cvv2" autocomplete="cc-csc" type="password" style="max-width: 50%">
		</div>';

			if ($this->require_ssn)
				echo '<div class="form-row form-row-wide">
			<label>Document Number (CPF) <span class="required">*</span></label>
			<input name="ssn" id="ssn" autocomplete="cc-ssn">
		</div>';

				echo '<div class="clear"></div>';

				do_action( 'woocommerce_credit_card_form_end', $this->id );

				echo '<div class="clear"></div></fieldset>';
			} elseif ($this->payment_method == 'form') {
				if (!empty($this->description))
					echo wpautop(wptexturize($this->description));
				
			}
		}


		public function validate_fields(){
			if ($this->payment_method == 'direct') {
				if( empty( $_POST[ 'cvv2' ] ) ) {
					wc_add_notice( 'cvv2 is required!', 'error' );
					return false;
				}
				if( empty( $_POST[ 'expire_year' ] ) ) {
					wc_add_notice( 'Expiry year is required!', 'error' );
					return false;
				}
				if( empty( $_POST[ 'expire_month' ] ) ) {
					wc_add_notice( 'Expiry month is required!', 'error' );
					return false;
				}
				if( empty( $_POST[ 'card_printed_name' ] ) ) {
					wc_add_notice( 'Printed name is required!', 'error' );
					return false;
				}
				if( empty( $_POST[ 'credit_card_number' ] ) ) {
					wc_add_notice( 'Card Number is required!', 'error' );
					return false;
				}
				if( empty( $_POST[ 'ssn' ] ) ) {
					wc_add_notice( 'CPF is required!', 'error' );
					return false;
				}
			}
			return true;
		}


		/**
         * Обработчик return, вызываемый при переходе на страницу return_url, после попытки оплаты.
         *
         * @return void
         */
		public function return_handler(): void {
			$this->logger->setOption('additionalCommonText', 'return-' . rand(1111, 9999));
			$order_id = $_GET['orderId'] ?? null;

			try {
				if (empty($order_id))
					throw new \Exception(__('Order ID is empty.', 'wc-payneteasy'));
				

				// Logging $_REQUEST
				$this->logger->debug(
					__FUNCTION__ . ' > return: ', [
					'request_data' => $_GET
				]);

				$this->set_order($order_id);

				$payment_status = $this->get_payment_status();

				$this->change_payment_status(trim($payment_status['status']));

				if ($this->payment_method == 'form') {
					if (trim($payment_status['status']) == 'approved') {
//                        $redirect_url = $this->get_return_url($this->order);
//                        $redirect_url = $this->order->get_checkout_order_received_url();
//                        wp_redirect($redirect_url);
                        echo '<div style="width: 100%; text-align: center"><div><h1>Your payment was approved. Thank you.</h1></div><div><a href="'.get_site_url().'">Return homepage</a></div></div>';
                        die();
					} elseif (trim($payment_status['status']) == 'error') {
//                        $redirect_url = str_replace('&amp;', '&', $this->order->get_cancel_order_url());
//                        wp_redirect($redirect_url);
                        echo '<div style="width: 100%; text-align: center"><div><h1>Your payment was not completed because of an error. Could you try again.</h1></div><div><a href="'.get_site_url().'">Return homepage</a></div></div>';
                        die();
					} elseif (trim($payment_status['status']) == 'declined') {
//                        $redirect_url = str_replace('&amp;', '&', $this->order->get_cancel_order_url());
//                        wp_redirect($redirect_url);
                        echo '<div style="width: 100%; text-align: center"><div><h1>Your payment was declined. Could you try again.</h1></div><div><a href="'.get_site_url().'">Return homepage</a></div></div>';
                        die();
					}
				} elseif ($this->payment_method == 'direct') {
					if (trim($payment_status['status']) == 'processing') {
                        if ($this->three_d_secure) {
                            echo $payment_status['html'];
                            die();
                        }
					} elseif (trim($payment_status['status']) == 'approved') {
//                        $redirect_url = $this->get_return_url($this->order);
//                        $redirect_url = $this->order->get_checkout_order_received_url();
//                        wp_redirect($redirect_url);
                        echo '<div style="width: 100%; text-align: center"><div><h1>Your payment was approved. Thank you.</h1></div><div><a href="'.get_site_url().'">Return homepage</a></div></div>';
                        die();
					} elseif (trim($payment_status['status']) == 'error') {
//                        $redirect_url = str_replace('&amp;', '&', $this->order->get_cancel_order_url());
//                        wp_redirect($redirect_url);
                        echo '<div style="width: 100%; text-align: center"><div><h1>Your payment was not completed because of an error. Could you try again.</h1></div><div><a href="'.get_site_url().'">Return homepage</a></div></div>';
                        die();
					} elseif (trim($payment_status['status']) == 'declined') {
//                        $redirect_url = str_replace('&amp;', '&', $this->order->get_cancel_order_url());
//                        wp_redirect($redirect_url);
                        echo '<div style="width: 100%; text-align: center"><div><h1>Your payment was declined. Could you try again.</h1></div><div><a href="'.get_site_url().'">Return homepage</a></div></div>';
                        die();
					}
				}


			} catch (\Exception | PayneteasyException $e) {
				// Handle exception and log error
				$context = [
					'file_exception' => $e->getFile(),
					'line_exception' => $e->getLine(),
				];
				if (method_exists($e, 'getContext')) $context = array_merge($e->getContext(), $context);

				$this->logger->error(sprintf(
					__FUNCTION__ . ' > Payneteasy exception : %s; Order id: %s;',
					$e->getMessage(),
					$order_id ?: ''
				), $context);

				wp_die($e->getMessage());
			}
		}


		/**
         * Обработчик return, вызываемый при переходе на страницу return_url, после попытки оплаты.
         *
         * @return void
         */
		public function webhook_handler(): void {
			$this->logger->setOption('additionalCommonText', 'webhook-' . rand(1111, 9999));
			$php_input = json_decode(file_get_contents('php://input'), true) ?: null;
			$order_id = $php_input['object']['orderId'] ?? '';

			try {
				if (empty($order_id))
					throw new \Exception(__('Order ID is empty.', 'wc-payneteasy'));
				

				// Logging php input
				$this->logger->debug(
					__FUNCTION__ . ' > callback: ', [
					'php_input' => $php_input
				]);

				$this->set_order($order_id);

				if ($this->order->get_status() === $php_input['object']['status']['value']) die('OK');

				$payment_status = $this->get_payment_status();

				$this->change_payment_status(trim($payment_status['status']));

				die('OK');

			} catch (\Exception | PayneteasyException $e) {
				// Handle exception and log error
				$context = [
					'file_exception' => $e->getFile(),
					'line_exception' => $e->getLine(),
				];
				if (method_exists($e, 'getContext')) $context = array_merge($e->getContext(), $context);

				$this->logger->error(sprintf(
					__FUNCTION__ . ' > Payneteasy exception : %s; Order id: %s;',
					$e->getMessage(),
					$order_id ?: ''
				), $context);

				wp_die($e->getMessage());
			}
		}


		/**
         * Устанавливаем статус заказа
         *
         * @param string $payment_status Статус платежа
         *
         * @return bool
         */
		private function change_payment_status(string $payment_status): bool {
			$available_statuses = [
				'approved' => fn() => $this->actions_for_paid_order(),
				'processing' => fn() => $this->actions_for_hold_order()
			];

			$order_status_fnc = $available_statuses[$payment_status] ?? false;

			if (!empty($order_status_fnc)) $order_status_fnc();
			else $this->actions_for_unpaid_order(); // Payment not completed

			return !empty($order_status_fnc);
		}


		/**
         * Проверяем статус платежа, и если он approved, то устанавливаем заказ в CMS как оплаченный.
         *
         * @return void
         */
		public function ajax_handler(): void {
			$this->logger->setOption('additionalCommonText', 'ajax-' . rand(1111, 9999));
			// Получает данные из AJAX-запроса через $_POST
			$order_id = $_POST['order_id'] ?? '';
			$action = $_POST['action'] ?? '';

			// Logging php input
			$this->logger->debug(
				__FUNCTION__ . ' > ajax: ', [
				'post' => $_POST
			]);

			try {
				if (!wp_verify_nonce($_POST['nonce'] ?? '', 'payneteasy-ajax-nonce'))
					throw new \Exception(__('Failed ajax validation.', 'wc-payneteasy'));
				

				if (empty($order_id))
					throw new \Exception(__('Order ID is empty.', 'wc-payneteasy'));
				

				if (empty($action))
					throw new \Exception(__('Required action not specified.', 'wc-payneteasy'));
				

				// Logging php input
				$this->logger->debug(
					__FUNCTION__ . ' > ajax: ', [
					'post' => $_POST
				]);

				$this->set_order($order_id);

				$payment_status = $this->get_payment_status();

				if ($action == 'refund') {
					$this->make_chargeback();

					$message = __('Payment refunded.', 'wc-payneteasy');
				}
				elseif ($action == 'check_status') {
					$this->change_payment_status(trim($payment_status['status']));

					$message = __('Status updated.', 'wc-payneteasy');
				}

				// Отправьте JSON-ответ и завершите выполнение скрипта
				wp_send_json([
					'success' => true,
					'message' => $message
				]);

			} catch (\Exception | PayneteasyException $e) {
				// Handle exception and log error
				$context = [
					'file_exception' => $e->getFile(),
					'line_exception' => $e->getLine(),
				];
				if (method_exists($e, 'getContext')) $context = array_merge($e->getContext(), $context);

				$this->logger->error(sprintf(
					__FUNCTION__ . ' > Payneteasy exception : %s; Order id: %s;',
					$e->getMessage(),
					$order_id ?: ''
				), $context);

				wp_send_json([
					'success' => false,
					'message' => $e->getMessage()
				]);
			}
		}


		/**
         * Отправляем запрос на возврат в PAYNET, возвращвем результат запроса и логируем входящие и выходящие данные.
         * Если запрос прошёл успешно, то и в CMS отображаем информацию о возврате.
         *
         * @return array
         */
		private function make_chargeback(): array {
			$order_id = $this->order->get_id();

			$amount = $_POST['refund_amount'] ?? 0;

			if (empty($amount)) throw new \Exception(__('Refund amount not specified.', 'wc-payneteasy'));

			$amount = self::parse_amount($amount);

			$email = $this->order->get_billing_email();


			// Logging input
			$this->logger->debug(
				__FUNCTION__ . ' > setRefunds - INPUT: ', [
				'arguments' => [
					'order_id' => $order_id,
					'amount' => $amount,
					'email' => $email,
				]
			]);

			global $wpdb;
			$paynet_order_id = $wpdb->get_var("SELECT paynet_order_id FROM {$wpdb->prefix}payneteasy_payments WHERE (merchant_order_id = '" . $order_id . "')");

			$data = [
				'login' => $this->login,
				'client_orderid' => $order_id,
				'orderid' => $paynet_order_id,
				'comment' => 'Order cancel '
			];

			$data['control'] = $this->signPaymentRequest($data, $this->endpoint_id, $this->control_key);

			$action_url = $this->live_url;
			if ($this->sandbox)
				$action_url = $this->sandbox_url;

			$response = $this->get_paynet_api()->return(
				$data,
				$this->payment_method,
				$this->sandbox,
				$action_url,
				$this->endpoint_id
			);

			// Logging output
			$this->logger->debug(
				__FUNCTION__ . ' > setRefunds - OUTPUT: ', [
				'response' => $response
			]);

			return $response;
		}


		/**
         * Подготавливает элементы для возврата.
         *
         * @param array $items Элементы для подготовки.
         * @return array Массив подготовленных элементов.
         * @throws \Exception Если отсутствуют необходимые данные для возврата.
         */
         private function preparing_items_for_refunds(array $items): array {
             $refund_order_item_qty = $_POST['refund_order_item_qty'] ?? [];
             $refund_line_total = $_POST['refund_line_total'] ?? [];

             if (empty($refund_order_item_qty) || empty($refund_line_total))
                 throw new \Exception(__('The required data for a refund was not transmitted.', 'wc-payneteasy'));
             

             $prepared_items = [];

             foreach ($items as $item) {
                 $item_id = explode('-', $item['code'])[0] ?? '';
                 $quantity = $refund_order_item_qty[$item_id] ?? 0;
                 $amount = $refund_line_total[$item_id] ?? 0;

                 if (empty($quantity) && empty($amount)) continue;

                 if (!empty($amount)) $item['amount'] = self::parse_amount($amount);
                 else $item['amount'] = (int) $quantity * $item['price'];

                 if (!empty($quantity)) $item['quantity'] = (int) $quantity;

                 $item['price'] = $item['amount'] / $item['quantity'];

                 // Добавляем элемент в новый массив
                 $prepared_items[] = $item;
             }

             return $prepared_items;
         }


		/**
         * Преобразует строку в число с плавающей запятой.
         *
         * @param mixed $amount Сумма для преобразования.
         * @return float Преобразованное значение суммы.
         */
		private static function parse_amount($amount): float {
			if (is_string($amount))
				$amount = str_replace([' ', ','], ['', '.'], $amount);
			

			return floatval($amount);
		}


		/**
         * Обработка действий для оплаченного заказа.
         *
         * @return void
         */
		private function actions_for_paid_order(): void {
			$completed_status = str_replace('wc-', '', $this->transaction_end);
			if ($this->order->get_status() !== $completed_status) {
				$this->order->update_status(
					$completed_status,
					__('Payment completed successfully.', 'wc-payneteasy')
				);
				$this->order->payment_complete();
				wc_reduce_stock_levels($this->order->get_id());
			}
		}


		/**
         * Обработка действий для удержанного заказа.
         *
         * @return void
         */
		private function actions_for_hold_order(): void {
			if ($this->order->get_status() !== 'on-hold') $this->order->update_status(
				'on-hold',
				__('Payment received but not confirmed.', 'wc-payneteasy')
			);
		}


		/**
         * Обработка действий в случае неоплаченного заказа.
         *
         * @return void
         */
		private function actions_for_unpaid_order(): void {
			// Logging an unpaid order
			$this->logger->debug(sprintf(
				__FUNCTION__ . '. ' . __('Payment not paid.', 'wc-payneteasy') . '; Order ID: %s',
				$this->order->get_id()
			));

			if ($this->order->get_status() !== 'failed') $this->order->update_status(
				'failed',
				__('Payment not paid.', 'wc-payneteasy')
			);
			wc_add_notice(__('Payment not paid. Your order has been canceled.', 'wc-payneteasy'), 'error');
		}


		/**
         * Обработка действий в случае возвращённого заказа.
         *
         * @return void
         */
		private function actions_for_refunded_order(): void {
			// Logging an unpaid order
			$this->logger->debug(sprintf(
				__FUNCTION__ . '. ' . __('Refund of payment.', 'wc-payneteasy') . '; Order ID: %s',
				$this->order->get_id()
			));

			if ($this->order->get_status() !== 'refunded') {
              $this->order->update_status(
                  'refunded',
                  __('Refund of payment.', 'wc-payneteasy')
              );
			}
			wc_add_notice(
				__('The payment was refunded.', 'wc-payneteasy'),
				'notice'
			);
		}


		/**
         * Обработка действий в случае частично возвращённого заказа.
         *
         * @return void
         */
		private function actions_for_partially_refunded_order(): void {
			// Logging an unpaid order
			$this->logger->debug(sprintf(
				__FUNCTION__ . '. ' . __('Partial refund of payment.', 'wc-payneteasy') . '; Order ID: %s',
				$this->order->get_id()
			));

			$completed_status = str_replace('wc-', '', $this->transaction_end);
			if ($this->order->get_status() !== $completed_status) {
              $this->order->update_status(
                  $completed_status,
                  __('Partial refund of payment.', 'wc-payneteasy')
              );
			}
			wc_add_notice(
				__('The payment was partial refunded.', 'wc-payneteasy'),
				'notice'
			);
		}


		/**
         * Получение статуса заказа WooCommerce из PAYNET API.
         *
         * @return string Значение статуса заказа, если оно существует, или пустая строка в противном случае.
         */
		private function get_payment_status(): array {
			global $wpdb;
			$paynet_order_id = $wpdb->get_var("SELECT paynet_order_id FROM {$wpdb->prefix}payneteasy_payments WHERE (merchant_order_id = '" . $this->order->get_id() . "')");

			$data = [
				'login' => $this->login,
				'client_orderid' => (string)$this->order->get_id(),
				'orderid' => $paynet_order_id,
			];
			$data['control'] = $this->signStatusRequest($data, $this->login, $this->control_key);

			$action_url = $this->live_url;
			if ($this->sandbox)
				$action_url = $this->sandbox_url;

			// Logging input
			$this->logger->debug(
				__FUNCTION__ . ' > getOrderInfo - INPUT: ', [
				'arguments' => [
					'order_id' => $this->order->get_id(),
					'paynet_order_id' => $paynet_order_id
				]
			]);

			$response = $this->get_paynet_api()->status($data, $this->payment_method, $this->sandbox, $action_url, $this->endpoint_id);

			// Logging output
			$this->logger->debug(
				__FUNCTION__ . ' > getOrderInfo - OUTPUT: ', [
				'response' => $response
			]);

			if (
				!isset($response['status'])
			) {
//            throw new Exception('No information about payment status.');
			}

			return $response;
		}


		/**
         * Инициализация и настройка объекта класса PayneteasyLogger.
         *
         * Эта функция инициализирует и настраивает логгер, используемый плагином Payneteasy для ведения журнала.
         *
         * @return void
         */
    		private function set_payneteasy_logger(): void {
			if (version_compare(WOOCOMMERCE_VERSION, '2.1', '<')) {
				global $woocommerce;
				$wc_log = $woocommerce->logger();
			}
			else
				$wc_log = new WC_Logger();
			
			$logging = $this->logging;

			$this->logger = PayneteasyLogger::getInstance()
                            ->setOption('showCurrentDate', false)
                            ->setOption('showLogLevel', false)
                            ->setCustomRecording(function($message) use ($wc_log){
                                $wc_log->error($message);
                            }, PayneteasyLogger::LOG_LEVEL_ERROR)
                            ->setCustomRecording(function($message) use ($wc_log, $logging){
                                if ($logging) $wc_log->debug($message);
                            }, PayneteasyLogger::LOG_LEVEL_DEBUG);
		}


		private function signStatusRequest($requestFields, $login, $merchantControl) {
			$base = '';
			$base .= $login;
			$base .= $requestFields['client_orderid'];
			$base .= $requestFields['orderid'];

			return $this->signString($base, $merchantControl);
		}


		private function signPaymentRequest($data, $endpointId, $merchantControl) {
			$base = '';
			$base .= $endpointId;
			$base .= $data['client_orderid'];
			$base .= $data['amount'] * 100;
			$base .= $data['email'];

			return $this->signString($base, $merchantControl);
		}


		private function signString($s, $merchantControl)
			{ return sha1($s . $merchantControl); }


		/**
         * Создаем новый экземпляр класса PaynetApi с заданными конфигурациями платёжной системы.
         *
         * @return PaynetApi The new PaynetApi instance.
         */
		protected function get_paynet_api(): PaynetApi {
			return new PaynetApi(
				$this->login,
				$this->control_key,
				$this->endpoint_id,
				$this->payment_method,
				(bool) $this->sandbox
			);
		}
	}
}

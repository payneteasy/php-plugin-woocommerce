<?php
/**
	* Функция add_payneteasy_gateway
	*
	* Добавляет платёжный метод "WC_Payneteasy" в список доступных платёжных методов WooCommerce.
	*
	* @param array $methods - Список доступных платёжных методов
	* @return array - Модифицированный список платёжных методов с добавлением WC_Payneteasy */
function add_payneteasy_gateway(array $methods): array {
	$methods[] = 'WC_Payneteasy';
	global $wpdb;

	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}payneteasy_payments (
		`id` int(11) NOT NULL AUTO_INCREMENT,
		`paynet_order_id` int(11) NOT NULL,
		`merchant_order_id` int(11) NOT NULL,
		PRIMARY KEY  (id)
	) $charset_collate";

	require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
	dbDelta( $sql );

	return $methods;
}

add_filter('woocommerce_payment_gateways', 'add_payneteasy_gateway');

/**
	* Функция load_payneteasy_textdomain
	*
	* Загружает локализацию (текстовый перевод) для плагина WC_Payneteasy. */
function load_payneteasy_textdomain(): void
	{ load_plugin_textdomain('wc-payneteasy', false, dirname(plugin_basename(__FILE__)) . '/languages'); }

add_action('plugins_loaded', 'load_payneteasy_textdomain');

/**
	* Функция get_payneteasy_field_options
	*
	* Возвращает список доступных опций для полей настроек WC_Payneteasy на основе идентификатора поля.
	*
	* @param string $field_id - Идентификатор поля настроек
	* @return array - Список опций для поля */
function get_payneteasy_field_options($field_id): array {
	$options = [ '-' => __('Select an option', 'wc-payneteasy') ];
	# Определяет опции для конкретного поля
	switch ($field_id) {
		case 'payneteasy_payment_method':
			$options = array_merge($options, [ 'form' => __('Form', 'wc-payneteasy'), 'direct' => __('Direct', 'wc-payneteasy') ]);
			break;
	}

	return $options;
}

/**
* Функция adding_payneteasy_button_to_orders_page
	*
	* Добавляет скрипты и стили к странице заказов и настройкам WC_Payneteasy в административной части.
	*
	* @param string $hook - Идентификатор страницы в административной части WordPress */
function adding_payneteasy_button_to_orders_page($hook): void {
	# Проверяет наличие настроек WC_Payneteasy и условия для добавления скриптов и стилей
	$payneteasy_settings = get_option('woocommerce_wc_payneteasy_settings');
	global $post;

	if (($hook == 'post-new.php' || $hook == 'post.php') && !empty($post) && $post->post_type === 'shop_order') {
		$order_id = $post->ID;
		$order = wc_get_order($order_id);
		$order_status = $order->get_status();
		$payment_method = $order->get_payment_method();

		if ($payment_method == 'wc_payneteasy' && !in_array($order_status, ['failed','refunded','cancelled'])) {
			# Подключает скрипт для работы с заказами WC_Payneteasy
			wp_enqueue_script('payneteasy-order-page-script',
				plugins_url('/assets/js/payneteasy-order-page-script.js', __FILE__), ['jquery'], '1.0', true);

			wp_localize_script(
				'payneteasy-order-page-script',
				'payneteasy_ajax_var', [ 'nonce' => wp_create_nonce('payneteasy-ajax-nonce'), 'api_url' => home_url('/wc-api/wc_payneteasy_ajax') ]);
		}
	}
}

add_action('admin_enqueue_scripts', 'adding_payneteasy_button_to_orders_page');

function register_chargeback_status(array $statuses): array {
	return array_merge($statuses, [
		'wc-chargeback' => [
			'label' => _x('Chargeback', 'Order status', 'payneteasy'),
			'public' => false, 
			'exclude_from_search' => false,
			'show_in_admin_all_list' => true,
			'show_in_admin_status_list' => true,
			'label_count' => _n_noop('Chargeback <span class="count">(%s)</span>', 'Chargeback <span class="count">(%s)</span>', 'payneteasy') ] ]);
}

add_action('woocommerce_register_shop_order_post_statuses', 'register_chargeback_status');

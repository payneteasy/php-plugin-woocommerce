<?php
return [
	'enabled' => [
		'title' => __('Enable/Disable', 'woocommerce'),
		'type' => 'checkbox',
		'label' => __('Enable Payment system PAYNETEASY', 'wc-payneteasy'),
		'default' => 'yes' ],
	'title' => [
		'title' => __('Title', 'woocommerce'),
		'type' => 'text',
		'description' => __('This controls the title which the user sees during checkout.', 'woocommerce'),
		'default' => __('Payment system PAYNETEASY', 'wc-payneteasy'),
		'desc_tip' => true ],
	'description' => [
		'title' => __('Customer Message', 'wc-payneteasy'),
		'type' => 'textarea',
		'css' => 'width:500px',
		'default' => __('Pay with PaynetEasy payment', 'wc-payneteasy'),
		'description' => __('The message which you want it to appear to the customer in the checkout page.', 'wc-payneteasy') ],
	'LIVE_URL' => [
		'title' => __('Gateway url (LIVE)', 'wc-payneteasy'),
		'type' => 'text',
		'description' => __("https://gate.payneteasy.com/ etc.", 'wc-payneteasy'),
		'placeholder' => __('Enter live url.', 'wc-payneteasy') ],
	'SANDBOX_URL' => [
		'title' => __('Gateway url (SANDBOX)', 'wc-payneteasy'),
		'type' => 'text',
		'description' => __("https://sandbox.payneteasy.com/ etc.", 'wc-payneteasy'),
		'placeholder' => __('Enter sandbox url.', 'wc-payneteasy') ],
	'IS_LIVE' => [
		'title' => __('Live mode', 'wc-payneteasy'),
		'type' => 'checkbox',
		'label' => __('Enable live mode', 'wc-payneteasy'),
		'description' => __('Sandbox or Live.', 'wc-payneteasy'),
		'default' => 'no' ],
	'IS_FORM' => [
		'title' => __('Integration method', 'wc-payneteasy'),
		'type' => 'checkbox',
		'label' => __('Form', 'wc-payneteasy'),
		'description' => __('Direct or Form.', 'wc-payneteasy'),
		'default' => 'no' ],
	'END_POINT' => [
		'title' => __('Endpoint id', 'wc-payneteasy') .' <span style="color:red">*<span/>',
		'type' => 'text',
		'description' => __('Merchant\'s Endpoint id is required to call the API.', 'wc-payneteasy'),
		'placeholder' => __('Enter Endpoint id', 'wc-payneteasy'),
		'custom_attributes' => ['required' => 'required'] ],
	'IS_MULTICURR' => [
		'title' => __('Multiple currencies', 'wc-payneteasy'),
		'type' => 'checkbox',
		'label' => __('Support multiple currencies', 'wc-payneteasy'),
		'description' => __('Allow using more than just one currency.', 'wc-payneteasy'),
		'default' => 'no' ],
	'LOGIN' => [
		'title' => __('Login', 'wc-payneteasy') .' <span style="color:red">*<span/>',
		'type' => 'text',
		'description' => __('Merchant\'s Login is required to call the API.', 'wc-payneteasy'),
		'placeholder' => __('Enter Login', 'wc-payneteasy'),
		'custom_attributes' => ['required' => 'required'] ],
	'CONTROL_KEY' => [
		'title' => __('Control key', 'wc-payneteasy') .' <span style="color:red">*<span/>',
		'type' => 'text',
		'description' => __('Merchant\'s Control key is required to call the API.', 'wc-payneteasy'),
		'placeholder' => __('Enter Control key', 'wc-payneteasy'),
		'custom_attributes' => ['required' => 'required'] ],
	'IS_SSN_REQUIRED' => [
		'title' => __('Require CPF', 'wc-payneteasy'),
		'type' => 'checkbox',
		'label' => __('Require Document Number (CPF)', 'wc-payneteasy'),
		'description' => __('Show CPF input field at checkout page.', 'wc-payneteasy'),
		'default' => 'no' ],
	'notify_url' => [
		'title' => __('Notify url', 'wc-payneteasy'),
		'type' => 'text',
		'description' => __('Notify gate url sprintf mask, order_id being parameter.', 'wc-payneteasy'),
		'placeholder' => __('Enter notify gate url mask.', 'wc-payneteasy') ],
	'transaction_end' => [
		'title' => __('Successful transaction order status', 'wc-payneteasy'),
		'type' => 'select',
		'options' => wc_get_order_statuses(),
		'description' => __('Select the order status to be displayed after successful payment.', 'wc-payneteasy'),
		'default' => 'wc-processing' ] ];

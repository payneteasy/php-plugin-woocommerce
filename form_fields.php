<?php
return [
    'enabled' => [
        'title' => __('Enable/Disable', 'woocommerce'),
        'type' => 'checkbox',
        'label' => __('Enable Payment system PAYNETEASY', 'wc-payneteasy'),
        'default' => 'yes'
    ],
    'title' => [
        'title' => __('Title', 'woocommerce'),
        'type' => 'text',
        'description' => __('This controls the title which the user sees during checkout.', 'woocommerce'),
        'default' => __('Payment system PAYNETEASY', 'wc-payneteasy'),
        'desc_tip' => true,
    ],
    'description' => [
        'title' => __('Customer Message', 'wc-payneteasy'),
        'type' => 'textarea',
        'css' => 'width:500px;',
        'default' => __('Pay with PaynetEasy payment', 'wc-payneteasy'),
        'description' => __('The message which you want it to appear to the customer in the checkout page.', 'wc-payneteasy'),
    ],
    'endpoint_id' => [
        'title' => __('Endpoint id', 'wc-payneteasy') . ' <span style="color:red;">*<span/>',
        'type' => 'text',
        'description' => __('Merchant\'s Endpoint id is required to call the API.', 'wc-payneteasy'),
        'placeholder' => __('Enter Endpoint id', 'wc-payneteasy'),
        'custom_attributes' => ['required' => 'required'],
    ],
    'login' => [
        'title' => __('Login', 'wc-payneteasy') . ' <span style="color:red;">*<span/>',
        'type' => 'text',
        'description' => __('Merchant\'s Login is required to call the API.', 'wc-payneteasy'),
        'placeholder' => __('Enter Login', 'wc-payneteasy'),
        'custom_attributes' => ['required' => 'required'],
    ],
    'control_key' => [
        'title' => __('Control key', 'wc-payneteasy') . ' <span style="color:red;">*<span/>',
        'type' => 'text',
        'description' => __('Merchant\'s Control key is required to call the API.', 'wc-payneteasy'),
        'placeholder' => __('Enter Control key', 'wc-payneteasy'),
        'custom_attributes' => ['required' => 'required'],
    ],
    'payment_method' => [
        'title' => __('Payment method', 'wc-payneteasy'),
        'type' => 'select',
        'description' => __('', 'wc-payneteasy'),
        'placeholder' => __('Enter Payment method', 'wc-payneteasy'),
        'options' => [
            'form' => __('Form', 'wc-payneteasy'),
            'direct' => __('Direct', 'wc-payneteasy')
        ],
        'desc_tip' => true,
        'default' => 'form'
    ],
    'sandbox' => [
        'title' => __('Sandbox mode', 'wc-payneteasy'),
        'type' => 'checkbox',
        'label' => __('Enable sandbox mode', 'wc-payneteasy'),
        'description' => __('In this mode, the payment for the goods is not charged.', 'wc-payneteasy'),
        'default' => 'no'
    ],
    'logging' => [
        'title' => __('Logging', 'wc-payneteasy'),
        'type' => 'checkbox',
        'label' => __('Enable logging', 'wc-payneteasy'),
        'description' => __('Logging is used to debug plugin performance by storing API request data.', 'wc-payneteasy'),
        'default' => 'no'
    ],
    'three_d_secure' => [
        'title' => __('3D Secure', 'wc-payneteasy'),
        'type' => 'checkbox',
        'label' => __('Enable 3D Secure', 'wc-payneteasy'),
        'description' => __('3D Secure or Non 3D Secure (WORK ONLY WITH DIRECT INTEGRATION METHOD)', 'wc-payneteasy'),
        'default' => 'no'
    ],
		'require_ssn' => [
        'title' => __('Require CPF', 'wc-payneteasy'),
        'type' => 'checkbox',
        'label' => __('Require Document Number (CPF)', 'wc-payneteasy'),
        'description' => __('Show CPF input field at checkout page', 'wc-payneteasy'),
        'default' => 'no'
		],
    'live_url' => [
        'title' => __('Gateway url (LIVE)', 'wc-payneteasy'),
        'type' => 'text',
        'description' => __("https://gate.payneteasy.com/ etc.", 'wc-payneteasy'),
        'placeholder' => __('Enter live url.', 'wc-payneteasy'),
    ],
    'sandbox_url' => [
        'title' => __('Gateway url (SANDBOX)', 'wc-payneteasy'),
        'type' => 'text',
        'description' => __("https://sandbox.payneteasy.com/ etc.", 'wc-payneteasy'),
        'placeholder' => __('Enter sandbox url.', 'wc-payneteasy'),
    ],
		'notify_url' => [
        'title' => __('Notify url', 'wc-payneteasy'),
        'type' => 'text',
        'description' => __('Notify gate url sprintf mask, order_id being parameter', 'wc-payneteasy'),
        'placeholder' => __('Enter notify gate url mask.', 'wc-payneteasy'),
    ],
    'transaction_end' => [
        'title' => __('Successful transaction order status', 'wc-payneteasy'),
        'type' => 'select',
        'options' => wc_get_order_statuses(),
        'description' => __('Select the order status to be displayed after successful payment.', 'wc-payneteasy'),
        'default' => 'wc-processing'
    ]
];

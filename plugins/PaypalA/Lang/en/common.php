<?php

return [
    // Payment page
    'title'            => 'PayPal',
    'choose_method'    => 'Choose a payment method. Your order status updates automatically once the payment completes.',
    'order_number'     => 'Order number',
    'amount_payable'   => 'Amount due',
    'session_notice'   => 'This payment session is valid for :minutes minutes. Please do not pay from multiple tabs at the same time.',
    'pay_with_wallet'  => 'Pay with PayPal wallet',
    'pay_with_card'    => 'Pay by credit or debit card',

    // Buyer-facing messages when starting a payment
    'method_disabled'      => 'This PayPal payment method is currently unavailable.',
    'order_not_payable'    => 'This order cannot start a PayPal payment right now.',
    'session_failed'       => 'The PayPal payment session could not be created. Please try again, and contact the merchant if the problem persists.',
    'session_expired'      => 'This PayPal payment session has expired. Please start the payment again.',
    'session_cancelled'    => 'This PayPal payment session was cancelled. Please start the payment again.',
    'session_reconciling'  => 'The PayPal result is still being confirmed. Please do not pay again; try once more in a moment.',
    'method_locked'        => 'This order already has a PayPal session in progress. Please continue with the method you chose first, or wait for the session to expire.',
    'checkout_url_invalid' => 'No usable payment redirect address was returned. Please contact the merchant.',
    'start_failed'         => 'The payment cannot be started at the moment. Please try again shortly.',
    'result_confirming'    => 'The payment result is being confirmed. Please do not pay again.',
    'gateway_unreachable'  => 'The PayPal payment gateway is temporarily unreachable. Please try again shortly.',
    'gateway_unreadable'   => 'The PayPal payment status is temporarily unavailable. Please try again shortly.',

    // Status messages after returning from the payment page
    'status_cancelled'   => 'The PayPal payment was cancelled. You can pay for this order again.',
    'status_failed'      => 'The PayPal payment was not completed. You can pay for this order again.',
    'status_expired'     => 'The payment session has expired. Please start the payment again.',
    'status_reconciling' => 'The PayPal result is still being confirmed. Please do not pay again; try once more in a moment.',
    'status_pending'     => 'The payment is not complete yet. Please finish the payment and come back.',
];

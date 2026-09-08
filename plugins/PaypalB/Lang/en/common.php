<?php

return [
    // Merchant and policies
    'merchant_responsibility' => 'Payment, refunds, customer support and dispute handling for this transaction are provided by the merchant above.',
    'refund_policy'           => 'Refund Policy',
    'privacy_policy'          => 'Privacy Policy',
    'terms_policy'            => 'Terms of Sale',
    'contact_us'              => 'Contact Us',
    'customer_service'        => 'Support',
    'merchant_section'        => 'Merchant of record',
    'policy_available'        => 'Available on this site: Contact, Refund Policy, Privacy Policy and Terms of Sale.',

    // Order summary
    'order_summary'  => 'Order details',
    'order_number'   => 'Order number',
    'amount_payable' => 'Amount due',
    'ship_to'        => 'Ship to',

    // Wallet checkout
    'wallet_title'       => 'Pay with PayPal',
    'wallet_subtitle'    => 'Complete the authorisation with the official PayPal component.',
    'wallet_load_failed' => 'The PayPal wallet component failed to load. Please refresh the page and try again.',
    'wallet_unavailable' => 'The PayPal wallet component cannot be displayed.',
    'wallet_failed'      => 'PayPal wallet payment failed. Please try again.',
    'wallet_cancelled'   => 'PayPal authorisation was cancelled. You can start the payment again.',
    'wallet_incomplete'  => 'The PayPal wallet payment is not complete yet. Please try again shortly.',

    // Card checkout
    'card_title'          => 'Pay by credit or debit card',
    'card_subtitle'       => 'Card details are handled securely by PayPal hosted fields and are never sent to this site.',
    'card_holder_name'    => 'Cardholder name',
    'card_number'         => 'Card number',
    'card_expiry'         => 'Expiry date',
    'card_cvv'            => 'Security code',
    'card_submit'         => 'Confirm card payment',
    'card_load_failed'    => 'The PayPal card component failed to load. Please pay with the PayPal wallet instead.',
    'card_not_eligible'   => 'Card payments are not supported for this PayPal account or region. Please pay with the PayPal wallet instead.',
    'card_failed'         => 'PayPal card payment failed. Please try again.',
    'card_invalid'        => 'PayPal card payment failed. Please check your card details.',
    'card_incomplete'     => 'The card payment is not complete yet. Please try again shortly.',

    // Payment progress
    'payment_completed'   => 'Payment complete. Synchronising your order…',
    'payment_accepted'    => 'PayPal has accepted the payment and is confirming the result…',
    'payment_confirming'  => 'PayPal is still confirming the payment. Your order will update automatically once it settles.',
    'payment_not_done'    => 'The PayPal payment could not be completed.',

    // Error page
    'error_title'      => 'PayPal payment is unavailable',
    'use_wallet'       => 'Pay with PayPal wallet instead',
    'back_to_order'    => 'Back to order',

    // Payment status messages
    'status_completed'   => 'The PayPal payment is complete.',
    'status_failed'      => 'The PayPal payment could not be completed.',
    'status_cancelled'   => 'The PayPal payment was cancelled.',
    'status_expired'     => 'The PayPal payment session has expired.',
    'status_reconciling' => 'The PayPal result is still being confirmed. Please do not pay again.',
    'status_pending'     => 'The PayPal payment result is still being confirmed.',
    'capture_wallet_ok'  => 'The PayPal wallet payment is complete.',
    'capture_card_ok'    => 'The card payment is complete.',
    'capture_pending'    => 'PayPal has accepted the payment; the result is still being confirmed.',
    'capture_failed'     => 'PayPal could not capture the payment. Please try again shortly.',

    // Session-related buyer messages
    'session_ended'        => 'This PayPal payment session has ended. Please start the payment again from the original site.',
    'session_expired'      => 'The payment session has expired.',
    'session_processing'   => 'This payment is being processed. Please check the status shortly.',
    'session_reconciling'  => 'The PayPal result is not confirmed yet. Please try again later or contact the merchant.',
    'session_unavailable'  => 'The PayPal authorisation link is unavailable. Please start the payment again.',
    'method_not_enabled'   => 'This payment method is not enabled for this payment session.',
    'card_not_enabled'     => 'Card payments are currently disabled on this site.',
    'wallet_not_enabled'   => 'PayPal wallet payments are currently disabled on this site.',
    'card_not_supported'   => 'The current PayPal account does not support automatic card payments.',
    'card_component_down'  => 'The PayPal card component is temporarily unavailable. Please pay with the PayPal wallet instead.',
    'capture_token_invalid' => 'The payment token is invalid. Please start the payment again from the original site.',

    // Policy pages
    'contact_title'     => 'Contact Us',
    'contact_email'     => 'Support email: :email',
    'contact_phone'     => 'Support phone: :phone',
    'refund_title'      => 'Refund Policy',
    'refund_body_1'     => 'The refund policy and order terms shown on the payment page apply to this transaction.',
    'refund_body_2'     => 'Refunds are processed by our support team against the order records.',
    'privacy_title'     => 'Privacy Policy',
    'privacy_body_1'    => 'We retain the transaction data required for order processing, payment and compliance purposes.',
    'privacy_body_2'    => 'For details on how data is processed, retained and accessed, please refer to the policy pages on this site.',
    'terms_title'       => 'Terms of Sale',
    'terms_body_1'      => 'This site is responsible for payment, refunds and dispute handling.',
    'terms_body_2'      => 'Please read the order details, refund policy, privacy policy and terms of sale before paying.',

    // B 站自营订单支付
    'local_title'              => 'PayPal',
    'local_subtitle'           => 'Continue to the secure PayPal payment page. Your order status updates automatically once the payment completes.',
    'local_session_notice'     => 'This payment session is valid for :minutes minutes. Please do not pay from multiple tabs at the same time.',
    'local_pay_now'            => 'Pay with PayPal',
    'local_order_not_payable'  => 'This order cannot start a PayPal payment right now.',
    'local_no_method'          => 'No PayPal payment method is enabled on this site. Please contact the administrator.',
];

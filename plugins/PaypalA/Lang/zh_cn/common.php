<?php

return [
    // 支付页
    'title'            => 'PayPal',
    'choose_method'    => '请选择付款方式。付款完成后，订单状态会自动更新。',
    'order_number'     => '订单号',
    'amount_payable'   => '应付金额',
    'session_notice'   => '支付会话有效期为 :minutes 分钟。请勿在多个页面同时付款。',
    'pay_with_wallet'  => '使用 PayPal 钱包付款',
    'pay_with_card'    => '使用信用卡/借记卡付款',

    // 发起支付时的买家提示
    'method_disabled'      => '该 PayPal 付款方式当前未启用。',
    'order_not_payable'    => '该订单当前不能发起 PayPal 支付。',
    'session_failed'       => '创建 PayPal 支付会话失败，请重新发起付款；若反复失败请联系商户。',
    'session_expired'      => '本次 PayPal 支付会话已过期，请重新发起付款。',
    'session_cancelled'    => '本次 PayPal 支付会话已被取消，请重新发起付款。',
    'session_reconciling'  => 'PayPal 创建结果正在确认，请勿重复付款，稍后重新点击付款。',
    'method_locked'        => '该订单已有进行中的 PayPal 会话，请继续使用首次选择的付款方式或等待会话过期。',
    'checkout_url_invalid' => '未能获取可用的支付跳转地址，请联系商户处理。',
    'start_failed'         => '支付暂时无法发起，请稍后重试。',
    'result_confirming'    => '支付结果正在确认，请勿重复付款。',
    'gateway_unreachable'  => '暂时无法连接 PayPal 支付网关，请稍后重试。',
    'gateway_unreadable'   => '暂时无法读取 PayPal 支付状态，请稍后重试。',

    // 回跳后的状态提示
    'status_cancelled'   => '已取消 PayPal 支付，订单仍可重新付款。',
    'status_failed'      => 'PayPal 支付未完成，订单仍可重新付款。',
    'status_expired'     => '支付会话已过期，请重新发起付款。',
    'status_reconciling' => 'PayPal 创建结果正在确认，请勿重复付款，稍后再试。',
    'status_pending'     => '支付尚未完成，请在支付页完成付款后返回。',
];

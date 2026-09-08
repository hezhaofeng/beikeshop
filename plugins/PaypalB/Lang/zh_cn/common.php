<?php

return [
    // 收款主体与政策
    'merchant_responsibility' => '本次交易的收款、退款、客服和争议处理由上述商户负责。',
    'refund_policy'           => '退款政策',
    'privacy_policy'          => '隐私政策',
    'terms_policy'            => '交易条款',
    'contact_us'              => '联系我们',
    'customer_service'        => '客服',
    'merchant_section'        => '收款主体',
    'policy_available'        => '站内可访问：联系、退款政策、隐私政策、交易条款。',

    // 订单摘要
    'order_summary'  => '订单明细',
    'order_number'   => '订单号',
    'amount_payable' => '应付金额',
    'ship_to'        => '收货',

    // 钱包付款页
    'wallet_title'       => 'PayPal 钱包付款',
    'wallet_subtitle'    => '使用 PayPal 官方安全组件完成授权。',
    'wallet_load_failed' => 'PayPal 钱包组件加载失败，请刷新页面后重试。',
    'wallet_unavailable' => 'PayPal 钱包组件无法显示。',
    'wallet_failed'      => 'PayPal 钱包付款失败，请重试。',
    'wallet_cancelled'   => 'PayPal 授权已取消，您可以重新点击付款。',
    'wallet_incomplete'  => 'PayPal 钱包收款尚未完成，请稍后重试。',

    // 信用卡付款页
    'card_title'          => '信用卡/借记卡付款',
    'card_subtitle'       => '卡片信息由 PayPal 托管字段安全处理，不会提交到本站。',
    'card_holder_name'    => '持卡人姓名',
    'card_number'         => '卡号',
    'card_expiry'         => '有效期',
    'card_cvv'            => '安全码',
    'card_submit'         => '确认信用卡付款',
    'card_load_failed'    => 'PayPal 信用卡组件加载失败，请改用 PayPal 钱包付款。',
    'card_not_eligible'   => '当前 PayPal 收款账号或地区暂不支持信用卡付款，请改用 PayPal 钱包付款。',
    'card_failed'         => 'PayPal 信用卡付款失败，请重试。',
    'card_invalid'        => 'PayPal 信用卡付款失败，请检查卡片信息。',
    'card_incomplete'     => '信用卡收款尚未完成，请稍后重试。',

    // 付款过程状态
    'payment_completed'   => '付款已完成，正在同步订单状态…',
    'payment_accepted'    => 'PayPal 已接受付款，正在确认收款状态…',
    'payment_confirming'  => '付款仍在 PayPal 确认中，订单状态会在到账后自动更新。',
    'payment_not_done'    => 'PayPal 付款未能完成。',

    // 错误页
    'error_title'      => 'PayPal 支付暂不可用',
    'use_wallet'       => '改用 PayPal 钱包付款',
    'back_to_order'    => '返回订单',

    // 收款状态消息
    'status_completed'   => 'PayPal 付款已完成。',
    'status_failed'      => 'PayPal 付款未能完成。',
    'status_cancelled'   => 'PayPal 付款已取消。',
    'status_expired'     => 'PayPal 支付会话已过期。',
    'status_reconciling' => 'PayPal 创建结果正在确认，请勿重复付款。',
    'status_pending'     => 'PayPal 收款状态仍在确认。',
    'capture_wallet_ok'  => 'PayPal 钱包付款已完成。',
    'capture_card_ok'    => '信用卡付款已完成。',
    'capture_pending'    => 'PayPal 已接受付款，收款状态仍在确认。',
    'capture_failed'     => 'PayPal 收款捕获失败，请稍后重试。',

    // 支付会话相关的买家提示
    'session_ended'        => '该 PayPal 支付会话已经结束，请从原站点重新发起付款。',
    'session_expired'      => '支付会话已过期。',
    'session_processing'   => '该支付正在处理中，请稍后查询状态。',
    'session_reconciling'  => 'PayPal 创建结果尚未确认，请稍后重试或联系商户处理。',
    'session_unavailable'  => 'PayPal 授权地址暂不可用，请重新发起付款。',
    'method_not_enabled'   => '该支付会话未启用此付款方式。',
    'card_not_enabled'     => '本站当前未启用信用卡收款。',
    'wallet_not_enabled'   => '本站当前未启用 PayPal 钱包收款。',
    'card_not_supported'   => '当前 PayPal 收款账号不支持信用卡自动收款。',
    'card_component_down'  => 'PayPal 信用卡组件暂时不可用，请改用 PayPal 钱包付款。',
    'capture_token_invalid' => '支付捕获令牌无效，请从原站点重新发起付款。',

    // 政策页正文
    'contact_title'     => '联系我们',
    'contact_email'     => '客服邮箱：:email',
    'contact_phone'     => '客服电话：:phone',
    'refund_title'      => '退款政策',
    'refund_body_1'     => '请以支付页展示的退款政策和订单条款为准。',
    'refund_body_2'     => '具体退款流程由本站客服与后台订单记录处理。',
    'privacy_title'     => '隐私政策',
    'privacy_body_1'    => '本站会按订单处理、支付与合规要求保存必要的交易数据。',
    'privacy_body_2'    => '有关数据处理、保留和访问方式，请以站内政策页面为准。',
    'terms_title'       => '交易条款',
    'terms_body_1'      => '本站负责支付、退款与争议处理。',
    'terms_body_2'      => '支付前请阅读订单信息、退款政策、隐私政策和交易条款。',

    // B 站自营订单支付
    'local_title'              => 'PayPal',
    'local_subtitle'           => '点击下方按钮前往 PayPal 安全支付页完成付款。付款完成后，订单状态会自动更新。',
    'local_session_notice'     => '支付会话有效期为 :minutes 分钟。请勿在多个页面同时付款。',
    'local_pay_now'            => '使用 PayPal 付款',
    'local_order_not_payable'  => '该订单当前不能发起 PayPal 支付。',
    'local_no_method'          => '本站未启用任何 PayPal 收款方式，请联系管理员。',
];

<?php

return [
    // 支付頁
    'title'            => 'PayPal',
    'choose_method'    => '請選擇付款方式。付款完成後，訂單狀態會自動更新。',
    'order_number'     => '訂單號',
    'amount_payable'   => '應付金額',
    'session_notice'   => '支付工作階段有效期為 :minutes 分鐘。請勿在多個頁面同時付款。',
    'pay_with_wallet'  => '使用 PayPal 錢包付款',
    'pay_with_card'    => '使用信用卡／扣賬卡付款',

    // 發起支付時的買家提示
    'method_disabled'      => '該 PayPal 付款方式目前未啟用。',
    'order_not_payable'    => '該訂單目前不能發起 PayPal 支付。',
    'session_failed'       => '建立 PayPal 支付工作階段失敗，請重新發起付款；若反覆失敗請聯絡商戶。',
    'session_expired'      => '本次 PayPal 支付工作階段已過期，請重新發起付款。',
    'session_cancelled'    => '本次 PayPal 支付工作階段已被取消，請重新發起付款。',
    'session_reconciling'  => 'PayPal 建立結果正在確認，請勿重複付款，稍後重新點擊付款。',
    'method_locked'        => '該訂單已有進行中的 PayPal 工作階段，請繼續使用首次選擇的付款方式或等待工作階段過期。',
    'checkout_url_invalid' => '未能取得可用的支付跳轉地址，請聯絡商戶處理。',
    'start_failed'         => '支付暫時無法發起，請稍後重試。',
    'result_confirming'    => '支付結果正在確認，請勿重複付款。',
    'gateway_unreachable'  => '暫時無法連接 PayPal 支付閘道，請稍後重試。',
    'gateway_unreadable'   => '暫時無法讀取 PayPal 支付狀態，請稍後重試。',

    // 回跳後的狀態提示
    'status_cancelled'   => '已取消 PayPal 支付，訂單仍可重新付款。',
    'status_failed'      => 'PayPal 支付未完成，訂單仍可重新付款。',
    'status_expired'     => '支付工作階段已過期，請重新發起付款。',
    'status_reconciling' => 'PayPal 建立結果正在確認，請勿重複付款，稍後再試。',
    'status_pending'     => '支付尚未完成，請在支付頁完成付款後返回。',
];

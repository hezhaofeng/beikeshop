<?php

return [
    // 收款主體與政策
    'merchant_responsibility' => '本次交易的收款、退款、客服和爭議處理由上述商戶負責。',
    'refund_policy'           => '退款政策',
    'privacy_policy'          => '隱私政策',
    'terms_policy'            => '交易條款',
    'contact_us'              => '聯絡我們',
    'customer_service'        => '客服',
    'merchant_section'        => '收款主體',
    'policy_available'        => '站內可存取：聯絡、退款政策、隱私政策、交易條款。',

    // 訂單摘要
    'order_summary'  => '訂單明細',
    'order_number'   => '訂單號',
    'amount_payable' => '應付金額',
    'ship_to'        => '收貨',

    // 錢包付款頁
    'wallet_title'       => 'PayPal 錢包付款',
    'wallet_subtitle'    => '使用 PayPal 官方安全元件完成授權。',
    'wallet_load_failed' => 'PayPal 錢包元件載入失敗，請重新整理頁面後再試。',
    'wallet_unavailable' => 'PayPal 錢包元件無法顯示。',
    'wallet_failed'      => 'PayPal 錢包付款失敗，請重試。',
    'wallet_cancelled'   => 'PayPal 授權已取消，您可以重新點擊付款。',
    'wallet_incomplete'  => 'PayPal 錢包收款尚未完成，請稍後重試。',

    // 信用卡付款頁
    'card_title'          => '信用卡／扣賬卡付款',
    'card_subtitle'       => '卡片資訊由 PayPal 託管欄位安全處理，不會提交到本站。',
    'card_holder_name'    => '持卡人姓名',
    'card_number'         => '卡號',
    'card_expiry'         => '有效期',
    'card_cvv'            => '安全碼',
    'card_submit'         => '確認信用卡付款',
    'card_load_failed'    => 'PayPal 信用卡元件載入失敗，請改用 PayPal 錢包付款。',
    'card_not_eligible'   => '目前 PayPal 收款帳號或地區暫不支援信用卡付款，請改用 PayPal 錢包付款。',
    'card_failed'         => 'PayPal 信用卡付款失敗，請重試。',
    'card_invalid'        => 'PayPal 信用卡付款失敗，請檢查卡片資訊。',
    'card_incomplete'     => '信用卡收款尚未完成，請稍後重試。',

    // 付款過程狀態
    'payment_completed'   => '付款已完成，正在同步訂單狀態…',
    'payment_accepted'    => 'PayPal 已接受付款，正在確認收款狀態…',
    'payment_confirming'  => '付款仍在 PayPal 確認中，訂單狀態會在到賬後自動更新。',
    'payment_not_done'    => 'PayPal 付款未能完成。',

    // 錯誤頁
    'error_title'      => 'PayPal 支付暫不可用',
    'use_wallet'       => '改用 PayPal 錢包付款',
    'back_to_order'    => '返回訂單',

    // 收款狀態訊息
    'status_completed'   => 'PayPal 付款已完成。',
    'status_failed'      => 'PayPal 付款未能完成。',
    'status_cancelled'   => 'PayPal 付款已取消。',
    'status_expired'     => 'PayPal 支付工作階段已過期。',
    'status_reconciling' => 'PayPal 建立結果正在確認，請勿重複付款。',
    'status_pending'     => 'PayPal 收款狀態仍在確認。',
    'capture_wallet_ok'  => 'PayPal 錢包付款已完成。',
    'capture_card_ok'    => '信用卡付款已完成。',
    'capture_pending'    => 'PayPal 已接受付款，收款狀態仍在確認。',
    'capture_failed'     => 'PayPal 收款捕獲失敗，請稍後重試。',

    // 支付工作階段相關的買家提示
    'session_ended'        => '該 PayPal 支付工作階段已經結束，請從原站點重新發起付款。',
    'session_expired'      => '支付工作階段已過期。',
    'session_processing'   => '該支付正在處理中，請稍後查詢狀態。',
    'session_reconciling'  => 'PayPal 建立結果尚未確認，請稍後重試或聯絡商戶處理。',
    'session_unavailable'  => 'PayPal 授權地址暫不可用，請重新發起付款。',
    'method_not_enabled'   => '該支付工作階段未啟用此付款方式。',
    'card_not_enabled'     => '本站目前未啟用信用卡收款。',
    'wallet_not_enabled'   => '本站目前未啟用 PayPal 錢包收款。',
    'card_not_supported'   => '目前 PayPal 收款帳號不支援信用卡自動收款。',
    'card_component_down'  => 'PayPal 信用卡元件暫時不可用，請改用 PayPal 錢包付款。',
    'capture_token_invalid' => '支付捕獲權杖無效，請從原站點重新發起付款。',

    // 政策頁正文
    'contact_title'     => '聯絡我們',
    'contact_email'     => '客服電郵：:email',
    'contact_phone'     => '客服電話：:phone',
    'refund_title'      => '退款政策',
    'refund_body_1'     => '請以支付頁展示的退款政策和訂單條款為準。',
    'refund_body_2'     => '具體退款流程由本站客服與後台訂單記錄處理。',
    'privacy_title'     => '隱私政策',
    'privacy_body_1'    => '本站會按訂單處理、支付與合規要求保存必要的交易資料。',
    'privacy_body_2'    => '有關資料處理、保留和存取方式，請以站內政策頁面為準。',
    'terms_title'       => '交易條款',
    'terms_body_1'      => '本站負責支付、退款與爭議處理。',
    'terms_body_2'      => '支付前請閱讀訂單資訊、退款政策、隱私政策和交易條款。',

    // B 站自營訂單支付
    'local_title'              => 'PayPal',
    'local_subtitle'           => '點擊下方按鈕前往 PayPal 安全支付頁完成付款。付款完成後，訂單狀態會自動更新。',
    'local_session_notice'     => '支付工作階段有效期為 :minutes 分鐘。請勿在多個頁面同時付款。',
    'local_pay_now'            => '使用 PayPal 付款',
    'local_order_not_payable'  => '該訂單目前不能發起 PayPal 支付。',
    'local_no_method'          => '本站未啟用任何 PayPal 收款方式，請聯絡管理員。',
];

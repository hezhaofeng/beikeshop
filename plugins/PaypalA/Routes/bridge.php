<?php

use Illuminate\Support\Facades\Route;
use Plugin\PaypalA\Controllers\PaypalAController;

// 该路由只接收 B 站 HMAC 签名回调，不使用浏览器会话和 CSRF。
$register = function (): void {
    Route::post('callback', [PaypalAController::class, 'callback']);
};

Route::prefix('api/paypal/bridge')->middleware('throttle:api')->group($register);

// 兼容旧前缀：B 站 paypal_b_transactions.callback_url 里存着历史地址，
// 这些进行中的会话必须仍能回调成功。确认无历史会话后可以删除本组。
Route::prefix('api/paypal-a/bridge')->middleware('throttle:api')->group($register);

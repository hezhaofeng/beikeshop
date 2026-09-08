<?php

use Illuminate\Support\Facades\Route;
use Plugin\PaypalB\Controllers\PaypalBController;

$register = function (): void {
    Route::post('sessions', [PaypalBController::class, 'createSession']);
    Route::get('transactions/{transactionId}', [PaypalBController::class, 'status']);
    Route::post('transactions/{transactionId}/fulfillment', [PaypalBController::class, 'syncFulfillment']);
};

Route::prefix('api/paypal/bridge')->middleware('throttle:api')->group($register);

// 兼容旧前缀：A 站升级前仍会请求 /api/paypal-b/bridge/...，
// 两站不可能同一秒完成部署，这一组保证升级窗口内跨站调用不中断。
Route::prefix('api/paypal-b/bridge')->middleware('throttle:api')->group($register);

// Webhook 地址已登记在 PayPal 后台，新旧路径都必须可用，
// 否则改前缀会直接导致收款通知丢失。
Route::post('api/paypal/webhooks/{accountId}', [PaypalBController::class, 'webhook'])
    ->middleware('throttle:api');
Route::post('api/paypal-b/webhooks/{accountId}', [PaypalBController::class, 'webhook'])
    ->middleware('throttle:api');

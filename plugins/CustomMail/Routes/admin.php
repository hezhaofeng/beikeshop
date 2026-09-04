<?php

use Illuminate\Support\Facades\Route;
use Plugin\CustomMail\Controllers\CustomMailController;

// 发送催款邮件不会修改订单，但属于订单操作，沿用订单查看权限。
Route::middleware('can:orders_show')->group(function (): void {
    Route::post('custom-mail/orders/{order}/payment-reminder', [CustomMailController::class, 'sendPaymentReminder'])
        ->name('custom-mail.orders.payment-reminder');
});

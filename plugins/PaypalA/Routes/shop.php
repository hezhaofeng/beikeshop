<?php

use Illuminate\Support\Facades\Route;
use Plugin\PaypalA\Controllers\PaypalAController;

$register = function (): void {
    // 创建支付会话有远端副作用，禁止被预加载器、爬虫或邮件安全扫描以 GET 触发。
    Route::post('orders/{number}/start', [PaypalAController::class, 'start'])
        ->middleware('throttle:10,1')
        ->name('orders.start');
    Route::get('return', [PaypalAController::class, 'returned'])->name('return');
};

// 主路径。路由名保持 paypal_a.*，只有对外 URL 改为 /paypal。
Route::prefix('paypal')->name('paypal_a.')->group($register);

// 兼容旧前缀：PayPal 侧和 B 站数据库里可能仍存着 /paypal-a/... 的历史地址，
// 切换前缀时这些进行中的会话不能断。确认无历史会话后可以删除本组。
Route::prefix('paypal-a')->name('paypal_a.legacy.')->group($register);

<?php

use Illuminate\Support\Facades\Route;
use Plugin\PaypalB\Controllers\PaypalBController;

$register = function (): void {
    Route::get('contact', [PaypalBController::class, 'contact'])->name('contact');
    Route::get('refunds', [PaypalBController::class, 'refunds'])->name('refunds');
    Route::get('privacy', [PaypalBController::class, 'privacy'])->name('privacy');
    Route::get('terms', [PaypalBController::class, 'terms'])->name('terms');

    Route::get('checkout/{token}', [PaypalBController::class, 'checkout'])->name('checkout');
    Route::post('checkout/{token}/capture', [PaypalBController::class, 'captureCheckout'])
        ->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class)
        ->middleware('throttle:30,1')
        ->name('capture');
    Route::post('checkout/{token}/status', [PaypalBController::class, 'checkoutStatus'])
        ->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class)
        ->middleware('throttle:60,1')
        ->name('status');
    Route::get('return', [PaypalBController::class, 'returnFromPaypal'])->name('return');
    Route::get('cancel', [PaypalBController::class, 'cancel'])->name('cancel');

    // 买家回跳 A 站的中转入口。A 站地址只存在于 B 站服务端与 302 的 Location 中，
    // 结账页 DOM 与 JS 里不再出现 A 站域名。
    Route::get('finish/{token}', [PaypalBController::class, 'finish'])
        ->middleware('throttle:60,1')
        ->name('finish');
};

// 主路径。路由名保持 paypal_b.*，只有对外 URL 改为 /paypal。
Route::prefix('paypal')->name('paypal_b.')->group($register);

// 兼容旧前缀：PayPal 侧已创建订单的 return_url / cancel_url 仍指向 /paypal-b/...，
// 这些已授权未捕获的会话不能断。确认无历史会话后可以删除本组。
Route::prefix('paypal-b')->name('paypal_b.legacy.')->group($register);

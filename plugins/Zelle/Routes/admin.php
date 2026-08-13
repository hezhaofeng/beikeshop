<?php

use Illuminate\Support\Facades\Route;
use Plugin\Zelle\Controllers\ZelleController;

// 到账核验会改变订单支付状态，沿用订单状态更新权限。
Route::prefix('zelle')->name('zelle.')->middleware('can:orders_update_status')->group(function (): void {
    Route::post('orders/{order}/confirm', [ZelleController::class, 'confirm'])->name('orders.confirm');
});

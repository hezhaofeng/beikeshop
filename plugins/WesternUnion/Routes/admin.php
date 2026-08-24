<?php

use Illuminate\Support\Facades\Route;
use Plugin\WesternUnion\Controllers\WesternUnionController;

// 到账核验和私有凭证查看均沿用订单状态更新权限。
Route::prefix('western-union')->name('western-union.')->middleware('can:orders_update_status')->group(function (): void {
    Route::get('orders/{order}/receipt', [WesternUnionController::class, 'receipt'])->name('orders.receipt');
    Route::post('orders/{order}/confirm', [WesternUnionController::class, 'confirm'])->name('orders.confirm');
});

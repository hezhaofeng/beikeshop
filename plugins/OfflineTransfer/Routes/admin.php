<?php

use Illuminate\Support\Facades\Route;
use Plugin\OfflineTransfer\Controllers\OfflineTransferController;

// 到账核验和私有凭证查看均沿用订单状态更新权限。
Route::prefix('offline-transfer')->name('offline-transfer.')->middleware('can:orders_update_status')->group(function (): void {
    Route::get('orders/{order}/receipt', [OfflineTransferController::class, 'receipt'])->name('orders.receipt');
    Route::post('orders/{order}/confirm', [OfflineTransferController::class, 'confirm'])->name('orders.confirm');
});

<?php

use Illuminate\Support\Facades\Route;
use Plugin\OfflineTransfer\Controllers\OfflineTransferController;

// 游客可通过订单号和下单邮箱查询任意支付方式的订单详情。
Route::get('order-lookup', [OfflineTransferController::class, 'orderLookup'])
    ->name('offline-transfer.orders.lookup');
Route::post('order-lookup', [OfflineTransferController::class, 'findOrder'])
    ->name('offline-transfer.orders.find');

// 买家提交凭证后仍等待后台核验，路由由前台 shop 中间件保护。
Route::post('offline-transfer/orders/{number}/declaration', [OfflineTransferController::class, 'declare'])
    ->name('offline-transfer.orders.declare');

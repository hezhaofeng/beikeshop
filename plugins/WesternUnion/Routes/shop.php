<?php

use Illuminate\Support\Facades\Route;
use Plugin\WesternUnion\Controllers\WesternUnionController;

// 游客可通过订单号和下单邮箱查询任意支付方式的订单详情。
Route::get('western-union/order-lookup', [WesternUnionController::class, 'orderLookup'])
    ->name('western-union.orders.lookup');
Route::post('western-union/order-lookup', [WesternUnionController::class, 'findOrder'])
    ->name('western-union.orders.find');

// 买家提交凭证后仍等待后台核验，路由由前台 shop 中间件保护。
Route::post('western-union/orders/{number}/declaration', [WesternUnionController::class, 'declare'])
    ->name('western-union.orders.declare');

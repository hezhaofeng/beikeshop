<?php

use Illuminate\Support\Facades\Route;
use Plugin\Zelle\Controllers\ZelleController;

// 买家提交付款声明后仍等待后台核验，路由由前台 shop 中间件保护。
Route::post('zelle/orders/{number}/declaration', [ZelleController::class, 'declare'])
    ->name('zelle.orders.declare');

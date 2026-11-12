<?php

use Illuminate\Support\Facades\Route;
use Plugin\Bestseller\Controllers\AdminBestsellerSelectorController;

// 保留合并前的 URL、路由名和权限，兼容已打开的选品页。
Route::prefix('bestseller-selector')->name('bestseller_selector.')->middleware('can:plugins_update')->group(function (): void {
    Route::get('state', [AdminBestsellerSelectorController::class, 'state'])->name('state');
    Route::get('categories', [AdminBestsellerSelectorController::class, 'categories'])->name('categories');
    Route::get('products', [AdminBestsellerSelectorController::class, 'products'])->name('products');
    Route::post('save', [AdminBestsellerSelectorController::class, 'save'])->name('save');
});

<?php

use Illuminate\Support\Facades\Route;
use Plugin\CyberCloakSimple\Controllers\AdminCyberCloakSimpleController;

Route::prefix('cyber-cloak-simple')->name('cyber_cloak_simple.')->middleware('can:plugins_update')->group(function (): void {
    // 商品和分类使用同一个设置页权限，避免新增独立权限迁移。
    Route::post('mappings/product', [AdminCyberCloakSimpleController::class, 'rebuildProductMappings'])
        ->name('mappings.product');
    Route::post('mappings/category', [AdminCyberCloakSimpleController::class, 'rebuildCategoryMappings'])
        ->name('mappings.category');
});

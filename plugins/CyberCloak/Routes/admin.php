<?php

use Illuminate\Support\Facades\Route;
use Plugin\CyberCloak\Controllers\AdminCyberCloakController;

Route::prefix('cyber-cloak')->name('cyber_cloak.')->group(function (): void {
    // key 和供应商配置沿用插件管理权限，避免新增独立权限迁移。
    Route::middleware('can:plugins_update')->group(function (): void {
        Route::get('keys', [AdminCyberCloakController::class, 'keys'])->name('keys');
        Route::post('keys', [AdminCyberCloakController::class, 'createKey'])->name('keys.create');
        Route::put('keys/{id}', [AdminCyberCloakController::class, 'updateKey'])->name('keys.update');
        Route::delete('keys/{id}', [AdminCyberCloakController::class, 'deleteKey'])->name('keys.delete');
        Route::get('ip-provider', [AdminCyberCloakController::class, 'providerStatus'])->name('ip_provider.status');
        Route::post('ip-provider/test', [AdminCyberCloakController::class, 'testProvider'])->name('ip_provider.test');
        Route::post('ip-provider/sync', [AdminCyberCloakController::class, 'syncProvider'])->name('ip_provider.sync');

        // 映射管理集中在插件编辑页，命令行仍作为批量运维入口保留。
        Route::get('mappings', [AdminCyberCloakController::class, 'mappingStatus'])->name('mappings.status');
        Route::get('mappings/public-skus', [AdminCyberCloakController::class, 'searchPublicSkus'])->name('mappings.public_skus');
        Route::post('mappings/product', [AdminCyberCloakController::class, 'rebuildProductMappings'])->name('mappings.product');
        Route::post('mappings/category', [AdminCyberCloakController::class, 'rebuildCategoryMappings'])->name('mappings.category');
        Route::post('mappings/banner', [AdminCyberCloakController::class, 'scanBannerMappings'])->name('mappings.banner');
        Route::post('mappings/cache', [AdminCyberCloakController::class, 'clearMappingCache'])->name('mappings.cache');
        Route::post('mappings/rebuild', [AdminCyberCloakController::class, 'rebuildMappings'])->name('mappings.rebuild');
        Route::post('mappings/confirm', [AdminCyberCloakController::class, 'confirmMapping'])->name('mappings.confirm');
        Route::post('mappings/publish', [AdminCyberCloakController::class, 'publishMapping'])->name('mappings.publish');
        Route::post('mappings/routes', [AdminCyberCloakController::class, 'rebuildRoutes'])->name('mappings.routes');
    });

    Route::middleware('can:orders_update_status')->post('orders/{order}/review', [AdminCyberCloakController::class, 'reviewOrder'])
        ->name('orders.review');
});

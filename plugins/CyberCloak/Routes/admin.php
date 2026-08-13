<?php

use Illuminate\Support\Facades\Route;
use Plugin\CyberCloak\Controllers\AdminCyberCloakController;

Route::prefix('cyber-cloak')->name('cyber_cloak.')->group(function (): void {
    // 设置和映射管理沿用插件管理权限，避免新增独立权限迁移。
    Route::middleware('can:plugins_update')->group(function (): void {
        Route::put('settings', [AdminCyberCloakController::class, 'updateSettings'])->name('settings.update');
        Route::get('runtime-status', [AdminCyberCloakController::class, 'runtimeStatus'])->name('runtime.status');
        Route::get('keys', [AdminCyberCloakController::class, 'keys'])->name('keys');
        Route::post('keys', [AdminCyberCloakController::class, 'createKey'])->name('keys.create');
        Route::post('keys/{id}/share', [AdminCyberCloakController::class, 'shareKey'])->name('keys.share');
        Route::put('keys/{id}', [AdminCyberCloakController::class, 'updateKey'])->name('keys.update');
        Route::delete('keys/{id}', [AdminCyberCloakController::class, 'deleteKey'])->name('keys.delete');
        Route::get('traffic-risks', [AdminCyberCloakController::class, 'trafficRiskProfiles'])->name('traffic_risks.index');
        Route::post('traffic-risks/{profile}/review', [AdminCyberCloakController::class, 'reviewTrafficRisk'])->name('traffic_risks.review');
        // 映射管理集中在插件编辑页，命令行仍作为批量运维入口保留。
        Route::get('mappings', [AdminCyberCloakController::class, 'mappingStatus'])->name('mappings.status');
        Route::get('mappings/tasks/{task}', [AdminCyberCloakController::class, 'mappingTask'])->name('mappings.tasks.show');
        Route::get('mappings/public-skus', [AdminCyberCloakController::class, 'searchPublicSkus'])->name('mappings.public_skus');
        Route::post('mappings/product', [AdminCyberCloakController::class, 'rebuildProductMappings'])->name('mappings.product');
        Route::post('mappings/category', [AdminCyberCloakController::class, 'rebuildCategoryMappings'])->name('mappings.category');
        Route::post('mappings/banner', [AdminCyberCloakController::class, 'scanBannerMappings'])->name('mappings.banner');
        Route::post('mappings/banner-images/sync', [AdminCyberCloakController::class, 'syncBannerImages'])->name('mappings.banner_images.sync');
        Route::post('mappings/banner-images/{mapping}', [AdminCyberCloakController::class, 'updateBannerImage'])->name('mappings.banner_images.update');
        Route::post('mappings/cache', [AdminCyberCloakController::class, 'clearMappingCache'])->name('mappings.cache');
        Route::post('mappings/rebuild', [AdminCyberCloakController::class, 'rebuildMappings'])->name('mappings.rebuild');
        Route::post('mappings/confirm', [AdminCyberCloakController::class, 'confirmMapping'])->name('mappings.confirm');
        Route::post('mappings/publish', [AdminCyberCloakController::class, 'publishMapping'])->name('mappings.publish');
        Route::post('mappings/routes', [AdminCyberCloakController::class, 'rebuildRoutes'])->name('mappings.routes');
    });
});

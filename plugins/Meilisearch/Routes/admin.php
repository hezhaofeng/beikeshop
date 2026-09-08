<?php

use Illuminate\Support\Facades\Route;
use Plugin\Meilisearch\Controllers\AdminMeilisearchController;

Route::prefix('meilisearch')->name('meilisearch.')->group(function (): void {
    // 索引管理沿用插件管理权限，避免新增独立权限迁移。
    Route::middleware('can:plugins_update')->group(function (): void {
        Route::get('status', [AdminMeilisearchController::class, 'status'])->name('status');
        Route::post('indexes/delete', [AdminMeilisearchController::class, 'deleteIndex'])->name('indexes.delete');
        Route::post('rebuild', [AdminMeilisearchController::class, 'rebuild'])->name('rebuild');
        Route::post('sync', [AdminMeilisearchController::class, 'sync'])->name('sync');
        Route::get('tasks/{task}', [AdminMeilisearchController::class, 'task'])->name('tasks.show');
        Route::post('tasks/{task}/cancel', [AdminMeilisearchController::class, 'cancelTask'])->name('tasks.cancel');
    });
});

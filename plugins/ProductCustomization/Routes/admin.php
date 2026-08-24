<?php

use Illuminate\Support\Facades\Route;
use Plugin\ProductCustomization\Controllers\AdminCustomizationController;

Route::prefix('product-customization')->name('product_customization.')->middleware('can:plugins_update')->group(function (): void {
    Route::get('/', [AdminCustomizationController::class, 'index'])->name('index');
    Route::post('templates', [AdminCustomizationController::class, 'storeTemplate'])->name('templates.store');
    Route::put('templates/{template}', [AdminCustomizationController::class, 'updateTemplate'])->name('templates.update');
    Route::delete('templates/{template}', [AdminCustomizationController::class, 'destroyTemplate'])->name('templates.destroy');
    Route::post('templates/{template}/fields', [AdminCustomizationController::class, 'storeField'])->name('fields.store');
    Route::put('templates/{template}/fields/{field}', [AdminCustomizationController::class, 'updateField'])->name('fields.update');
    Route::delete('templates/{template}/fields/{field}', [AdminCustomizationController::class, 'destroyField'])->name('fields.destroy');
});

<?php

use Illuminate\Support\Facades\Route;
use Plugin\PaypalA\Controllers\PaypalAAdminController;

Route::prefix('paypal')->name('paypal_a.')->middleware('can:plugins_update')->group(function (): void {
    Route::put('settings', [PaypalAAdminController::class, 'updateSettings'])->name('settings.update');
});

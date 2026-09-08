<?php

use Illuminate\Support\Facades\Route;
use Plugin\PaypalB\Controllers\PaypalBAdminController;

Route::prefix('paypal')->name('paypal_b.')->middleware('can:plugins_update')->group(function (): void {
    Route::put('settings', [PaypalBAdminController::class, 'updateSettings'])->name('settings.update');
    Route::get('accounts', [PaypalBAdminController::class, 'accounts'])->name('accounts.index');
    Route::post('accounts', [PaypalBAdminController::class, 'storeAccount'])->name('accounts.store');
    Route::put('accounts/{account}', [PaypalBAdminController::class, 'updateAccount'])->name('accounts.update');
    Route::delete('accounts/{account}', [PaypalBAdminController::class, 'deleteAccount'])->name('accounts.destroy');
    Route::post('transactions/{transaction}/retry-callback', [PaypalBAdminController::class, 'retryCallback'])->name('transactions.retry_callback');
    Route::post('transactions/{transaction}/refunds', [PaypalBAdminController::class, 'requestRefund'])->name('transactions.refunds.store');
    Route::post('refunds/{refund}/retry', [PaypalBAdminController::class, 'retryRefund'])->name('refunds.retry');
    Route::put('transactions/{transaction}/fulfillment', [PaypalBAdminController::class, 'updateFulfillmentEvidence'])->name('transactions.fulfillment');
});

<?php

use Illuminate\Support\Facades\Route;
use Plugin\AdTracking\Controllers\ConsentController;

Route::post('ad-tracking/consent', [ConsentController::class, 'update'])
    ->name('ad_tracking.consent');

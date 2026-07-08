<?php

use App\Http\Controllers\Integrations\GoogleIntegrationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'rbac'])->group(function () {
    Route::get('google/places/search', [GoogleIntegrationController::class, 'search'])
        ->middleware(['spa.browser:ca-master', 'throttle:30,1']);
});

<?php

use App\Http\Controllers\Master\CityController;
use App\Http\Controllers\Master\EmployeeLookupController;
use App\Http\Controllers\Master\LocationLookupController;
use App\Http\Controllers\Master\RoleMasterController;
use App\Http\Controllers\Master\SourceLeadController;
use App\Http\Controllers\Master\StateController;
use App\Http\Controllers\Master\TeamSizeMasterController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'rbac'])->group(function () {
    Route::get('lookups/states', [LocationLookupController::class, 'states']);
    Route::get('lookups/cities', [LocationLookupController::class, 'cities']);
    Route::get('lookups/executives', [EmployeeLookupController::class, 'executives']);

    Route::apiResource('states', StateController::class)
        ->middleware('spa.browser:ca-master');
    Route::apiResource('cities', CityController::class)
        ->middleware('spa.browser:ca-master');
    Route::apiResource('source-leads', SourceLeadController::class)
        ->middleware('spa.browser:ca-master');
    Route::apiResource('team-sizes', TeamSizeMasterController::class)
        ->middleware('spa.browser:ca-master');
    Route::apiResource('role-masters', RoleMasterController::class)
        ->middleware('spa.browser:ca-master');
});

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
    Route::get('lookups/sources', [LocationLookupController::class, 'sources']);
    Route::get('lookups/executives', [EmployeeLookupController::class, 'executives']);

    $registerMasterLifecycle = function (string $prefix, string $controller, string $parameter): void {
        Route::get("{$prefix}/{{$parameter}}/dependencies", [$controller, 'dependencies'])
            ->middleware('spa.browser:ca-master');
        Route::patch("{$prefix}/{{$parameter}}/deactivate", [$controller, 'deactivate'])
            ->middleware('spa.browser:ca-master');
        Route::patch("{$prefix}/{{$parameter}}/reactivate", [$controller, 'reactivate'])
            ->middleware('spa.browser:ca-master');
    };

    $registerMasterLifecycle('states', StateController::class, 'state');
    $registerMasterLifecycle('cities', CityController::class, 'city');
    $registerMasterLifecycle('source-leads', SourceLeadController::class, 'source_lead');
    $registerMasterLifecycle('team-sizes', TeamSizeMasterController::class, 'team_size');
    $registerMasterLifecycle('role-masters', RoleMasterController::class, 'role_master');

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

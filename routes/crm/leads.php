<?php

use App\Http\Controllers\Leads\CaMasterController;
use App\Http\Controllers\Leads\DemoConfirmationController;
use App\Http\Controllers\Leads\LeadActionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'rbac'])->group(function () {
    Route::get('ca-masters/{lead}/demo-confirmation', [DemoConfirmationController::class, 'showForLead'])
        ->middleware('spa.browser:leads');
    Route::post('lead-actions', [LeadActionController::class, 'store']);
    Route::patch('ca-masters/{ca_master}/status', [CaMasterController::class, 'updateStatus'])
        ->middleware('spa.browser:ca-master');
    Route::patch('ca-masters/{ca_master}/contact', [CaMasterController::class, 'updateContact'])
        ->middleware('spa.browser:leads');
    Route::resource('ca-masters', CaMasterController::class)
        ->middleware('spa.browser:ca-master');
});

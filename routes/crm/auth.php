<?php

use App\Http\Controllers\Auth\CrmAuthController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\ProfileController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('login', [CrmAuthController::class, 'showLogin'])->name('crm.login');
    Route::post('login', [CrmAuthController::class, 'login'])
        ->middleware('throttle:login')
        ->name('crm.login.attempt');
});

Route::post('logout', [CrmAuthController::class, 'logout'])->middleware('auth')->name('crm.logout');

Route::middleware(['auth', 'rbac'])->group(function () {
    Route::get('auth/me', [CrmAuthController::class, 'me']);
    Route::put('auth/profile', [ProfileController::class, 'update']);
    Route::post('auth/change-password', [PasswordController::class, 'change']);
});

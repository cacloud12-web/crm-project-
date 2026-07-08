<?php

use App\Http\Controllers\Auth\CrmAuthController;
use App\Http\Controllers\Auth\LoginEmailChangeController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\ProfileController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('login', [CrmAuthController::class, 'showLogin'])->name('crm.login');
    Route::post('login', [CrmAuthController::class, 'login'])
        ->name('crm.login.attempt');
    Route::get('forgot-password', [PasswordResetController::class, 'showForgotForm'])
        ->name('crm.password.request');
    Route::post('forgot-password', [PasswordResetController::class, 'sendResetLink'])
        ->name('crm.password.email');
    Route::get('reset-password/{token}', [PasswordResetController::class, 'showResetForm'])
        ->name('crm.password.reset');
    Route::post('reset-password', [PasswordResetController::class, 'reset'])
        ->name('crm.password.update');
});

Route::get('auth/verify-login-email/{token}', [LoginEmailChangeController::class, 'verify'])
    ->name('auth.verify-login-email');

Route::post('logout', [CrmAuthController::class, 'logout'])->middleware('auth')->name('crm.logout');

Route::middleware(['auth', 'rbac'])->group(function () {
    Route::get('auth/me', [CrmAuthController::class, 'me']);
    Route::put('auth/profile', [ProfileController::class, 'update']);
    Route::post('auth/change-password', [PasswordController::class, 'change']);
    Route::get('auth/login-email-change', [LoginEmailChangeController::class, 'show']);
    Route::get('auth/login-email-change/history', [LoginEmailChangeController::class, 'history']);
    Route::post('auth/login-email-change', [LoginEmailChangeController::class, 'store']);
    Route::post('auth/login-email-change/resend', [LoginEmailChangeController::class, 'resend']);
    Route::post('auth/login-email-change/cancel', [LoginEmailChangeController::class, 'cancel']);
});

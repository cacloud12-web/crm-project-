<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'rbac'])->group(function () {
    Route::get('/', fn () => view('crm.index', ['spaPage' => 'dashboard']))
        ->middleware('spa.access:dashboard');

    $spaPages = [
        'dashboard',
        'assignment',
        'leads',
        'followups',
        'bulk',
        'ca-master',
        'settings',
        'reports',
        'analytics',
        'audit',
        'communication',
        'consent-dnd',
        'whatsapp',
        'sms',
        'email',
        'notifications',
        'activity',
        'security',
        'queue',
    ];

    foreach ($spaPages as $page) {
        Route::get($page, fn () => view('crm.index', ['spaPage' => $page]))
            ->middleware('spa.access:'.$page);
    }

    Route::redirect('payments', '/dashboard');
    Route::redirect('reception', '/communication');
});

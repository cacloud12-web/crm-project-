<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'rbac'])->group(function () {
    Route::get('/', fn () => view('crm.index', ['spaPage' => 'dashboard']))
        ->middleware('spa.access:dashboard');

    $spaPages = [
        'dashboard',
        'assignment',
        'followups',
        'bulk',
        'ca-master',
        'recycle-bin',
        'settings',
        'reports',
        'duplicate-attempts',
        'analytics',
        'audit',
        'communication',
        'consent-dnd',
        'whatsapp',
        'sms',
        'email',
        'campaigns',
        'notifications',
        'activity',
        'security',
        'queue',
        'demo-calendar',
    ];

    foreach ($spaPages as $page) {
        Route::get($page, fn () => view('crm.index', ['spaPage' => $page]))
            ->middleware('spa.access:'.$page);
    }

    Route::get('leads', function (Request $request) {
        $user = $request->user();
        $role = $user ? strtolower((string) ($user->crm_role ?? 'employee')) : 'employee';
        if (in_array($role, ['super_admin', 'manager', 'admin'], true)) {
            return redirect('/ca-masters');
        }

        return view('crm.index', ['spaPage' => 'leads']);
    })->middleware('spa.access:leads');

    Route::get('settings/sales-list', fn () => view('crm.index', ['spaPage' => 'sales-list']))
        ->middleware('spa.access:sales-list');
    Route::get('settings/roles-permissions', fn () => view('crm.index', ['spaPage' => 'roles-permissions']))
        ->middleware('spa.access:roles-permissions');
    Route::get('settings/email-templates', fn () => view('crm.index', ['spaPage' => 'settings-email-templates']))
        ->middleware('spa.access:settings-email-templates');
    Route::get('settings/whatsapp-templates', fn () => view('crm.index', ['spaPage' => 'settings-whatsapp-templates']))
        ->middleware('spa.access:settings-whatsapp-templates');
    Route::get('settings/google-api', fn () => view('crm.index', ['spaPage' => 'settings-google-api']))
        ->middleware('spa.access:settings');
    Route::get('settings/demo-providers', fn () => view('crm.index', ['spaPage' => 'settings-demo-providers']))
        ->middleware('spa.access:settings');
    Route::redirect('sales-list', '/settings/sales-list');

    Route::redirect('payments', '/dashboard');
    Route::redirect('reception', '/communication');
});

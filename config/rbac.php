<?php

return [
    'roles' => [
        'super_admin' => 'Super Admin',
        'admin' => 'Admin',
        'manager' => 'Manager',
        'employee' => 'Employee',
    ],

    'permissions' => [
        'view',
        'create',
        'edit',
        'delete',
        'import',
        'export',
        'campaigns',
        'reports',
    ],

    'modules' => [
        'dashboard',
        'ca_master',
        'leads',
        'employees',
        'assignment',
        'followups',
        'bulk',
        'campaigns',
        'consent',
        'activity',
        'reports',
        'admin',
        'security',
        'settings',
    ],

    'spa_pages' => [
        'dashboard' => ['module' => 'dashboard', 'permission' => 'view'],
        'ca-master' => ['module' => 'ca_master', 'permission' => 'view'],
        'leads' => ['module' => 'leads', 'permission' => 'view'],
        'assignment' => ['module' => 'assignment', 'permission' => 'view'],
        'followups' => ['module' => 'followups', 'permission' => 'view'],
        'bulk' => ['module' => 'bulk', 'permission' => 'view'],
        'communication' => ['module' => 'campaigns', 'permission' => 'view'],
        'whatsapp' => ['module' => 'campaigns', 'permission' => 'view'],
        'sms' => ['module' => 'campaigns', 'permission' => 'view'],
        'email' => ['module' => 'campaigns', 'permission' => 'view'],
        'consent-dnd' => ['module' => 'consent', 'permission' => 'view'],
        'reports' => ['module' => 'reports', 'permission' => 'reports'],
        'activity' => ['module' => 'activity', 'permission' => 'view'],
        'security' => ['module' => 'security', 'permission' => 'view'],
        'queue' => ['module' => 'admin', 'permission' => 'view'],
        'db-health' => ['module' => 'admin', 'permission' => 'reports'],
        'settings' => ['module' => 'settings', 'permission' => 'view'],
        'notifications' => ['module' => 'dashboard', 'permission' => 'view'],
        'analytics' => ['module' => 'reports', 'permission' => 'reports'],
        'audit' => ['module' => 'activity', 'permission' => 'view'],
    ],

    'matrix' => [
        'super_admin' => ['*' => ['*']],

        'admin' => [
            '*' => ['view', 'create', 'edit', 'delete', 'import', 'export', 'campaigns', 'reports'],
            'security' => ['view', 'edit'],
            'admin' => ['view', 'reports'],
        ],

        'manager' => [
            'dashboard' => ['view', 'reports'],
            'ca_master' => ['view', 'create', 'edit', 'import', 'export'],
            'leads' => ['view', 'create', 'edit', 'export'],
            'employees' => ['view', 'create', 'edit'],
            'assignment' => ['view', 'create', 'edit'],
            'followups' => ['view', 'create', 'edit', 'delete'],
            'bulk' => ['view', 'import', 'export', 'edit'],
            'campaigns' => ['view', 'campaigns'],
            'consent' => ['view', 'create', 'edit'],
            'activity' => ['view', 'reports'],
            'reports' => ['view', 'reports', 'export'],
            'settings' => ['view'],
        ],

        'employee' => [
            'dashboard' => ['view'],
            'leads' => ['view', 'create', 'edit'],
            'followups' => ['view', 'create', 'edit'],
        ],
    ],

    'default_role' => 'employee',

    'login_redirect' => '/dashboard',
];

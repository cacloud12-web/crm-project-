<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Root Super Admin (optional protection marker)
    |--------------------------------------------------------------------------
    | When CRM_ROOT_SUPER_ADMIN_EMAIL is set, that account cannot be deleted
    | or demoted. Leave empty in production and create admins via:
    |   php artisan crm:create-super-admin
    |
    | Do not commit real credentials. Prefer the artisan command over env
    | password bootstrapping.
    */
    'root_super_admin_email' => env('CRM_ROOT_SUPER_ADMIN_EMAIL'),
    'root_super_admin_name' => env('CRM_ROOT_SUPER_ADMIN_NAME', 'Super Admin'),
    'root_super_admin_password' => env('CRM_ROOT_SUPER_ADMIN_PASSWORD'),
];

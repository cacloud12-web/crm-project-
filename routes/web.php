<?php

/**
 * CRM web routes — modular entry point.
 * All URLs are unchanged; routes are grouped by domain module.
 */
$crmRoutes = [
    'auth',
    'spa',
    'dashboard',
    'leads',
    'bulk',
    'assignment',
    'followups',
    'workflow',
    'demo-calendar',
    'sales',
    'communication',
    'reports',
    'settings',
    'webhooks',
    'admin',
    'master',
    'google',
];

foreach ($crmRoutes as $module) {
    require __DIR__."/crm/{$module}.php";
}

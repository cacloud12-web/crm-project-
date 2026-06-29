<?php

return [
    'master_ttl' => (int) env('CRM_CACHE_MASTER_TTL', 300),
    'dashboard_ttl' => (int) env('CRM_CACHE_DASHBOARD_TTL', 60),
    'reports_summary_ttl' => (int) env('CRM_CACHE_REPORTS_SUMMARY_TTL', 60),
];

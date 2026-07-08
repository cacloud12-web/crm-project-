<?php

return [
    'master_ttl' => (int) env('CRM_CACHE_MASTER_TTL', 300),
    'dashboard_ttl' => (int) env('CRM_CACHE_DASHBOARD_TTL', 120),
    'reports_summary_ttl' => (int) env('CRM_CACHE_REPORTS_SUMMARY_TTL', 120),
    'dashboard_insights_ttl' => (int) env('CRM_CACHE_DASHBOARD_INSIGHTS_TTL', 120),
    'sales_options_ttl' => (int) env('CRM_CACHE_SALES_OPTIONS_TTL', 300),
    'listing_page_ttl' => (int) env('CRM_CACHE_LISTING_PAGE_TTL', 30),
];

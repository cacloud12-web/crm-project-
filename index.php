<?php

/**
 * Hostinger shared-hosting entry point.
 *
 * Used when Git auto-deploy places the Laravel project root inside public_html
 * instead of pointing the document root at the public/ folder.
 *
 * php artisan serve still uses public/index.php locally — this file is not used in local dev.
 */

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

if (file_exists($maintenance = __DIR__.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require __DIR__.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once __DIR__.'/bootstrap/app.php';

$app->handleRequest(Request::capture());

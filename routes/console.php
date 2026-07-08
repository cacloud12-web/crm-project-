<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('notifications:scan-due-followups')->everyFifteenMinutes();
Schedule::command('followups:process-automation')->everyFiveMinutes();
Schedule::command('workflow:process-demo-reminders')->everyMinute()->withoutOverlapping();
Schedule::command('email:sync')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('campaigns:process-scheduled')->everyMinute()->withoutOverlapping();

// Local dev: run `php artisan schedule:work` to process email:sync, campaigns, and auto-drain.
// Production: add * * * * * php artisan schedule:run to cron.

Schedule::command('queue:work --stop-when-empty --max-time=55 --tries=3')
    ->everyMinute()
    ->withoutOverlapping()
    ->when(fn () => (bool) config('crm_queue.auto_drain', false));

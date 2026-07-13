<?php

namespace App\Providers;

use App\Database\Grammar\MySqlGrammar;
use App\Database\Grammar\SqliteGrammar;
use App\Events\LeadSaved;
use App\Listeners\RefreshEmployeeProductivityOnLeadSaved;
use App\Models\CaMaster;
use App\Models\EmailLog;
use App\Models\SmsLog;
use App\Models\WaMessageLog;
use App\Policies\CaMasterPolicy;
use App\Services\Leads\LeadQualityHistoryService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerMysqlGrammar();
        $this->registerSqliteGrammar();
        Gate::policy(CaMaster::class, CaMasterPolicy::class);
        Gate::policy(\App\Models\OcrDocument::class, \App\Policies\OcrDocumentPolicy::class);
        Event::listen(LeadSaved::class, RefreshEmployeeProductivityOnLeadSaved::class);
        $this->registerCommunicationQualityHooks();

        $this->registerLoginRateLimiter();
        $this->registerActionRateLimiters();
    }

    private function registerMysqlGrammar(): void
    {
        if (config('database.default') !== 'mysql') {
            return;
        }

        // Defer until first query — avoids connecting to remote MySQL on every HTTP boot.
        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event): void {
            $connection = $event->connection;
            if ($connection->getDriverName() !== 'mysql') {
                return;
            }

            $connection->setQueryGrammar(new MySqlGrammar($connection));
        });
    }

    private function registerSqliteGrammar(): void
    {
        if (config('database.default') !== 'sqlite') {
            return;
        }

        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event): void {
            $connection = $event->connection;
            if ($connection->getDriverName() !== 'sqlite') {
                return;
            }

            $connection->setQueryGrammar(new SqliteGrammar($connection));
        });
    }

    private function registerCommunicationQualityHooks(): void
    {
        $handler = function ($log, string $channel): void {
            if (! $log->ca_id) {
                return;
            }

            $status = match ($channel) {
                'sms' => $log->sms_status ?? null,
                'whatsapp' => $log->message_status ?? null,
                'email' => $log->email_status ?? null,
                default => null,
            };

            if (! in_array((string) $status, ['Failed', 'failed', 'Skipped', 'Bounced'], true)) {
                return;
            }

            $reason = $log->failed_reason ?? $log->error_message ?? 'Communication failed';
            $lead = CaMaster::query()->find($log->ca_id);

            if ($lead) {
                app(LeadQualityHistoryService::class)->recordCommunicationFailure(
                    $lead,
                    $channel,
                    (string) $reason,
                    $log->employee_id ? (int) $log->employee_id : null,
                );
            }
        };

        SmsLog::created(function (SmsLog $log) use ($handler) {
            $handler($log, 'sms');
        });

        WaMessageLog::created(function (WaMessageLog $log) use ($handler) {
            $handler($log, 'whatsapp');
        });

        EmailLog::created(function (EmailLog $log) use ($handler) {
            $handler($log, 'email');
        });
    }

    private function registerLoginRateLimiter(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $maxAttempts = (int) config('crm_queue.login_max_attempts', 5);
            $decayMinutes = (int) config('crm_queue.login_decay_minutes', 15);
            $email = strtolower(trim((string) $request->input('email', '')));

            return Limit::perMinutes($decayMinutes, $maxAttempts)
                ->by(sha1($email.'|'.$request->ip()));
        });
    }

    private function registerActionRateLimiters(): void
    {
        $this->registerUserActionLimiter('bulk-import', 'bulk_import');
        $this->registerUserActionLimiter('campaign', 'campaign');
        $this->registerUserActionLimiter('follow-up', 'follow_up');
        $this->registerUserActionLimiter('lead-action', 'lead_action');
    }

    private function registerUserActionLimiter(string $name, string $configKey): void
    {
        RateLimiter::for($name, function (Request $request) use ($configKey) {
            $maxAttempts = (int) config("crm_rate_limits.{$configKey}.max_attempts", 10);
            $decayMinutes = (int) config("crm_rate_limits.{$configKey}.decay_minutes", 1);
            $userId = $request->user()?->id;

            return Limit::perMinutes($decayMinutes, $maxAttempts)
                ->by($userId ? 'user:'.$userId : 'ip:'.$request->ip());
        });
    }
}

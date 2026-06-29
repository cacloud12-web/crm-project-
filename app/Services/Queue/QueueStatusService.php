<?php

namespace App\Services\Queue;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class QueueStatusService
{
    public function summary(): array
    {
        $pendingJobs = Schema::hasTable('jobs') ? (int) DB::table('jobs')->count() : 0;
        $failedJobs = Schema::hasTable('failed_jobs') ? (int) DB::table('failed_jobs')->count() : 0;

        $recentFailures = [];
        if (Schema::hasTable('failed_jobs')) {
            $recentFailures = DB::table('failed_jobs')
                ->orderByDesc('failed_at')
                ->limit(10)
                ->get(['uuid', 'queue', 'payload', 'exception', 'failed_at'])
                ->map(function ($row) {
                    $payload = json_decode((string) $row->payload, true) ?? [];
                    $jobName = $payload['displayName'] ?? class_basename($payload['job'] ?? 'unknown');

                    return [
                        'uuid' => $row->uuid,
                        'queue' => $row->queue,
                        'job' => $jobName,
                        'failed_at' => $row->failed_at,
                        'exception' => $this->truncateException((string) $row->exception),
                    ];
                })
                ->all();
        }

        return [
            'connection' => config('queue.default'),
            'pending_jobs' => $pendingJobs,
            'failed_jobs' => $failedJobs,
            'healthy' => $failedJobs === 0,
            'worker_required' => $pendingJobs > 0,
            'recent_failures' => $recentFailures,
            'commands' => [
                'work' => 'php artisan queue:work',
                'failed' => 'php artisan queue:failed',
                'retry_all' => 'php artisan queue:retry all',
                'audit' => 'php artisan crm:queue-audit',
            ],
        ];
    }

    private function truncateException(string $exception): string
    {
        $firstLine = strtok($exception, "\n") ?: $exception;

        return strlen($firstLine) > 240 ? substr($firstLine, 0, 237).'...' : $firstLine;
    }
}

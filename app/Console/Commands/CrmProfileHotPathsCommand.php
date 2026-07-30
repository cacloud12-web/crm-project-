<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Local/staging request profiler for CRM hot paths.
 * Does not change application behavior — read-only measurements.
 */
class CrmProfileHotPathsCommand extends Command
{
    protected $signature = 'crm:profile-hot-paths
                            {--base= : Base URL (default APP_URL)}
                            {--token= : Bearer token for API routes}
                            {--cookie= : Session cookie header value}
                            {--json : Emit JSON only}';

    protected $description = 'Profile dashboard / listing API cold paths with DB query counting';

    public function handle(): int
    {
        $base = rtrim((string) ($this->option('base') ?: config('app.url')), '/');
        $paths = [
            ['name' => 'Dashboard metrics', 'method' => 'GET', 'path' => '/api/dashboard/metrics'],
            ['name' => 'Employee dashboard', 'method' => 'GET', 'path' => '/api/dashboard/employee'],
            ['name' => 'Master Data page', 'method' => 'GET', 'path' => '/api/ca-masters?page=1&per_page=25'],
            ['name' => 'Assignments', 'method' => 'GET', 'path' => '/api/lead-assignments?page=1&per_page=25'],
            ['name' => 'Follow-ups', 'method' => 'GET', 'path' => '/api/follow-ups?page=1&per_page=25'],
            ['name' => 'Login page', 'method' => 'GET', 'path' => '/login'],
        ];

        $rows = [];
        foreach ($paths as $path) {
            $rows[] = $this->profilePath($base, $path);
        }

        if ($this->option('json')) {
            $this->line(json_encode(['base' => $base, 'rows' => $rows], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->table(
            ['Route', 'HTTP ms', 'Queries', 'Query ms', 'Slowest query', 'Memory', 'Status'],
            array_map(static function (array $row) {
                return [
                    $row['name'],
                    $row['http_ms'] === null ? 'n/a' : $row['http_ms'].' ms',
                    $row['queries'] ?? 'n/a',
                    isset($row['query_ms']) ? $row['query_ms'].' ms' : 'n/a',
                    $row['slowest'] ?? 'n/a',
                    $row['memory'] ?? 'n/a',
                    $row['status'] ?? 'n/a',
                ];
            }, $rows)
        );

        $this->newLine();
        $this->info('Service-level DB profile (no HTTP):');
        $this->profileServices();

        return self::SUCCESS;
    }

    /**
     * @param  array{name: string, method: string, path: string}  $path
     * @return array<string, mixed>
     */
    private function profilePath(string $base, array $path): array
    {
        $headers = ['Accept' => 'application/json'];
        if ($token = $this->option('token')) {
            $headers['Authorization'] = 'Bearer '.$token;
        }
        if ($cookie = $this->option('cookie')) {
            $headers['Cookie'] = $cookie;
        }

        $url = $base.$path['path'];
        $started = microtime(true);
        $status = null;
        $error = null;

        try {
            $response = Http::withHeaders($headers)
                ->timeout(60)
                ->send($path['method'], $url);
            $status = $response->status();
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        $httpMs = (int) round((microtime(true) - $started) * 1000);

        return [
            'name' => $path['name'].' '.$path['path'],
            'http_ms' => $httpMs,
            'status' => $error ? ('ERR: '.$error) : (string) $status,
            'queries' => null,
            'query_ms' => null,
            'slowest' => $error ? substr($error, 0, 80) : null,
            'memory' => $this->formatBytes(memory_get_peak_usage(true)),
        ];
    }

    private function profileServices(): void
    {
        if (! app()->bound(\App\Services\Dashboard\DashboardService::class)) {
            $this->warn('DashboardService not bound — skip service profile.');

            return;
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $memBefore = memory_get_usage(true);
        $t0 = microtime(true);

        try {
            /** @var \App\Services\Dashboard\DashboardService $dashboard */
            $dashboard = app(\App\Services\Dashboard\DashboardService::class);
            // Force cold build path when available.
            if (method_exists($dashboard, 'metrics')) {
                $dashboard->metrics(null, ['preset' => 'today']);
            }
        } catch (Throwable $e) {
            $this->warn('DashboardService profile failed: '.$e->getMessage());
        }

        $elapsed = (int) round((microtime(true) - $t0) * 1000);
        $log = DB::getQueryLog();
        $queryMs = (int) round(array_sum(array_column($log, 'time')));
        $slowest = collect($log)->sortByDesc('time')->first();
        DB::disableQueryLog();

        $this->line(sprintf(
            'DashboardService cold: %d ms wall · %d queries · %d ms SQL · peak +%s · slowest %.2f ms',
            $elapsed,
            count($log),
            $queryMs,
            $this->formatBytes(max(0, memory_get_usage(true) - $memBefore)),
            (float) ($slowest['time'] ?? 0),
        ));

        if ($slowest) {
            $this->line('  '.$this->truncateSql((string) ($slowest['query'] ?? '')));
        }
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / 1048576, 1).' MB';
    }

    private function truncateSql(string $sql): string
    {
        $sql = preg_replace('/\s+/', ' ', $sql) ?? $sql;

        return strlen($sql) > 220 ? substr($sql, 0, 217).'...' : $sql;
    }
}

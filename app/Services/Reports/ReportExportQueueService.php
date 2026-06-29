<?php

namespace App\Services\Reports;

use App\Jobs\Reports\ProcessReportExportJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class ReportExportQueueService
{
    private const CACHE_TTL_MINUTES = 120;

    public function shouldQueue(int $rowCount): bool
    {
        return $rowCount > (int) config('crm_queue.report_export_sync_row_limit', 500);
    }

    public function queue(string $slug, array $query, array $exportData, string $performedBy): array
    {
        $exportId = (string) Str::uuid();

        Cache::put($this->cacheKey($exportId), [
            'export_id' => $exportId,
            'slug' => $slug,
            'query' => $query,
            'filename' => $exportData['filename'],
            'columns' => $exportData['columns'],
            'rows' => $exportData['rows'],
            'performed_by' => $performedBy,
            'status' => 'Processing',
            'created_at' => now()->toIso8601String(),
        ], now()->addMinutes(self::CACHE_TTL_MINUTES));

        ProcessReportExportJob::dispatch($exportId);

        return [
            'export_id' => $exportId,
            'uses_background' => true,
            'status' => 'Processing',
            'row_count' => count($exportData['rows']),
        ];
    }

    public function process(string $exportId): void
    {
        $payload = Cache::get($this->cacheKey($exportId));

        if (! $payload) {
            throw new RuntimeException('Report export session not found.');
        }

        $directory = 'report-exports/'.$exportId;
        Storage::disk('local')->makeDirectory($directory);
        $path = $directory.'/'.$payload['filename'];

        $handle = fopen(Storage::disk('local')->path($path), 'w');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, array_values($payload['columns']));

        $keys = array_keys($payload['columns']);
        foreach ($payload['rows'] as $row) {
            $line = [];
            foreach ($keys as $key) {
                $line[] = $row[$key] ?? '';
            }
            fputcsv($handle, $line);
        }

        fclose($handle);

        Cache::put($this->cacheKey($exportId), array_merge($payload, [
            'status' => 'Completed',
            'storage_path' => $path,
            'completed_at' => now()->toIso8601String(),
        ]), now()->addMinutes(self::CACHE_TTL_MINUTES));
    }

    public function status(string $exportId): array
    {
        $payload = Cache::get($this->cacheKey($exportId));

        if (! $payload) {
            throw new RuntimeException('Report export not found.');
        }

        return [
            'export_id' => $exportId,
            'slug' => $payload['slug'],
            'status' => $payload['status'],
            'filename' => $payload['filename'],
            'row_count' => count($payload['rows'] ?? []),
            'download_ready' => ($payload['status'] ?? '') === 'Completed',
            'created_at' => $payload['created_at'] ?? null,
            'completed_at' => $payload['completed_at'] ?? null,
            'error' => $payload['error'] ?? null,
        ];
    }

    public function downloadPath(string $exportId): array
    {
        $payload = Cache::get($this->cacheKey($exportId));

        if (! $payload || ($payload['status'] ?? '') !== 'Completed') {
            throw new RuntimeException('Report export file is not ready.');
        }

        $path = Storage::disk('local')->path($payload['storage_path']);

        if (! is_file($path)) {
            throw new RuntimeException('Report export file is missing.');
        }

        return [
            'path' => $path,
            'file_name' => $payload['filename'],
            'mime' => 'text/csv; charset=UTF-8',
        ];
    }

    public function markFailed(string $exportId, string $message): void
    {
        $payload = Cache::get($this->cacheKey($exportId));

        if (! $payload) {
            return;
        }

        Cache::put($this->cacheKey($exportId), array_merge($payload, [
            'status' => 'Failed',
            'error' => $message,
            'completed_at' => now()->toIso8601String(),
        ]), now()->addMinutes(self::CACHE_TTL_MINUTES));
    }

    private function cacheKey(string $exportId): string
    {
        return 'crm:report-export:'.$exportId;
    }
}

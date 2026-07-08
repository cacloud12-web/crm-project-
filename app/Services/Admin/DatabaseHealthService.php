<?php

namespace App\Services\Admin;

use App\Support\Demo\DemoDataCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Throwable;

class DatabaseHealthService
{
    private const TABLES = [
        'ca_masters' => ['pk' => 'ca_id'],
        'employees' => ['pk' => 'employee_id'],
        'lead_assignment_engines' => ['pk' => 'assignment_id'],
        'assignment_histories' => ['pk' => 'id', 'created' => 'assigned_at'],
        'follow_ups' => ['pk' => 'followup_id'],
        'states' => ['pk' => 'state_id'],
        'cities' => ['pk' => 'city_id'],
        'source_leads' => ['pk' => 'source_id'],
        'team_size_masters' => ['pk' => 'id'],
        'role_masters' => ['pk' => 'id'],
        'bulk_actions' => ['pk' => 'bulk_action_id'],
        'bulk_action_logs' => ['pk' => 'log_id'],
        'activity_logs' => ['pk' => 'id'],
        'api_rate_limits' => ['pk' => 'rate_id'],
        'throttle_logs' => ['pk' => 'throttle_id'],
        'retry_logics' => ['pk' => 'retry_id'],
        'failed_queues' => ['pk' => 'queue_id'],
        'bounce_handlings' => ['pk' => 'bounce_id'],
        'spam_protections' => ['pk' => 'spam_log_id'],
        'email_campaigns' => ['pk' => 'id'],
        'email_logs' => ['pk' => 'id'],
        'whatsapp_campaigns' => ['pk' => 'id'],
        'wa_message_logs' => ['pk' => 'id'],
        'queue_jobs' => ['pk' => 'id'],
        'queue_logs' => ['pk' => 'id'],
        'lead_actions' => ['pk' => 'action_id'],
        'crm_settings' => ['pk' => 'id'],
        'sms_campaigns' => ['pk' => 'id'],
        'sms_logs' => ['pk' => 'id'],
        'consent_trackings' => ['pk' => 'id'],
        'dnd_management' => ['pk' => 'id'],
        'crm_notifications' => ['pk' => 'id'],
    ];

    private const DUPLICATE_CHECKS = [
        ['table' => 'ca_masters', 'column' => 'mobile_no'],
        ['table' => 'ca_masters', 'column' => 'email_id'],
        ['table' => 'ca_masters', 'column' => 'gst_no'],
        ['table' => 'employees', 'column' => 'email_id'],
        ['table' => 'employees', 'column' => 'mobile_no'],
    ];

    private const API_ROUTES = [
        ['path' => 'ca-masters', 'method' => 'GET'],
        ['path' => 'employees', 'method' => 'GET'],
        ['path' => 'lead-assignments', 'method' => 'GET'],
        ['path' => 'follow-ups', 'method' => 'GET'],
        ['path' => 'states', 'method' => 'GET'],
        ['path' => 'cities', 'method' => 'GET'],
        ['path' => 'source-leads', 'method' => 'GET'],
        ['path' => 'team-sizes', 'method' => 'GET'],
        ['path' => 'role-masters', 'method' => 'GET'],
        ['path' => 'ca-masters/bulk-import/sample.csv', 'method' => 'GET'],
        ['path' => 'ca-masters/bulk-import/sample.xlsx', 'method' => 'GET'],
        ['path' => 'ca-masters/bulk-import/history', 'method' => 'GET'],
        ['path' => 'ca-masters/bulk-import/parse', 'method' => 'POST'],
        ['path' => 'ca-masters/bulk-import/validate', 'method' => 'POST'],
        ['path' => 'ca-masters/bulk-import', 'method' => 'POST'],
        ['path' => 'ca-masters/bulk-import/mapping-templates', 'method' => 'GET'],
        ['path' => 'lead-assignments/bulk', 'method' => 'POST'],
        ['path' => 'whatsapp-campaigns', 'method' => 'GET'],
        ['path' => 'whatsapp-campaigns', 'method' => 'POST'],
        ['path' => 'wa-message-logs', 'method' => 'GET'],
        ['path' => 'email-campaigns', 'method' => 'GET'],
        ['path' => 'email-campaigns', 'method' => 'POST'],
        ['path' => 'email-logs', 'method' => 'GET'],
        ['path' => 'dashboard/metrics', 'method' => 'GET'],
    ];

    public function report(): array
    {
        $tables = $this->inspectTables();
        $duplicates = $this->duplicateChecks();
        $foreignKeys = $this->foreignKeyChecks();

        $summary = [
            'total_tables' => count(self::TABLES),
            'healthy_tables' => count(array_filter($tables, fn (array $t) => $t['status'] === 'healthy')),
            'empty_tables' => count(array_filter($tables, fn (array $t) => $t['status'] === 'empty')),
            'future_module_tables' => count(array_filter($tables, fn (array $t) => $t['status'] === 'future_module')),
            'missing_tables' => count(array_filter($tables, fn (array $t) => $t['status'] === 'missing')),
            'error_tables' => count(array_filter($tables, fn (array $t) => $t['status'] === 'error')),
            'duplicate_issues' => count(array_filter($duplicates, fn (array $d) => $d['duplicate_count'] > 0)),
            'fk_issues' => count(array_filter($foreignKeys, fn (array $f) => $f['invalid_count'] > 0)),
        ];

        return [
            'generated_at' => now()->toIso8601String(),
            'database' => $this->databaseInfo(),
            'summary' => $summary,
            'tables' => $tables,
            'duplicates' => $duplicates,
            'foreign_keys' => $foreignKeys,
            'api_routes' => $this->apiRouteChecks(),
        ];
    }

    private function inspectTables(): array
    {
        $results = [];

        foreach (self::TABLES as $tableName => $meta) {
            $results[] = $this->inspectTable($tableName, $meta);
        }

        return $results;
    }

    private function inspectTable(string $tableName, array $meta): array
    {
        $futureMeta = DemoDataCatalog::FUTURE_MODULE_TABLES[$tableName] ?? null;

        $base = [
            'table_name' => $tableName,
            'exists' => false,
            'total_records' => 0,
            'latest_record_id' => null,
            'latest_created_at' => null,
            'status' => 'missing',
            'classification' => null,
            'module' => $futureMeta,
            'error' => null,
        ];

        try {
            if (! Schema::hasTable($tableName)) {
                return $base;
            }

            $base['exists'] = true;
            $base['total_records'] = (int) DB::table($tableName)->count();

            if ($base['total_records'] === 0) {
                if ($futureMeta !== null) {
                    $base['status'] = 'future_module';
                    $base['classification'] = 'Future module / intentionally empty';
                } else {
                    $base['status'] = 'empty';
                }

                return $base;
            }

            $pk = $meta['pk'];
            $createdColumn = $meta['created'] ?? 'created_at';

            if (! Schema::hasColumn($tableName, $pk)) {
                $base['status'] = 'error';
                $base['error'] = "Primary key column [{$pk}] not found.";

                return $base;
            }

            $selectColumns = [$pk];
            if (Schema::hasColumn($tableName, $createdColumn)) {
                $selectColumns[] = $createdColumn;
            }

            $latestQuery = DB::table($tableName)->select($selectColumns);

            if (Schema::hasColumn($tableName, $createdColumn)) {
                $latestQuery->orderByDesc($createdColumn);
            } else {
                $latestQuery->orderByDesc($pk);
            }

            $latest = $latestQuery->first();

            if ($latest) {
                $base['latest_record_id'] = $latest->{$pk};
                if (Schema::hasColumn($tableName, $createdColumn)) {
                    $base['latest_created_at'] = $latest->{$createdColumn};
                }
            }

            $base['status'] = 'healthy';
            if ($futureMeta !== null) {
                $base['classification'] = 'Future module (has data)';
            } else {
                $base['classification'] = 'Active / connected';
            }
        } catch (Throwable $e) {
            $base['exists'] = Schema::hasTable($tableName);
            $base['status'] = 'error';
            $base['error'] = $e->getMessage();
        }

        return $base;
    }

    private function duplicateChecks(): array
    {
        $results = [];

        foreach (self::DUPLICATE_CHECKS as $check) {
            $results[] = $this->duplicateCheck($check['table'], $check['column']);
        }

        return $results;
    }

    private function duplicateCheck(string $table, string $column): array
    {
        $field = "{$table}.{$column}";
        $result = [
            'field' => $field,
            'duplicate_count' => 0,
            'duplicate_groups' => 0,
            'sample_values' => [],
            'status' => 'healthy',
            'error' => null,
        ];

        try {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                $result['status'] = 'missing';

                return $result;
            }

            $duplicates = DB::table($table)
                ->select($column, DB::raw('COUNT(*) as row_count'))
                ->whereNotNull($column)
                ->where($column, '!=', '')
                ->groupBy($column)
                ->havingRaw('COUNT(*) > 1')
                ->orderByDesc('row_count')
                ->limit(10)
                ->get();

            $duplicateGroups = $duplicates->count();
            $extraRows = $duplicates->sum(fn ($row) => max(0, (int) $row->row_count - 1));

            $result['duplicate_groups'] = $duplicateGroups;
            $result['duplicate_count'] = (int) $extraRows;
            $result['sample_values'] = $duplicates->pluck($column)->take(5)->values()->all();
            $result['status'] = $extraRows > 0 ? 'issue' : 'healthy';
        } catch (Throwable $e) {
            $result['status'] = 'error';
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    private function foreignKeyChecks(): array
    {
        $checks = [
            $this->invalidReferenceCheck(
                'ca_masters.state_id → states',
                'ca_masters as child',
                'states as parent',
                'child.state_id',
                'parent.state_id',
                ['child.ca_id', 'child.state_id'],
            ),
            $this->invalidReferenceCheck(
                'ca_masters.city_id → cities',
                'ca_masters as child',
                'cities as parent',
                'child.city_id',
                'parent.city_id',
                ['child.ca_id', 'child.city_id'],
            ),
            $this->invalidReferenceCheck(
                'ca_masters.source_id → source_leads',
                'ca_masters as child',
                'source_leads as parent',
                'child.source_id',
                'parent.source_id',
                ['child.ca_id', 'child.source_id'],
            ),
            $this->invalidReferenceCheck(
                'lead_assignment_engines.ca_id → ca_masters',
                'lead_assignment_engines as child',
                'ca_masters as parent',
                'child.ca_id',
                'parent.ca_id',
                ['child.assignment_id', 'child.ca_id'],
            ),
            $this->invalidReferenceCheck(
                'lead_assignment_engines.employee_id → employees',
                'lead_assignment_engines as child',
                'employees as parent',
                'child.employee_id',
                'parent.employee_id',
                ['child.assignment_id', 'child.employee_id'],
            ),
            $this->invalidReferenceCheck(
                'follow_ups.ca_id → ca_masters',
                'follow_ups as child',
                'ca_masters as parent',
                'child.ca_id',
                'parent.ca_id',
                ['child.followup_id', 'child.ca_id'],
            ),
            $this->invalidReferenceCheck(
                'follow_ups.employee_id → employees',
                'follow_ups as child',
                'employees as parent',
                'child.employee_id',
                'parent.employee_id',
                ['child.followup_id', 'child.employee_id'],
            ),
            $this->invalidReferenceCheck(
                'assignment_histories.ca_id → ca_masters',
                'assignment_histories as child',
                'ca_masters as parent',
                'child.ca_id',
                'parent.ca_id',
                ['child.id', 'child.ca_id'],
            ),
            $this->invalidReferenceCheck(
                'assignment_histories.new_employee_id → employees',
                'assignment_histories as child',
                'employees as parent',
                'child.new_employee_id',
                'parent.employee_id',
                ['child.id', 'child.new_employee_id'],
            ),
        ];

        return $checks;
    }

    private function invalidReferenceCheck(
        string $label,
        string $childTable,
        string $parentTable,
        string $childColumn,
        string $parentColumn,
        array $sampleColumns,
    ): array {
        $result = [
            'check' => $label,
            'invalid_count' => 0,
            'sample_invalid_rows' => [],
            'status' => 'healthy',
            'error' => null,
        ];

        try {
            [$childName] = explode(' ', $childTable);
            [$parentName] = explode(' ', $parentTable);

            if (! Schema::hasTable($childName) || ! Schema::hasTable($parentName)) {
                $result['status'] = 'missing';

                return $result;
            }

            $childCol = str_contains($childColumn, '.') ? explode('.', $childColumn)[1] : $childColumn;
            if (! Schema::hasColumn($childName, $childCol)) {
                $result['status'] = 'missing';

                return $result;
            }

            $baseQuery = DB::table($childTable)
                ->leftJoin($parentTable, $childColumn, '=', $parentColumn)
                ->whereNotNull(DB::raw($childColumn))
                ->whereNull(DB::raw($parentColumn));

            $result['invalid_count'] = (int) (clone $baseQuery)->count();
            $result['sample_invalid_rows'] = (clone $baseQuery)
                ->select($sampleColumns)
                ->limit(5)
                ->get()
                ->map(fn ($row) => (array) $row)
                ->all();
            $result['status'] = $result['invalid_count'] > 0 ? 'issue' : 'healthy';
        } catch (Throwable $e) {
            $result['status'] = 'error';
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    private function databaseInfo(): array
    {
        $databaseName = DB::connection()->getDatabaseName();
        $info = [
            'name' => $databaseName,
            'driver' => DB::connection()->getDriverName(),
            'total_size' => null,
            'total_size_bytes' => null,
            'tables' => [],
        ];

        try {
            if ($info['driver'] === 'pgsql') {
                $sizeRow = DB::selectOne(
                    'SELECT pg_database_size(?) AS size_bytes, pg_size_pretty(pg_database_size(?)) AS size_pretty',
                    [$databaseName, $databaseName],
                );

                if ($sizeRow) {
                    $info['total_size_bytes'] = (int) $sizeRow->size_bytes;
                    $info['total_size'] = $sizeRow->size_pretty;
                }

                $tableSizes = DB::select(
                    'SELECT relname AS table_name,
                  pg_total_relation_size(relid) AS size_bytes,
                  pg_size_pretty(pg_total_relation_size(relid)) AS size_pretty
           FROM pg_catalog.pg_statio_user_tables
           WHERE schemaname = current_schema()
           ORDER BY pg_total_relation_size(relid) DESC',
                );

                $info['tables'] = collect($tableSizes)
                    ->map(fn ($row) => [
                        'table_name' => $row->table_name,
                        'size_bytes' => (int) $row->size_bytes,
                        'size' => $row->size_pretty,
                    ])
                    ->values()
                    ->all();
            } elseif ($info['driver'] === 'mysql') {
                $sizeRow = DB::selectOne(
                    'SELECT SUM(data_length + index_length) AS size_bytes
                     FROM information_schema.tables
                     WHERE table_schema = ?',
                    [$databaseName],
                );

                if ($sizeRow && $sizeRow->size_bytes !== null) {
                    $info['total_size_bytes'] = (int) $sizeRow->size_bytes;
                    $info['total_size'] = $this->formatBytes((int) $sizeRow->size_bytes);
                }

                $tableSizes = DB::select(
                    'SELECT table_name,
                            (data_length + index_length) AS size_bytes
                     FROM information_schema.tables
                     WHERE table_schema = ?
                     ORDER BY (data_length + index_length) DESC',
                    [$databaseName],
                );

                $info['tables'] = collect($tableSizes)
                    ->map(fn ($row) => [
                        'table_name' => $row->table_name,
                        'size_bytes' => (int) $row->size_bytes,
                        'size' => $this->formatBytes((int) $row->size_bytes),
                    ])
                    ->values()
                    ->all();
            }
        } catch (Throwable $e) {
            $info['error'] = $e->getMessage();
        }

        return $info;
    }

    private function apiRouteChecks(): array
    {
        $registered = collect(Route::getRoutes())->flatMap(function ($route) {
            return collect($route->methods())
                ->reject(fn (string $method) => $method === 'HEAD')
                ->map(fn (string $method) => [
                    'uri' => $route->uri(),
                    'method' => $method,
                ]);
        });

        return array_map(function (array $expected) use ($registered) {
            $exists = $registered->contains(function (array $route) use ($expected) {
                return $route['uri'] === $expected['path'] && $route['method'] === $expected['method'];
            });

            return [
                'path' => '/'.$expected['path'],
                'method' => $expected['method'],
                'route_exists' => $exists,
                'status' => $exists ? 'healthy' : 'missing',
            ];
        }, self::API_ROUTES);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1048576) {
            return round($bytes / 1024, 1).' KB';
        }

        if ($bytes < 1073741824) {
            return round($bytes / 1048576, 1).' MB';
        }

        return round($bytes / 1073741824, 2).' GB';
    }
}

<?php

namespace App\Support\Listing;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;

class ListingQueryApplier
{
    public static function apply(Builder $query, array $params, array $config): array
    {
        $params = self::normalizeParams($params, $config);

        self::applyColumnProjection($query, $config);
        self::applyListingFilters($query, $params, $config);
        self::applySort($query, $params, $config);

        $all = filter_var($params['all'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $maxAll = (int) ($config['max_all'] ?? config('listing.max_all', 5000));

        if ($all) {
            $items = $query->limit($maxAll)->get();

            return [
                'items' => $items,
                'pagination' => null,
                'meta' => self::buildMeta($params, $items->count()),
            ];
        }

        $perPage = self::resolvePerPage($params, $config);
        $page = max((int) ($params['page'] ?? 1), 1);

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return [
            'items' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'meta' => self::buildMeta($params, $paginator->total()),
        ];
    }

    public static function applyListingFilters(Builder $query, array $params, array $config): array
    {
        $params = self::normalizeParams($params, $config);

        self::applySearch($query, $params, $config);
        self::applyColumnFilters($query, $params, $config);
        self::applyDateRange($query, $params, $config);

        return $params;
    }

    public static function config(string $key): array
    {
        return config("listing.{$key}", []);
    }

    /**
     * Resolve per-page size for a listing config.
     * When allowed_per_page is configured, only those values are accepted;
     * anything else falls back to default_per_page (or 10).
     */
    public static function resolvePerPage(array $params, array $config): int
    {
        $default = (int) ($config['default_per_page'] ?? config('listing.default_per_page', 10));
        if ($default < 1) {
            $default = 10;
        }

        $requested = (int) ($params['per_page'] ?? $default);
        $allowed = $config['allowed_per_page'] ?? null;

        if (is_array($allowed) && $allowed !== []) {
            $allowed = array_values(array_map('intval', $allowed));
            if (in_array($requested, $allowed, true)) {
                return $requested;
            }

            return in_array($default, $allowed, true) ? $default : $allowed[0];
        }

        $max = (int) ($config['max_per_page'] ?? config('listing.max_per_page', 100));

        return min(max($requested, 1), max(1, $max));
    }

    public static function applyColumnProjection(Builder $query, array $config): void
    {
        $exclude = $config['exclude_columns'] ?? [];
        if ($exclude === []) {
            return;
        }

        $table = $query->getModel()->getTable();
        $columns = array_values(array_diff(Schema::getColumnListing($table), $exclude));
        if ($columns === []) {
            return;
        }

        $query->select(array_map(static fn (string $column) => $table.'.'.$column, $columns));
    }

    private static function normalizeParams(array $params, array $config): array
    {
        if (! empty($params['q']) && empty($params['search'])) {
            $params['search'] = $params['q'];
        }

        if (! empty($params['sort']) && empty($params['sort_by'])) {
            $params['sort_by'] = $params['sort'];
        }

        if (! empty($params['direction']) && empty($params['sort_dir'])) {
            $params['sort_dir'] = $params['direction'];
        }

        if (! empty($params['date_from']) && empty($params['from'])) {
            $params['from'] = $params['date_from'];
        }

        if (! empty($params['date_to']) && empty($params['to'])) {
            $params['to'] = $params['date_to'];
        }

        if (! empty($params['filters']) && is_array($params['filters'])) {
            $params = array_merge($params, $params['filters']);
        }

        $sortable = $config['sortable'] ?? [];
        $sortBy = (string) ($params['sort_by'] ?? $config['default_sort'] ?? 'created_at');
        if ($sortable !== [] && ! in_array($sortBy, $sortable, true)) {
            $params['sort_by'] = $config['default_sort'] ?? 'created_at';
        }

        $sortDir = strtolower((string) ($params['sort_dir'] ?? $config['default_sort_dir'] ?? 'desc'));
        $params['sort_dir'] = in_array($sortDir, ['asc', 'desc'], true) ? $sortDir : 'desc';

        return $params;
    }

    private static function applySearch(Builder $query, array $params, array $config): void
    {
        $term = trim((string) ($params['search'] ?? ''));
        if ($term === '') {
            return;
        }

        $columns = $config['search_columns'] ?? [];
        $relations = $config['search_relations'] ?? [];
        $escaped = '%'.addcslashes($term, '%_\\').'%';
        $table = $query->getModel()->getTable();

        $query->where(function (Builder $outer) use ($columns, $relations, $escaped, $table) {
            foreach ($columns as $column) {
                $qualified = str_contains($column, '.') ? $column : $table.'.'.$column;
                self::whereIlike($outer, $qualified, $escaped, 'or');
            }

            foreach ($relations as $relation => $relationColumns) {
                $outer->orWhereHas($relation, function (Builder $relationQuery) use ($relationColumns, $escaped) {
                    $relationQuery->where(function (Builder $inner) use ($relationColumns, $escaped) {
                        foreach ($relationColumns as $column) {
                            self::whereIlike($inner, $column, $escaped, 'or');
                        }
                    });
                });
            }
        });
    }

    /**
     * Case-insensitive LIKE that works on MySQL, SQLite, and PostgreSQL
     * even when the custom ILIKE query grammar is not registered yet.
     */
    private static function whereIlike(Builder $query, string $column, string $likeValue, string $boolean = 'and'): void
    {
        $driver = $query->getConnection()->getDriverName();
        $method = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';

        if ($driver === 'pgsql') {
            $query->{$method}($column.' ILIKE ?', [$likeValue]);

            return;
        }

        $query->{$method}('LOWER('.$column.') LIKE LOWER(?)', [$likeValue]);
    }

    private static function applyColumnFilters(Builder $query, array $params, array $config): void
    {
        $filters = $config['filters'] ?? [];

        foreach ($filters as $key => $type) {
            if (! array_key_exists($key, $params)) {
                continue;
            }

            $value = $params[$key];
            if ($value === null || $value === '') {
                continue;
            }

            match ($type) {
                'exact' => $query->where($key, $value),
                'exact_int' => $query->where($key, (int) $value),
                'min_int' => $query->where(str_ends_with($key, '_min') ? substr($key, 0, -4) : $key, '>=', (int) $value),
                'max_int' => $query->where(str_ends_with($key, '_max') ? substr($key, 0, -4) : $key, '<=', (int) $value),
                'min_decimal' => $query->where(str_ends_with($key, '_min') ? substr($key, 0, -4) : $key, '>=', (float) $value),
                'max_decimal' => $query->where(str_ends_with($key, '_max') ? substr($key, 0, -4) : $key, '<=', (float) $value),
                'purchase_date_exact' => $query->whereDate('purchase_date', $value),
                'expiry_date_exact' => $query->whereDate('expiry_date', $value),
                'team_size_min' => $query->where('team_size', '>=', (int) $value),
                'team_size_max' => $query->where('team_size', '<=', (int) $value),
                'rating_min' => $query->where('rating', '>=', (int) $value),
                'rating_max' => $query->where('rating', '<=', (int) $value),
                'boolean' => $query->where($key, filter_var($value, FILTER_VALIDATE_BOOLEAN)),
                'ilike' => self::whereIlike($query, $key, '%'.addcslashes((string) $value, '%_\\').'%'),
                'performed_by_ilike' => self::whereIlike($query, 'performed_by', '%'.$value.'%'),
                'date_exact' => $query->whereDate($config['date_column'] ?? 'created_at', $value),
                'city_name' => $query->whereHas('city', function (Builder $q) use ($value) {
                    self::whereIlike($q, 'city_name', '%'.addcslashes((string) $value, '%_\\').'%');
                }),
                'state_name' => $query->whereHas('state', function (Builder $q) use ($value) {
                    self::whereIlike($q, 'state_name', '%'.addcslashes((string) $value, '%_\\').'%');
                }),
                'source_name' => $query->whereHas('sourceLead', function (Builder $q) use ($value) {
                    self::whereIlike($q, 'source_name', '%'.addcslashes((string) $value, '%_\\').'%');
                }),
                'executive_name' => $query->whereHas('activeAssignment.employee', function (Builder $q) use ($value) {
                    self::whereIlike($q, 'name', '%'.addcslashes((string) $value, '%_\\').'%');
                }),
                'team_size_search' => self::applyTeamSizeSearch($query, $value),
                'employee_name' => $query->whereHas('employee', function (Builder $q) use ($value) {
                    self::whereIlike($q, 'name', '%'.addcslashes((string) $value, '%_\\').'%');
                }),
                'employee_name_exact' => $query->whereHas('employee', fn (Builder $q) => $q->where('name', $value)),
                'manager_name_exact' => $query->whereHas('manager', fn (Builder $q) => $q->where('name', $value)),
                'segment' => self::applySegment($query, (string) $value),
                'master_pipeline_stage' => self::applyMasterPipelineStage($query, (string) $value),
                'lead_tag' => self::applyLeadTag($query, (string) $value),
                'followup_due' => self::applyFollowupDue($query, (string) $value, $config),
                default => null,
            };
        }
    }

    private static function applyLeadTag(Builder $query, string $tag): void
    {
        $query->whereJsonContains('lead_tags', $tag);
    }

    private static function applyTeamSizeSearch(Builder $query, mixed $value): void
    {
        $term = trim((string) $value);
        if ($term === '') {
            return;
        }

        $normalized = strtolower($term);
        if (in_array($normalized, ['not specified', 'not', 'unspecified', 'empty', 'null', 'none', 'n/a', 'na'], true)) {
            $query->where(function (Builder $inner) {
                $inner->whereNull('team_size')->orWhere('team_size', '<=', 0);
            });

            return;
        }

        if (is_numeric($term)) {
            $query->where('team_size', (int) $term);

            return;
        }

        $escaped = '%'.addcslashes($term, '%_\\').'%';
        $table = $query->getModel()->getTable();
        $driver = $query->getConnection()->getDriverName();
        $castExpression = match ($driver) {
            'sqlite' => "CAST({$table}.team_size AS TEXT)",
            'mysql' => "CAST({$table}.team_size AS CHAR)",
            default => "CAST({$table}.team_size AS VARCHAR)",
        };
        $operator = $driver === 'pgsql' ? 'ILIKE' : 'LIKE';

        $query->whereRaw("{$castExpression} {$operator} ?", [$escaped]);
    }

    private static function applyMasterPipelineStage(Builder $query, string $stage): void
    {
        $stage = trim($stage);
        if ($stage === '') {
            return;
        }

        $statuses = config('crm_master_pipeline.stage_statuses.'.$stage);

        if (is_array($statuses) && $statuses !== []) {
            $query->whereIn('status', $statuses);

            return;
        }

        $query->whereRaw('1 = 0');
    }

    private static function applySegment(Builder $query, string $segment): void
    {
        match ($segment) {
            'new' => $query->where('is_newly_established', true),
            'hot' => $query->where('status', 'Hot'),
            'cold' => $query->where('status', 'Cold'),
            'pipeline' => $query->whereIn('status', \App\Support\CrmPipeline::pipelineSegmentStatuses()),
            'negotiation' => $query->whereIn('status', ['Negotiation', 'Hot']),
            'lost' => $query->whereIn('status', ['Lost', 'Inactive']),
            'mobile_missing' => $query->where(function (Builder $inner) {
                $inner->whereNull('mobile_no')->orWhere('mobile_no', '');
            }),
            default => null,
        };
    }

    private static function applyFollowupDue(Builder $query, string $due, array $config): void
    {
        $column = $config['date_column'] ?? 'scheduled_date';
        $openStatuses = ['Pending', 'Scheduled', 'Open'];

        match ($due) {
            'today' => $query->whereDate($column, now()->toDateString())
                ->whereIn('status', $openStatuses),
            'overdue' => $query->whereDate($column, '<', now()->toDateString())
                ->whereIn('status', $openStatuses),
            'pending' => $query->whereIn('status', $openStatuses),
            'completed' => $query->whereIn('status', ['Completed', 'Closed']),
            default => null,
        };
    }

    private static function applyDateRange(Builder $query, array $params, array $config): void
    {
        $column = $config['date_column'] ?? 'created_at';
        $from = $params['from'] ?? $params['date_from'] ?? null;
        $to = $params['to'] ?? $params['date_to'] ?? null;

        if ($from) {
            $query->whereDate($column, '>=', $from);
        }

        if ($to) {
            $query->whereDate($column, '<=', $to);
        }
    }

    private static function applySort(Builder $query, array $params, array $config): void
    {
        $sortBy = (string) ($params['sort_by'] ?? $config['default_sort'] ?? 'created_at');
        $sortDir = (string) ($params['sort_dir'] ?? $config['default_sort_dir'] ?? 'desc');
        $table = $query->getModel()->getTable();

        if ($sortBy === 'last_activity_at') {
            $query->orderByRaw(
                '(SELECT MAX(v) FROM (
                    SELECT called_at AS v FROM call_logs WHERE call_logs.ca_id = '.$table.'.ca_id
                    UNION ALL SELECT created_at FROM follow_up_histories WHERE follow_up_histories.ca_id = '.$table.'.ca_id
                    UNION ALL SELECT updated_at FROM follow_ups WHERE follow_ups.ca_id = '.$table.'.ca_id AND follow_ups.deleted_at IS NULL
                    UNION ALL SELECT action_at FROM lead_actions WHERE lead_actions.ca_id = '.$table.'.ca_id
                    UNION ALL SELECT assigned_at FROM assignment_histories WHERE assignment_histories.ca_id = '.$table.'.ca_id
                    UNION ALL SELECT COALESCE(reply_received_at, sent_at, created_at) FROM email_logs WHERE email_logs.ca_id = '.$table.'.ca_id
                    UNION ALL SELECT COALESCE(received_at, created_at) FROM email_inbound_messages WHERE email_inbound_messages.ca_id = '.$table.'.ca_id
                    UNION ALL SELECT COALESCE(sent_at, delivered_at, created_at) FROM wa_message_logs WHERE wa_message_logs.ca_id = '.$table.'.ca_id
                    UNION ALL SELECT COALESCE(sent_at, delivered_at, created_at) FROM sms_logs WHERE sms_logs.ca_id = '.$table.'.ca_id
                    UNION ALL SELECT recorded_at FROM lead_quality_histories WHERE lead_quality_histories.ca_id = '.$table.'.ca_id
                    UNION ALL SELECT created_at FROM ca_masters AS cm WHERE cm.ca_id = '.$table.'.ca_id
                    UNION ALL SELECT updated_at FROM ca_masters AS cm2 WHERE cm2.ca_id = '.$table.'.ca_id
                )) '.$sortDir
            );

            return;
        }

        if ($sortBy === 'team_members_count') {
            $query->orderByRaw(
                '(select count(*) from lead_assignment_engines where lead_assignment_engines.ca_id = '.$table.'.ca_id and lead_assignment_engines.status = ? and lead_assignment_engines.deleted_at is null) '.$sortDir,
                ['Active'],
            );

            return;
        }

        if (! str_contains($sortBy, '.')) {
            $sortBy = $table.'.'.$sortBy;
        }

        $query->orderBy($sortBy, $sortDir);
    }

    private static function buildMeta(array $params, int $total): array
    {
        return [
            'total' => $total,
            'search' => $params['search'] ?? null,
            'sort_by' => $params['sort_by'] ?? null,
            'sort_dir' => $params['sort_dir'] ?? null,
            'filters' => Arr::only($params, array_keys($params)),
        ];
    }
}

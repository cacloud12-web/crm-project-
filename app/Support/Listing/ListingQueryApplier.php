<?php

namespace App\Support\Listing;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;

class ListingQueryApplier
{
    public static function apply(Builder $query, array $params, array $config): array
    {
        $params = self::normalizeParams($params, $config);

        self::applySearch($query, $params, $config);
        self::applyColumnFilters($query, $params, $config);
        self::applyDateRange($query, $params, $config);
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

        $perPage = min(
            max((int) ($params['per_page'] ?? $config['default_per_page'] ?? config('listing.default_per_page', 25)), 1),
            (int) ($config['max_per_page'] ?? config('listing.max_per_page', 100)),
        );
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

    public static function config(string $key): array
    {
        return config("listing.{$key}", []);
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
        $escaped = '%'.addcslashes(strtolower($term), '%_\\').'%';

        $query->where(function (Builder $outer) use ($columns, $relations, $escaped) {
            foreach ($columns as $column) {
                $outer->orWhereRaw('LOWER('.$column.') LIKE ?', [$escaped]);
            }

            foreach ($relations as $relation => $relationColumns) {
                $outer->orWhereHas($relation, function (Builder $relationQuery) use ($relationColumns, $escaped) {
                    $relationQuery->where(function (Builder $inner) use ($relationColumns, $escaped) {
                        foreach ($relationColumns as $column) {
                            $inner->orWhereRaw('LOWER('.$column.') LIKE ?', [$escaped]);
                        }
                    });
                });
            }
        });
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
                'min_int' => $query->where($key, '>=', (int) $value),
                'max_int' => $query->where($key, '<=', (int) $value),
                'team_size_min' => $query->where('team_size', '>=', (int) $value),
                'team_size_max' => $query->where('team_size', '<=', (int) $value),
                'rating_min' => $query->where('rating', '>=', (int) $value),
                'rating_max' => $query->where('rating', '<=', (int) $value),
                'boolean' => $query->where($key, filter_var($value, FILTER_VALIDATE_BOOLEAN)),
                'performed_by_ilike' => $query->where('performed_by', 'ilike', '%'.$value.'%'),
                'date_exact' => $query->whereDate($config['date_column'] ?? 'created_at', $value),
                'city_name' => $query->whereHas('city', fn (Builder $q) => $q->where('city_name', 'ilike', $value)),
                'state_name' => $query->whereHas('state', fn (Builder $q) => $q->where('state_name', 'ilike', $value)),
                'segment' => self::applySegment($query, (string) $value),
                'followup_due' => self::applyFollowupDue($query, (string) $value, $config),
                default => null,
            };
        }
    }

    private static function applySegment(Builder $query, string $segment): void
    {
        match ($segment) {
            'new' => $query->where('is_newly_established', true),
            'hot' => $query->where('status', 'Hot'),
            'cold' => $query->where('status', 'Cold'),
            'pipeline' => $query->whereIn('status', ['Pipeline', 'Demo Scheduled', 'Negotiation', 'Details Shared']),
            'lost' => $query->whereIn('status', ['Lost', 'Inactive']),
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

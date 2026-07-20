<?php

namespace App\Services\Templates;

use App\Models\CrmTemplateVariable;
use Illuminate\Support\Collection;

class TemplateVariableCatalogService
{
    public function syncFromConfig(): void
    {
        $sort = 0;
        foreach (config('template_variables.groups', []) as $group => $items) {
            foreach ($items as $item) {
                $payload = [
                    'group_name' => $group,
                    'label' => $item['label'],
                    'sort_order' => $sort++,
                    'is_active' => true,
                ];

                $existing = CrmTemplateVariable::query()->where('variable_key', $item['key'])->first();
                if ($existing === null) {
                    CrmTemplateVariable::query()->create([
                        'variable_key' => $item['key'],
                        ...$payload,
                    ]);
                    continue;
                }

                if ($existing->group_name === $payload['group_name']
                    && $existing->label === $payload['label']
                    && (int) $existing->sort_order === $payload['sort_order']
                    && (bool) $existing->is_active === true) {
                    continue;
                }

                $existing->fill($payload)->save();
            }
        }
    }

    /**
     * @return array<string, array<int, array{key: string, label: string}>>
     */
    public function groupedForUi(): array
    {
        $fromDb = CrmTemplateVariable::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        if ($fromDb->isNotEmpty()) {
            return $fromDb
                ->groupBy('group_name')
                ->map(fn (Collection $rows) => $rows->map(fn ($row) => [
                    'key' => $row->variable_key,
                    'label' => $row->label,
                ])->values()->all())
                ->all();
        }

        $groups = [];
        foreach (config('template_variables.groups', []) as $group => $items) {
            $groups[$group] = $items;
        }

        return $groups;
    }

    public function categories(): array
    {
        return config('template_variables.categories', []);
    }
}

<?php

namespace App\Services\Templates;

use App\Models\CaMaster;
use App\Models\CrmTemplateVariable;
use App\Models\User;
use Illuminate\Support\Collection;

class TemplateVariableCatalogService
{
    public function syncFromConfig(): void
    {
        $sort = 0;
        foreach (config('template_variables.groups', []) as $group => $items) {
            foreach ($items as $item) {
                CrmTemplateVariable::query()->updateOrCreate(
                    ['variable_key' => $item['key']],
                    [
                        'group_name' => $group,
                        'label' => $item['label'],
                        'sort_order' => $sort++,
                        'is_active' => true,
                    ],
                );
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

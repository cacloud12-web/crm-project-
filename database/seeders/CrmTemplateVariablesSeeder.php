<?php

namespace Database\Seeders;

use App\Models\CrmTemplateVariable;
use App\Services\Templates\TemplateVariableCatalogService;
use Illuminate\Database\Seeder;

class CrmTemplateVariablesSeeder extends Seeder
{
    public function run(): void
    {
        $expected = 0;
        foreach (config('template_variables.groups', []) as $items) {
            $expected += count($items);
        }

        // Skip work when the catalog already matches config (avoids SQLite write locks in tests).
        if ($expected > 0 && CrmTemplateVariable::query()->count() === $expected) {
            $keys = collect(config('template_variables.groups', []))
                ->flatten(1)
                ->pluck('key')
                ->all();
            $existing = CrmTemplateVariable::query()->pluck('variable_key')->all();
            sort($keys);
            sort($existing);
            if ($keys === $existing) {
                return;
            }
        }

        app(TemplateVariableCatalogService::class)->syncFromConfig();
    }
}

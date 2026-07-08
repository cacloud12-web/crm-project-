<?php

namespace Database\Seeders;

use App\Services\Templates\TemplateVariableCatalogService;
use Illuminate\Database\Seeder;

class CrmTemplateVariablesSeeder extends Seeder
{
    public function run(): void
    {
        app(TemplateVariableCatalogService::class)->syncFromConfig();
    }
}

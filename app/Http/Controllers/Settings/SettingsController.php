<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\Settings\CrmSettingsService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function __construct(
        private readonly CrmSettingsService $crmSettingsService,
    ) {}

    public function show(): JsonResponse
    {
        return ApiResponse::success(
            $this->crmSettingsService->all(),
            'Settings loaded',
        );
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'general' => ['sometimes', 'array'],
            'general.company_name' => ['sometimes', 'string', 'max:255'],
            'general.timezone' => ['sometimes', 'string', 'max:64'],
            'general.date_format' => ['sometimes', 'string', 'max:32'],
            'general.default_city' => ['sometimes', 'string', 'max:120'],
            'assignment' => ['sometimes', 'array'],
            'assignment.auto_assignment' => ['sometimes', 'boolean'],
            'assignment.hot_lead_priority' => ['sometimes', 'boolean'],
            'assignment.workload_balancing' => ['sometimes', 'boolean'],
            'assignment.city_routing' => ['sometimes', 'boolean'],
        ]);

        $user = auth()->user();

        return ApiResponse::success(
            $this->crmSettingsService->save(
                $validated,
                $user?->name ?? $user?->email ?? 'System',
            ),
            'Settings saved successfully',
        );
    }
}

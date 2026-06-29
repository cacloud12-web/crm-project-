<?php

namespace App\Http\Controllers\Activity;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityLogResource;
use App\Services\Activity\ActivityLogService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    public function __construct(
        private readonly ActivityLogService $activityLogService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $params = $request->only([
            'module_name', 'action', 'date', 'user', 'limit', 'page', 'per_page',
            'search', 'q', 'sort_by', 'sort_dir', 'from', 'to', 'date_from', 'date_to',
        ]);

        if (! empty($params['limit']) && empty($params['page']) && empty($params['per_page'])) {
            $result = $this->activityLogService->list($params);

            return ApiResponse::success([
                'logs' => ActivityLogResource::collection($result['logs']),
                'filter_options' => $result['filter_options'],
            ], 'Activity logs loaded');
        }

        $result = $this->activityLogService->search($params);

        $payload = [
            'logs' => ActivityLogResource::collection($result['logs']),
            'filter_options' => $result['filter_options'],
        ];

        if ($result['pagination']) {
            $payload['pagination'] = $result['pagination'];
            $payload['meta'] = $result['meta'];
        }

        return ApiResponse::success($payload, 'Activity logs loaded');
    }
}

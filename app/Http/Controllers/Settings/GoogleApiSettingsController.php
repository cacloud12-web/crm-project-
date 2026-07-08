<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateGoogleApiSettingsRequest;
use App\Services\Leads\GooglePlacesApiService;
use App\Services\Settings\GoogleApiSettingsService;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;

class GoogleApiSettingsController extends Controller
{
    public function __construct(
        private readonly GoogleApiSettingsService $googleApiSettingsService,
        private readonly GooglePlacesApiService $googlePlacesApiService,
    ) {}

    public function show(): JsonResponse
    {
        try {
            $this->googleApiSettingsService->ensureCanView(auth()->user());
        } catch (AuthorizationException $exception) {
            return ApiResponse::error($exception->getMessage(), 403);
        }

        return ApiResponse::success(
            $this->googleApiSettingsService->toPublicArray(),
            'Google API settings loaded',
        );
    }

    public function update(UpdateGoogleApiSettingsRequest $request): JsonResponse
    {
        try {
            $data = $this->googleApiSettingsService->update(
                $request->validated(),
                auth()->user(),
            );
        } catch (AuthorizationException $exception) {
            return ApiResponse::error($exception->getMessage(), 403);
        }

        return ApiResponse::success($data, 'Google API settings saved');
    }

    public function test(): JsonResponse
    {
        try {
            $this->googleApiSettingsService->ensureCanView(auth()->user());
            $result = $this->googleApiSettingsService->testConnection($this->googlePlacesApiService);
        } catch (AuthorizationException $exception) {
            return ApiResponse::error($exception->getMessage(), 403);
        }

        return ApiResponse::success(
            $result,
            $result['valid'] ? $result['message'] : $result['message'],
        );
    }
}

<?php

namespace App\Http\Controllers\Communication;

use App\Http\Controllers\Controller;
use App\Http\Requests\Consent\StoreConsentTrackingRequest;
use App\Http\Resources\ConsentTrackingResource;
use App\Services\Consent\ConsentTrackingService;
use App\Support\ApiResponse;
use App\Support\Listing\ListingResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConsentTrackingController extends Controller
{
    public function __construct(
        private readonly ConsentTrackingService $consentTrackingService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $result = $this->consentTrackingService->search($request->query());

        return ListingResponse::from($result, ConsentTrackingResource::class, 'Consent records loaded');
    }

    public function store(StoreConsentTrackingRequest $request): JsonResponse
    {
        $consent = $this->consentTrackingService->upsert($request->validated());

        return ApiResponse::created(
            new ConsentTrackingResource($consent),
            'Consent saved successfully',
        );
    }
}

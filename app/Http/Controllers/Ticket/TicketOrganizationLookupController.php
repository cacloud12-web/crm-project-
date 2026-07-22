<?php

namespace App\Http\Controllers\Ticket;

use App\Contracts\Ticket\OrganizationLookupServiceInterface;
use App\Exceptions\Ticket\CaCloudDeskIntegrationException;
use App\Exceptions\Ticket\CaCloudDeskIntegrationNotConfiguredException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ticket\LookupTicketOrganizationsRequest;
use App\Http\Requests\Ticket\VerifyTicketOrganizationRequest;
use App\Models\SupportTicket;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

class TicketOrganizationLookupController extends Controller
{
    public function __construct(
        private readonly OrganizationLookupServiceInterface $organizationLookupService,
    ) {}

    public function index(LookupTicketOrganizationsRequest $request): JsonResponse
    {
        $this->authorize('create', SupportTicket::class);

        try {
            $result = $this->organizationLookupService->lookupByMobile(
                (string) $request->validated('mobile_number'),
                $request->user(),
            );

            return ApiResponse::success($result, 'Organizations loaded');
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        } catch (CaCloudDeskIntegrationNotConfiguredException $e) {
            return ApiResponse::error(
                'CA Cloud Desk organization lookup is not configured yet.',
                $e->httpStatus ?: 503,
            );
        } catch (CaCloudDeskIntegrationException $e) {
            return ApiResponse::error($e->getMessage(), $e->httpStatus ?: 500);
        }
    }

    public function verify(VerifyTicketOrganizationRequest $request): JsonResponse
    {
        $this->authorize('create', SupportTicket::class);

        try {
            $result = $this->organizationLookupService->verifyOrganization(
                (string) $request->validated('mobile_number'),
                (string) $request->validated('organization_number'),
                (string) $request->validated('correlation_id'),
                $request->user(),
            );

            return ApiResponse::success($result, 'Organization verified');
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        } catch (CaCloudDeskIntegrationNotConfiguredException $e) {
            return ApiResponse::error(
                'CA Cloud Desk organization lookup is not configured yet.',
                $e->httpStatus ?: 503,
            );
        } catch (CaCloudDeskIntegrationException $e) {
            return ApiResponse::error($e->getMessage(), $e->httpStatus ?: 500);
        }
    }
}

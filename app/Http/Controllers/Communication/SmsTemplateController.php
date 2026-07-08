<?php

namespace App\Http\Controllers\Communication;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sms\StoreSmsTemplateRequest;
use App\Http\Requests\Sms\UpdateSmsTemplateRequest;
use App\Services\Sms\SmsDltTemplateService;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SmsTemplateController extends Controller
{
    public function __construct(
        private readonly SmsDltTemplateService $smsDltTemplateService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $this->smsDltTemplateService->ensureCanViewTemplates(auth()->user());
        } catch (AuthorizationException $exception) {
            return ApiResponse::error($exception->getMessage(), 403);
        }

        $approvedOnly = filter_var($request->query('approved_only', false), FILTER_VALIDATE_BOOL);
        $templates = $approvedOnly
            ? $this->smsDltTemplateService->listApproved()
            : $this->smsDltTemplateService->listAll();

        return ApiResponse::success(
            $templates->map(fn ($template) => $this->smsDltTemplateService->toPublicArray($template))->values(),
            'SMS templates loaded',
        );
    }

    public function store(StoreSmsTemplateRequest $request): JsonResponse
    {
        try {
            return ApiResponse::created(
                $this->smsDltTemplateService->create($request->validated(), auth()->user()),
                'SMS template created',
            );
        } catch (AuthorizationException $exception) {
            return ApiResponse::error($exception->getMessage(), 403);
        }
    }

    public function update(UpdateSmsTemplateRequest $request, string $id): JsonResponse
    {
        try {
            return ApiResponse::success(
                $this->smsDltTemplateService->update(
                    $this->smsDltTemplateService->find((int) $id),
                    $request->validated(),
                    auth()->user(),
                ),
                'SMS template updated',
            );
        } catch (AuthorizationException $exception) {
            return ApiResponse::error($exception->getMessage(), 403);
        }
    }

    public function destroy(string $id): JsonResponse
    {
        try {
            $this->smsDltTemplateService->delete(
                $this->smsDltTemplateService->find((int) $id),
                auth()->user(),
            );
        } catch (AuthorizationException $exception) {
            return ApiResponse::error($exception->getMessage(), 403);
        }

        return ApiResponse::success(null, 'SMS template deleted');
    }

    public function preview(Request $request): JsonResponse
    {
        try {
            $this->smsDltTemplateService->ensureCanViewTemplates(auth()->user());
        } catch (AuthorizationException $exception) {
            return ApiResponse::error($exception->getMessage(), 403);
        }

        $data = $request->validate([
            'sms_template_id' => 'required|integer|exists:sms_templates,id',
            'lead_id' => 'required|integer|exists:ca_masters,ca_id',
        ]);

        try {
            $template = $this->smsDltTemplateService->findApproved((int) $data['sms_template_id']);
        } catch (ValidationException $exception) {
            return ApiResponse::error(
                collect($exception->errors())->flatten()->first() ?? 'Validation failed.',
                422,
                $exception->errors(),
            );
        }

        return ApiResponse::success(
            $this->smsDltTemplateService->preview($template, (int) $data['lead_id']),
            'DLT template preview generated',
        );
    }
}

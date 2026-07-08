<?php

namespace App\Http\Controllers\Communication;

use App\Http\Controllers\Controller;
use App\Http\Requests\Templates\StoreWhatsAppTemplateRequest;
use App\Http\Requests\Templates\UpdateWhatsAppTemplateRequest;
use App\Models\CaMaster;
use App\Services\Templates\WhatsAppTemplateManagementService;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MessageTemplateController extends Controller
{
    public function __construct(
        private readonly WhatsAppTemplateManagementService $templateService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            if ($request->boolean('paginate') || $request->has('page')) {
                $paginator = $this->templateService->paginate($request->only([
                    'search', 'category', 'publish_status', 'sort', 'dir', 'per_page',
                ]), auth()->user());

                return ApiResponse::success([
                    'items' => collect($paginator->items())->map(fn ($t) => $this->templateService->toPublicArray($t))->values(),
                    'pagination' => [
                        'current_page' => $paginator->currentPage(),
                        'last_page' => $paginator->lastPage(),
                        'per_page' => $paginator->perPage(),
                        'total' => $paginator->total(),
                    ],
                ], 'WhatsApp templates loaded');
            }

            $dispatchableOnly = $request->boolean('dispatchable');
            $templates = $this->templateService->listApproved($dispatchableOnly)
                ->map(fn ($template) => $this->templateService->toPublicArray($template))
                ->values();

            return ApiResponse::success($templates, 'WhatsApp templates loaded');
        } catch (AuthorizationException $exception) {
            return ApiResponse::error($exception->getMessage(), 403);
        }
    }

    public function show(string $id): JsonResponse
    {
        try {
            $this->templateService->ensureCanView(auth()->user());
            $template = $this->templateService->find((int) $id);

            return ApiResponse::success($this->templateService->toPublicArray($template), 'WhatsApp template loaded');
        } catch (AuthorizationException $exception) {
            return ApiResponse::error($exception->getMessage(), 403);
        }
    }

    public function store(StoreWhatsAppTemplateRequest $request): JsonResponse
    {
        try {
            $template = $this->templateService->create($request->validated(), auth()->user());

            return ApiResponse::created($this->templateService->toPublicArray($template), 'WhatsApp template created');
        } catch (AuthorizationException $exception) {
            return ApiResponse::error($exception->getMessage(), 403);
        }
    }

    public function update(UpdateWhatsAppTemplateRequest $request, string $id): JsonResponse
    {
        try {
            $template = $this->templateService->update(
                $this->templateService->find((int) $id),
                $request->validated(),
                auth()->user(),
            );

            return ApiResponse::success($this->templateService->toPublicArray($template), 'WhatsApp template updated');
        } catch (AuthorizationException $exception) {
            return ApiResponse::error($exception->getMessage(), 403);
        }
    }

    public function destroy(string $id): JsonResponse
    {
        try {
            $this->templateService->delete($this->templateService->find((int) $id), auth()->user());

            return ApiResponse::success(null, 'WhatsApp template deleted');
        } catch (AuthorizationException $exception) {
            return ApiResponse::error($exception->getMessage(), 403);
        }
    }

    public function duplicate(string $id): JsonResponse
    {
        try {
            $copy = $this->templateService->duplicate($this->templateService->find((int) $id), auth()->user());

            return ApiResponse::created($this->templateService->toPublicArray($copy), 'WhatsApp template duplicated');
        } catch (AuthorizationException $exception) {
            return ApiResponse::error($exception->getMessage(), 403);
        }
    }

    public function setStatus(Request $request, string $id): JsonResponse
    {
        try {
            $data = $request->validate(['publish_status' => 'required|string']);
            $template = $this->templateService->setPublishStatus(
                $this->templateService->find((int) $id),
                $data['publish_status'],
                auth()->user(),
            );

            return ApiResponse::success($this->templateService->toPublicArray($template), 'WhatsApp template status updated');
        } catch (AuthorizationException $exception) {
            return ApiResponse::error($exception->getMessage(), 403);
        }
    }

    public function preview(Request $request, string $id): JsonResponse
    {
        try {
            $this->templateService->ensureCanView(auth()->user());
            $data = $request->validate(['lead_id' => 'nullable|integer|exists:ca_masters,ca_id']);
            $template = $this->templateService->find((int) $id);
            $lead = isset($data['lead_id'])
                ? CaMaster::query()->with(['city', 'state'])->findOrFail((int) $data['lead_id'])
                : null;

            return ApiResponse::success(
                $this->templateService->preview($template, $lead, auth()->user()),
                'WhatsApp template preview generated',
            );
        } catch (AuthorizationException $exception) {
            return ApiResponse::error($exception->getMessage(), 403);
        }
    }

    public function submitToMeta(string $id): JsonResponse
    {
        try {
            $result = $this->templateService->submitToMeta($this->templateService->find((int) $id), auth()->user());

            return ApiResponse::success(
                $this->templateService->toPublicArray($result['template']),
                $result['message'],
            );
        } catch (AuthorizationException $exception) {
            return ApiResponse::error($exception->getMessage(), 403);
        } catch (ValidationException $exception) {
            return ApiResponse::error(
                collect($exception->errors())->flatten()->first() ?? 'Meta template submission failed.',
                422,
                $exception->errors(),
            );
        }
    }

    public function previewMetaPayload(string $id): JsonResponse
    {
        try {
            $this->templateService->ensureCanManage(auth()->user());
            $template = $this->templateService->find((int) $id);

            return ApiResponse::success([
                'template' => $this->templateService->toPublicArray($template),
            ], 'Meta payload preview');
        } catch (AuthorizationException $exception) {
            return ApiResponse::error($exception->getMessage(), 403);
        }
    }
}

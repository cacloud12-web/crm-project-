<?php

namespace App\Http\Controllers\Communication;

use App\Http\Controllers\Controller;
use App\Http\Requests\Email\PreviewEmailTemplateRequest;
use App\Http\Requests\Templates\StoreEmailTemplateRequest;
use App\Http\Requests\Templates\UpdateEmailTemplateRequest;
use App\Models\CaMaster;
use App\Services\Email\GoDaddyMailService;
use App\Services\Templates\EmailTemplateManagementService;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailTemplateController extends Controller
{
    public function __construct(
        private readonly EmailTemplateManagementService $templateService,
        private readonly GoDaddyMailService $mailService,
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
                ], 'Email templates loaded');
            }

            $templates = $this->templateService->listActive()
                ->map(fn ($template) => $this->templateService->toPublicArray($template))
                ->values();

            return ApiResponse::success($templates, 'Email templates loaded');
        } catch (AuthorizationException $exception) {
            return ApiResponse::error($exception->getMessage(), 403);
        }
    }

    public function show(string $id): JsonResponse
    {
        try {
            $template = $this->templateService->find((int) $id);
            $this->templateService->ensureCanView(auth()->user());

            return ApiResponse::success($this->templateService->toPublicArray($template), 'Email template loaded');
        } catch (AuthorizationException $exception) {
            return ApiResponse::error($exception->getMessage(), 403);
        }
    }

    public function store(StoreEmailTemplateRequest $request): JsonResponse
    {
        try {
            $template = $this->templateService->create($request->validated(), auth()->user());

            return ApiResponse::created($this->templateService->toPublicArray($template), 'Email template created');
        } catch (AuthorizationException $exception) {
            return ApiResponse::error($exception->getMessage(), 403);
        }
    }

    public function update(UpdateEmailTemplateRequest $request, string $id): JsonResponse
    {
        try {
            $template = $this->templateService->update(
                $this->templateService->find((int) $id),
                $request->validated(),
                auth()->user(),
            );

            return ApiResponse::success($this->templateService->toPublicArray($template), 'Email template updated');
        } catch (AuthorizationException $exception) {
            return ApiResponse::error($exception->getMessage(), 403);
        }
    }

    public function destroy(string $id): JsonResponse
    {
        try {
            $this->templateService->delete($this->templateService->find((int) $id), auth()->user());

            return ApiResponse::success(null, 'Email template deleted');
        } catch (AuthorizationException $exception) {
            return ApiResponse::error($exception->getMessage(), 403);
        }
    }

    public function duplicate(string $id): JsonResponse
    {
        try {
            $copy = $this->templateService->duplicate($this->templateService->find((int) $id), auth()->user());

            return ApiResponse::created($this->templateService->toPublicArray($copy), 'Email template duplicated');
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

            return ApiResponse::success($this->templateService->toPublicArray($template), 'Email template status updated');
        } catch (AuthorizationException $exception) {
            return ApiResponse::error($exception->getMessage(), 403);
        }
    }

    public function preview(PreviewEmailTemplateRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $template = $this->templateService->find((int) $validated['email_template_id']);
        $lead = isset($validated['lead_id'])
            ? CaMaster::query()->with(['city', 'state'])->findOrFail((int) $validated['lead_id'])
            : null;

        $preview = $this->templateService->preview($template, $lead, auth()->user(), $this->mailService);

        return ApiResponse::success($preview, 'Email template preview generated');
    }
}

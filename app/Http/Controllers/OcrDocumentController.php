<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOcrDocumentRequest;
use App\Http\Requests\UpdateOcrDocumentTextRequest;
use App\Http\Resources\OcrDocumentResource;
use App\Http\Resources\OcrParsedFirmResource;
use App\Models\OcrDocument;
use App\Services\Ocr\OcrDocumentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OcrDocumentController extends Controller
{
    public function __construct(
        private readonly OcrDocumentService $ocrDocumentService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', OcrDocument::class);

        $validated = $request->validate([
            'ca_id' => ['nullable', 'integer', 'exists:ca_masters,ca_id'],
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'in:pending,queued,uploading_to_cloud,processing,finalizing,completed,failed,cancelled'],
            'mime_type' => ['nullable', 'string', 'max:100'],
            'uploaded_by' => ['nullable', 'integer', 'exists:users,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $caId = isset($validated['ca_id']) ? (int) $validated['ca_id'] : null;
        $perPage = (int) ($validated['per_page'] ?? ($caId ? 10 : 15));

        $documents = $caId
            ? $this->ocrDocumentService->listForCa($caId, $perPage)
            : $this->ocrDocumentService->searchHistory($request->user(), $validated);

        return ApiResponse::success([
            'items' => OcrDocumentResource::collection($documents->items())->resolve(),
            'pagination' => [
                'current_page' => $documents->currentPage(),
                'last_page' => $documents->lastPage(),
                'per_page' => $documents->perPage(),
                'total' => $documents->total(),
                'from' => $documents->firstItem(),
                'to' => $documents->lastItem(),
            ],
        ], 'OCR documents loaded')->header('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->header('Pragma', 'no-cache');
    }

    public function create(): JsonResponse
    {
        $this->authorize('create', OcrDocument::class);

        return ApiResponse::success([
            'max_file_mb' => (int) config('document-ai.max_file_mb', 20),
            'online_max_pages' => (int) config('document-ai.online_max_pages', 30),
            'batch_max_pages' => (int) config('document-ai.batch_max_pages', 500),
            'online_max_file_mb' => (int) config('document-ai.online_max_file_mb', 40),
            'batch_max_file_mb' => (int) config('document-ai.batch_max_file_mb', 1024),
            'batch_configured' => filled(config('document-ai.gcs.input_bucket'))
                && filled(config('document-ai.gcs.output_bucket')),
            'supported_mime_types' => config('document-ai.supported_mime_types', []),
            'supported_extensions' => config('document-ai.supported_extensions', []),
            'location' => (string) config('document-ai.location', 'us'),
            'provider' => 'google_document_ai',
        ], 'OCR upload metadata');
    }

    public function store(StoreOcrDocumentRequest $request): JsonResponse
    {
        $caId = $request->filled('ca_id') ? (int) $request->integer('ca_id') : null;

        try {
            $document = $this->ocrDocumentService->store(
                $request->file('document'),
                $caId,
                $request->user(),
            );
        } catch (\App\Exceptions\DocumentAi\DocumentAiConfigurationException $exception) {
            return ApiResponse::error(
                $this->configurationErrorMessage($request, $exception),
                422,
            );
        } catch (\App\Exceptions\Ocr\OcrFileException $exception) {
            return ApiResponse::error($exception->getMessage(), 422, [
                'document' => [$exception->getMessage()],
            ]);
        }

        return ApiResponse::created(
            new OcrDocumentResource($document),
            'Document uploaded successfully. OCR processing has started.',
        );
    }

    public function show(OcrDocument $ocrDocument): JsonResponse
    {
        $this->authorize('view', $ocrDocument);
        $ocrDocument->loadMissing([
            'uploader:id,name,email',
            'caMaster:ca_id,firm_name,ca_name',
            'parsedFirms.members',
        ]);

        return ApiResponse::success(
            (new OcrDocumentResource($ocrDocument))->resolve(),
        )
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->header('Pragma', 'no-cache');
    }

    public function updateText(UpdateOcrDocumentTextRequest $request, OcrDocument $ocrDocument): JsonResponse
    {
        $document = $this->ocrDocumentService->updateCorrectedText(
            $ocrDocument,
            (string) $request->input('corrected_text'),
            $request->user(),
        );

        return ApiResponse::success(new OcrDocumentResource($document), 'Corrected OCR text saved.');
    }

    public function retry(OcrDocument $ocrDocument): JsonResponse
    {
        $this->authorize('retry', $ocrDocument);

        try {
            $document = $this->ocrDocumentService->retry($ocrDocument, request()->user());
        } catch (\App\Exceptions\DocumentAi\DocumentAiConfigurationException $exception) {
            return ApiResponse::error(
                $this->configurationErrorMessage($request = request(), $exception),
                422,
            );
        } catch (\App\Exceptions\Ocr\OcrFileException $exception) {
            return ApiResponse::error($exception->getMessage(), 422);
        }

        return ApiResponse::success(new OcrDocumentResource($document), 'OCR retry queued.');
    }

    public function downloadOriginal(OcrDocument $ocrDocument): StreamedResponse
    {
        $this->authorize('download', $ocrDocument);

        return $this->ocrDocumentService->streamOriginal($ocrDocument, asAttachment: false);
    }

    public function preview(OcrDocument $ocrDocument): StreamedResponse
    {
        return $this->downloadOriginal($ocrDocument);
    }

    public function download(OcrDocument $ocrDocument): StreamedResponse
    {
        $this->authorize('download', $ocrDocument);

        return $this->ocrDocumentService->streamOriginal($ocrDocument, asAttachment: true);
    }

    public function destroy(OcrDocument $ocrDocument): JsonResponse
    {
        $this->authorize('delete', $ocrDocument);

        if ($ocrDocument->isActiveProcessing()) {
            return ApiResponse::error(
                'This OCR document is still processing and cannot be deleted yet.',
                409,
            );
        }

        $this->ocrDocumentService->destroy($ocrDocument, request()->user());

        return ApiResponse::success(null, 'OCR document deleted successfully.');
    }

    public function reparse(OcrDocument $ocrDocument): JsonResponse
    {
        $this->authorize('update', $ocrDocument);

        if (! $ocrDocument->isCompleted()) {
            return ApiResponse::error('Only completed OCR documents can be restructured.', 422);
        }

        $document = $this->ocrDocumentService->reparseStructure($ocrDocument);

        return ApiResponse::success(
            (new OcrDocumentResource($document->load(['uploader:id,name,email', 'parsedFirms.members'])))->resolve(),
            'OCR document restructured successfully.',
        );
    }

    public function reviewFirm(\Illuminate\Http\Request $request, OcrDocument $ocrDocument, \App\Models\OcrParsedFirm $parsedFirm): JsonResponse
    {
        $this->authorize('update', $ocrDocument);

        if ((int) $parsedFirm->ocr_document_id !== (int) $ocrDocument->id) {
            abort(404);
        }

        $validated = $request->validate([
            'review_status' => ['required', 'string', 'in:pending,approved,rejected'],
        ]);

        $parsedFirm->update([
            'review_status' => $validated['review_status'],
        ]);

        return ApiResponse::success(
            (new OcrParsedFirmResource($parsedFirm->load('members')))->resolve(),
            'Firm review status updated.',
        );
    }

    private function configurationErrorMessage(
        \Illuminate\Http\Request $request,
        \App\Exceptions\DocumentAi\DocumentAiConfigurationException $exception,
    ): string {
        $user = $request->user();
        if ($user) {
            $role = app(\App\Services\Rbac\RbacService::class)->roleKey($user);
            if (in_array($role, ['super_admin', 'admin'], true)) {
                return $exception->detailForAdministrators();
            }
        }

        return $exception->publicMessage();
    }
}

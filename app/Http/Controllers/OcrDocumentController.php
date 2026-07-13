<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOcrDocumentRequest;
use App\Http\Requests\UpdateOcrDocumentTextRequest;
use App\Http\Resources\OcrDocumentResource;
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
            'ca_id' => ['required', 'integer', 'exists:ca_masters,ca_id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $documents = $this->ocrDocumentService->listForCa(
            (int) $validated['ca_id'],
            (int) ($validated['per_page'] ?? 10),
        );

        return ApiResponse::success([
            'items' => OcrDocumentResource::collection($documents->items())->resolve(),
            'pagination' => [
                'current_page' => $documents->currentPage(),
                'last_page' => $documents->lastPage(),
                'per_page' => $documents->perPage(),
                'total' => $documents->total(),
            ],
        ], 'OCR documents loaded');
    }

    public function store(StoreOcrDocumentRequest $request): JsonResponse
    {
        $document = $this->ocrDocumentService->store(
            $request->file('document'),
            (int) $request->integer('ca_id'),
            $request->user(),
        );

        return ApiResponse::created(
            new OcrDocumentResource($document),
            'Document uploaded for OCR processing.',
        );
    }

    public function show(OcrDocument $ocrDocument): JsonResponse
    {
        $this->authorize('view', $ocrDocument);
        $ocrDocument->loadMissing('uploader:id,name,email');

        return ApiResponse::success(new OcrDocumentResource($ocrDocument));
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

        $document = $this->ocrDocumentService->retry($ocrDocument, request()->user());

        return ApiResponse::success(new OcrDocumentResource($document), 'OCR retry queued.');
    }

    public function downloadOriginal(OcrDocument $ocrDocument): StreamedResponse
    {
        $this->authorize('download', $ocrDocument);

        return $this->ocrDocumentService->downloadOriginal($ocrDocument);
    }

    public function destroy(OcrDocument $ocrDocument): JsonResponse
    {
        $this->authorize('delete', $ocrDocument);

        $this->ocrDocumentService->destroy($ocrDocument, request()->user());

        return ApiResponse::success(null, 'OCR document deleted.');
    }
}

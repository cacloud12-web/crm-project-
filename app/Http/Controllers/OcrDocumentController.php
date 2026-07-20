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
        $file = $request->file('document');

        $importType = (string) $request->input('import_type', \App\Models\OcrDocument::IMPORT_SALES_TEAM);

        \Illuminate\Support\Facades\Log::info('ocr.pipeline.step', [
            'step' => 'http_upload_received',
            'original_filename' => $file?->getClientOriginalName(),
            'file_size' => $file?->getSize(),
            'mime_type' => $file?->getMimeType(),
            'ca_id' => $caId,
            'import_type' => $importType,
            'user_id' => $request->user()?->id,
            'force_reimport' => (bool) $request->boolean('force_reimport'),
        ]);

        try {
            $document = $this->ocrDocumentService->store(
                $file,
                $caId,
                $request->user(),
                (bool) $request->boolean('force_reimport'),
                $importType,
            );
        } catch (\App\Exceptions\DocumentAi\DocumentAiConfigurationException $exception) {
            \Illuminate\Support\Facades\Log::error('ocr.pipeline.upload_rejected', [
                'step' => 'http_upload',
                'error_code' => 'configuration_error',
                'error_message' => $exception->getMessage(),
            ]);

            return ApiResponse::error(
                $this->configurationErrorMessage($request, $exception),
                422,
            );
        } catch (\App\Exceptions\Ocr\OcrFileException $exception) {
            \Illuminate\Support\Facades\Log::error('ocr.pipeline.upload_rejected', [
                'step' => 'http_upload',
                'error_code' => $exception->errorCode,
                'error_message' => $exception->getMessage(),
            ]);
            $payload = ['document' => [$exception->getMessage()]];
            if ($exception->errorCode === 'duplicate_file') {
                $payload['duplicate_file'] = true;
                $payload['requires_confirmation'] = true;
            }

            return ApiResponse::error($exception->getMessage(), 422, $payload);
        } catch (\Throwable $exception) {
            \Illuminate\Support\Facades\Log::error('ocr.pipeline.upload_exception', [
                'step' => 'http_upload',
                'error_code' => 'upload_failed',
                'error_message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);
            throw $exception;
        }

        \Illuminate\Support\Facades\Log::info('ocr.pipeline.step', [
            'step' => 'http_upload_created',
            'ocr_document_id' => $document->id,
            'status' => $document->status,
            'pipeline_stage' => $document->pipelineStage(),
        ]);

        return ApiResponse::created(
            (new OcrDocumentResource($document))->resolve(),
            'Document uploaded successfully. OCR processing has started.',
        );
    }

    public function show(OcrDocument $ocrDocument): JsonResponse
    {
        $this->authorize('view', $ocrDocument);

        // Read-only: never UPDATE/dispatch from GET show.
        // Polling used to reset progress to "Queued…" and fight ImportMasterCaOcrJob for SQLite locks,
        // which left the UI stuck on Importing and hid structured firms.

        // Keep show() fast: preview first 100 firms only. Full lists use GET …/firms (paginated).
        $ocrDocument->loadMissing([
            'uploader:id,name,email',
            'caMaster:ca_id,firm_name,ca_name',
        ]);
        $ocrDocument->load([
            'parsedFirms' => function ($q) {
                $q->orderBy('sequence_no')->limit(100);
            },
            'parsedFirms.members',
        ]);

        $payload = (new OcrDocumentResource($ocrDocument))->resolve();
        $payload['firms_preview_limited'] = true;
        $payload['firms_preview_limit'] = 100;
        $payload['firms_total'] = (int) ($ocrDocument->valid_firm_count
            ?? $ocrDocument->parsed_firm_count
            ?? $ocrDocument->parsedFirms()->count());
        $payload['candidate_firm_count'] = (int) ($ocrDocument->candidate_firm_count
            ?? $ocrDocument->parsed_firm_count
            ?? $payload['firms_total']);
        $payload['valid_firm_count'] = (int) ($ocrDocument->valid_firm_count
            ?? $ocrDocument->parsed_firm_count
            ?? $payload['firms_total']);

        return ApiResponse::success($payload)
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->header('Pragma', 'no-cache');
    }

    public function firms(Request $request, OcrDocument $ocrDocument): JsonResponse
    {
        $this->authorize('view', $ocrDocument);

        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'in:50,100,250'],
            'search' => ['nullable', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'in:all,verified,needs_review,conflict,invalid,rejected,failed,ready_to_accept'],
            'match_type' => ['nullable', 'string', 'max:80'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 50);
        $status = $validated['status'] ?? 'all';
        $search = trim((string) ($validated['search'] ?? ''));
        $city = trim((string) ($validated['city'] ?? ''));

        $query = $ocrDocument->parsedFirms()->with('members')->orderBy('sequence_no');

        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function ($q) use ($like) {
                $q->where('firm_name', 'like', $like)
                    ->orWhere('raw_firm_name', 'like', $like)
                    ->orWhere('city', 'like', $like)
                    ->orWhere('normalized_firm_name', 'like', $like)
                    ->orWhereHas('members', function ($m) use ($like) {
                        $m->where('ca_name', 'like', $like)->orWhere('raw_ca_name', 'like', $like);
                    });
            });
        }
        if ($city !== '') {
            $query->where('city', 'like', '%'.$city.'%');
        }

        if ($status !== 'all') {
            $this->applyFirmStatusFilter($query, $status);
        }

        $paginator = $query->paginate($perPage);

        return ApiResponse::success([
            'items' => collect($paginator->items())
                ->map(fn ($firm) => (new OcrParsedFirmResource($firm))->resolve())
                ->values()
                ->all(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'filters' => [
                'search' => $search,
                'city' => $city,
                'status' => $status,
                'scope' => 'document',
            ],
            'document_id' => $ocrDocument->id,
            'parsed_firm_count' => (int) ($ocrDocument->valid_firm_count ?? $ocrDocument->parsed_firm_count ?? 0),
            'candidate_firm_count' => (int) ($ocrDocument->candidate_firm_count ?? $ocrDocument->parsed_firm_count ?? 0),
            'valid_firm_count' => (int) ($ocrDocument->valid_firm_count ?? $ocrDocument->parsed_firm_count ?? 0),
            'partner_count' => (int) \App\Models\OcrParsedMember::query()
                ->whereIn('ocr_parsed_firm_id', $ocrDocument->parsedFirms()->select('id'))
                ->where(function ($q) {
                    $q->where('is_primary', false)->orWhereNull('is_primary');
                })
                ->count(),
        ], 'OCR firms loaded')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->header('Pragma', 'no-cache');
    }

    public function exportFirmsCsv(Request $request, OcrDocument $ocrDocument): StreamedResponse|JsonResponse
    {
        $this->authorize('view', $ocrDocument);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'in:all,verified,needs_review,conflict,invalid,rejected,failed,ready_to_accept'],
            'mode' => ['nullable', 'string', 'in:firms,partners'],
        ]);

        $status = $validated['status'] ?? 'all';
        $search = trim((string) ($validated['search'] ?? ''));
        $city = trim((string) ($validated['city'] ?? ''));
        $mode = $validated['mode'] ?? 'firms';

        $query = $ocrDocument->parsedFirms()->with('members')->orderBy('sequence_no');
        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function ($q) use ($like) {
                $q->where('firm_name', 'like', $like)
                    ->orWhere('raw_firm_name', 'like', $like)
                    ->orWhere('city', 'like', $like)
                    ->orWhereHas('members', function ($m) use ($like) {
                        $m->where('ca_name', 'like', $like)->orWhere('raw_ca_name', 'like', $like);
                    });
            });
        }
        if ($city !== '') {
            $query->where('city', 'like', '%'.$city.'%');
        }
        if ($status !== 'all') {
            $this->applyFirmStatusFilter($query, $status);
        }

        $filename = 'ocr-'.$ocrDocument->id.'-'.$mode.'-'.now()->format('Ymd-His').'.csv';
        $documentName = (string) ($ocrDocument->original_filename ?? ('document-'.$ocrDocument->id));

        return response()->streamDownload(function () use ($query, $mode, $documentName) {
            $out = fopen('php://output', 'w');
            if ($mode === 'partners') {
                fputcsv($out, [
                    'document', 'page', 'column', 'city', 'firm_name', 'ca_name', 'partner_name',
                    'status', 'firm_source_fingerprint', 'partner_source_fingerprint', 'validation_errors',
                ]);
                $query->chunk(500, function ($firms) use ($out, $documentName) {
                    foreach ($firms as $firm) {
                        $payload = (new OcrParsedFirmResource($firm))->resolve();
                        $partners = is_array($payload['partners'] ?? null) ? $payload['partners'] : [];
                        $errors = is_array($payload['validation_errors'] ?? null)
                            ? implode('|', $payload['validation_errors'])
                            : '';
                        $firmFp = (string) ($payload['source_fingerprint'] ?? $firm->source_fingerprint ?? '');
                        if ($partners === []) {
                            fputcsv($out, [
                                $documentName,
                                $firm->page_number,
                                $payload['column_number'] ?? $firm->column_number,
                                $payload['city'] ?? '',
                                $payload['firm_name'] ?? '',
                                $payload['ca_name'] ?? '',
                                '',
                                $payload['status'] ?? '',
                                $firmFp,
                                '',
                                $errors,
                            ]);
                            continue;
                        }
                        foreach ($partners as $partner) {
                            $partnerName = trim((string) $partner);
                            fputcsv($out, [
                                $documentName,
                                $firm->page_number,
                                $payload['column_number'] ?? $firm->column_number,
                                $payload['city'] ?? '',
                                $payload['firm_name'] ?? '',
                                $payload['ca_name'] ?? '',
                                $partnerName,
                                $payload['status'] ?? '',
                                $firmFp,
                                hash('sha256', $firmFp.'|'.mb_strtolower($partnerName)),
                                $errors,
                            ]);
                        }
                    }
                });
            } else {
                fputcsv($out, ['city', 'firm_name', 'ca_name', 'partner_count', 'status', 'page_number', 'sequence_no']);
                $query->chunk(500, function ($firms) use ($out) {
                    foreach ($firms as $firm) {
                        $payload = (new OcrParsedFirmResource($firm))->resolve();
                        fputcsv($out, [
                            $payload['city'] ?? '',
                            $payload['firm_name'] ?? '',
                            $payload['ca_name'] ?? '',
                            $payload['partner_count'] ?? 0,
                            $payload['status'] ?? '',
                            $firm->page_number,
                            $firm->sequence_no,
                        ]);
                    }
                });
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\OcrParsedFirm>  $query
     */
    private function applyFirmStatusFilter($query, string $status): void
    {
        match ($status) {
            'verified' => $query->where(function ($q) {
                $q->whereIn('match_status', ['verified', 'matched', 'imported', 'updated_official', 'duplicate', 'auto_mapped', 'auto_created'])
                    ->orWhere(function ($q2) {
                        $q2->where('review_status', 'approved')->whereNotNull('crm_ca_id');
                    });
            }),
            'needs_review', 'ready_to_accept' => $query->where(function ($q) {
                $q->where(function ($inner) {
                    $inner->whereNull('review_status')->orWhere('review_status', 'pending');
                })->where(function ($inner) {
                    $inner->whereNull('match_status')
                        ->orWhereIn('match_status', ['pending', 'needs_review', 'ready_to_accept', 'unmatched', '']);
                });
            }),
            'conflict' => $query->where('match_status', 'conflict'),
            'invalid' => $query->where('match_status', 'invalid'),
            'rejected', 'failed' => $query->where(function ($q) {
                $q->where('review_status', 'rejected')->orWhere('match_status', 'rejected');
            }),
            default => null,
        };
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

        try {
            $document = $this->ocrDocumentService->reparseStructure($ocrDocument);
        } catch (\Throwable $exception) {
            report($exception);
            $fresh = $ocrDocument->fresh();
            $parsed = is_array($fresh?->structured_data) ? ($fresh->structured_data['parsed'] ?? []) : [];
            $error = is_array($parsed['error'] ?? null) ? $parsed['error'] : null;

            return ApiResponse::error(
                is_array($error) ? (string) ($error['message'] ?? 'Structured parsing failed.') : 'Structured parsing failed.',
                422,
                [
                    'parse_status' => $fresh?->parse_status,
                    'parse_error' => $error,
                    'code' => is_array($error) ? ($error['code'] ?? 'parser_exception') : 'parser_exception',
                ],
            );
        }

        return ApiResponse::success(
            (new OcrDocumentResource($document->load(['uploader:id,name,email', 'parsedFirms.members'])))->resolve(),
            'OCR document restructured successfully.',
        );
    }

    public function reviewFirm(
        \Illuminate\Http\Request $request,
        OcrDocument $ocrDocument,
        \App\Models\OcrParsedFirm $parsedFirm,
        \App\Services\Ocr\OcrFirmApprovalService $approvalService,
    ): JsonResponse {
        $this->authorize('update', $ocrDocument);

        $validated = $request->validate([
            'review_status' => ['required', 'string', 'in:pending,approved,rejected'],
            'matched_ca_id' => ['nullable', 'integer', 'exists:ca_masters,ca_id'],
        ]);

        \Illuminate\Support\Facades\Log::info('ocr.approve.pipeline', [
            'step' => 'http_review_received',
            'ocr_document_id' => $ocrDocument->id,
            'staging_id' => $parsedFirm->id,
            'import_type' => $ocrDocument->import_type,
            'review_status' => $validated['review_status'],
            'user_id' => $request->user()?->id,
        ]);

        try {
            $result = $approvalService->review(
                $ocrDocument,
                $parsedFirm,
                $validated['review_status'],
                isset($validated['matched_ca_id']) ? (int) $validated['matched_ca_id'] : null,
            );
        } catch (\Illuminate\Validation\ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            \Illuminate\Support\Facades\Log::error('ocr.approve.pipeline', [
                'step' => 'http_review_failed',
                'ocr_document_id' => $ocrDocument->id,
                'staging_id' => $parsedFirm->id,
                'error_message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            return ApiResponse::error(
                'Approve failed: '.$exception->getMessage(),
                500,
            );
        }

        $firmPayload = (new OcrParsedFirmResource($result['firm']))->resolve();
        $firmPayload['ca_id'] = $result['ca_id'];
        $firmPayload['approval_action'] = $result['action'];
        $firmPayload['master_created'] = $result['created'];
        $firmPayload['master_updated'] = $result['updated'];
        $firmPayload['document_progress'] = $ocrDocument->fresh()?->processing_progress;
        $firmPayload['pipeline_stage'] = $ocrDocument->fresh()?->pipelineStage();

        return ApiResponse::success($firmPayload, $result['message']);
    }

    public function correctFirmFields(
        \Illuminate\Http\Request $request,
        OcrDocument $ocrDocument,
        \App\Models\OcrParsedFirm $parsedFirm,
        \App\Services\Ocr\OcrFirmApprovalService $approvalService,
    ): JsonResponse {
        $this->authorize('update', $ocrDocument);

        $validated = $request->validate([
            'firm_name' => ['nullable', 'string', 'max:255'],
            'ca_name' => ['nullable', 'string', 'max:255'],
            'membership_no' => ['nullable', 'string', 'max:50'],
            'frn' => ['nullable', 'string', 'max:50'],
            'gst_no' => ['nullable', 'string', 'max:20'],
            'pan_no' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:1000'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'pincode' => ['nullable', 'string', 'max:12'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'string', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
            'firm_type' => ['nullable', 'string', 'max:80'],
        ]);

        $firm = $approvalService->correctFields($ocrDocument, $parsedFirm, $validated);

        return ApiResponse::success(
            (new OcrParsedFirmResource($firm))->resolve(),
            'Fields corrected. Review the side-by-side panel, then Approve.',
        );
    }

    public function approveAllSafe(
        OcrDocument $ocrDocument,
        \App\Services\Ocr\OcrFirmApprovalService $approvalService,
    ): JsonResponse {
        $this->authorize('update', $ocrDocument);

        try {
            $stats = $approvalService->approveAllSafe($ocrDocument);
        } catch (\Illuminate\Validation\ValidationException $exception) {
            return ApiResponse::error(
                collect($exception->errors())->flatten()->first() ?: 'Bulk approve is disabled.',
                422,
            );
        }

        return ApiResponse::success($stats, sprintf(
            'Safe records processed: %d auto-created, %d auto-updated, %d still need review, %d conflicts.',
            (int) ($stats['auto_created'] ?? 0),
            (int) ($stats['auto_updated'] ?? 0),
            (int) ($stats['needs_review'] ?? 0),
            (int) ($stats['conflicts'] ?? 0),
        ));
    }

    public function rejectSelectedFirms(
        \Illuminate\Http\Request $request,
        OcrDocument $ocrDocument,
        \App\Services\Ocr\OcrFirmApprovalService $approvalService,
    ): JsonResponse {
        $this->authorize('update', $ocrDocument);

        $validated = $request->validate([
            'firm_ids' => ['required', 'array', 'min:1'],
            'firm_ids.*' => ['integer', 'exists:ocr_parsed_firms,id'],
        ]);

        $result = $approvalService->rejectSelected($ocrDocument, array_map('intval', $validated['firm_ids']));

        return ApiResponse::success($result, $result['rejected'].' firm(s) rejected.');
    }

    public function retryMapping(
        OcrDocument $ocrDocument,
        \App\Services\Ocr\OcrFirmApprovalService $approvalService,
    ): JsonResponse {
        $this->authorize('update', $ocrDocument);

        if (! $ocrDocument->isCompleted()) {
            return ApiResponse::error('Only completed OCR documents can retry mapping.', 422);
        }

        if ($ocrDocument->isMasterCaImport()) {
            $stats = app(\App\Services\Ocr\OcrStructurePersistService::class)
                ->resumeStuckMasterCaImport($ocrDocument, auth()->id() ? (int) auth()->id() : null);
            if ($stats === null) {
                $stats = app(\App\Services\Ocr\MasterCaDirectImportService::class)->processDocument(
                    (int) $ocrDocument->id,
                    auth()->id() ? (int) auth()->id() : null,
                );
                app(\App\Services\Ocr\MasterCaDirectImportService::class)->refreshDocumentCompletion($ocrDocument->fresh());
            }

            return ApiResponse::success($stats, 'Master CA import resumed.');
        }

        $stats = $approvalService->retryMapping($ocrDocument);

        return ApiResponse::success($stats, 'Mapping retry completed.');
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

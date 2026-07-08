<?php

namespace App\Services\Bulk;

use App\Jobs\Bulk\ProcessBulkCaMasterImportJob;
use App\Models\BulkAction;
use App\Models\BulkActionLog;
use App\Models\BulkImportMappingTemplate;
use App\Models\CaMaster;
use App\Models\DuplicateAttempt;
use App\Models\DuplicateAttemptLog;
use App\Models\ImportDuplicateLog;
use App\Models\TeamSizeMaster;
use App\Services\Activity\ActivityLogService;
use App\Services\Leads\DuplicateLeadDetectionService;
use App\Services\Leads\PhoneClassificationService;
use App\Services\Leads\PhoneNormalizationService;
use App\Services\Master\LookupResolverService;
use App\Services\Notifications\NotificationService;
use App\Services\Rbac\EmployeeDataScopeService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator as ValidationValidator;
use RuntimeException;

class BulkCaMasterImportService
{
    private const MAX_ROWS = 10000;

    private const CHUNK_SIZE = 500;

    private const SESSION_TTL_MINUTES = 120;

    private const REQUIRED_FIELDS = ['ca_name', 'firm_name'];

    private const IMPORTABLE_STATUSES = ['valid', 'landline', 'missing_mobile'];

    private const FORCE_ACTIONS = ['import_anyway', 'merge', 'replace'];

    public function __construct(
        private readonly BulkImportFileParser $fileParser,
        private readonly BulkImportMappingService $mappingService,
        private readonly LookupResolverService $lookupResolver,
        private readonly ActivityLogService $activityLogService,
        private readonly NotificationService $notificationService,
        private readonly DuplicateLeadDetectionService $duplicateLeadDetection,
        private readonly PhoneNormalizationService $phoneNormalization,
        private readonly PhoneClassificationService $phoneClassification,
        private readonly EmployeeDataScopeService $employeeDataScope,
    ) {}

    public function parseUpload(UploadedFile $file): array
    {
        $parsed = $this->fileParser->parse($file);
        $totalRows = count($parsed['rows']);

        if ($totalRows === 0) {
            throw new RuntimeException('The file has no data rows.');
        }

        if ($totalRows > self::MAX_ROWS) {
            throw new RuntimeException('The file exceeds the maximum of '.self::MAX_ROWS.' rows.');
        }

        $sessionId = (string) Str::uuid();
        $fileHash = hash_file('sha256', $file->getRealPath()) ?: null;
        Cache::put($this->sessionKey($sessionId), [
            'file_name' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'file_hash' => $fileHash,
            'headers' => $parsed['headers'],
            'rows' => $parsed['rows'],
            'total_rows' => $totalRows,
            'row_actions' => [],
            'uploaded_by' => Auth::id(),
        ], now()->addMinutes(self::SESSION_TTL_MINUTES));

        return [
            'session_id' => $sessionId,
            'file_name' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'file_size_label' => $this->formatFileSize($file->getSize()),
            'total_rows' => $totalRows,
            'headers' => $parsed['headers'],
            'has_mobile_column' => $this->mappingService->fileHasMobileColumn($parsed['headers']),
            'crm_fields' => $this->mappingService->crmFieldsForHeaders($parsed['headers']),
            'suggested_mapping' => $this->mappingService->suggestMapping($parsed['headers']),
            'saved_templates' => $this->listMappingTemplates(),
        ];
    }

    public function validateSession(string $sessionId, array $mapping): array
    {
        $session = $this->getSession($sessionId);
        $this->assertRequiredMappings($session['headers'] ?? [], $mapping);
        $mappedRows = $this->mappingService->applyMapping($session['rows'], $mapping);
        $validateMobile = $this->mappingService->mobileMappingIsActive($session['headers'] ?? [], $mapping);
        $validateAlternateMobile = $this->mappingService->alternateMobileMappingIsActive($session['headers'] ?? [], $mapping);
        $results = $this->evaluateRows(
            $mappedRows,
            $validateMobile,
            $validateAlternateMobile,
            $session['file_name'] ?? null,
            (int) ($session['uploaded_by'] ?? Auth::id()),
        );

        $rowActions = $session['row_actions'] ?? [];
        $results['rows'] = $this->applyStoredActionsToRows($results['rows'], $rowActions);

        Cache::put($this->sessionKey($sessionId), array_merge($session, [
            'mapping' => $mapping,
            'validation' => $results,
            'validate_mobile' => $validateMobile,
            'validate_alternate_mobile' => $validateAlternateMobile,
            'row_actions' => $rowActions,
        ]), now()->addMinutes(self::SESSION_TTL_MINUTES));

        return $this->validationResponse($sessionId, $session, $results);
    }

    /**
     * @param  array<int|string, string>  $actions  row_number => action
     */
    public function applyRowActions(string $sessionId, array $actions): array
    {
        $session = $this->getSession($sessionId);
        $validation = $session['validation'] ?? null;
        if (! $validation) {
            throw new RuntimeException('Validate the file before setting duplicate actions.');
        }

        $isSuperAdmin = $this->actorIsSuperAdmin();
        $rowActions = $session['row_actions'] ?? [];

        foreach ($actions as $rowNumber => $action) {
            $action = strtolower(trim((string) $action));
            if (! in_array($action, ['skip', 'import_anyway', 'merge', 'replace'], true)) {
                throw new RuntimeException('Invalid action for row '.$rowNumber.'.');
            }
            if (in_array($action, self::FORCE_ACTIONS, true) && ! $isSuperAdmin) {
                throw new RuntimeException('Only Super Admin can use Import Anyway, Merge, or Replace.');
            }
            $rowActions[(int) $rowNumber] = $action;
        }

        $validation['rows'] = $this->applyStoredActionsToRows($validation['rows'], $rowActions);
        $validation = $this->recountEvaluation($validation);

        Cache::put($this->sessionKey($sessionId), array_merge($session, [
            'validation' => $validation,
            'row_actions' => $rowActions,
        ]), now()->addMinutes(self::SESSION_TTL_MINUTES));

        return $this->validationResponse($sessionId, $session, $validation);
    }

    public function importStatus(int $bulkActionId): array
    {
        $bulkAction = BulkAction::query()->findOrFail($bulkActionId);
        $total = max(1, (int) $bulkAction->total_records);
        $processed = (int) $bulkAction->processed_records;
        $percent = min(100, (int) round(($processed / $total) * 100));

        if (in_array($bulkAction->status, ['Completed', 'Completed with errors', 'Failed'], true)) {
            $percent = 100;
        }

        return [
            'bulk_action_id' => $bulkAction->bulk_action_id,
            'status' => $bulkAction->status,
            'file_name' => $bulkAction->file_name,
            'total_rows' => (int) $bulkAction->total_records,
            'processed_records' => $processed,
            'inserted_rows' => (int) $bulkAction->success_records,
            'duplicate_rows' => (int) $bulkAction->duplicate_records,
            'failed_rows' => (int) $bulkAction->failed_records,
            'skipped_rows' => (int) $bulkAction->skipped_records,
            'progress_percent' => $percent,
            'completed' => in_array($bulkAction->status, ['Completed', 'Completed with errors', 'Failed'], true),
        ];
    }

    public function sessionErrorRows(string $sessionId): array
    {
        $session = $this->getSession($sessionId);
        $validation = $session['validation'] ?? null;

        if (! $validation) {
            $mapping = $session['mapping'] ?? $this->mappingService->suggestMapping($session['headers'] ?? []);
            $mappedRows = $this->mappingService->applyMapping($session['rows'], $mapping);
            $validateMobile = $session['validate_mobile']
                ?? $this->mappingService->mobileMappingIsActive($session['headers'] ?? [], $mapping);
            $validateAlternateMobile = $session['validate_alternate_mobile']
                ?? $this->mappingService->alternateMobileMappingIsActive($session['headers'] ?? [], $mapping);
            $validation = $this->evaluateRows(
                $mappedRows,
                $validateMobile,
                $validateAlternateMobile,
                $session['file_name'] ?? null,
                (int) ($session['uploaded_by'] ?? Auth::id()),
            );
        }

        $rows = [];
        foreach ($validation['rows'] as $result) {
            if (in_array($result['status'] ?? '', self::IMPORTABLE_STATUSES, true)) {
                continue;
            }

            $rows[] = [
                'row_number' => $result['row_number'] ?? null,
                'original_data' => $result['data'] ?? [],
                'error_reason' => implode('; ', $result['errors'] ?? []),
                'status' => $result['status'] ?? 'invalid',
            ];
        }

        return $rows;
    }

    public function sessionFailedRowsForReimport(string $sessionId): array
    {
        return array_values(array_filter(
            $this->sessionErrorRows($sessionId),
            fn (array $row) => ($row['status'] ?? '') === 'invalid',
        ));
    }

    public function importSession(string $sessionId, array $mapping, ?string $templateName = null, array $rowActions = []): array
    {
        $session = $this->getSession($sessionId);
        $this->assertFileNotAlreadyImported($session);

        $mapping = $mapping ?: ($session['mapping'] ?? []);
        $this->assertRequiredMappings($session['headers'] ?? [], $mapping);

        if ($rowActions !== []) {
            $this->applyRowActions($sessionId, $rowActions);
            $session = $this->getSession($sessionId);
        }

        if (! empty($session['validation']['rows'])) {
            $evaluation = $session['validation'];
            $evaluation['rows'] = $this->applyStoredActionsToRows(
                $evaluation['rows'],
                $session['row_actions'] ?? [],
            );
            $evaluation = $this->recountEvaluation($evaluation);
        } else {
            $mappedRows = $this->mappingService->applyMapping($session['rows'], $mapping);
            $validateMobile = $this->mappingService->mobileMappingIsActive($session['headers'] ?? [], $mapping);
            $validateAlternateMobile = $this->mappingService->alternateMobileMappingIsActive($session['headers'] ?? [], $mapping);
            $evaluation = $this->evaluateRows(
                $mappedRows,
                $validateMobile,
                $validateAlternateMobile,
                $session['file_name'] ?? null,
                (int) ($session['uploaded_by'] ?? Auth::id()),
            );
            $evaluation['rows'] = $this->applyStoredActionsToRows($evaluation['rows'], $session['row_actions'] ?? []);
            $evaluation = $this->recountEvaluation($evaluation);
        }

        if ($templateName) {
            $this->saveMappingTemplate($templateName, $mapping);
        }

        $bulkAction = BulkAction::create([
            'action_type' => 'ca_master_import',
            'file_name' => $session['file_name'],
            'total_records' => $session['total_rows'],
            'processed_records' => 0,
            'success_records' => 0,
            'duplicate_records' => 0,
            'skipped_records' => 0,
            'failed_records' => 0,
            'imported_by' => Auth::user()?->name ?? 'System',
            'status' => 'Processing',
            'started_at' => now(),
        ]);

        $importableCount = $evaluation['ready_to_import_rows']
            + ($evaluation['landline_rows'] ?? 0)
            + ($evaluation['missing_mobile_rows'] ?? 0)
            + ($evaluation['force_import_rows'] ?? 0);

        $syncLimit = (int) config('crm_queue.import_sync_row_limit', 100);
        if ($importableCount > $syncLimit) {
            Cache::put($this->queuedImportKey($bulkAction->bulk_action_id), [
                'session_id' => $sessionId,
                'mapping' => $mapping,
                'evaluation' => $evaluation,
                'session' => [
                    'file_name' => $session['file_name'],
                    'file_hash' => $session['file_hash'] ?? null,
                    'total_rows' => $session['total_rows'],
                    'uploaded_by' => $session['uploaded_by'] ?? Auth::id(),
                    'row_actions' => $session['row_actions'] ?? [],
                ],
            ], now()->addMinutes(self::SESSION_TTL_MINUTES));

            if ($this->shouldProcessImportInline()) {
                @set_time_limit(0);

                return $this->processQueuedImport($bulkAction->bulk_action_id);
            }

            ProcessBulkCaMasterImportJob::dispatch($bulkAction->bulk_action_id);

            return [
                'bulk_action_id' => $bulkAction->bulk_action_id,
                'uses_background' => true,
                'status' => 'Processing',
                'file_name' => $bulkAction->file_name,
                'total_rows' => $session['total_rows'],
                'valid_rows' => $evaluation['valid_rows'],
                'invalid_rows' => $evaluation['invalid_rows'],
                'landline_rows' => $evaluation['landline_rows'] ?? 0,
                'missing_mobile_rows' => $evaluation['missing_mobile_rows'] ?? 0,
                'ready_to_import_rows' => $evaluation['ready_to_import_rows'] ?? 0,
                'inserted_rows' => 0,
                'duplicate_rows' => 0,
                'failed_rows' => 0,
                'skipped_rows' => 0,
                'progress_percent' => 0,
                'imported_by' => $bulkAction->imported_by,
                'error_row_count' => $evaluation['invalid_rows'] + $evaluation['duplicate_rows'],
                'errors' => [],
                'queue_notice' => 'Import queued. Start a queue worker or set CRM_IMPORT_PROCESS_INLINE=true to import immediately.',
            ];
        }

        return $this->completeImport($bulkAction, $session, $evaluation, $sessionId);
    }

    public function processQueuedImport(int $bulkActionId): array
    {
        $payload = Cache::get($this->queuedImportKey($bulkActionId));

        if (! $payload) {
            BulkAction::query()
                ->where('bulk_action_id', $bulkActionId)
                ->update(['status' => 'Failed', 'completed_at' => now()]);

            throw new RuntimeException('Queued import payload expired or was not found.');
        }

        $bulkAction = BulkAction::query()->findOrFail($bulkActionId);
        $session = array_merge(
            $payload['session'],
            [
                'total_rows' => $payload['session']['total_rows'] ?? $bulkAction->total_records,
                'file_hash' => $payload['session']['file_hash'] ?? null,
                'uploaded_by' => $payload['session']['uploaded_by'] ?? null,
                'row_actions' => $payload['session']['row_actions'] ?? [],
            ],
        );

        $evaluation = $payload['evaluation'];
        $evaluation['rows'] = $this->applyStoredActionsToRows(
            $evaluation['rows'] ?? [],
            $session['row_actions'] ?? [],
        );
        $evaluation = $this->recountEvaluation($evaluation);

        $result = $this->completeImport($bulkAction, $session, $evaluation, $payload['session_id']);
        Cache::forget($this->queuedImportKey($bulkActionId));
        Cache::forget($this->sessionKey($payload['session_id']));

        return array_merge($result, [
            'uses_background' => false,
            'status' => $bulkAction->fresh()->status ?? 'Completed',
        ]);
    }

    private function shouldProcessImportInline(): bool
    {
        if (config('queue.default') === 'sync') {
            return true;
        }

        return (bool) config('crm_queue.import_process_inline', false);
    }

    private function completeImport(BulkAction $bulkAction, array $session, array $evaluation, ?string $sessionId = null): array
    {
        $inserted = 0;
        $duplicate = 0;
        $failed = 0;
        $skipped = 0;
        $landlineImported = 0;
        $errors = [];
        $totalRows = count($evaluation['rows']);
        $processed = 0;
        $isSuperAdmin = $this->actorIsSuperAdmin();
        $uploadedBy = (int) ($session['uploaded_by'] ?? Auth::id());

        foreach (array_chunk($evaluation['rows'], self::CHUNK_SIZE) as $chunk) {
            foreach ($chunk as $result) {
                $rowNumber = $result['row_number'];
                $status = $result['status'];
                $action = strtolower((string) ($result['action'] ?? 'skip'));
                $processed++;

                if ($status === 'duplicate') {
                    $this->finalizeDuplicateLog(
                        $result,
                        $action,
                        $bulkAction->bulk_action_id,
                        $session['file_name'] ?? null,
                        $uploadedBy,
                    );

                    if ($action === 'skip' || $action === '') {
                        $duplicate++;
                        $message = implode('; ', $result['errors'] ?? ['Duplicate row skipped']);
                        $errors[] = [
                            'row' => $rowNumber,
                            'status' => 'duplicate',
                            'code' => $result['error_codes'][0] ?? 'duplicate',
                            'message' => $message,
                        ];
                        $this->logRow($bulkAction->bulk_action_id, $rowNumber, 'Duplicate', $message, $result['data'] ?? null);
                        $this->touchProgress($bulkAction, $processed, $inserted, $duplicate, $failed, $skipped);

                        continue;
                    }

                    if (! $isSuperAdmin || ! in_array($action, self::FORCE_ACTIONS, true)) {
                        $duplicate++;
                        $message = 'Duplicate action not permitted';
                        $errors[] = [
                            'row' => $rowNumber,
                            'status' => 'duplicate',
                            'code' => 'action_not_permitted',
                            'message' => $message,
                        ];
                        $this->logRow($bulkAction->bulk_action_id, $rowNumber, 'Duplicate', $message, $result['data'] ?? null);
                        $this->touchProgress($bulkAction, $processed, $inserted, $duplicate, $failed, $skipped);

                        continue;
                    }

                    $insertResult = $this->applyDuplicateAction($result, $action, $bulkAction->bulk_action_id);
                    if ($insertResult['status'] === 'inserted' || $insertResult['status'] === 'updated') {
                        $inserted++;
                        if (($result['phone_category'] ?? '') === 'landline') {
                            $landlineImported++;
                        }
                        $this->logRow($bulkAction->bulk_action_id, $rowNumber, 'Success', 'Duplicate '.$action);
                        $this->touchProgress($bulkAction, $processed, $inserted, $duplicate, $failed, $skipped);

                        continue;
                    }

                    $status = $insertResult['status'] === 'duplicate' ? 'duplicate' : 'invalid';
                    $result['errors'] = [$insertResult['message']];
                    $result['error_codes'] = [$insertResult['code']];
                }

                if (in_array($status, self::IMPORTABLE_STATUSES, true)) {
                    $insertResult = $this->insertValidatedRow($result['data'], $bulkAction->bulk_action_id, false);
                    if ($insertResult['status'] === 'inserted') {
                        $inserted++;
                        if ($status === 'landline' || ($result['phone_category'] ?? '') === 'landline') {
                            $landlineImported++;
                        }
                        $this->logRow($bulkAction->bulk_action_id, $rowNumber, 'Success', null);
                        $this->touchProgress($bulkAction, $processed, $inserted, $duplicate, $failed, $skipped);

                        continue;
                    }

                    $status = $insertResult['status'];
                    $result['errors'] = [$insertResult['message']];
                    $result['error_codes'] = [$insertResult['code']];
                }

                match ($status) {
                    'duplicate' => $duplicate++,
                    'invalid', 'failed' => $failed++,
                    default => $skipped++,
                };

                $message = implode('; ', $result['errors'] ?? []);
                $errors[] = [
                    'row' => $rowNumber,
                    'status' => $status,
                    'code' => $result['error_codes'][0] ?? $status,
                    'message' => $message,
                ];

                $this->logRow(
                    $bulkAction->bulk_action_id,
                    $rowNumber,
                    $this->logStatusLabel($status === 'duplicate' ? 'duplicate' : 'failed'),
                    $message ?: null,
                    $result['data'] ?? null,
                );
                $this->touchProgress($bulkAction, $processed, $inserted, $duplicate, $failed, $skipped);
            }
        }

        $bulkAction->update([
            'processed_records' => $processed,
            'success_records' => $inserted,
            'duplicate_records' => $duplicate,
            'skipped_records' => $skipped,
            'failed_records' => $failed,
            'status' => ($failed > 0 || $duplicate > 0 || $skipped > 0) ? 'Completed with errors' : 'Completed',
            'completed_at' => now(),
        ]);

        if (! empty($session['file_hash'])) {
            Cache::put(
                $this->completedFileHashKey($session['file_hash']),
                $bulkAction->bulk_action_id,
                now()->addDay(),
            );
        }

        $this->activityLogService->log(
            'BULK_ACTIONS',
            'Bulk Import',
            (string) $bulkAction->bulk_action_id,
            sprintf(
                '%s — %d inserted, %d duplicates, %d failed out of %d rows',
                $bulkAction->file_name ?: 'Import',
                $inserted,
                $duplicate,
                $failed,
                $session['total_rows'],
            ),
        );

        try {
            $this->notificationService->importCompleted(
                $bulkAction->file_name ?: 'Import',
                $inserted,
                $failed,
                $session['total_rows'],
                $bulkAction->bulk_action_id,
                $bulkAction->imported_by,
            );
        } catch (\Throwable $e) {
            report($e);
        }

        if ($sessionId) {
            Cache::forget($this->sessionKey($sessionId));
        }

        return [
            'bulk_action_id' => $bulkAction->bulk_action_id,
            'file_name' => $bulkAction->file_name,
            'total_rows' => $session['total_rows'],
            'valid_rows' => $evaluation['valid_rows'],
            'invalid_rows' => $evaluation['invalid_rows'],
            'missing_mobile_rows' => $evaluation['missing_mobile_rows'] ?? 0,
            'missing_email_rows' => $evaluation['missing_email_rows'] ?? 0,
            'landline_rows' => $landlineImported ?: ($evaluation['landline_rows'] ?? 0),
            'ready_to_import_rows' => $evaluation['ready_to_import_rows'] ?? 0,
            'inserted_rows' => $inserted,
            'duplicate_rows' => $duplicate,
            'failed_rows' => $failed,
            'skipped_rows' => $skipped,
            'progress_percent' => 100,
            'imported_by' => $bulkAction->imported_by,
            'error_row_count' => $failed + $duplicate,
            'errors' => $errors,
        ];
    }

    public function listMappingTemplates(): array
    {
        return BulkImportMappingTemplate::query()
            ->latest('id')
            ->limit(20)
            ->get(['id', 'template_name', 'field_mapping', 'created_at'])
            ->map(fn (BulkImportMappingTemplate $template) => [
                'id' => $template->id,
                'template_name' => $template->template_name,
                'field_mapping' => $template->field_mapping,
                'created_at' => $template->created_at,
            ])
            ->all();
    }

    public function saveMappingTemplate(string $templateName, array $mapping): BulkImportMappingTemplate
    {
        return BulkImportMappingTemplate::updateOrCreate(
            ['template_name' => $templateName],
            [
                'field_mapping' => $mapping,
                'created_by' => 'System',
            ],
        );
    }

    private function evaluateRows(
        array $mappedRows,
        bool $validateMobile = false,
        bool $validateAlternateMobile = false,
        ?string $fileName = null,
        ?int $uploadedBy = null,
    ): array {
        $seenMobiles = [];
        $seenEmails = [];
        $seenGst = [];
        $seenPrimaryKeys = [];
        $rows = [];

        foreach (array_chunk($mappedRows, self::CHUNK_SIZE, true) as $chunk) {
            foreach ($chunk as $index => $row) {
                $rowNumber = $index + 2;
                $result = $this->validateMappedRow(
                    $row,
                    $seenMobiles,
                    $seenEmails,
                    $seenGst,
                    $seenPrimaryKeys,
                    $validateMobile,
                    $validateAlternateMobile,
                );
                $result['row_number'] = $rowNumber;
                $result['data'] = $row;
                $result['action'] = $result['status'] === 'duplicate' ? 'skip' : 'import';

                if ($result['status'] === 'duplicate') {
                    $this->logImportDuplicateDetection(
                        $result,
                        $fileName,
                        $uploadedBy,
                        null,
                        'detected',
                    );
                }

                $rows[] = $result;
            }
        }

        return $this->recountEvaluation(['rows' => $rows]);
    }

    private function validateMappedRow(
        array $row,
        array &$seenMobiles,
        array &$seenEmails,
        array &$seenGst,
        array &$seenPrimaryKeys,
        bool $validateMobile,
        bool $validateAlternateMobile,
    ): array {
        $normalized = $this->normalizeRow($row);
        $validator = Validator::make($normalized, $this->rowRules());
        $errors = [];
        $codes = [];

        if ($validator->fails()) {
            $errors[] = $this->validationFailureMessage($validator);
            $codes[] = $this->validationFailureCode($validator);

            return [
                'status' => 'invalid',
                'errors' => $errors,
                'error_codes' => $codes,
                'field_errors' => $this->fieldErrors($validator),
            ];
        }

        $data = $validator->validated();
        $data['email_id'] = $this->normalizeEmail($data['email_id'] ?? null);
        $data['mobile_no'] = $this->normalizeOptionalPhone($data['mobile_no'] ?? null);
        $data['alternate_mobile_no'] = $this->normalizeOptionalPhone($data['alternate_mobile_no'] ?? null);
        $data['frn'] = $this->normalizeOptionalCode($data['frn'] ?? null);
        $data['membership_no'] = $this->normalizeOptionalCode($data['membership_no'] ?? null);

        if ($validateMobile && $this->hasValue($data['mobile_no'] ?? null)) {
            $phoneError = $this->phoneClassification->validateForSave($data['mobile_no'], 'mobile_no');
            if ($phoneError) {
                return [
                    'status' => 'invalid',
                    'errors' => ['validation_error: mobile_no — '.$phoneError],
                    'error_codes' => ['validation_error'],
                    'field_errors' => ['mobile_no' => $phoneError],
                ];
            }
        }

        if ($validateAlternateMobile && $this->hasValue($data['alternate_mobile_no'] ?? null)) {
            $altError = $this->phoneClassification->validateForSave($data['alternate_mobile_no'], 'alternate_mobile_no');
            if ($altError) {
                return [
                    'status' => 'invalid',
                    'errors' => ['validation_error: alternate_mobile_no — '.$altError],
                    'error_codes' => ['validation_error'],
                    'field_errors' => ['alternate_mobile_no' => $altError],
                ];
            }
        }

        if ($this->hasValue($data['email_id'] ?? null) && ! filter_var($data['email_id'], FILTER_VALIDATE_EMAIL)) {
            return [
                'status' => 'invalid',
                'errors' => ['validation_error: email_id — Enter a valid email address.'],
                'error_codes' => ['validation_error'],
                'field_errors' => ['email_id' => 'Enter a valid email address.'],
            ];
        }

        $mobile = (string) ($this->storeablePhone($data['mobile_no'] ?? null) ?? '');
        $alternateMobile = (string) ($this->storeablePhone($data['alternate_mobile_no'] ?? null) ?? '');
        $email = $data['email_id'] ?? null;
        $gst = $this->normalizeGst($data['gst_no'] ?? null);
        $frn = $data['frn'] ?? null;
        $membershipNo = $data['membership_no'] ?? null;
        $caNameKey = $this->normalizeNameKey($data['ca_name'] ?? null);
        $firmNameKey = $this->normalizeNameKey($data['firm_name'] ?? null);
        $cityKey = $this->normalizeNameKey($data['city_id'] ?? null);
        $identityKey = $this->identityCompositeKey($firmNameKey, $caNameKey, $cityKey);
        $phoneCategory = $this->resolvePhoneCategory($data['mobile_no'] ?? null, $mobile);

        $identity = [
            'ca_name' => $data['ca_name'] ?? null,
            'firm_name' => $data['firm_name'] ?? null,
            'mobile' => $mobile,
            'alternate_mobile' => $alternateMobile,
            'email' => $email,
            'gst' => $gst,
            'frn' => $frn,
            'membership_no' => $membershipNo,
            'ca_name_key' => $caNameKey,
            'firm_name_key' => $firmNameKey,
            'city_key' => $cityKey,
            'identity_key' => $identityKey,
        ];

        $inFileDuplicate = $this->findInFileDuplicateReason(
            $identity,
            $seenMobiles,
            $seenEmails,
            $seenGst,
            $seenPrimaryKeys,
        );
        if ($inFileDuplicate) {
            return $this->duplicateRowResult($inFileDuplicate, $phoneCategory);
        }

        $dbDuplicate = $this->findDatabaseDuplicateReason($identity);
        if ($dbDuplicate) {
            return $this->duplicateRowResult($dbDuplicate, $phoneCategory);
        }

        $resolved = $this->resolveLookups($data);
        if ($resolved['code']) {
            return [
                'status' => 'invalid',
                'errors' => [$resolved['message']],
                'error_codes' => [$resolved['code']],
                'field_errors' => $this->lookupFieldErrors($resolved['code']),
            ];
        }

        if ($mobile !== '') {
            $seenMobiles[$mobile] = true;
        }
        if ($alternateMobile !== '') {
            $seenMobiles[$alternateMobile] = true;
        }
        if ($email) {
            $seenEmails[$email] = true;
        }
        if ($gst) {
            $seenGst[$gst] = true;
        }
        $primaryKey = $this->primaryDuplicateKey($identity);
        if ($primaryKey) {
            $seenPrimaryKeys[$primaryKey['type']][$primaryKey['value']] = true;
        }

        $status = match ($phoneCategory) {
            'landline' => 'landline',
            'missing' => 'missing_mobile',
            default => 'valid',
        };

        return [
            'status' => $status,
            'phone_category' => $phoneCategory,
            'missing_email' => ! $email,
            'errors' => [],
            'error_codes' => [],
            'field_errors' => [],
            'resolved' => $resolved['payload'],
        ];
    }

    private function insertValidatedRow(array $row, ?int $bulkActionId = null, bool $skipDuplicateCheck = false): array
    {
        $normalized = $this->normalizeRow($row);
        $normalized['email_id'] = $this->normalizeEmail($normalized['email_id'] ?? null);
        $normalized['frn'] = $this->normalizeOptionalCode($normalized['frn'] ?? null);
        $normalized['membership_no'] = $this->normalizeOptionalCode($normalized['membership_no'] ?? null);
        $resolved = $this->resolveLookups($normalized);
        if ($resolved['code']) {
            return $this->failedResult($resolved['code'], $resolved['message']);
        }

        $mobile = (string) ($this->storeablePhone($normalized['mobile_no'] ?? null) ?? '');
        $alternateMobile = (string) ($this->storeablePhone($normalized['alternate_mobile_no'] ?? null) ?? '');
        $email = $normalized['email_id'] ?? null;
        $gst = $this->normalizeGst($normalized['gst_no'] ?? null);
        $frn = $normalized['frn'] ?? null;
        $membershipNo = $normalized['membership_no'] ?? null;
        $caNameKey = $this->normalizeNameKey($normalized['ca_name'] ?? null);
        $firmNameKey = $this->normalizeNameKey($normalized['firm_name'] ?? null);
        $cityKey = $this->normalizeNameKey($normalized['city_id'] ?? null);

        if (! $skipDuplicateCheck) {
            $dbDuplicate = $this->findDatabaseDuplicateReason([
                'ca_name' => $normalized['ca_name'] ?? null,
                'firm_name' => $normalized['firm_name'] ?? null,
                'mobile' => $mobile,
                'alternate_mobile' => $alternateMobile,
                'email' => $email,
                'gst' => $gst,
                'frn' => $frn,
                'membership_no' => $membershipNo,
                'ca_name_key' => $caNameKey,
                'firm_name_key' => $firmNameKey,
                'city_key' => $cityKey,
                'identity_key' => $this->identityCompositeKey($firmNameKey, $caNameKey, $cityKey),
            ]);
            if ($dbDuplicate) {
                return $this->duplicateResult($dbDuplicate['code'], $dbDuplicate['message']);
            }
        }

        try {
            DB::transaction(function () use ($resolved, $bulkActionId) {
                $payload = $this->withPhoneTypes($resolved['payload']);
                if ($bulkActionId) {
                    $payload['bulk_action_id'] = $bulkActionId;
                }
                $payload['created_by_employee_id'] = $this->employeeDataScope->resolveEmployeeId(Auth::user());
                $payload['normalized_mobile'] = $this->phoneNormalization->normalize($payload['mobile_no'] ?? null);
                $payload['normalized_alternate_mobile'] = $this->phoneNormalization->normalize($payload['alternate_mobile_no'] ?? null);
                $lead = CaMaster::create($payload);
                $this->duplicateLeadDetection->syncLeadPhones($lead);
            });
        } catch (\Throwable $e) {
            return $this->failedResult('database_error', 'Database error: '.$e->getMessage());
        }

        return [
            'status' => 'inserted',
            'code' => null,
            'message' => null,
        ];
    }

    private function duplicateResult(string $code, string $message): array
    {
        return [
            'status' => 'duplicate',
            'code' => $code,
            'message' => $message,
        ];
    }

    private function failedResult(string $code, string $message): array
    {
        return [
            'status' => 'failed',
            'code' => $code,
            'message' => $message,
        ];
    }

    private function fieldErrors(ValidationValidator $validator): array
    {
        $errors = [];
        foreach ($validator->errors()->messages() as $field => $messages) {
            $errors[$field] = $messages[0] ?? 'Invalid value';
        }

        return $errors;
    }

    private function duplicateFieldErrors(string $code): array
    {
        return match (true) {
            str_contains($code, 'alternate_mobile') => ['alternate_mobile_no' => 'Duplicate alternate mobile number'],
            str_contains($code, 'mobile') => ['mobile_no' => 'Duplicate mobile number'],
            str_contains($code, 'email') => ['email_id' => 'Duplicate email'],
            str_contains($code, 'gst') => ['gst_no' => 'Duplicate GST number'],
            str_contains($code, 'frn') => ['frn' => 'Duplicate FRN'],
            str_contains($code, 'membership') => ['membership_no' => 'Duplicate membership number'],
            str_contains($code, 'identity') => ['firm_name' => 'Duplicate firm, CA name, and city'],
            default => [],
        };
    }

    private function lookupFieldErrors(string $code): array
    {
        return match (true) {
            str_contains($code, 'state') => ['state_id' => 'Invalid state'],
            str_contains($code, 'city') => ['city_id' => 'Invalid city'],
            str_contains($code, 'source') => ['source_id' => 'Invalid source'],
            str_contains($code, 'team_size') => ['team_size_id' => 'Invalid team size'],
            default => [],
        };
    }

    private function validationFailureCode(ValidationValidator $validator): string
    {
        foreach (self::REQUIRED_FIELDS as $field) {
            if ($validator->errors()->has($field)) {
                return 'missing_required_field:'.$field;
            }
        }

        return 'validation_error';
    }

    private function validationFailureMessage(ValidationValidator $validator): string
    {
        foreach (self::REQUIRED_FIELDS as $field) {
            if ($validator->errors()->has($field)) {
                return 'missing_required_field: '.$field;
            }
        }

        $firstKey = $validator->errors()->keys()[0] ?? 'row';

        return 'validation_error: '.$firstKey.' — '.$validator->errors()->first();
    }

    /**
     * @param  array<string, mixed>  $identity
     * @param  array<string, true>  $seenMobiles
     * @param  array<string, true>  $seenEmails
     * @param  array<string, true>  $seenGst
     * @param  array<string, array<string, true>>  $seenPrimaryKeys
     * @return array<string, mixed>|null
     */
    private function findInFileDuplicateReason(
        array $identity,
        array $seenMobiles,
        array $seenEmails,
        array $seenGst,
        array $seenPrimaryKeys,
    ): ?array {
        $mobile = (string) ($identity['mobile'] ?? '');
        $alternateMobile = (string) ($identity['alternate_mobile'] ?? '');
        $email = $identity['email'] ?? null;
        $gst = $identity['gst'] ?? null;

        $primaryKey = $this->primaryDuplicateKey($identity);
        if ($primaryKey) {
            $seenBucket = $seenPrimaryKeys[$primaryKey['type']] ?? [];
            if (isset($seenBucket[$primaryKey['value']])) {
                return $this->duplicateMatch(
                    'duplicate_'.$primaryKey['type'].'_in_file',
                    'duplicate_'.$primaryKey['type'].'_in_file: '.$this->duplicateTypeLabel($primaryKey['type']).' already appears earlier in this file',
                    $primaryKey['type'],
                    $primaryKey['value'],
                    'file',
                );
            }
        }

        if ($mobile !== '' && $alternateMobile !== '' && $mobile === $alternateMobile) {
            return $this->duplicateMatch(
                'duplicate_mobile_no_in_file',
                'duplicate_mobile_no_in_file: primary and alternate mobile cannot be the same',
                'mobile',
                $mobile,
                'file',
            );
        }

        if ($mobile !== '' && isset($seenMobiles[$mobile])) {
            return $this->duplicateMatch(
                'duplicate_mobile_no_in_file',
                'duplicate_mobile_no_in_file: mobile '.$mobile.' already appears earlier in this file',
                'mobile',
                $mobile,
                'file',
            );
        }

        if ($alternateMobile !== '' && isset($seenMobiles[$alternateMobile])) {
            return $this->duplicateMatch(
                'duplicate_alternate_mobile_no_in_file',
                'duplicate_alternate_mobile_no_in_file: alternate mobile '.$alternateMobile.' already appears earlier in this file',
                'alternate_mobile',
                $alternateMobile,
                'file',
            );
        }

        if ($email && isset($seenEmails[$email])) {
            return $this->duplicateMatch(
                'duplicate_email_id_in_file',
                'duplicate_email_id_in_file: email '.$email.' already appears earlier in this file',
                'email',
                $email,
                'file',
            );
        }

        if ($gst && isset($seenGst[$gst])) {
            return $this->duplicateMatch(
                'duplicate_gst_no_in_file',
                'duplicate_gst_no_in_file: GST '.$gst.' already appears earlier in this file',
                'gst',
                $gst,
                'file',
            );
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $identity
     * @return array<string, mixed>|null
     */
    private function findDatabaseDuplicateReason(array $identity): ?array
    {
        $mobile = (string) ($identity['mobile'] ?? '');
        $alternateMobile = (string) ($identity['alternate_mobile'] ?? '');
        $email = $identity['email'] ?? null;
        $gst = $identity['gst'] ?? null;

        $primaryKey = $this->primaryDuplicateKey($identity);
        if ($primaryKey) {
            $existing = $this->findExistingLeadByPrimaryKey($primaryKey, $identity);
            if ($existing) {
                return $this->duplicateMatch(
                    'duplicate_'.$primaryKey['type'],
                    'duplicate_'.$primaryKey['type'].': '.$this->duplicateTypeLabel($primaryKey['type']).' already exists in database',
                    $primaryKey['type'],
                    $primaryKey['value'],
                    'database',
                    $this->leadSummary($existing),
                );
            }
        }

        if ($mobile !== '' && $alternateMobile !== '' && $mobile === $alternateMobile) {
            return $this->duplicateMatch(
                'duplicate_mobile_no',
                'duplicate_mobile_no: primary and alternate mobile cannot be the same',
                'mobile',
                $mobile,
                'database',
            );
        }

        foreach ([['mobile', $mobile], ['alternate_mobile', $alternateMobile]] as [$field, $number]) {
            if ($number === '') {
                continue;
            }

            $duplicate = $this->duplicateLeadDetection->checkMobile($number);
            if ($duplicate) {
                return $this->duplicateMatch(
                    'duplicate_'.$field,
                    'duplicate_'.$field.': '.$number.' already exists in database',
                    $field,
                    $number,
                    'database',
                    $duplicate['existing_lead'] ?? null,
                );
            }

            $existingByPhone = CaMaster::query()
                ->where(function ($query) use ($number) {
                    $query->where('normalized_mobile', $number)
                        ->orWhere('normalized_alternate_mobile', $number)
                        ->orWhere('mobile_no', $number)
                        ->orWhere('alternate_mobile_no', $number);
                })
                ->first(['ca_id', 'ca_name', 'firm_name', 'mobile_no', 'email_id', 'status']);

            if ($existingByPhone) {
                return $this->duplicateMatch(
                    'duplicate_'.$field,
                    'duplicate_'.$field.': '.$number.' already exists in database',
                    $field,
                    $number,
                    'database',
                    $this->leadSummary($existingByPhone),
                );
            }
        }

        if ($email) {
            $existing = CaMaster::query()
                ->whereRaw('LOWER(email_id) = ?', [$email])
                ->first(['ca_id', 'ca_name', 'firm_name', 'mobile_no', 'email_id', 'status']);
            if ($existing) {
                return $this->duplicateMatch(
                    'duplicate_email_id',
                    'duplicate_email_id: email '.$email.' already exists in database',
                    'email',
                    $email,
                    'database',
                    $this->leadSummary($existing),
                );
            }
        }

        if ($gst) {
            $existing = CaMaster::query()
                ->where('gst_no', $gst)
                ->first(['ca_id', 'ca_name', 'firm_name', 'mobile_no', 'email_id', 'status']);
            if ($existing) {
                return $this->duplicateMatch(
                    'duplicate_gst_no',
                    'duplicate_gst_no: GST '.$gst.' already exists in database',
                    'gst',
                    $gst,
                    'database',
                    $this->leadSummary($existing),
                );
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $identity
     * @return array{type: string, value: string}|null
     */
    private function primaryDuplicateKey(array $identity): ?array
    {
        if ($this->hasValue($identity['frn'] ?? null)) {
            return ['type' => 'frn', 'value' => (string) $identity['frn']];
        }

        if ($this->hasValue($identity['membership_no'] ?? null)) {
            return ['type' => 'membership_no', 'value' => (string) $identity['membership_no']];
        }

        if ($this->hasValue($identity['identity_key'] ?? null)) {
            return ['type' => 'identity', 'value' => (string) $identity['identity_key']];
        }

        return null;
    }

    /**
     * @param  array{type: string, value: string}  $primaryKey
     * @param  array<string, mixed>  $identity
     */
    private function findExistingLeadByPrimaryKey(array $primaryKey, array $identity): ?CaMaster
    {
        return match ($primaryKey['type']) {
            'frn' => CaMaster::query()
                ->where('frn', $primaryKey['value'])
                ->first(['ca_id', 'ca_name', 'firm_name', 'mobile_no', 'email_id', 'status']),
            'membership_no' => CaMaster::query()
                ->where('membership_no', $primaryKey['value'])
                ->first(['ca_id', 'ca_name', 'firm_name', 'mobile_no', 'email_id', 'status']),
            'identity' => $this->findExistingLeadByIdentity($identity),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $identity
     */
    private function findExistingLeadByIdentity(array $identity): ?CaMaster
    {
        $firmNameKey = $identity['firm_name_key'] ?? null;
        $caNameKey = $identity['ca_name_key'] ?? null;
        $cityKey = $identity['city_key'] ?? null;

        if (! $firmNameKey || ! $caNameKey) {
            return null;
        }

        return CaMaster::query()
            ->whereRaw('LOWER(TRIM(firm_name)) = ?', [$firmNameKey])
            ->whereRaw('LOWER(TRIM(ca_name)) = ?', [$caNameKey])
            ->when(
                $cityKey,
                fn ($query) => $query->whereHas('city', fn ($cityQuery) => $cityQuery->whereRaw('LOWER(TRIM(city_name)) = ?', [$cityKey])),
                fn ($query) => $query->whereNull('city_id'),
            )
            ->first(['ca_id', 'ca_name', 'firm_name', 'mobile_no', 'email_id', 'status']);
    }

    private function duplicateTypeLabel(string $type): string
    {
        return match ($type) {
            'frn' => 'FRN',
            'membership_no' => 'Membership No',
            'identity' => 'Firm + CA + City',
            'mobile', 'mobile_no' => 'Mobile',
            'alternate_mobile', 'alternate_mobile_no' => 'Alternate Mobile',
            'email', 'email_id' => 'Email',
            'gst', 'gst_no' => 'GST',
            default => ucwords(str_replace('_', ' ', $type)),
        };
    }

    private function rowRules(): array
    {
        return [
            'ca_name' => 'required|string|max:255',
            'firm_name' => 'required|string|max:255',
            'membership_no' => 'nullable|string|max:60',
            'frn' => 'nullable|string|max:60',
            'address' => 'nullable|string|max:2000',
            'pincode' => 'nullable|string|max:12',
            'email_id' => 'nullable|string|max:255',
            'gst_no' => 'nullable|string|max:50',
            'team_size' => 'nullable|integer|min:1',
            'team_size_id' => 'nullable|integer',
            'existing_software' => 'nullable|string|max:255',
            'website' => 'nullable|string|max:255',
            'rating' => 'nullable|integer|min:1|max:5',
            'status' => 'nullable|string|max:255',
            'state_id' => 'nullable|string|max:255',
            'city_id' => 'nullable|string|max:255',
            'source_id' => 'nullable|string|max:255',
            'mobile_no' => 'nullable|string|max:20',
            'alternate_mobile_no' => 'nullable|string|max:20',
        ];
    }

    private function resolveLookups(array $data): array
    {
        $stateRaw = $data['state_id'] ?? null;
        $cityRaw = $data['city_id'] ?? null;
        $sourceRaw = $data['source_id'] ?? null;

        $stateId = $this->hasValue($stateRaw) ? $this->lookupResolver->resolveStateId($stateRaw) : null;
        $cityId = $this->hasValue($cityRaw)
            ? $this->lookupResolver->resolveCityId($cityRaw, $stateId)
            : null;

        if ($cityId && $stateId && ! $this->lookupResolver->cityBelongsToState($cityId, $stateId)) {
            $cityId = null;
        }

        $sourceId = $this->hasValue($sourceRaw) ? $this->lookupResolver->resolveSourceId($sourceRaw) : null;

        $teamSizeId = null;
        if ($this->hasValue($data['team_size_id'] ?? null)) {
            $teamSizeId = TeamSizeMaster::where('id', (int) $data['team_size_id'])->value('id');
        }

        return [
            'code' => null,
            'message' => null,
            'payload' => [
                'ca_name' => trim($data['ca_name']),
                'firm_name' => trim($data['firm_name']),
                'membership_no' => $this->normalizeOptionalCode($data['membership_no'] ?? null),
                'frn' => $this->normalizeOptionalCode($data['frn'] ?? null),
                'address' => $this->normalizeOptionalCode($data['address'] ?? null),
                'pincode' => $this->normalizeOptionalCode($data['pincode'] ?? null),
                'mobile_no' => $this->storeablePhone($data['mobile_no'] ?? null),
                'alternate_mobile_no' => $this->storeablePhone($data['alternate_mobile_no'] ?? null),
                'email_id' => $this->normalizeEmail($data['email_id'] ?? null),
                'gst_no' => $this->normalizeGst($data['gst_no'] ?? null),
                'team_size' => $data['team_size'] ?? null,
                'team_size_id' => $teamSizeId,
                'existing_software' => $data['existing_software'] ?? null,
                'website' => $data['website'] ?? null,
                'rating' => $data['rating'] ?? 1,
                'status' => $data['status'] ?? 'New',
                'state_id' => $stateId,
                'city_id' => $cityId,
                'source_id' => $sourceId,
            ],
        ];
    }

    private function normalizeRow(array $row): array
    {
        foreach ($row as $key => $value) {
            if (is_string($value)) {
                $row[$key] = trim($value);
                if ($row[$key] === '' || $this->isPlaceholderValue($row[$key])) {
                    $row[$key] = null;
                }
            }
        }

        return $row;
    }

    private function hasValue(mixed $value): bool
    {
        return $value !== null && trim((string) $value) !== '';
    }

    private function normalizePhone(?string $mobile): string
    {
        return $this->phoneNormalization->normalize($mobile) ?? '';
    }

    private function storeablePhone(mixed $value): ?string
    {
        if (! $this->hasValue($value)) {
            return null;
        }

        $normalized = $this->phoneNormalization->normalize($value);
        if ($normalized) {
            return $normalized;
        }

        return $this->phoneClassification->digitsOnly($value);
    }

    private function assertRequiredMappings(array $headers, array $mapping): void
    {
        foreach ($this->mappingService->crmFieldsForHeaders($headers) as $field) {
            if (! ($field['required'] ?? false)) {
                continue;
            }

            if (! $this->hasValue($mapping[$field['key']] ?? null)) {
                throw new RuntimeException('Map the required field: '.$field['label']);
            }
        }
    }

    private function logStatusLabel(string $status): string
    {
        return match ($status) {
            'inserted', 'valid' => 'Success',
            'duplicate' => 'Duplicate',
            'skipped' => 'Skipped',
            default => 'Failed',
        };
    }

    private function logRow(int $bulkActionId, int $rowNumber, string $status, ?string $message, ?array $originalData = null): void
    {
        BulkActionLog::create([
            'bulk_action_id' => $bulkActionId,
            'row_number' => $rowNumber,
            'status' => $status,
            'error_message' => $message,
            'original_data' => $originalData,
        ]);
    }

    private function getSession(string $sessionId): array
    {
        $session = Cache::get($this->sessionKey($sessionId));
        if (! $session) {
            throw new RuntimeException('Import session expired. Please upload the file again.');
        }

        return $session;
    }

    private function sessionKey(string $sessionId): string
    {
        return 'bulk_import_session:'.$sessionId;
    }

    private function queuedImportKey(int $bulkActionId): string
    {
        return 'bulk_import_job:'.$bulkActionId;
    }

    private function formatFileSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / 1048576, 2).' MB';
    }

    /**
     * @param  array<string, mixed>  $session
     * @param  array<string, mixed>  $results
     * @return array<string, mixed>
     */
    private function validationResponse(string $sessionId, array $session, array $results): array
    {
        $duplicateRows = array_values(array_filter(
            $results['rows'],
            fn (array $row) => ($row['status'] ?? '') === 'duplicate',
        ));

        return [
            'session_id' => $sessionId,
            'file_name' => $session['file_name'],
            'total_rows' => $session['total_rows'],
            'valid_rows' => $results['valid_rows'],
            'invalid_rows' => $results['invalid_rows'],
            'duplicate_rows' => $results['duplicate_rows'],
            'missing_mobile_rows' => $results['missing_mobile_rows'] ?? 0,
            'missing_email_rows' => $results['missing_email_rows'] ?? 0,
            'landline_rows' => $results['landline_rows'] ?? 0,
            'ready_to_import_rows' => $results['ready_to_import_rows'] ?? 0,
            'force_import_rows' => $results['force_import_rows'] ?? 0,
            'error_row_count' => $results['invalid_rows'] + $results['duplicate_rows'],
            'preview_rows' => array_slice($results['rows'], 0, 100),
            'duplicate_report' => array_map(
                fn (array $row) => $this->duplicateReportRow($row),
                array_slice($duplicateRows, 0, 100),
            ),
            'duplicate_report_total' => count($duplicateRows),
            'can_force_actions' => $this->actorIsSuperAdmin(),
            'has_mobile_column' => $this->mappingService->fileHasMobileColumn($session['headers'] ?? []),
            'crm_fields' => $this->mappingService->crmFieldsForHeaders($session['headers'] ?? []),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function duplicateReportRow(array $row): array
    {
        $data = $row['data'] ?? [];
        $matched = $row['matched_lead'] ?? null;

        return [
            'row_number' => $row['row_number'] ?? null,
            'ca_name' => $data['ca_name'] ?? null,
            'firm_name' => $data['firm_name'] ?? null,
            'mobile' => $data['mobile_no'] ?? null,
            'email' => $data['email_id'] ?? null,
            'duplicate_type' => $row['duplicate_type'] ?? null,
            'duplicate_type_label' => $this->duplicateTypeLabel((string) ($row['duplicate_type'] ?? '')),
            'duplicate_value' => $row['duplicate_value'] ?? null,
            'duplicate_reason' => implode('; ', $row['errors'] ?? []),
            'matched_existing_lead' => $matched,
            'matched_lead_label' => $matched
                ? trim(($matched['ca_name'] ?? '').' / '.($matched['firm_name'] ?? '').' (#'.($matched['ca_id'] ?? '').')')
                : 'In-file duplicate',
            'action' => $row['action'] ?? 'skip',
            'source' => $row['duplicate_source'] ?? null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<int|string, string>  $rowActions
     * @return list<array<string, mixed>>
     */
    private function applyStoredActionsToRows(array $rows, array $rowActions): array
    {
        return array_map(function (array $row) use ($rowActions) {
            $rowNumber = (int) ($row['row_number'] ?? 0);
            if (($row['status'] ?? '') === 'duplicate') {
                $row['action'] = $rowActions[$rowNumber] ?? $row['action'] ?? 'skip';
            } else {
                $row['action'] = 'import';
            }

            return $row;
        }, $rows);
    }

    /**
     * @param  array<string, mixed>  $evaluation
     * @return array<string, mixed>
     */
    private function recountEvaluation(array $evaluation): array
    {
        $valid = 0;
        $invalid = 0;
        $duplicate = 0;
        $missingMobile = 0;
        $missingEmail = 0;
        $landline = 0;
        $ready = 0;
        $forceImport = 0;

        foreach ($evaluation['rows'] as $result) {
            if (! empty($result['missing_email'])) {
                $missingEmail++;
            }

            $status = $result['status'] ?? 'invalid';
            $action = $result['action'] ?? 'skip';

            if ($status === 'duplicate') {
                $duplicate++;
                if (in_array($action, self::FORCE_ACTIONS, true)) {
                    $forceImport++;
                }

                continue;
            }

            if (in_array($status, self::IMPORTABLE_STATUSES, true)) {
                $valid++;
                $ready++;
            }

            match ($status) {
                'landline' => $landline++,
                'missing_mobile' => $missingMobile++,
                'invalid' => $invalid++,
                default => null,
            };
        }

        $evaluation['valid_rows'] = $valid;
        $evaluation['invalid_rows'] = $invalid;
        $evaluation['duplicate_rows'] = $duplicate;
        $evaluation['missing_mobile_rows'] = $missingMobile;
        $evaluation['missing_email_rows'] = $missingEmail;
        $evaluation['landline_rows'] = $landline;
        $evaluation['ready_to_import_rows'] = $ready;
        $evaluation['force_import_rows'] = $forceImport;

        return $evaluation;
    }

    /**
     * @param  array<string, mixed>  $match
     * @return array<string, mixed>
     */
    private function duplicateRowResult(array $match, string $phoneCategory): array
    {
        return [
            'status' => 'duplicate',
            'phone_category' => $phoneCategory,
            'missing_email' => false,
            'errors' => [$match['message']],
            'error_codes' => [$match['code']],
            'field_errors' => $this->duplicateFieldErrors($match['code']),
            'duplicate_type' => $match['duplicate_type'],
            'duplicate_value' => $match['duplicate_value'],
            'duplicate_source' => $match['source'],
            'matched_lead' => $match['matched_lead'],
            'matched_lead_id' => $match['matched_lead']['ca_id'] ?? null,
            'action' => 'skip',
        ];
    }

    /**
     * @param  array<string, mixed>|null  $matchedLead
     * @return array<string, mixed>
     */
    private function duplicateMatch(
        string $code,
        string $message,
        string $type,
        string $value,
        string $source,
        ?array $matchedLead = null,
    ): array {
        return [
            'code' => $code,
            'message' => $message,
            'duplicate_type' => $type,
            'duplicate_value' => $value,
            'source' => $source,
            'matched_lead' => $matchedLead,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function leadSummary(CaMaster $lead): array
    {
        return [
            'ca_id' => $lead->ca_id,
            'ca_name' => $lead->ca_name,
            'firm_name' => $lead->firm_name,
            'mobile_no' => $lead->mobile_no,
            'email_id' => $lead->email_id ?? null,
            'status' => $lead->status,
        ];
    }

    private function normalizeNameKey(mixed $value): ?string
    {
        if (! $this->hasValue($value)) {
            return null;
        }

        $normalized = preg_replace('/\s+/', ' ', strtolower(trim((string) $value)));

        return $normalized !== '' ? $normalized : null;
    }

    private function resolvePhoneCategory(mixed $rawMobile, string $normalizedMobile): string
    {
        if ($normalizedMobile === '' && ! $this->hasValue($rawMobile)) {
            return 'missing';
        }

        $type = $this->phoneClassification->classify($rawMobile ?: $normalizedMobile);

        return match ($type) {
            PhoneClassificationService::TYPE_LANDLINE => 'landline',
            PhoneClassificationService::TYPE_MOBILE => 'mobile',
            default => $normalizedMobile !== '' ? 'mobile' : 'missing',
        };
    }

    private function identityCompositeKey(?string $firmKey, ?string $caKey, ?string $cityKey): ?string
    {
        if (! $firmKey || ! $caKey) {
            return null;
        }

        return $firmKey.'|'.$caKey.'|'.($cityKey ?? '');
    }

    private function normalizeOptionalCode(?string $value): ?string
    {
        if (! $this->hasValue($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $this->isPlaceholderValue($trimmed) ? null : $trimmed;
    }

    private function normalizeOptionalPhone(?string $value): ?string
    {
        return $this->normalizeOptionalCode($value);
    }

    private function normalizeEmail(?string $value): ?string
    {
        $normalized = $this->normalizeOptionalCode($value);
        if (! $normalized || ! str_contains($normalized, '@')) {
            return null;
        }

        return strtolower($normalized);
    }

    private function normalizeGst(?string $value): ?string
    {
        $normalized = $this->normalizeOptionalCode($value);

        return $normalized ? strtoupper($normalized) : null;
    }

    private function isPlaceholderValue(string $value): bool
    {
        $normalized = strtolower(trim($value));

        return in_array($normalized, [
            '-',
            '—',
            '.',
            'na',
            'n/a',
            'n.a.',
            'nil',
            'none',
            'null',
            'not available',
            'not applicable',
            'unknown',
        ], true);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withPhoneTypes(array $payload): array
    {
        $payload['mobile_no_type'] = $this->phoneClassification->classify($payload['mobile_no'] ?? null);
        $payload['alternate_mobile_no_type'] = $this->phoneClassification->classify($payload['alternate_mobile_no'] ?? null);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function applyDuplicateAction(array $result, string $action, int $bulkActionId): array
    {
        $matchedLeadId = $result['matched_lead_id'] ?? ($result['matched_lead']['ca_id'] ?? null);

        return match ($action) {
            'import_anyway' => $this->insertValidatedRow($result['data'] ?? [], $bulkActionId, true),
            'merge' => $this->mergeIntoExistingLead((int) $matchedLeadId, $result['data'] ?? [], $bulkActionId),
            'replace' => $this->replaceExistingLead((int) $matchedLeadId, $result['data'] ?? [], $bulkActionId),
            default => $this->duplicateResult('action_not_permitted', 'Unsupported duplicate action'),
        };
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function mergeIntoExistingLead(int $matchedLeadId, array $row, int $bulkActionId): array
    {
        if ($matchedLeadId <= 0) {
            return $this->insertValidatedRow($row, $bulkActionId, true);
        }

        $lead = CaMaster::query()->find($matchedLeadId);
        if (! $lead) {
            return $this->insertValidatedRow($row, $bulkActionId, true);
        }

        $normalized = $this->normalizeRow($row);
        $resolved = $this->resolveLookups($normalized);
        if ($resolved['code']) {
            return $this->failedResult($resolved['code'], $resolved['message']);
        }

        try {
            DB::transaction(function () use ($lead, $resolved, $bulkActionId) {
                $payload = $this->withPhoneTypes($resolved['payload']);
                foreach ($payload as $key => $value) {
                    if ($value === null || $value === '') {
                        continue;
                    }
                    if ($lead->{$key} === null || $lead->{$key} === '') {
                        $lead->{$key} = $value;
                    }
                }
                $lead->bulk_action_id = $bulkActionId;
                $lead->normalized_mobile = $this->phoneNormalization->normalize($lead->mobile_no);
                $lead->normalized_alternate_mobile = $this->phoneNormalization->normalize($lead->alternate_mobile_no);
                $lead->mobile_no_type = $this->phoneClassification->classify($lead->mobile_no);
                $lead->alternate_mobile_no_type = $this->phoneClassification->classify($lead->alternate_mobile_no);
                $lead->save();
                $this->duplicateLeadDetection->syncLeadPhones($lead);
            });
        } catch (\Throwable $e) {
            return $this->failedResult('database_error', 'Database error: '.$e->getMessage());
        }

        return ['status' => 'updated', 'code' => null, 'message' => null];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function replaceExistingLead(int $matchedLeadId, array $row, int $bulkActionId): array
    {
        if ($matchedLeadId <= 0) {
            return $this->insertValidatedRow($row, $bulkActionId, true);
        }

        $lead = CaMaster::query()->find($matchedLeadId);
        if (! $lead) {
            return $this->insertValidatedRow($row, $bulkActionId, true);
        }

        $normalized = $this->normalizeRow($row);
        $resolved = $this->resolveLookups($normalized);
        if ($resolved['code']) {
            return $this->failedResult($resolved['code'], $resolved['message']);
        }

        try {
            DB::transaction(function () use ($lead, $resolved, $bulkActionId) {
                $payload = $this->withPhoneTypes($resolved['payload']);
                $payload['bulk_action_id'] = $bulkActionId;
                $payload['normalized_mobile'] = $this->phoneNormalization->normalize($payload['mobile_no'] ?? null);
                $payload['normalized_alternate_mobile'] = $this->phoneNormalization->normalize($payload['alternate_mobile_no'] ?? null);
                $lead->fill($payload);
                $lead->save();
                $this->duplicateLeadDetection->syncLeadPhones($lead);
            });
        } catch (\Throwable $e) {
            return $this->failedResult('database_error', 'Database error: '.$e->getMessage());
        }

        return ['status' => 'updated', 'code' => null, 'message' => null];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function logImportDuplicateDetection(
        array $result,
        ?string $fileName,
        ?int $uploadedBy,
        ?int $bulkActionId,
        string $actionTaken,
    ): void {
        $data = $result['data'] ?? [];
        $matchedLeadId = $result['matched_lead_id'] ?? ($result['matched_lead']['ca_id'] ?? null);
        $duplicateValue = (string) ($result['duplicate_value'] ?? '');
        $duplicateType = (string) ($result['duplicate_type'] ?? 'unknown');

        ImportDuplicateLog::query()->create([
            'bulk_action_id' => $bulkActionId,
            'uploaded_by' => $uploadedBy,
            'file_name' => $fileName,
            'row_number' => $result['row_number'] ?? null,
            'duplicate_value' => $duplicateValue,
            'duplicate_type' => $duplicateType,
            'matched_lead_id' => $matchedLeadId,
            'action_taken' => $actionTaken,
            'ca_name' => $data['ca_name'] ?? null,
            'firm_name' => $data['firm_name'] ?? null,
            'mobile_no' => $data['mobile_no'] ?? null,
            'email_id' => $data['email_id'] ?? null,
            'source' => $result['duplicate_source'] ?? 'file',
        ]);

        if (! $matchedLeadId) {
            return;
        }

        $employeeId = $this->employeeDataScope->resolveEmployeeId(Auth::user());
        $isPhone = in_array($duplicateType, ['mobile', 'alternate_mobile'], true);

        DuplicateAttemptLog::query()->create([
            'employee_id' => $employeeId,
            'lead_id' => $matchedLeadId,
            'attempted_mobile' => $isPhone ? ($duplicateValue ?: 'n/a') : 'n/a',
            'attempted_email' => $duplicateType === 'email' ? $duplicateValue : null,
            'attempted_at' => now(),
            'reason' => 'import_duplicate_'.$duplicateType,
            'ip_address' => Request::ip(),
        ]);

        if ($isPhone && $duplicateValue !== '') {
            DuplicateAttempt::query()->create([
                'employee_id' => $employeeId,
                'lead_id' => $matchedLeadId,
                'duplicate_number' => $duplicateValue,
                'matched_lead_id' => $matchedLeadId,
                'attempt_type' => DuplicateAttempt::TYPE_DUPLICATE,
                'status' => DuplicateAttempt::STATUS_OPEN,
                'field_name' => $duplicateType === 'alternate_mobile' ? 'alternate_mobile_no' : 'mobile_no',
                'ip' => Request::ip(),
                'browser' => Request::userAgent() ? substr((string) Request::userAgent(), 0, 255) : null,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function finalizeDuplicateLog(
        array $result,
        string $action,
        int $bulkActionId,
        ?string $fileName,
        ?int $uploadedBy,
    ): void {
        ImportDuplicateLog::query()->create([
            'bulk_action_id' => $bulkActionId,
            'uploaded_by' => $uploadedBy,
            'file_name' => $fileName,
            'row_number' => $result['row_number'] ?? null,
            'duplicate_value' => $result['duplicate_value'] ?? null,
            'duplicate_type' => $result['duplicate_type'] ?? null,
            'matched_lead_id' => $result['matched_lead_id'] ?? ($result['matched_lead']['ca_id'] ?? null),
            'action_taken' => $action ?: 'skip',
            'ca_name' => $result['data']['ca_name'] ?? null,
            'firm_name' => $result['data']['firm_name'] ?? null,
            'mobile_no' => $result['data']['mobile_no'] ?? null,
            'email_id' => $result['data']['email_id'] ?? null,
            'source' => $result['duplicate_source'] ?? 'file',
        ]);
    }

    private function touchProgress(
        BulkAction $bulkAction,
        int $processed,
        int $inserted,
        int $duplicate,
        int $failed,
        int $skipped,
    ): void {
        if ($processed % 25 !== 0 && $processed !== (int) $bulkAction->total_records) {
            return;
        }

        $bulkAction->update([
            'processed_records' => $processed,
            'success_records' => $inserted,
            'duplicate_records' => $duplicate,
            'failed_records' => $failed,
            'skipped_records' => $skipped,
        ]);
    }

    private function assertFileNotAlreadyImported(array $session): void
    {
        $hash = $session['file_hash'] ?? null;
        if (! $hash) {
            return;
        }

        $priorId = Cache::get($this->completedFileHashKey($hash));
        if ($priorId) {
            throw new RuntimeException(
                'This file was already imported recently (bulk action #'.$priorId.'). Re-upload is blocked to prevent duplicate inserts.',
            );
        }
    }

    private function completedFileHashKey(string $hash): string
    {
        return 'bulk_import_completed_hash:'.$hash;
    }

    private function actorIsSuperAdmin(): bool
    {
        $role = strtolower((string) (Auth::user()?->crm_role ?? ''));

        return $role === 'super_admin';
    }
}

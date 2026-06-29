<?php

namespace App\Services\Bulk;

use App\Jobs\Bulk\ProcessBulkCaMasterImportJob;
use App\Models\BulkAction;
use App\Models\BulkActionLog;
use App\Models\BulkImportMappingTemplate;
use App\Models\CaMaster;
use App\Models\TeamSizeMaster;
use App\Rules\ValidMobileNumber;
use App\Services\Activity\ActivityLogService;
use App\Services\Master\LookupResolverService;
use App\Services\Notifications\NotificationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator as ValidationValidator;
use RuntimeException;

class BulkCaMasterImportService
{
    private const MAX_ROWS = 10000;

    private const SESSION_TTL_MINUTES = 120;

    private const REQUIRED_FIELDS = ['ca_name', 'firm_name'];

    public function __construct(
        private readonly BulkImportFileParser $fileParser,
        private readonly BulkImportMappingService $mappingService,
        private readonly LookupResolverService $lookupResolver,
        private readonly ActivityLogService $activityLogService,
        private readonly NotificationService $notificationService,
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
        Cache::put($this->sessionKey($sessionId), [
            'file_name' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'headers' => $parsed['headers'],
            'rows' => $parsed['rows'],
            'total_rows' => $totalRows,
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
        $results = $this->evaluateRows($mappedRows, $validateMobile, $validateAlternateMobile);

        Cache::put($this->sessionKey($sessionId), array_merge($session, [
            'mapping' => $mapping,
            'validation' => $results,
            'validate_mobile' => $validateMobile,
            'validate_alternate_mobile' => $validateAlternateMobile,
        ]), now()->addMinutes(self::SESSION_TTL_MINUTES));

        $preview = array_slice($results['rows'], 0, 50);

        return [
            'session_id' => $sessionId,
            'file_name' => $session['file_name'],
            'total_rows' => $session['total_rows'],
            'valid_rows' => $results['valid_rows'],
            'invalid_rows' => $results['invalid_rows'],
            'duplicate_rows' => $results['duplicate_rows'],
            'error_row_count' => $results['invalid_rows'] + $results['duplicate_rows'],
            'preview_rows' => $preview,
            'has_mobile_column' => $this->mappingService->fileHasMobileColumn($session['headers'] ?? []),
            'crm_fields' => $this->mappingService->crmFieldsForHeaders($session['headers'] ?? []),
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
            $validation = $this->evaluateRows($mappedRows, $validateMobile, $validateAlternateMobile);
        }

        $rows = [];
        foreach ($validation['rows'] as $result) {
            if (($result['status'] ?? '') === 'valid') {
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

    public function importSession(string $sessionId, array $mapping, ?string $templateName = null): array
    {
        $session = $this->getSession($sessionId);
        $mapping = $mapping ?: ($session['mapping'] ?? []);
        $this->assertRequiredMappings($session['headers'] ?? [], $mapping);
        $mappedRows = $this->mappingService->applyMapping($session['rows'], $mapping);
        $validateMobile = $this->mappingService->mobileMappingIsActive($session['headers'] ?? [], $mapping);
        $validateAlternateMobile = $this->mappingService->alternateMobileMappingIsActive($session['headers'] ?? [], $mapping);
        $evaluation = $this->evaluateRows($mappedRows, $validateMobile, $validateAlternateMobile);

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

        $syncLimit = (int) config('crm_queue.import_sync_row_limit', 100);
        if ($evaluation['valid_rows'] > $syncLimit) {
            Cache::put($this->queuedImportKey($bulkAction->bulk_action_id), [
                'session_id' => $sessionId,
                'mapping' => $mapping,
                'evaluation' => $evaluation,
                'session' => [
                    'file_name' => $session['file_name'],
                    'total_rows' => $session['total_rows'],
                ],
            ], now()->addMinutes(self::SESSION_TTL_MINUTES));

            ProcessBulkCaMasterImportJob::dispatch($bulkAction->bulk_action_id);

            return [
                'bulk_action_id' => $bulkAction->bulk_action_id,
                'uses_background' => true,
                'status' => 'Processing',
                'file_name' => $bulkAction->file_name,
                'total_rows' => $session['total_rows'],
                'valid_rows' => $evaluation['valid_rows'],
                'invalid_rows' => $evaluation['invalid_rows'],
                'inserted_rows' => 0,
                'duplicate_rows' => 0,
                'failed_rows' => 0,
                'skipped_rows' => 0,
                'imported_by' => $bulkAction->imported_by,
                'error_row_count' => $evaluation['invalid_rows'] + $evaluation['duplicate_rows'],
                'errors' => [],
            ];
        }

        return $this->completeImport($bulkAction, $session, $evaluation, $sessionId);
    }

    public function processQueuedImport(int $bulkActionId): void
    {
        $payload = Cache::get($this->queuedImportKey($bulkActionId));

        if (! $payload) {
            BulkAction::query()
                ->where('bulk_action_id', $bulkActionId)
                ->update(['status' => 'Failed', 'completed_at' => now()]);

            return;
        }

        $bulkAction = BulkAction::query()->findOrFail($bulkActionId);
        $session = array_merge(
            $payload['session'],
            ['total_rows' => $payload['session']['total_rows'] ?? $bulkAction->total_records],
        );

        $this->completeImport($bulkAction, $session, $payload['evaluation'], $payload['session_id']);
        Cache::forget($this->queuedImportKey($bulkActionId));
        Cache::forget($this->sessionKey($payload['session_id']));
    }

    private function completeImport(BulkAction $bulkAction, array $session, array $evaluation, ?string $sessionId = null): array
    {
        $inserted = 0;
        $duplicate = 0;
        $failed = 0;
        $skipped = 0;
        $errors = [];

        foreach ($evaluation['rows'] as $result) {
            $rowNumber = $result['row_number'];
            $status = $result['status'];

            if ($status === 'valid') {
                $insertResult = $this->insertValidatedRow($result['data'], $bulkAction->bulk_action_id);
                if ($insertResult['status'] === 'inserted') {
                    $inserted++;
                    $this->logRow($bulkAction->bulk_action_id, $rowNumber, 'Success', null);

                    continue;
                }

                $status = $insertResult['status'];
                $result['errors'] = [$insertResult['message']];
                $result['error_codes'] = [$insertResult['code']];
            }

            match ($status) {
                'duplicate' => $duplicate++,
                'invalid' => $failed++,
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
        }

        $bulkAction->update([
            'processed_records' => $inserted + $duplicate + $failed + $skipped,
            'success_records' => $inserted,
            'duplicate_records' => $duplicate,
            'skipped_records' => $skipped,
            'failed_records' => $failed,
            'status' => ($failed > 0 || $skipped > 0) ? 'Completed with errors' : 'Completed',
            'completed_at' => now(),
        ]);

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

        $this->notificationService->importCompleted(
            $bulkAction->file_name ?: 'Import',
            $inserted,
            $failed,
            $session['total_rows'],
            $bulkAction->bulk_action_id,
            $bulkAction->imported_by,
        );

        if ($sessionId) {
            Cache::forget($this->sessionKey($sessionId));
        }

        return [
            'bulk_action_id' => $bulkAction->bulk_action_id,
            'file_name' => $bulkAction->file_name,
            'total_rows' => $session['total_rows'],
            'valid_rows' => $evaluation['valid_rows'],
            'invalid_rows' => $evaluation['invalid_rows'],
            'inserted_rows' => $inserted,
            'duplicate_rows' => $duplicate,
            'failed_rows' => $failed,
            'skipped_rows' => $skipped,
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

    private function evaluateRows(array $mappedRows, bool $validateMobile = false, bool $validateAlternateMobile = false): array
    {
        $seenMobiles = [];
        $seenEmails = [];
        $seenGst = [];
        $rows = [];
        $valid = 0;
        $invalid = 0;
        $duplicate = 0;

        foreach ($mappedRows as $index => $row) {
            $rowNumber = $index + 2;
            $result = $this->validateMappedRow($row, $seenMobiles, $seenEmails, $seenGst, $validateMobile, $validateAlternateMobile);
            $result['row_number'] = $rowNumber;
            $result['data'] = $row;
            $rows[] = $result;

            match ($result['status']) {
                'valid' => $valid++,
                'duplicate' => $duplicate++,
                default => $invalid++,
            };
        }

        return [
            'rows' => $rows,
            'valid_rows' => $valid,
            'invalid_rows' => $invalid,
            'duplicate_rows' => $duplicate,
        ];
    }

    private function validateMappedRow(array $row, array &$seenMobiles, array &$seenEmails, array &$seenGst, bool $validateMobile, bool $validateAlternateMobile): array
    {
        $normalized = $this->normalizeRow($row);
        $validator = Validator::make($normalized, $this->rowRules($validateMobile, $validateAlternateMobile));
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
        $mobile = $this->hasValue($data['mobile_no'] ?? null)
            ? $this->normalizePhone((string) $data['mobile_no'])
            : '';
        $email = isset($data['email_id']) ? strtolower(trim($data['email_id'])) : null;
        $gst = isset($data['gst_no']) ? strtoupper(trim($data['gst_no'])) : null;

        $inFileDuplicate = $this->findInFileDuplicateReason($mobile, $email, $gst, $seenMobiles, $seenEmails, $seenGst);
        if ($inFileDuplicate) {
            return [
                'status' => 'duplicate',
                'errors' => [$inFileDuplicate['message']],
                'error_codes' => [$inFileDuplicate['code']],
                'field_errors' => $this->duplicateFieldErrors($inFileDuplicate['code']),
            ];
        }

        $dbDuplicate = $this->findDatabaseDuplicateReason($mobile, $email, $gst);
        if ($dbDuplicate) {
            return [
                'status' => 'duplicate',
                'errors' => [$dbDuplicate['message']],
                'error_codes' => [$dbDuplicate['code']],
                'field_errors' => $this->duplicateFieldErrors($dbDuplicate['code']),
            ];
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
        if ($email) {
            $seenEmails[$email] = true;
        }
        if ($gst) {
            $seenGst[$gst] = true;
        }

        return [
            'status' => 'valid',
            'errors' => [],
            'error_codes' => [],
            'field_errors' => [],
            'resolved' => $resolved['payload'],
        ];
    }

    private function insertValidatedRow(array $row, ?int $bulkActionId = null): array
    {
        $normalized = $this->normalizeRow($row);
        $resolved = $this->resolveLookups($normalized);
        if ($resolved['code']) {
            return $this->failedResult($resolved['code'], $resolved['message']);
        }

        $mobile = $this->hasValue($normalized['mobile_no'] ?? null)
            ? $this->normalizePhone((string) $normalized['mobile_no'])
            : '';
        $email = isset($normalized['email_id']) ? strtolower(trim($normalized['email_id'])) : null;
        $gst = isset($normalized['gst_no']) ? strtoupper(trim($normalized['gst_no'])) : null;

        $dbDuplicate = $this->findDatabaseDuplicateReason($mobile, $email, $gst);
        if ($dbDuplicate) {
            return $this->duplicateResult($dbDuplicate['code'], $dbDuplicate['message']);
        }

        try {
            DB::transaction(function () use ($resolved, $bulkActionId) {
                $payload = $resolved['payload'];
                if ($bulkActionId) {
                    $payload['bulk_action_id'] = $bulkActionId;
                }
                CaMaster::create($payload);
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
            str_contains($code, 'mobile') => ['mobile_no' => 'Duplicate mobile number'],
            str_contains($code, 'email') => ['email_id' => 'Duplicate email'],
            str_contains($code, 'gst') => ['gst_no' => 'Duplicate GST number'],
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

    private function findInFileDuplicateReason(string $mobile, ?string $email, ?string $gst, array $seenMobiles, array $seenEmails, array $seenGst): ?array
    {
        if ($mobile !== '' && isset($seenMobiles[$mobile])) {
            return [
                'code' => 'duplicate_mobile_no_in_file',
                'message' => 'duplicate_mobile_no_in_file: mobile '.$mobile.' already appears earlier in this file',
            ];
        }

        if ($email && isset($seenEmails[$email])) {
            return [
                'code' => 'duplicate_email_id_in_file',
                'message' => 'duplicate_email_id_in_file: email '.$email.' already appears earlier in this file',
            ];
        }

        if ($gst && isset($seenGst[$gst])) {
            return [
                'code' => 'duplicate_gst_no_in_file',
                'message' => 'duplicate_gst_no_in_file: GST '.$gst.' already appears earlier in this file',
            ];
        }

        return null;
    }

    private function findDatabaseDuplicateReason(string $mobile, ?string $email, ?string $gst): ?array
    {
        if ($mobile !== '' && $this->mobileExistsInDatabase($mobile)) {
            return [
                'code' => 'duplicate_mobile_no',
                'message' => 'duplicate_mobile_no: mobile '.$mobile.' already exists in database',
            ];
        }

        if ($email && CaMaster::where('email_id', $email)->exists()) {
            return [
                'code' => 'duplicate_email_id',
                'message' => 'duplicate_email_id: email '.$email.' already exists in database',
            ];
        }

        if ($gst && CaMaster::where('gst_no', $gst)->exists()) {
            return [
                'code' => 'duplicate_gst_no',
                'message' => 'duplicate_gst_no: GST '.$gst.' already exists in database',
            ];
        }

        return null;
    }

    private function mobileExistsInDatabase(string $mobile): bool
    {
        return CaMaster::query()
            ->where(function ($query) use ($mobile) {
                $query->where('mobile_no', $mobile)
                    ->orWhereRaw("regexp_replace(mobile_no, '\\s+', '', 'g') = ?", [$mobile]);
            })
            ->exists();
    }

    private function rowRules(bool $validateMobile = false, bool $validateAlternateMobile = false): array
    {
        $rules = [
            'ca_name' => 'required|string|max:255',
            'firm_name' => 'required|string|max:255',
            'email_id' => 'nullable|email|max:255',
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
        ];

        if ($validateMobile) {
            $rules['mobile_no'] = ['nullable', 'string', 'max:20', new ValidMobileNumber];
        }

        if ($validateAlternateMobile) {
            $rules['alternate_mobile_no'] = ['nullable', 'string', 'max:20', new ValidMobileNumber];
        }

        return $rules;
    }

    private function resolveLookups(array $data): array
    {
        $stateRaw = $data['state_id'] ?? null;
        $cityRaw = $data['city_id'] ?? null;
        $sourceRaw = $data['source_id'] ?? null;

        $stateId = $this->lookupResolver->resolveStateId($stateRaw);
        if ($this->hasValue($stateRaw) && ! $stateId) {
            return [
                'code' => 'mapping_error:invalid_state',
                'message' => 'mapping_error: invalid state "'.$stateRaw.'"',
                'payload' => [],
            ];
        }

        if ($this->hasValue($cityRaw) && ! $this->hasValue($stateRaw)) {
            return [
                'code' => 'mapping_error:state_required_for_city',
                'message' => 'mapping_error: state is required when city is provided',
                'payload' => [],
            ];
        }

        $cityId = $this->lookupResolver->resolveCityId($cityRaw, $stateId);
        if ($this->hasValue($cityRaw) && ! $cityId) {
            return [
                'code' => 'mapping_error:invalid_city',
                'message' => 'mapping_error: invalid city "'.$cityRaw.'" for the selected state',
                'payload' => [],
            ];
        }

        if ($cityId && $stateId && ! $this->lookupResolver->cityBelongsToState($cityId, $stateId)) {
            return [
                'code' => 'mapping_error:city_state_mismatch',
                'message' => 'mapping_error: Selected city does not belong to selected state.',
                'payload' => [],
            ];
        }

        $sourceId = $this->lookupResolver->resolveSourceId($sourceRaw);
        if ($this->hasValue($sourceRaw) && ! $sourceId) {
            return [
                'code' => 'mapping_error:invalid_source',
                'message' => 'mapping_error: invalid source "'.$sourceRaw.'"',
                'payload' => [],
            ];
        }

        if ($this->hasValue($data['team_size_id'] ?? null)) {
            $teamSizeId = TeamSizeMaster::where('id', (int) $data['team_size_id'])->value('id');
            if (! $teamSizeId) {
                return [
                    'code' => 'mapping_error:invalid_team_size_id',
                    'message' => 'mapping_error: invalid team_size_id "'.$data['team_size_id'].'"',
                    'payload' => [],
                ];
            }
        } else {
            $teamSizeId = null;
        }

        return [
            'code' => null,
            'message' => null,
            'payload' => [
                'ca_name' => trim($data['ca_name']),
                'firm_name' => trim($data['firm_name']),
                'mobile_no' => $this->hasValue($data['mobile_no'] ?? null)
                    ? $this->normalizePhone((string) $data['mobile_no'])
                    : null,
                'alternate_mobile_no' => $this->hasValue($data['alternate_mobile_no'] ?? null)
                    ? $this->normalizePhone((string) $data['alternate_mobile_no'])
                    : null,
                'email_id' => $data['email_id'] ?? null,
                'gst_no' => $data['gst_no'] ?? null,
                'team_size' => $data['team_size'] ?? null,
                'team_size_id' => $teamSizeId,
                'existing_software' => $data['existing_software'] ?? null,
                'website' => $data['website'] ?? null,
                'rating' => $data['rating'] ?? 1,
                'status' => $data['status'] ?? 'Active',
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
                if ($row[$key] === '') {
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
        if ($mobile === null || trim($mobile) === '') {
            return '';
        }

        $digits = preg_replace('/\D/', '', trim($mobile)) ?? '';
        if (strlen($digits) > 10 && str_starts_with($digits, '91')) {
            $digits = substr($digits, -10);
        }

        return $digits;
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
}

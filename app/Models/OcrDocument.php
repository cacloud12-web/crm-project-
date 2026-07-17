<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class OcrDocument extends Model
{
    use SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_UPLOADING_TO_CLOUD = 'uploading_to_cloud';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_FINALIZING = 'finalizing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const IMPORT_MASTER_CA = 'master_ca';

    public const IMPORT_SALES_TEAM = 'sales_team';

    public const IMPORT_TYPES = [
        self::IMPORT_MASTER_CA,
        self::IMPORT_SALES_TEAM,
    ];

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_QUEUED,
        self::STATUS_UPLOADING_TO_CLOUD,
        self::STATUS_PROCESSING,
        self::STATUS_FINALIZING,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];

    public const ACTIVE_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_QUEUED,
        self::STATUS_UPLOADING_TO_CLOUD,
        self::STATUS_PROCESSING,
        self::STATUS_FINALIZING,
    ];

    protected $fillable = [
        'import_batch_id',
        'ca_id',
        'uploaded_by',
        'original_filename',
        'stored_filename',
        'storage_disk',
        'storage_path',
        'mime_type',
        'file_size',
        'checksum',
        'result_checksum',
        'status',
        'provider',
        'import_type',
        'processing_mode',
        'provider_reference',
        'provider_operation_name',
        'gcs_input_uri',
        'gcs_output_uri',
        'processing_progress',
        'extracted_text',
        'corrected_text',
        'corrected_by',
        'corrected_at',
        'structured_data',
        'page_count',
        'total_pages',
        'processed_pages',
        'detected_languages',
        'average_confidence',
        'processor_name',
        'error_code',
        'error_message',
        'processing_attempts',
        'processing_started_at',
        'batch_started_at',
        'batch_completed_at',
        'processed_at',
        'failed_at',
        'parse_status',
        'parsed_firm_count',
        'parsed_at',
    ];

    protected function casts(): array
    {
        return [
            'structured_data' => 'array',
            'detected_languages' => 'array',
            'average_confidence' => 'decimal:4',
            'processing_started_at' => 'datetime',
            'batch_started_at' => 'datetime',
            'batch_completed_at' => 'datetime',
            'processed_at' => 'datetime',
            'failed_at' => 'datetime',
            'corrected_at' => 'datetime',
            'parsed_at' => 'datetime',
            'file_size' => 'integer',
            'page_count' => 'integer',
            'total_pages' => 'integer',
            'processed_pages' => 'integer',
            'processing_attempts' => 'integer',
            'parsed_firm_count' => 'integer',
        ];
    }

    public function caMaster(): BelongsTo
    {
        return $this->belongsTo(CaMaster::class, 'ca_id', 'ca_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function correctedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by');
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(OcrImportBatch::class, 'import_batch_id');
    }

    public function parsedFirms(): HasMany
    {
        return $this->hasMany(OcrParsedFirm::class, 'ocr_document_id')
            ->orderBy('sequence_no');
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isProcessing(): bool
    {
        return in_array($this->status, [
            self::STATUS_PROCESSING,
            self::STATUS_UPLOADING_TO_CLOUD,
            self::STATUS_FINALIZING,
        ], true);
    }

    public function isActiveProcessing(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function isQueued(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_QUEUED], true);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function displayText(): ?string
    {
        return $this->corrected_text ?? $this->extracted_text;
    }

    public function isMasterCaImport(): bool
    {
        return $this->import_type === self::IMPORT_MASTER_CA;
    }

    public function isSalesTeamImport(): bool
    {
        return $this->import_type === self::IMPORT_SALES_TEAM
            || ($this->import_type === null || $this->import_type === '');
    }

    public function statusLabel(): string
    {
        return match ($this->pipelineStage()) {
            'pending' => 'Pending',
            'uploading' => 'Uploading',
            'ocr' => 'OCR',
            'parsing' => 'Parsing',
            'validating' => 'Validating',
            'importing' => 'Importing',
            'mapping' => 'Mapping',
            'updating' => 'Updating/Creating',
            'completed' => 'Completed',
            'failed' => 'Failed',
            default => match ($this->status) {
                self::STATUS_UPLOADING_TO_CLOUD => 'Uploading',
                self::STATUS_FINALIZING => 'OCR',
                default => $this->status ? ucfirst(str_replace('_', ' ', $this->status)) : 'Pending',
            },
        };
    }

    /**
     * User-facing pipeline stage (mode-aware):
     * Master: Uploading → OCR → Parsing → Validating → Importing → Completed
     * Sales:  Uploading → OCR → Parsing → Mapping → Updating/Creating → Completed
     */
    public function pipelineStage(): string
    {
        if ($this->status === self::STATUS_FAILED) {
            return 'failed';
        }
        if ($this->status === self::STATUS_CANCELLED) {
            return 'failed';
        }
        if (in_array($this->status, [self::STATUS_PENDING], true)) {
            return 'pending';
        }
        if (in_array($this->status, [self::STATUS_QUEUED, self::STATUS_UPLOADING_TO_CLOUD], true)) {
            return 'uploading';
        }
        if (in_array($this->status, [self::STATUS_PROCESSING, self::STATUS_FINALIZING], true)) {
            return 'ocr';
        }
        if ($this->status !== self::STATUS_COMPLETED) {
            return 'pending';
        }

        $progress = mb_strtolower((string) ($this->processing_progress ?? ''));
        if ($this->parse_status === 'failed' || str_contains($progress, 'parsing failed') || str_contains($progress, 'import failed') || str_contains($progress, 'mapping failed')) {
            return 'failed';
        }
        if ($this->parse_status !== 'completed') {
            return 'parsing';
        }

        if ($this->isMasterCaImport()) {
            if (str_contains($progress, 'validat')) {
                return 'validating';
            }
            if (str_contains($progress, 'import') && ! str_contains($progress, 'completed')) {
                return 'importing';
            }
            if (str_contains($progress, 'queued for master')) {
                // Should never happen for Master CA — treat as importing recovery.
                return 'importing';
            }

            return 'completed';
        }

        if (
            (str_contains($progress, 'mapping') && ! str_contains($progress, 'completed'))
            || str_contains($progress, 'queued for master')
        ) {
            return 'mapping';
        }
        if (str_contains($progress, 'updating') || str_contains($progress, 'creating')) {
            return 'updating';
        }

        return 'completed';
    }
}

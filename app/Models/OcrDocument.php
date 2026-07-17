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

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_UPLOADING_TO_CLOUD => 'Uploading to cloud',
            self::STATUS_FINALIZING => 'Finalizing',
            default => $this->status ? ucfirst(str_replace('_', ' ', $this->status)) : 'Pending',
        };
    }
}

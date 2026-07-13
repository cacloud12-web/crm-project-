<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class OcrDocument extends Model
{
    use SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'ca_id',
        'uploaded_by',
        'original_filename',
        'stored_filename',
        'storage_disk',
        'storage_path',
        'mime_type',
        'file_size',
        'checksum',
        'status',
        'extracted_text',
        'corrected_text',
        'structured_data',
        'page_count',
        'detected_languages',
        'average_confidence',
        'processor_name',
        'error_code',
        'error_message',
        'processing_attempts',
        'processing_started_at',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'structured_data' => 'array',
            'detected_languages' => 'array',
            'average_confidence' => 'decimal:4',
            'processing_started_at' => 'datetime',
            'processed_at' => 'datetime',
            'file_size' => 'integer',
            'page_count' => 'integer',
            'processing_attempts' => 'integer',
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

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function displayText(): ?string
    {
        return $this->corrected_text ?? $this->extracted_text;
    }
}

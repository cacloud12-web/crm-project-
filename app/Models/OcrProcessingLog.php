<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OcrProcessingLog extends Model
{
    protected $connection = 'ca_reference';

    protected $table = 'ocr_processing_logs';

    protected $fillable = [
        'import_log_id',
        'raw_text',
        'structured_json',
        'confidence',
        'status',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'structured_json' => 'array',
            'confidence' => 'decimal:4',
        ];
    }

    public function importLog(): BelongsTo
    {
        return $this->belongsTo(OcrImportLog::class, 'import_log_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OcrImportLog extends Model
{
    protected $connection = 'ca_reference';

    protected $table = 'ocr_import_logs';

    protected $fillable = [
        'filename',
        'uploaded_by',
        'status',
        'total_records',
        'successful_records',
        'failed_records',
        'duplicate_records',
        'processing_time',
    ];

    protected function casts(): array
    {
        return [
            'uploaded_by' => 'integer',
            'total_records' => 'integer',
            'successful_records' => 'integer',
            'failed_records' => 'integer',
            'duplicate_records' => 'integer',
            'processing_time' => 'integer',
        ];
    }

    public function processingLogs(): HasMany
    {
        return $this->hasMany(OcrProcessingLog::class, 'import_log_id');
    }
}

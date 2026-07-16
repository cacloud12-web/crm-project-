<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class OcrImportBatch extends Model
{
    protected $fillable = [
        'batch_name',
        'uploaded_by',
        'total_documents',
        'completed_documents',
        'failed_documents',
        'status',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'total_documents' => 'integer',
            'completed_documents' => 'integer',
            'failed_documents' => 'integer',
        ];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}

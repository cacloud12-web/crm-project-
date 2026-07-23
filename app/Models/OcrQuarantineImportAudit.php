<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OcrQuarantineImportAudit extends Model
{
    protected $table = 'ocr_quarantine_import_audits';

    protected $fillable = [
        'batch_id',
        'ocr_parsed_firm_id',
        'candidate_id',
        'action',
        'category',
        'disposition',
        'message',
        'before',
        'after',
        'actor_id',
        'dry_run',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'dry_run' => 'boolean',
        ];
    }
}

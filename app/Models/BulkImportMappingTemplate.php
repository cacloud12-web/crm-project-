<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BulkImportMappingTemplate extends Model
{
    protected $fillable = [
        'template_name',
        'field_mapping',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'field_mapping' => 'array',
        ];
    }
}

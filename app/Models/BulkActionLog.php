<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BulkActionLog extends Model
{
    protected $primaryKey = 'log_id';

    protected $table = 'bulk_action_logs';

    protected $fillable = [
        'bulk_action_id',
        'row_number',
        'status',
        'error_message',
        'original_data',
    ];

    protected function casts(): array
    {
        return [
            'original_data' => 'array',
        ];
    }

    public function bulkAction(): BelongsTo
    {
        return $this->belongsTo(BulkAction::class, 'bulk_action_id', 'bulk_action_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesListEditHistory extends Model
{
    protected $fillable = [
        'sales_list_entry_id',
        'user_id',
        'field_name',
        'old_value',
        'new_value',
        'edited_at',
    ];

    protected function casts(): array
    {
        return [
            'edited_at' => 'datetime',
        ];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(SalesListEntry::class, 'sales_list_entry_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

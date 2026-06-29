<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DndManagement extends Model
{
    protected $table = 'dnd_management';

    protected $fillable = [
        'ca_id',
        'mobile_no',
        'email_id',
        'dnd_type',
        'reason',
        'added_by',
        'added_at',
    ];

    protected function casts(): array
    {
        return [
            'added_at' => 'datetime',
        ];
    }

    public function caMaster(): BelongsTo
    {
        return $this->belongsTo(CaMaster::class, 'ca_id', 'ca_id');
    }
}

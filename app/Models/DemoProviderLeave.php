<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DemoProviderLeave extends Model
{
    protected $fillable = [
        'demo_provider_id',
        'leave_date',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'leave_date' => 'date',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(DemoProvider::class, 'demo_provider_id');
    }
}

<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait HasMasterRecordLifecycle
{
    public function initializeHasMasterRecordLifecycle(): void
    {
        $this->mergeFillable([
            'is_active',
            'deactivated_at',
            'deactivated_by',
            'is_system',
        ]);

        $this->mergeCasts([
            'is_active' => 'boolean',
            'is_system' => 'boolean',
            'deactivated_at' => 'datetime',
        ]);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function deactivatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deactivated_by');
    }

    public function isActive(): bool
    {
        return (bool) ($this->is_active ?? true);
    }

    public function isSystemProtected(): bool
    {
        return (bool) ($this->is_system ?? false);
    }
}

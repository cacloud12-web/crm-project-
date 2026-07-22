<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TicketOrganizationLookup extends Model
{
    protected $fillable = [
        'mobile_number',
        'organization_number',
        'organization_name',
        'organizations_payload',
        'lookup_status',
        'verification_status',
        'verified_email',
        'verified_at',
        'expires_at',
        'lookup_source',
        'correlation_id',
        'requested_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'organizations_payload' => 'array',
            'verified_at' => 'datetime',
            'expires_at' => 'datetime',
            'correlation_id' => 'string',
        ];
    }

    public function requestedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class, 'verification_correlation_id', 'correlation_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isVerified(): bool
    {
        return $this->verification_status === 'verified' && filled($this->verified_email);
    }
}

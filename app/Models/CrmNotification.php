<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrmNotification extends Model
{
    protected $fillable = [
        'user_id',
        'audience',
        'audience_roles',
        'type',
        'title',
        'message',
        'severity',
        'entity_type',
        'entity_id',
        'payload',
        'dedup_key',
    ];

    protected function casts(): array
    {
        return [
            'audience_roles' => 'array',
            'payload' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reads(): HasMany
    {
        return $this->hasMany(CrmNotificationRead::class);
    }

    public function scopeVisibleTo($query, User $user)
    {
        return $query->where(function ($builder) use ($user) {
            $builder->where('user_id', $user->id)
                ->orWhere(function ($shared) {
                    $shared->whereNull('user_id')
                        ->where('audience', 'all');
                })
                ->orWhere(function ($shared) use ($user) {
                    $shared->whereNull('user_id')
                        ->where('audience', 'roles')
                        ->whereJsonContains('audience_roles', $user->crm_role);
                });
        });
    }

    public function scopeUnreadFor($query, User $user)
    {
        return $query->visibleTo($user)
            ->whereDoesntHave('reads', fn ($readQuery) => $readQuery->where('user_id', $user->id));
    }
}

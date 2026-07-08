<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

trait HasCampaignMetadata
{
    public static function bootHasCampaignMetadata(): void
    {
        static::creating(function ($model): void {
            if (empty($model->campaign_uuid)) {
                $model->campaign_uuid = (string) Str::uuid();
            }
        });
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function metadataCasts(): array
    {
        return [
            'sender_snapshot' => 'array',
            'template_snapshot' => 'array',
            'status_history' => 'array',
            'paused_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'completed_at' => 'datetime',
            'pending_count' => 'integer',
            'invalid_count' => 'integer',
            'duplicate_count' => 'integer',
            'bounce_count' => 'integer',
            'retry_count' => 'integer',
        ];
    }

    public function recordStatusChange(string $status, ?string $note = null): void
    {
        $history = is_array($this->status_history) ? $this->status_history : [];
        $history[] = [
            'status' => $status,
            'note' => $note,
            'at' => now()->toIso8601String(),
            'by' => auth()->user()?->name ?? auth()->user()?->email ?? 'System',
        ];
        $this->status_history = $history;
        $this->status = $status;
    }
}

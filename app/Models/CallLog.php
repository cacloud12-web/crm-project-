<?php

namespace App\Models;

use App\Services\Cache\CrmCacheService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CallLog extends Model
{
    protected $fillable = [
        'ca_id',
        'employee_id',
        'followup_id',
        'called_at',
        'call_status',
        'call_note',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'called_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (CallLog $log): void {
            if (! $log->employee_id) {
                return;
            }

            $cache = app(CrmCacheService::class);
            $cache->forgetDailyEmployeeTargets((int) $log->employee_id);
            $cache->forgetDashboardMetrics();
        });
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(CaMaster::class, 'ca_id', 'ca_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }

    public function followUp(): BelongsTo
    {
        return $this->belongsTo(FollowUp::class, 'followup_id', 'followup_id');
    }
}

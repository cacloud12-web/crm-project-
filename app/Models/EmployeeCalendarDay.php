<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeCalendarDay extends Model
{
    public const TYPE_WORKING = 'working';

    public const TYPE_HOLIDAY = 'holiday';

    public const TYPE_SUNDAY = 'sunday';

    public const TYPE_LEAVE = 'leave';

    public const TYPE_INACTIVE = 'inactive';

    protected $fillable = [
        'employee_id',
        'yearly_employee_target_id',
        'calendar_date',
        'day_type',
        'holiday_name',
        'lead_target',
        'call_target',
        'demo_target',
        'followup_target',
        'email_target',
        'sms_target',
    ];

    protected function casts(): array
    {
        return [
            'employee_id' => 'integer',
            'yearly_employee_target_id' => 'integer',
            'calendar_date' => 'date',
            'lead_target' => 'integer',
            'call_target' => 'integer',
            'demo_target' => 'integer',
            'followup_target' => 'integer',
            'email_target' => 'integer',
            'sms_target' => 'integer',
        ];
    }

    public function isWorkingDay(): bool
    {
        return $this->day_type === self::TYPE_WORKING;
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }

    public function yearlyTarget(): BelongsTo
    {
        return $this->belongsTo(YearlyEmployeeTarget::class, 'yearly_employee_target_id');
    }
}

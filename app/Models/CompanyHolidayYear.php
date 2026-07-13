<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyHolidayYear extends Model
{
    protected $fillable = [
        'company_holiday_id',
        'year',
        'holiday_date',
    ];

    protected function casts(): array
    {
        return [
            'company_holiday_id' => 'integer',
            'year' => 'integer',
            'holiday_date' => 'date',
        ];
    }

    public function companyHoliday(): BelongsTo
    {
        return $this->belongsTo(CompanyHoliday::class);
    }
}

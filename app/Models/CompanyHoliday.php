<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompanyHoliday extends Model
{
    protected $fillable = [
        'name',
        'month',
        'day',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'month' => 'integer',
            'day' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function yearOverrides(): HasMany
    {
        return $this->hasMany(CompanyHolidayYear::class);
    }

    public function dateForYear(int $year): string
    {
        $override = $this->relationLoaded('yearOverrides')
            ? $this->yearOverrides->firstWhere('year', $year)
            : $this->yearOverrides()->where('year', $year)->first();

        if ($override?->holiday_date) {
            return $override->holiday_date instanceof \Carbon\Carbon
                ? $override->holiday_date->toDateString()
                : (string) $override->holiday_date;
        }

        return sprintf('%04d-%02d-%02d', $year, $this->month, $this->day);
    }

    public function isMovable(): bool
    {
        return in_array($this->name, config('yearly_productivity.movable_holiday_names', []), true);
    }
}

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

    public function dateForYear(int $year): string
    {
        return sprintf('%04d-%02d-%02d', $year, $this->month, $this->day);
    }
}

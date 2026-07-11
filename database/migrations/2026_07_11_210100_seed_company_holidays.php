<?php

use App\Models\CompanyHoliday;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $defaults = config('company_holidays.defaults', []);
        $order = 0;

        foreach ($defaults as $holiday) {
            CompanyHoliday::query()->updateOrCreate(
                [
                    'name' => $holiday['name'],
                    'month' => (int) $holiday['month'],
                    'day' => (int) $holiday['day'],
                ],
                [
                    'is_active' => true,
                    'sort_order' => ++$order,
                ],
            );
        }
    }

    public function down(): void
    {
        CompanyHoliday::query()->delete();
    }
};

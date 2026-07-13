<?php

namespace Tests\Unit;

use App\Services\Demo\DemoSchedulingRulesService;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DemoSchedulingRulesServiceTest extends TestCase
{
    private DemoSchedulingRulesService $rules;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rules = app(DemoSchedulingRulesService::class);
    }

    public function test_valid_monday_morning_slot_passes(): void
    {
        $this->rules->validate('2026-07-13', '10:00', '10:30');
        $this->assertTrue(true);
    }

    public function test_valid_saturday_evening_slot_passes(): void
    {
        $this->rules->validate('2026-07-18', '18:00', '19:00');
        $this->assertTrue(true);
    }

    public function test_sunday_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Demos cannot be scheduled on Sundays.');
        $this->rules->validate('2026-07-12', '10:00', '11:00');
    }

    public function test_start_before_ten_am_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Demo start time must be 10:00 AM or later.');
        $this->rules->validate('2026-07-13', '09:30', '10:30');
    }

    public function test_end_after_seven_pm_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Demo end time must be 7:00 PM or earlier.');
        $this->rules->validate('2026-07-13', '18:30', '19:30');
    }

    public function test_end_not_after_start_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Demo end time must be after the start time.');
        $this->rules->validate('2026-07-13', '14:00', '14:00');
    }

    #[DataProvider('checkProvider')]
    public function test_check_returns_expected_result(string $date, string $start, string $end, bool $valid): void
    {
        $result = $this->rules->check($date, $start, $end);
        $this->assertSame($valid, $result['valid']);
    }

    public static function checkProvider(): array
    {
        return [
            'monday 2pm' => ['2026-07-13', '14:00', '15:00', true],
            'sunday' => ['2026-07-12', '10:00', '11:00', false],
            'early start' => ['2026-07-13', '09:00', '10:00', false],
            'late end' => ['2026-07-13', '19:00', '20:00', false],
        ];
    }
}

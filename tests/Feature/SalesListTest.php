<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\SalesListEditHistory;
use App\Models\SalesListEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SalesListTest extends TestCase
{
    use DatabaseTransactions;

    public function test_manager_can_access_sales_list(): void
    {
        $manager = User::query()->where('email', 'manager@ca.local')->firstOrFail();
        $this->actingAs($manager);

        $this->getJson('/sales-list')
            ->assertOk()
            ->assertJsonPath('message', 'Sales list loaded');
    }

    public function test_employee_cannot_access_sales_list(): void
    {
        $employee = User::query()->where('email', 'employee@ca.local')->firstOrFail();
        $this->actingAs($employee);

        $this->getJson('/sales-list')
            ->assertForbidden();
    }

    public function test_employee_cannot_update_sales_list(): void
    {
        $employee = User::query()->where('email', 'employee@ca.local')->firstOrFail();
        $this->actingAs($employee);

        $entry = $this->makeSalesEntry();

        $this->patchJson('/sales-list/'.$entry->id, [
            'customer_name' => 'Blocked Update',
        ])->assertForbidden();
    }

    public function test_payment_update_recalculates_balance_and_status(): void
    {
        $manager = User::query()->where('email', 'manager@ca.local')->firstOrFail();
        $this->actingAs($manager);

        $entry = $this->makeSalesEntry([
            'total_amount' => 10000,
            'amount_received' => 0,
            'balance_amount' => 10000,
            'payment_status' => 'Pending',
        ]);

        $this->patchJson('/sales-list/'.$entry->id, [
            'amount_received' => 10000,
            'total_amount' => 10000,
        ])->assertOk();

        $entry->refresh();
        $this->assertSame(0.0, (float) $entry->balance_amount);
        $this->assertSame('Paid', $entry->payment_status);
    }

    public function test_manager_can_update_full_sales_record_and_logs_history(): void
    {
        $manager = User::query()->where('email', 'manager@ca.local')->firstOrFail();
        $this->actingAs($manager);

        $executive = Employee::query()->firstOrFail();
        $entry = $this->makeSalesEntry([
            'customer_name' => 'Before Name',
            'firm_name' => 'Before Firm',
            'points' => 3,
        ]);

        $this->patchJson('/sales-list/'.$entry->id, [
            'points' => 6,
            'customer_name' => 'After Name',
            'firm_name' => 'After Firm',
            'reference_name' => 'Ref Updated',
            'mobile_no' => '9111111111',
            'city_name' => 'Delhi',
            'plan_purchased' => 'CRM Half-Yearly',
            'purchase_date' => '2026-01-15',
            'cooling_period_days' => 7,
            'total_amount' => 15000,
            'amount_received' => 5000,
            'invoice_number' => $entry->invoice_number,
            'employee_id' => $executive->employee_id,
            'notes' => 'Updated via test',
        ])->assertOk()
            ->assertJsonPath('data.customer_name', 'After Name')
            ->assertJsonPath('data.points', 6);

        $entry->refresh();
        $this->assertSame('After Name', $entry->customer_name);
        $this->assertSame(10000.0, (float) $entry->balance_amount);
        $this->assertSame('Partial', $entry->payment_status);
        $this->assertSame('2026-07-15', $entry->expiry_date?->toDateString());

        $this->assertDatabaseHas('sales_list_edit_histories', [
            'sales_list_entry_id' => $entry->id,
            'field_name' => 'customer_name',
            'old_value' => 'Before Name',
            'new_value' => 'After Name',
            'user_id' => $manager->id,
        ]);
    }

    public function test_super_admin_can_view_edit_history_manager_cannot(): void
    {
        $entry = $this->makeSalesEntry(['customer_name' => 'Audit Target']);

        SalesListEditHistory::query()->create([
            'sales_list_entry_id' => $entry->id,
            'user_id' => User::query()->where('email', 'manager@ca.local')->value('id'),
            'field_name' => 'customer_name',
            'old_value' => 'Old',
            'new_value' => 'Audit Target',
            'edited_at' => now(),
        ]);

        $manager = User::query()->where('email', 'manager@ca.local')->firstOrFail();
        $this->actingAs($manager);
        $this->getJson('/sales-list/'.$entry->id.'/history')->assertForbidden();

        $admin = User::query()->where('email', 'superadmin@ca.local')->firstOrFail();
        $this->actingAs($admin);
        $this->getJson('/sales-list/'.$entry->id.'/history')
            ->assertOk()
            ->assertJsonPath('data.0.field_name', 'customer_name')
            ->assertJsonPath('data.0.new_value', 'Audit Target');
    }

    public function test_sales_list_column_filters_work_together(): void
    {
        $manager = User::query()->where('email', 'manager@ca.local')->firstOrFail();
        $this->actingAs($manager);

        $entry = $this->makeSalesEntry([
            'serial_number' => 999002,
            'sale_month' => 'Aug 2026',
            'points' => 5,
            'customer_name' => 'Filter Alpha',
            'firm_name' => 'Filter Firm',
            'reference_name' => 'Ref One',
            'mobile_no' => '9000000001',
            'city_name' => 'Mumbai',
            'plan_purchased' => 'CRM Annual',
            'purchase_date' => '2026-08-01',
            'cooling_period_days' => 15,
            'expiry_date' => '2027-08-01',
            'total_amount' => 5000,
            'amount_received' => 2000,
            'balance_amount' => 3000,
            'payment_status' => 'Partial',
        ]);

        $this->getJson('/sales-list?customer_name=Filter+Alpha&payment_status=Partial&total_amount_min=4000')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $entry->id);

        $this->getJson('/sales-list?serial_number=999002&points_min=10')
            ->assertOk()
            ->assertJsonCount(0, 'data.items');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeSalesEntry(array $overrides = []): SalesListEntry
    {
        return SalesListEntry::query()->create(array_merge([
            'serial_number' => random_int(990000, 999999),
            'ca_id' => \App\Models\CaMaster::query()->value('ca_id'),
            'sale_month' => 'Jul 2026',
            'points' => 10,
            'customer_name' => 'Test Customer',
            'firm_name' => 'Test Firm',
            'purchase_date' => now()->toDateString(),
            'cooling_period_days' => 15,
            'expiry_date' => now()->addYear()->toDateString(),
            'total_amount' => 10000,
            'amount_received' => 0,
            'balance_amount' => 10000,
            'invoice_number' => 'INV-TEST-'.uniqid(),
            'payment_status' => 'Pending',
        ], $overrides));
    }
}

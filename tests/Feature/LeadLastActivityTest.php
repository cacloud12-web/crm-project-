<?php

namespace Tests\Feature;

use Tests\Support\CrmTestAccounts;

use App\Models\CaMaster;
use App\Models\CallLog;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class LeadLastActivityTest extends TestCase
{
    use DatabaseTransactions;

    private function actingAsAdmin(): User
    {
        $admin = CrmTestAccounts::admin();
        $this->actingAs($admin);

        return $admin;
    }

    public function test_ca_master_listing_includes_last_activity_summary(): void
    {
        $this->actingAsAdmin();

        $ts = (string) microtime(true);
        $lead = CaMaster::query()->create([
            'ca_name' => 'Activity CA '.$ts,
            'firm_name' => 'Activity Firm '.$ts,
            'mobile_no' => '9'.substr(str_replace('.', '', $ts), -9),
            'state_id' => CaMaster::query()->value('state_id'),
            'status' => 'New',
        ]);
        $employee = Employee::query()->where('status', 'Active')->firstOrFail();

        CallLog::query()->create([
            'ca_id' => $lead->ca_id,
            'employee_id' => $employee->employee_id,
            'called_at' => now(),
            'call_status' => 'Connected',
            'call_note' => 'Client requested GST details.',
        ]);

        $response = $this->getJson('/ca-masters/'.$lead->ca_id);

        $response->assertOk()
            ->assertJsonPath('data.last_activity.type', 'call')
            ->assertJsonPath('data.last_activity.label', 'Call')
            ->assertJsonStructure([
                'data' => [
                    'last_activity' => [
                        'occurred_at',
                        'type',
                        'label',
                        'employee_name',
                        'relative_label',
                        'time_label',
                        'age_bucket',
                    ],
                ],
            ]);
    }

    public function test_ca_master_listing_formats_denormalized_last_activity_labels(): void
    {
        $this->actingAsAdmin();

        $ts = (string) microtime(true);
        $lead = CaMaster::query()->create([
            'ca_name' => 'Listed Activity CA '.$ts,
            'firm_name' => 'Listed Activity Firm '.$ts,
            'mobile_no' => '9'.substr(str_replace('.', '', $ts), -9),
            'state_id' => CaMaster::query()->value('state_id'),
            'status' => 'New',
            'last_activity_at' => now(),
        ]);

        $listing = $this->getJson('/ca-masters?search='.urlencode('Listed Activity Firm '.$ts))->assertOk();
        $items = $listing->json('data.items') ?? [];
        $listed = collect($items)->firstWhere('ca_id', $lead->ca_id);

        $this->assertNotNull($listed, 'Lead should appear in listing.');
        $this->assertSame('Today', $listed['last_activity']['relative_label'] ?? null);
        $this->assertSame('today', $listed['last_activity']['age_bucket'] ?? null);
        $this->assertSame('🟢', $listed['last_activity']['emoji'] ?? null);
        $this->assertNotEmpty($listed['last_activity']['time_label'] ?? null);
        $this->assertNotEmpty($listed['last_activity']['date_label'] ?? null);
    }

    public function test_ca_master_listing_uses_days_ago_labels_for_older_activity(): void
    {
        $this->actingAsAdmin();

        $ts = (string) microtime(true);
        $lead = CaMaster::query()->create([
            'ca_name' => 'Older Activity CA '.$ts,
            'firm_name' => 'Older Activity Firm '.$ts,
            'mobile_no' => '9'.substr(str_replace('.', '', $ts), -9),
            'state_id' => CaMaster::query()->value('state_id'),
            'status' => 'New',
            'last_activity_at' => now()->subDays(28),
        ]);

        $listing = $this->getJson('/ca-masters?search='.urlencode('Older Activity Firm '.$ts))->assertOk();
        $items = $listing->json('data.items') ?? [];
        $listed = collect($items)->firstWhere('ca_id', $lead->ca_id);

        $this->assertNotNull($listed);
        $this->assertSame('28 Days Ago', $listed['last_activity']['relative_label'] ?? null);
        $this->assertSame('old', $listed['last_activity']['age_bucket'] ?? null);
        $this->assertSame('🔴', $listed['last_activity']['emoji'] ?? null);
    }

    public function test_new_lead_falls_back_to_lead_created_activity(): void
    {
        $this->actingAsAdmin();

        $ts = (string) microtime(true);
        $lead = CaMaster::query()->create([
            'ca_name' => 'Fresh CA '.$ts,
            'firm_name' => 'Fresh Firm '.$ts,
            'mobile_no' => '9'.substr(str_replace('.', '', $ts), -9),
            'state_id' => CaMaster::query()->value('state_id'),
            'status' => 'New',
        ]);

        CallLog::query()->where('ca_id', $lead->ca_id)->delete();

        $response = $this->getJson('/ca-masters/'.$lead->ca_id);

        $response->assertOk()
            ->assertJsonPath('data.last_activity.type', 'lead_created')
            ->assertJsonPath('data.last_activity.label', 'Lead Created');
    }

    public function test_activity_timeline_endpoint_returns_recent_items(): void
    {
        $this->actingAsAdmin();

        $ts = (string) microtime(true);
        $lead = CaMaster::query()->create([
            'ca_name' => 'Timeline CA '.$ts,
            'firm_name' => 'Timeline Firm '.$ts,
            'mobile_no' => '9'.substr(str_replace('.', '', $ts), -9),
            'state_id' => CaMaster::query()->whereNotNull('state_id')->value('state_id'),
            'status' => 'New',
        ]);
        $employee = Employee::query()->where('status', 'Active')->firstOrFail();

        CallLog::query()->where('ca_id', $lead->ca_id)->delete();

        CallLog::query()->create([
            'ca_id' => $lead->ca_id,
            'employee_id' => $employee->employee_id,
            'called_at' => now(),
            'call_status' => 'Connected',
            'call_note' => 'Discussed pricing.',
        ]);

        $response = $this->getJson('/ca-masters/'.$lead->ca_id.'/activity-timeline?limit=10');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'ca_id',
                    'firm_name',
                    'items' => [[
                        'type',
                        'label',
                        'employee_name',
                        'occurred_at',
                        'relative_label',
                        'time_label',
                    ]],
                ],
            ]);

        $types = collect($response->json('data.items'))->pluck('type');
        $this->assertTrue($types->contains('call'), 'Expected call activity in timeline');
        $this->assertSame('call', $response->json('data.items.0.type'));
    }
}

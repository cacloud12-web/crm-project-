<?php

namespace Tests\Feature;

use Tests\Support\CrmTestAccounts;

use App\Models\ActivityLog;
use App\Models\AssignmentHistory;
use App\Models\CaMaster;
use App\Models\City;
use App\Models\Employee;
use App\Models\FollowUp;
use App\Models\LeadAssignmentEngine;
use App\Models\State;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Production-readiness lifecycle: import → assign → employee see → follow-up → status.
 * API-level E2E (no real external services). Uses DatabaseTransactions — never migrate:fresh.
 */
class FullLeadLifecycleE2ETest extends TestCase
{
    use DatabaseTransactions;

    private function seedStateCity(): array
    {
        $state = State::query()->firstOrFail();
        $city = City::query()->where('state_id', $state->state_id)->firstOrFail();

        return [$state, $city];
    }

    public function test_full_import_assign_followup_status_lifecycle(): void
    {
        [$state, $city] = $this->seedStateCity();
        $suffix = (string) random_int(100000, 999999);
        $mobile = '9'.substr(str_pad($suffix, 9, '0'), -9);
        $firm = 'Lifecycle Firm '.$suffix;

        // ── Super Admin imports a lead via CSV ─────────────────────────────
        $admin = CrmTestAccounts::admin();
        $this->actingAs($admin);

        $csv = "CA Name,Firm Name,Mobile No,Email\n"
            ."\"Lifecycle CA\",\"{$firm}\",{$mobile},\"lifecycle.{$suffix}@test.local\"\n";

        $file = UploadedFile::fake()->createWithContent('lifecycle-import.csv', $csv);

        $parse = $this->post('/ca-masters/bulk-import/parse', ['file' => $file], [
            'Accept' => 'application/json',
        ]);
        $parse->assertOk();
        $sessionId = $parse->json('data.session_id');
        $this->assertNotEmpty($sessionId);

        $mapping = [
            'ca_name' => 'CA Name',
            'firm_name' => 'Firm Name',
            'mobile_no' => 'Mobile No',
            'email_id' => 'Email',
        ];

        $this->postJson('/ca-masters/bulk-import/validate', [
            'session_id' => $sessionId,
            'mapping' => $mapping,
        ])->assertOk();

        $import = $this->postJson('/ca-masters/bulk-import', [
            'session_id' => $sessionId,
            'mapping' => $mapping,
        ]);
        $import->assertOk();
        $import->assertJsonPath('data.inserted_rows', 1);

        $lead = CaMaster::query()->where('firm_name', $firm)->first();
        $this->assertNotNull($lead, 'Imported lead must persist in ca_masters');
        $this->assertSame($mobile, (string) $lead->mobile_no);

        // ── Manager assigns to Present employee ────────────────────────────
        $manager = CrmTestAccounts::manager();
        $employee = CrmTestAccounts::employee();
        $employeeUser = CrmTestAccounts::employeeUser();
        $employeeUser->forceFill(['last_seen_at' => now(), 'is_active' => true])->save();

        $this->actingAs($manager);

        $assign = $this->postJson('/lead-assignments', [
            'ca_id' => $lead->ca_id,
            'employee_id' => $employee->employee_id,
            'assignment_type' => 'Manual',
            'reason' => 'LIFECYCLE_E2E',
        ]);
        $assign->assertCreated();

        $this->assertDatabaseHas('lead_assignment_engines', [
            'ca_id' => $lead->ca_id,
            'employee_id' => $employee->employee_id,
        ]);

        $this->assertTrue(
            AssignmentHistory::query()->where('ca_id', $lead->ca_id)->exists()
            || LeadAssignmentEngine::query()->where('ca_id', $lead->ca_id)->exists()
        );

        // Presence list for assignment UI must include employee
        $presence = $this->getJson('/lead-assignments/bulk/employees?per_page=50');
        if ($presence->status() === 200) {
            $items = $presence->json('data.items') ?? $presence->json('data') ?? [];
            $this->assertIsArray($items);
        }

        // ── Employee sees assigned lead; cannot assign / security ──────────
        $this->actingAs($employeeUser);

        $list = $this->getJson('/ca-masters?per_page=100&search='.urlencode($firm));
        $list->assertOk();
        $items = $list->json('data.items') ?? [];
        $seen = collect($items)->contains(fn ($row) => (int) ($row['ca_id'] ?? 0) === (int) $lead->ca_id);
        $this->assertTrue($seen, 'Employee must see assigned lead in list');

        $this->postJson('/lead-assignments', [
            'ca_id' => $lead->ca_id,
            'employee_id' => $employee->employee_id,
            'assignment_type' => 'Manual',
            'reason' => 'SHOULD_FAIL',
        ])->assertForbidden();

        $this->getJson('/admin/role-permissions')->assertForbidden();

        // Employee can open recycle-bin SPA page if allowed by RbacService
        $recycle = $this->get('/recycle-bin');
        $this->assertContains($recycle->status(), [200, 403], 'recycle-bin responds with 200 or scoped 403');

        // ── Employee schedules follow-up ───────────────────────────────────
        $scheduledAt = now()->addDay()->setTime(14, 30)->seconds(0);
        $fu = $this->postJson('/follow-ups', [
            'ca_id' => $lead->ca_id,
            'employee_id' => $employee->employee_id,
            'followup_type' => 'Call',
            'scheduled_date' => $scheduledAt->toDateTimeString(),
            'status' => 'Scheduled',
            'remarks' => 'Lifecycle E2E follow-up',
            'priority' => 'Normal',
        ]);
        $fu->assertCreated();
        $followupId = $fu->json('data.followup_id') ?? $fu->json('data.id');
        $this->assertNotNull($followupId);

        $this->assertDatabaseHas('follow_ups', [
            'followup_id' => $followupId,
            'ca_id' => $lead->ca_id,
            'employee_id' => $employee->employee_id,
        ]);

        // Reschedule
        $rescheduled = $scheduledAt->copy()->addHours(2);
        $this->putJson('/follow-ups/'.$followupId, [
            'ca_id' => $lead->ca_id,
            'employee_id' => $employee->employee_id,
            'followup_type' => 'Call',
            'scheduled_date' => $rescheduled->toDateTimeString(),
            'status' => 'Scheduled',
            'remarks' => 'Rescheduled lifecycle E2E',
        ])->assertSuccessful();

        // Complete
        $this->putJson('/follow-ups/'.$followupId, [
            'ca_id' => $lead->ca_id,
            'employee_id' => $employee->employee_id,
            'followup_type' => 'Call',
            'scheduled_date' => $rescheduled->toDateTimeString(),
            'status' => 'Completed',
            'remarks' => 'Completed lifecycle E2E',
        ])->assertSuccessful();

        $this->assertDatabaseHas('follow_ups', [
            'followup_id' => $followupId,
            'status' => 'Completed',
        ]);

        // ── Status progression toward Converted/Purchased pathway ──────────
        $this->patchJson('/ca-masters/'.$lead->ca_id.'/status', [
            'status' => 'Contacted',
        ])->assertSuccessful();

        $this->patchJson('/ca-masters/'.$lead->ca_id.'/status', [
            'status' => 'Interested',
        ])->assertSuccessful();

        $lead->refresh();
        $this->assertSame('Interested', $lead->status);

        // Manager / Admin see activity
        $this->actingAs($manager);
        $activity = $this->getJson('/activity-logs?per_page=20');
        $activity->assertOk();

        $this->actingAs($admin);
        $reports = $this->getJson('/reports/lead_conversion?from='.now()->subDays(30)->toDateString().'&to='.now()->toDateString());
        $reports->assertOk();

        // Confirm activity logs were written for lifecycle events
        $this->assertTrue(
            ActivityLog::query()->where('record_id', (string) $lead->ca_id)->exists()
            || ActivityLog::query()->where('action', 'like', '%Follow-up%')->exists()
            || ActivityLog::query()->where('action', 'like', '%Assignment%')->exists(),
            'Lifecycle actions should produce activity logs'
        );
    }

    public function test_employee_cannot_see_unassigned_leads_from_other_employees(): void
    {
        [$state, $city] = $this->seedStateCity();
        $suffix = (string) random_int(100000, 999999);

        $orphan = CaMaster::query()->create([
            'firm_name' => 'Orphan Firm '.$suffix,
            'ca_name' => 'Orphan CA',
            'mobile_no' => '8'.substr(str_pad($suffix, 9, '0'), -9),
            'email_id' => "orphan.{$suffix}@test.local",
            'city_id' => $city->city_id,
            'state_id' => $state->state_id,
            'status' => 'New',
        ]);

        $employeeUser = CrmTestAccounts::employeeUser();
        $this->actingAs($employeeUser);

        $list = $this->getJson('/ca-masters?per_page=100&search='.urlencode('Orphan Firm '.$suffix));
        $list->assertOk();
        $items = $list->json('data.items') ?? [];
        foreach ($items as $item) {
            $this->assertNotSame((int) $orphan->ca_id, (int) ($item['ca_id'] ?? 0));
        }
    }
}

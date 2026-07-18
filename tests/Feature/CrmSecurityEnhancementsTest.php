<?php

namespace Tests\Feature;

use Tests\Support\CrmTestAccounts;

use App\Models\ActivityLog;
use App\Models\CaMaster;
use App\Models\Employee;
use App\Models\LeadAssignmentEngine;
use App\Models\User;
use App\Support\Security\TextSanitizer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class CrmSecurityEnhancementsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_bulk_import_rejects_empty_file(): void
    {
        $admin = CrmTestAccounts::admin();
        $this->actingAs($admin);

        $file = UploadedFile::fake()->create('empty.csv', 0, 'text/csv');

        $this->postJson('/ca-masters/bulk-import/parse', ['file' => $file])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_bulk_import_rejects_invalid_extension(): void
    {
        $admin = CrmTestAccounts::admin();
        $this->actingAs($admin);

        $file = UploadedFile::fake()->create('malware.exe', 100, 'application/octet-stream');

        $this->postJson('/ca-masters/bulk-import/parse', ['file' => $file])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_bulk_import_is_rate_limited(): void
    {
        $admin = CrmTestAccounts::admin();
        $this->actingAs($admin);

        RateLimiter::clear('bulk-import:user:'.$admin->id);

        $file = UploadedFile::fake()->create('sample.csv', 10, 'text/csv');

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/ca-masters/bulk-import/parse', ['file' => $file]);
        }

        $this->postJson('/ca-masters/bulk-import/parse', ['file' => $file])
            ->assertStatus(429);
    }

    public function test_text_sanitizer_strips_script_tags(): void
    {
        $dirty = '<script>alert("xss")</script>Hello';
        $clean = TextSanitizer::plain($dirty);

        $this->assertSame('Hello', $clean);
        $this->assertStringNotContainsString('<script>', $clean);
    }

    public function test_follow_up_remarks_are_sanitized_on_store(): void
    {
        $admin = CrmTestAccounts::admin();
        $this->actingAs($admin);

        $lead = CaMaster::query()->firstOrFail();

        $response = $this->postJson('/follow-ups', [
            'ca_id' => $lead->ca_id,
            'followup_type' => 'Call',
            'scheduled_date' => now()->addDay()->toDateString(),
            'remarks' => '<b>Important</b><script>alert(1)</script>',
        ]);

        $response->assertCreated();
        $this->assertStringNotContainsString('<script>', (string) $response->json('data.remarks'));
    }

    public function test_manager_cannot_see_sms_api_key_in_settings(): void
    {
        $manager = CrmTestAccounts::manager();
        $this->actingAs($manager);

        $response = $this->getJson('/sms-settings');

        if ($response->status() === 200) {
            $payload = $response->json('data');
            $this->assertIsArray($payload);
            $this->assertArrayNotHasKey('api_key', $payload);
            $this->assertArrayHasKey('has_api_key', $payload);
        } else {
            $response->assertForbidden();
        }
    }

    public function test_employee_cannot_access_sms_settings(): void
    {
        $employee = CrmTestAccounts::employeeUser();
        $this->actingAs($employee);

        $this->getJson('/sms-settings')->assertForbidden();
    }
}

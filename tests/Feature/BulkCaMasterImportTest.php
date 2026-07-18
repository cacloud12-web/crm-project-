<?php

namespace Tests\Feature;

use Tests\Support\CrmTestAccounts;

use App\Models\CaMaster;
use App\Models\DuplicateAttempt;
use App\Models\DuplicateAttemptLog;
use App\Models\ImportDuplicateLog;
use App\Models\User;
use App\Services\Bulk\BulkCaMasterImportService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BulkCaMasterImportTest extends TestCase
{
    use DatabaseTransactions;

    private function actingAsAdmin(): User
    {
        $admin = CrmTestAccounts::admin();
        $this->actingAs($admin);

        return $admin;
    }

    public function test_bulk_import_succeeds_without_mobile_column(): void
    {
        $this->actingAsAdmin();
        $ts = (string) microtime(true);

        $csv = "CA Name,Firm Name,Email\n";
        $csv .= '"Feature CA '.$ts.'","Feature Firm '.$ts.'","import.'.$ts.'@test.local"'."\n";

        $file = UploadedFile::fake()->createWithContent('firms-no-mobile.csv', $csv);

        $parse = $this->post('/ca-masters/bulk-import/parse', ['file' => $file], [
            'Accept' => 'application/json',
        ]);
        $parse->assertOk();
        $parse->assertJsonPath('data.has_mobile_column', false);

        $sessionId = $parse->json('data.session_id');
        $mapping = [
            'ca_name' => 'CA Name',
            'firm_name' => 'Firm Name',
            'email_id' => 'Email',
        ];

        $validate = $this->postJson('/ca-masters/bulk-import/validate', [
            'session_id' => $sessionId,
            'mapping' => $mapping,
        ]);
        $validate->assertOk();
        $validate->assertJsonPath('data.valid_rows', 1);
        $validate->assertJsonPath('data.invalid_rows', 0);

        $import = $this->postJson('/ca-masters/bulk-import', [
            'session_id' => $sessionId,
            'mapping' => $mapping,
        ]);
        $import->assertOk();
        $import->assertJsonPath('data.inserted_rows', 1);

        $this->assertDatabaseHas('ca_masters', [
            'firm_name' => 'Feature Firm '.$ts,
            'ca_name' => 'Feature CA '.$ts,
            'email_id' => 'import.'.$ts.'@test.local',
            'mobile_no' => null,
        ]);
    }

    public function test_bulk_import_validates_mobile_only_when_column_is_mapped(): void
    {
        $this->actingAsAdmin();
        $ts = (string) microtime(true);

        $mobile = '9'.substr(str_replace('.', '', $ts), -9);

        $csv = "CA Name,Firm Name,Mobile No,Email\n";
        $csv .= '"Mapped CA '.$ts.'","Mapped Firm '.$ts.'",'.$mobile.',"mapped.'.$ts.'@test.local"'."\n";
        $csv .= '"Empty Mobile CA '.$ts.'","Empty Mobile Firm '.$ts.'",,"empty.'.$ts.'@test.local"'."\n";

        $file = UploadedFile::fake()->createWithContent('firms-with-mobile.csv', $csv);

        $parse = $this->post('/ca-masters/bulk-import/parse', ['file' => $file], [
            'Accept' => 'application/json',
        ]);
        $parse->assertOk();
        $parse->assertJsonPath('data.has_mobile_column', true);

        $sessionId = $parse->json('data.session_id');
        $mapping = [
            'ca_name' => 'CA Name',
            'firm_name' => 'Firm Name',
            'mobile_no' => 'Mobile No',
            'email_id' => 'Email',
        ];

        $validate = $this->postJson('/ca-masters/bulk-import/validate', [
            'session_id' => $sessionId,
            'mapping' => $mapping,
        ]);
        $validate->assertOk();
        $validate->assertJsonPath('data.valid_rows', 2);

        $import = $this->postJson('/ca-masters/bulk-import', [
            'session_id' => $sessionId,
            'mapping' => $mapping,
        ]);
        $import->assertOk();
        $import->assertJsonPath('data.inserted_rows', 2);

        $this->assertDatabaseHas('ca_masters', [
            'firm_name' => 'Mapped Firm '.$ts,
            'mobile_no' => $mobile,
        ]);
        $this->assertDatabaseHas('ca_masters', [
            'firm_name' => 'Empty Mobile Firm '.$ts,
            'mobile_no' => null,
        ]);
    }

    public function test_bulk_import_rejects_invalid_mobile_format_when_column_mapped(): void
    {
        $this->actingAsAdmin();
        $ts = (string) microtime(true);

        $csv = "CA Name,Firm Name,Mobile No,Email\n";
        $csv .= '"Invalid Mobile CA '.$ts.'","Invalid Mobile Firm '.$ts.'",12345,"invalid.'.$ts.'@test.local"'."\n";

        $file = UploadedFile::fake()->createWithContent('firms-invalid-mobile.csv', $csv);

        $parse = $this->post('/ca-masters/bulk-import/parse', ['file' => $file], [
            'Accept' => 'application/json',
        ]);
        $parse->assertOk();

        $sessionId = $parse->json('data.session_id');
        $mapping = [
            'ca_name' => 'CA Name',
            'firm_name' => 'Firm Name',
            'mobile_no' => 'Mobile No',
            'email_id' => 'Email',
        ];

        $validate = $this->postJson('/ca-masters/bulk-import/validate', [
            'session_id' => $sessionId,
            'mapping' => $mapping,
        ]);
        $validate->assertOk();
        $validate->assertJsonPath('data.valid_rows', 0);
        $validate->assertJsonPath('data.invalid_rows', 1);
    }

    public function test_bulk_import_allows_mobile_column_to_be_ignored(): void
    {
        $this->actingAsAdmin();
        $ts = (string) microtime(true);

        $csv = "CA Name,Firm Name,Mobile No,Email\n";
        $csv .= '"Ignored Mobile CA '.$ts.'","Ignored Mobile Firm '.$ts.'",9876543210,"ignored.'.$ts.'@test.local"'."\n";

        $file = UploadedFile::fake()->createWithContent('firms-ignore-mobile.csv', $csv);

        $parse = $this->post('/ca-masters/bulk-import/parse', ['file' => $file], [
            'Accept' => 'application/json',
        ]);
        $parse->assertOk();

        $sessionId = $parse->json('data.session_id');
        $mapping = [
            'ca_name' => 'CA Name',
            'firm_name' => 'Firm Name',
            'email_id' => 'Email',
        ];

        $validate = $this->postJson('/ca-masters/bulk-import/validate', [
            'session_id' => $sessionId,
            'mapping' => $mapping,
        ]);
        $validate->assertOk();
        $validate->assertJsonPath('data.valid_rows', 1);

        $import = $this->postJson('/ca-masters/bulk-import', [
            'session_id' => $sessionId,
            'mapping' => $mapping,
        ]);
        $import->assertOk();
        $import->assertJsonPath('data.inserted_rows', 1);

        $this->assertDatabaseHas('ca_masters', [
            'firm_name' => 'Ignored Mobile Firm '.$ts,
            'mobile_no' => null,
        ]);
    }

    public function test_bulk_import_parses_excel_template(): void
    {
        $this->actingAsAdmin();

        $binary = app(\App\Services\Bulk\BulkImportTemplateService::class)->sampleXlsx();
        $file = UploadedFile::fake()->createWithContent('firms.xlsx', $binary);

        $parse = $this->post('/ca-masters/bulk-import/parse', ['file' => $file], [
            'Accept' => 'application/json',
        ]);

        $parse->assertOk();
        $parse->assertJsonPath('data.total_rows', 1);
        $parse->assertJsonPath('data.headers.0', 'ca_name');
    }

    public function test_bulk_import_accepts_ca_master_profile_without_mobile_or_email(): void
    {
        $this->actingAsAdmin();
        $ts = (string) microtime(true);

        $csv = "Firm Name,CA Name,Membership No,FRN,Address,City,State,Pincode,Mobile,Email\n";
        $csv .= '"Profile Firm '.$ts.'","Profile CA '.$ts.'",M-1001,FRN-2001,"12 Main Road","Unknown City","Unknown State",400001,,'."\n";
        $csv .= '"Profile Firm B '.$ts.'","Profile CA B '.$ts.'",,,,"Another City","Another State",,,NA'."\n";

        $file = UploadedFile::fake()->createWithContent('ca-master-profile.csv', $csv);

        $parse = $this->post('/ca-masters/bulk-import/parse', ['file' => $file], [
            'Accept' => 'application/json',
        ]);
        $parse->assertOk();

        $sessionId = $parse->json('data.session_id');
        $mapping = [
            'firm_name' => 'Firm Name',
            'ca_name' => 'CA Name',
            'membership_no' => 'Membership No',
            'frn' => 'FRN',
            'address' => 'Address',
            'city_id' => 'City',
            'state_id' => 'State',
            'pincode' => 'Pincode',
            'mobile_no' => 'Mobile',
            'email_id' => 'Email',
        ];

        $validate = $this->postJson('/ca-masters/bulk-import/validate', [
            'session_id' => $sessionId,
            'mapping' => $mapping,
        ]);
        $validate->assertOk();
        $validate->assertJsonPath('data.valid_rows', 2);
        $validate->assertJsonPath('data.invalid_rows', 0);
        $validate->assertJsonPath('data.missing_mobile_rows', 2);
        $validate->assertJsonPath('data.missing_email_rows', 2);
        $validate->assertJsonPath('data.ready_to_import_rows', 2);

        $import = $this->postJson('/ca-masters/bulk-import', [
            'session_id' => $sessionId,
            'mapping' => $mapping,
        ]);
        $import->assertOk();
        $import->assertJsonPath('data.inserted_rows', 2);

        $this->assertDatabaseHas('ca_masters', [
            'firm_name' => 'Profile Firm '.$ts,
            'ca_name' => 'Profile CA '.$ts,
            'membership_no' => 'M-1001',
            'frn' => 'FRN-2001',
            'mobile_no' => null,
            'email_id' => null,
        ]);
    }

    public function test_bulk_import_maps_number_column_and_shows_mobile_in_preview(): void
    {
        $this->actingAsAdmin();
        $ts = (string) microtime(true);
        $mobile = '9'.substr(str_replace('.', '', $ts), -9);
        $altMobile = '8'.substr(str_replace('.', '', $ts), -9);

        $csv = "ca name,firm name,number,Alternate Mobile No,City\n";
        $csv .= ',"Sheet Firm '.$ts.'",'.$mobile.','.$altMobile.',"Mumbai"'."\n";

        $file = UploadedFile::fake()->createWithContent('google-sheet-export.csv', $csv);

        $parse = $this->post('/ca-masters/bulk-import/parse', ['file' => $file], [
            'Accept' => 'application/json',
        ]);
        $parse->assertOk();
        $parse->assertJsonPath('data.has_mobile_column', true);

        $crmFields = collect($parse->json('data.crm_fields'));
        $this->assertNotNull($crmFields->firstWhere('key', 'mobile_no'));
        $this->assertSame('Mobile Number', $crmFields->firstWhere('key', 'mobile_no')['label'] ?? null);
        $this->assertSame('number', $parse->json('data.suggested_mapping.mobile_no'));

        $sessionId = $parse->json('data.session_id');
        $mapping = [
            'firm_name' => 'firm name',
            'mobile_no' => 'number',
            'alternate_mobile_no' => 'Alternate Mobile No',
            'city_id' => 'City',
        ];

        $validate = $this->postJson('/ca-masters/bulk-import/validate', [
            'session_id' => $sessionId,
            'mapping' => $mapping,
        ]);
        $validate->assertOk();
        $validate->assertJsonPath('data.valid_rows', 1);
        $validate->assertJsonPath('data.invalid_rows', 0);
        $validate->assertJsonPath('data.missing_mobile_rows', 0);

        $preview = $validate->json('data.preview_rows.0');
        $this->assertSame('valid', $preview['status'] ?? null);
        $this->assertSame($mobile, $preview['data']['mobile_no'] ?? null);
        $this->assertSame('Sheet Firm '.$ts, $preview['data']['firm_name'] ?? null);

        $import = $this->postJson('/ca-masters/bulk-import', [
            'session_id' => $sessionId,
            'mapping' => $mapping,
        ]);
        $import->assertOk();
        $import->assertJsonPath('data.inserted_rows', 1);

        $this->assertDatabaseHas('ca_masters', [
            'firm_name' => 'Sheet Firm '.$ts,
            'mobile_no' => $mobile,
            'alternate_mobile_no' => $altMobile,
        ]);
    }

    public function test_bulk_import_accepts_blank_ca_name_when_firm_and_mobile_mapped(): void
    {
        $this->actingAsAdmin();
        $ts = (string) microtime(true);
        $mobile = '9'.substr(str_replace('.', '', $ts), -9);

        $csv = "ca name,firm name,number\n";
        $csv .= ',"Firm Only '.$ts.'",'.$mobile."\n";

        $file = UploadedFile::fake()->createWithContent('firm-mobile-no-ca.csv', $csv);
        $parse = $this->post('/ca-masters/bulk-import/parse', ['file' => $file], [
            'Accept' => 'application/json',
        ])->assertOk();

        $sessionId = $parse->json('data.session_id');
        $mapping = [
            'firm_name' => 'firm name',
            'mobile_no' => 'number',
        ];

        $this->postJson('/ca-masters/bulk-import/validate', [
            'session_id' => $sessionId,
            'mapping' => $mapping,
        ])->assertOk()
            ->assertJsonPath('data.valid_rows', 1)
            ->assertJsonPath('data.invalid_rows', 0);

        $this->postJson('/ca-masters/bulk-import', [
            'session_id' => $sessionId,
            'mapping' => $mapping,
        ])->assertOk()
            ->assertJsonPath('data.inserted_rows', 1);

        $this->assertDatabaseHas('ca_masters', [
            'firm_name' => 'Firm Only '.$ts,
            'mobile_no' => $mobile,
            'ca_name' => '',
        ]);
    }

    public function test_bulk_validation_uses_batched_duplicate_queries(): void
    {
        $this->actingAsAdmin();
        $suffix = str_replace('.', '', (string) microtime(true));
        $csv = "CA Name,Firm Name\n";
        for ($i = 0; $i < 200; $i++) {
            $csv .= '"Batch CA '.$suffix.' '.$i.'","Batch Firm '.$suffix.' '.$i.'"'."\n";
        }

        $parse = $this->post('/ca-masters/bulk-import/parse', [
            'file' => UploadedFile::fake()->createWithContent('batched-validation.csv', $csv),
        ], ['Accept' => 'application/json'])->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $validate = $this->postJson('/ca-masters/bulk-import/validate', [
            'session_id' => $parse->json('data.session_id'),
            'mapping' => [
                'ca_name' => 'CA Name',
                'firm_name' => 'Firm Name',
            ],
        ]);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $validate->assertOk()
            ->assertJsonPath('data.valid_rows', 200)
            ->assertJsonPath('data.invalid_rows', 0);
        $this->assertLessThan(
            40,
            $queryCount,
            'Validation query count should stay bounded instead of growing per row.',
        );
    }

    public function test_batched_duplicate_detection_preserves_audit_logs(): void
    {
        $this->actingAsAdmin();
        $existing = CaMaster::query()
            ->whereNotNull('normalized_mobile')
            ->where('normalized_mobile', '!=', '')
            ->first();
        if (! $existing) {
            $this->markTestSkipped('No lead with a normalized mobile fixture');
        }

        $before = [
            ImportDuplicateLog::query()->count(),
            DuplicateAttemptLog::query()->count(),
            DuplicateAttempt::query()->count(),
        ];
        $csv = "Firm Name,Mobile\n";
        $csv .= '"Duplicate Audit '.microtime(true).'","'.$existing->normalized_mobile.'"'."\n";
        $parse = $this->post('/ca-masters/bulk-import/parse', [
            'file' => UploadedFile::fake()->createWithContent('duplicate-audit.csv', $csv),
        ], ['Accept' => 'application/json'])->assertOk();

        $this->postJson('/ca-masters/bulk-import/validate', [
            'session_id' => $parse->json('data.session_id'),
            'mapping' => [
                'firm_name' => 'Firm Name',
                'mobile_no' => 'Mobile',
            ],
        ])->assertOk()
            ->assertJsonPath('data.duplicate_rows', 1);

        $this->assertSame($before[0] + 1, ImportDuplicateLog::query()->count());
        $this->assertSame($before[1] + 1, DuplicateAttemptLog::query()->count());
        $this->assertSame($before[2] + 1, DuplicateAttempt::query()->count());
    }

    public function test_large_import_queues_for_background_processing(): void
    {
        $this->actingAsAdmin();
        $suffix = str_replace('.', '', (string) microtime(true));
        $csv = "CA Name,Firm Name\n";
        for ($i = 0; $i < 150; $i++) {
            $csv .= '"Queue CA '.$suffix.' '.$i.'","Queue Firm '.$suffix.' '.$i.'"'."\n";
        }

        $parse = $this->post('/ca-masters/bulk-import/parse', [
            'file' => UploadedFile::fake()->createWithContent('queue-import.csv', $csv),
        ], ['Accept' => 'application/json'])->assertOk();

        $sessionId = $parse->json('data.session_id');
        $mapping = [
            'ca_name' => 'CA Name',
            'firm_name' => 'Firm Name',
        ];

        $this->postJson('/ca-masters/bulk-import/validate', [
            'session_id' => $sessionId,
            'mapping' => $mapping,
        ])->assertOk()
            ->assertJsonPath('data.valid_rows', 150);

        $started = microtime(true);
        $import = $this->postJson('/ca-masters/bulk-import', [
            'session_id' => $sessionId,
            'mapping' => $mapping,
        ]);
        $elapsed = microtime(true) - $started;

        $import->assertOk()
            ->assertJsonPath('data.uses_background', true)
            ->assertJsonPath('data.status', 'Processing')
            ->assertJsonPath('data.inserted_rows', 0)
            ->assertJsonPath('data.progress_percent', 0);

        $this->assertLessThan(
            2.0,
            $elapsed,
            'Import start should return immediately without blocking on row processing.',
        );

        $bulkActionId = $import->json('data.bulk_action_id');
        app(BulkCaMasterImportService::class)->processQueuedImport($bulkActionId);

        $this->getJson('/ca-masters/bulk-import/history/'.$bulkActionId.'/status')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'progress_percent',
                    'progress_message',
                    'processed_rows',
                    'total_rows',
                    'completed',
                ],
            ]);
    }

    public function test_admin_can_persistently_delete_import_history_without_removing_leads(): void
    {
        $this->actingAsAdmin();
        $ts = (string) microtime(true);

        $csv = "CA Name,Firm Name,Email\n";
        $csv .= '"Delete Hist CA '.$ts.'","Delete Hist Firm '.$ts.'","delete.hist.'.$ts.'@test.local"'."\n";
        $file = UploadedFile::fake()->createWithContent('delete-history.csv', $csv);

        $parse = $this->post('/ca-masters/bulk-import/parse', ['file' => $file], [
            'Accept' => 'application/json',
        ])->assertOk();

        $sessionId = $parse->json('data.session_id');
        $mapping = [
            'ca_name' => 'CA Name',
            'firm_name' => 'Firm Name',
            'email_id' => 'Email',
        ];

        $this->postJson('/ca-masters/bulk-import/validate', [
            'session_id' => $sessionId,
            'mapping' => $mapping,
        ])->assertOk();

        $import = $this->postJson('/ca-masters/bulk-import', [
            'session_id' => $sessionId,
            'mapping' => $mapping,
        ])->assertOk();

        $bulkActionId = (int) $import->json('data.bulk_action_id');
        $this->assertGreaterThan(0, $bulkActionId);

        $other = DB::table('bulk_actions')->insertGetId([
            'action_type' => 'ca_master_import',
            'file_name' => 'keep-other-'.$ts.'.csv',
            'total_records' => 1,
            'processed_records' => 1,
            'success_records' => 1,
            'duplicate_records' => 0,
            'skipped_records' => 0,
            'failed_records' => 0,
            'initiated_by' => null,
            'imported_by' => CrmTestAccounts::admin()->email,
            'status' => 'Completed',
            'started_at' => now(),
            'completed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], 'bulk_action_id');

        $this->assertDatabaseHas('bulk_actions', ['bulk_action_id' => $bulkActionId]);
        $this->assertDatabaseHas('ca_masters', [
            'email_id' => 'delete.hist.'.$ts.'@test.local',
            'bulk_action_id' => $bulkActionId,
        ]);

        $delete = $this->deleteJson('/ca-masters/bulk-import/history/'.$bulkActionId);
        $delete->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Import history record deleted');

        $this->assertDatabaseMissing('bulk_actions', ['bulk_action_id' => $bulkActionId]);
        $this->assertDatabaseMissing('bulk_action_logs', ['bulk_action_id' => $bulkActionId]);
        $this->assertDatabaseHas('bulk_actions', ['bulk_action_id' => $other]);

        $lead = CaMaster::query()->where('email_id', 'delete.hist.'.$ts.'@test.local')->first();
        $this->assertNotNull($lead);
        $this->assertNull($lead->bulk_action_id);

        $history = $this->getJson('/ca-masters/bulk-import/history')->assertOk();
        $ids = collect($history->json('data'))->pluck('bulk_action_id')->map(fn ($id) => (int) $id);
        $this->assertFalse($ids->contains($bulkActionId));
        $this->assertTrue($ids->contains((int) $other));

        $ops = $this->getJson('/ca-masters/bulk-operations/history')->assertOk();
        $opIds = collect($ops->json('data.items') ?? $ops->json('data') ?? [])
            ->pluck('bulk_action_id')
            ->map(fn ($id) => (int) $id);
        $this->assertFalse($opIds->contains($bulkActionId));

        $this->deleteJson('/ca-masters/bulk-import/history/'.$bulkActionId)
            ->assertNotFound();
    }

    public function test_employee_cannot_delete_import_history(): void
    {
        $admin = $this->actingAsAdmin();

        $bulkActionId = DB::table('bulk_actions')->insertGetId([
            'action_type' => 'ca_master_import',
            'file_name' => 'employee-deny-'.microtime(true).'.csv',
            'total_records' => 0,
            'processed_records' => 0,
            'success_records' => 0,
            'duplicate_records' => 0,
            'skipped_records' => 0,
            'failed_records' => 0,
            'initiated_by' => null,
            'imported_by' => $admin->email,
            'status' => 'Completed',
            'started_at' => now(),
            'completed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], 'bulk_action_id');

        $employee = CrmTestAccounts::employeeUser();
        $this->actingAs($employee);

        $this->deleteJson('/ca-masters/bulk-import/history/'.$bulkActionId)
            ->assertForbidden();

        $this->assertDatabaseHas('bulk_actions', ['bulk_action_id' => $bulkActionId]);
    }

    public function test_delete_missing_import_history_returns_404(): void
    {
        $this->actingAsAdmin();

        $this->deleteJson('/ca-masters/bulk-import/history/999999991')
            ->assertNotFound();
    }
}

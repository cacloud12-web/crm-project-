<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class BulkCaMasterImportTest extends TestCase
{
    use DatabaseTransactions;

    private function actingAsAdmin(): User
    {
        $admin = User::query()->where('email', 'admin@ca.local')->firstOrFail();
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
}

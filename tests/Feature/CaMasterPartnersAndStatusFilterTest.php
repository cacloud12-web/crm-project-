<?php

namespace Tests\Feature;

use App\Models\CaMaster;
use App\Models\CaMasterPartner;
use App\Services\Leads\CaMasterPartnerService;
use App\Services\Leads\CaMasterService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CrmTestAccounts;
use Tests\TestCase;

class CaMasterPartnersAndStatusFilterTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Auth::login(CrmTestAccounts::admin());
        if (! Schema::hasTable('ca_master_partners')) {
            $this->markTestSkipped('ca_master_partners table not migrated yet');
        }
    }

    public function test_status_new_filter_returns_only_new(): void
    {
        $ts = (string) microtime(true);
        $new = CaMaster::query()->create([
            'firm_name' => 'Status New Firm '.$ts,
            'ca_name' => 'Status New CA',
            'mobile_no' => '98'.substr(str_replace('.', '', $ts), -8),
            'status' => 'New',
        ]);
        CaMaster::query()->create([
            'firm_name' => 'Status Purchased Firm '.$ts,
            'ca_name' => 'Status Purchased CA',
            'mobile_no' => '97'.substr(str_replace('.', '', $ts), -8),
            'status' => 'Purchased',
        ]);

        $result = app(CaMasterService::class)->search([
            'status' => 'New',
            'search' => 'Status New Firm '.$ts,
            'per_page' => 25,
        ]);

        $this->assertSame(1, $result['pagination']['total']);
        $this->assertSame('New', $result['items'][0]->status);
        $this->assertSame($new->ca_id, $result['items'][0]->ca_id);
    }

    public function test_status_purchased_does_not_return_new(): void
    {
        $ts = (string) microtime(true);
        CaMaster::query()->create([
            'firm_name' => 'Buy Filter New '.$ts,
            'ca_name' => 'Buy New CA',
            'mobile_no' => '96'.substr(str_replace('.', '', $ts), -8),
            'status' => 'New',
        ]);
        $purchased = CaMaster::query()->create([
            'firm_name' => 'Buy Filter Purchased '.$ts,
            'ca_name' => 'Buy Purchased CA',
            'mobile_no' => '95'.substr(str_replace('.', '', $ts), -8),
            'status' => 'Purchased',
        ]);

        $result = app(CaMasterService::class)->search([
            'status' => 'Purchased',
            'search' => 'Buy Filter Purchased '.$ts,
            'per_page' => 25,
        ]);

        $this->assertGreaterThanOrEqual(1, $result['pagination']['total']);
        foreach ($result['items'] as $item) {
            $this->assertSame('Purchased', $item->status);
            $this->assertNotSame('New', $item->status);
        }
    }

    public function test_partners_sync_and_single_primary(): void
    {
        $ts = (string) microtime(true);
        $firm = CaMaster::query()->create([
            'firm_name' => 'Partner Firm '.$ts,
            'ca_name' => 'Primary Partner',
            'mobile_no' => '94'.substr(str_replace('.', '', $ts), -8),
            'status' => 'New',
        ]);

        app(CaMasterPartnerService::class)->syncFromMembers($firm, [
            ['ca_name' => 'Primary Partner', 'membership_no' => '111111', 'is_primary' => true],
            ['ca_name' => 'Second Partner', 'membership_no' => '222222'],
            ['ca_name' => 'Third Partner', 'membership_no' => '333333'],
        ]);

        $partners = CaMasterPartner::query()->where('ca_id', $firm->ca_id)->get();
        $this->assertCount(3, $partners);
        $this->assertSame(1, $partners->where('is_primary', true)->count());

        $result = app(CaMasterService::class)->search([
            'search' => 'Partner Firm '.$ts,
            'per_page' => 10,
        ]);
        $this->assertSame(1, $result['pagination']['total']);
        $lead = $result['items'][0];
        $lead->load('partners');
        $this->assertCount(3, $lead->partners);
    }

    public function test_partners_sync_from_linked_ocr_staging(): void
    {
        if (! Schema::hasTable('ocr_parsed_firms') || ! Schema::hasTable('ocr_parsed_members') || ! Schema::hasTable('ocr_documents')) {
            $this->markTestSkipped('OCR staging tables missing');
        }

        $ts = (string) microtime(true);
        $firm = CaMaster::query()->create([
            'firm_name' => 'OCR Partner Firm '.$ts,
            'ca_name' => 'Primary From Master',
            'mobile_no' => '93'.substr(str_replace('.', '', $ts), -8),
            'status' => 'New',
        ]);

        $document = \App\Models\OcrDocument::query()->create([
            'uploaded_by' => Auth::id(),
            'original_filename' => 'partners-test.pdf',
            'stored_filename' => 'partners-test.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'ocr-documents/partners-test.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'file_size' => 100,
            'checksum' => hash('sha256', 'partners-test-'.$ts),
            'status' => 'completed',
            'parse_status' => 'completed',
            'import_type' => \App\Models\OcrDocument::IMPORT_MASTER_CA,
            'extracted_text' => 'test',
        ]);

        $staging = \App\Models\OcrParsedFirm::query()->create([
            'ocr_document_id' => $document->id,
            'sequence_no' => 1,
            'firm_name' => $firm->firm_name,
            'raw_firm_name' => $firm->firm_name,
            'partner_count' => 2,
            'crm_ca_id' => $firm->ca_id,
            'matched_ca_id' => $firm->ca_id,
            'review_status' => 'approved',
            'source_data' => [
                'parsed' => [
                    'firm_name' => $firm->firm_name,
                    'ca_name' => 'Primary OCR CA',
                    'partners' => ['Partner Two', 'Partner Three'],
                ],
            ],
        ]);

        foreach ([
            ['Primary OCR CA', 1, 'Primary'],
            ['Partner Two', 0, 'Partner'],
            ['Partner Three', 0, 'Partner'],
        ] as $i => [$name, $primary, $role]) {
            \App\Models\OcrParsedMember::query()->create([
                'ocr_parsed_firm_id' => $staging->id,
                'sequence_no' => $i + 1,
                'ca_name' => $name,
                'raw_ca_name' => $name,
                'is_primary' => (bool) $primary,
                'role' => $role,
            ]);
        }

        $count = app(CaMasterPartnerService::class)->syncFromLinkedOcr($firm);
        $this->assertSame(3, $count);
        $this->assertSame(3, CaMasterPartner::query()->where('ca_id', $firm->ca_id)->count());
    }

    public function test_partner_update_does_not_change_parent_firm_fields(): void
    {
        $ts = (string) microtime(true);
        $firm = CaMaster::query()->create([
            'firm_name' => 'Edit Partner Parent '.$ts,
            'ca_name' => 'Primary CA',
            'mobile_no' => '93'.substr(str_replace('.', '', $ts), -8),
            'status' => 'New',
            'team_size' => 6,
            'website' => 'parent-firm.example',
            'existing_software' => 'Tally',
        ]);

        app(CaMasterPartnerService::class)->syncFromMembers($firm, [
            ['ca_name' => 'Primary CA', 'membership_no' => 'P111', 'is_primary' => true],
            [
                'ca_name' => 'PANKAJ KHURANA',
                'membership_no' => 'P222',
                'mobile' => '9811111111',
                'email' => 'old@example.com',
            ],
        ]);

        $partner = CaMasterPartner::query()
            ->where('ca_id', $firm->ca_id)
            ->where('ca_name', 'PANKAJ KHURANA')
            ->firstOrFail();

        $response = $this->patchJson('/ca-masters/'.$firm->ca_id.'/partners/'.$partner->id, [
            'ca_name' => 'PANKAJ KHURANA UPDATED',
            'mobile' => '9822222222',
            'alternate_mobile' => '9833333333',
            'email' => 'new@example.com',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.ca_name', 'PANKAJ KHURANA UPDATED')
            ->assertJsonPath('data.mobile', '9822222222')
            ->assertJsonPath('data.email', 'new@example.com');

        $firm->refresh();
        $this->assertSame('Edit Partner Parent '.$ts, $firm->firm_name);
        $this->assertSame('Primary CA', $firm->ca_name);
        $this->assertSame(6, (int) $firm->team_size);
        $this->assertSame('parent-firm.example', $firm->website);
        $this->assertSame('Tally', $firm->existing_software);

        $other = CaMasterPartner::query()
            ->where('ca_id', $firm->ca_id)
            ->where('ca_name', 'Primary CA')
            ->firstOrFail();
        $this->assertSame('Primary CA', $other->ca_name);

        $partner->refresh();
        $this->assertSame('PANKAJ KHURANA UPDATED', $partner->ca_name);
        $this->assertSame((int) $firm->ca_id, (int) $partner->ca_id);
    }

    public function test_listing_returns_ocr_city_when_master_city_id_missing(): void
    {
        if (! Schema::hasTable('ocr_parsed_firms') || ! Schema::hasTable('ocr_documents')) {
            $this->markTestSkipped('OCR staging tables missing');
        }

        $ts = (string) microtime(true);
        $firm = CaMaster::query()->create([
            'firm_name' => 'OCR City Display '.$ts,
            'ca_name' => 'OCR City CA',
            'mobile_no' => '92'.substr(str_replace('.', '', $ts), -8),
            'status' => 'New',
            'city_id' => null,
        ]);

        $document = \App\Models\OcrDocument::query()->create([
            'uploaded_by' => Auth::id(),
            'original_filename' => 'city-test.pdf',
            'stored_filename' => 'city-test.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'ocr-documents/city-test.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'file_size' => 100,
            'checksum' => hash('sha256', 'city-test-'.$ts),
            'status' => 'completed',
            'parse_status' => 'completed',
            'import_type' => \App\Models\OcrDocument::IMPORT_MASTER_CA,
            'extracted_text' => 'test',
        ]);

        \App\Models\OcrParsedFirm::query()->create([
            'ocr_document_id' => $document->id,
            'sequence_no' => 1,
            'firm_name' => $firm->firm_name,
            'raw_firm_name' => $firm->firm_name,
            'city' => 'ABU ROAD',
            'crm_ca_id' => $firm->ca_id,
            'matched_ca_id' => $firm->ca_id,
            'review_status' => 'approved',
        ]);

        $response = $this->getJson('/ca-masters?search='.urlencode('OCR City Display '.$ts));
        $response->assertOk();
        $items = $response->json('data.items') ?? [];
        $this->assertNotEmpty($items);
        $this->assertSame('ABU ROAD', $items[0]['city'] ?? null);

        $this->getJson('/ca-masters/'.$firm->ca_id)
            ->assertOk()
            ->assertJsonPath('data.city', 'ABU ROAD');
    }

    public function test_master_data_filter_config_includes_new(): void
    {
        $options = config('crm_statuses.master_data_filter');
        $this->assertIsArray($options);
        $this->assertContains('New', $options);
        $this->assertContains('Purchased', $options);
        $this->assertContains('Left in between', $options);
        $this->assertSame('New', $options[0]);
    }
}

<?php

namespace Tests\Feature;

use App\Models\CaMaster;
use App\Models\City;
use App\Models\State;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\CrmTestAccounts;
use Tests\TestCase;

class LeadFormCityStateAndFieldsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_cities_lookup_includes_state_id_for_auto_fill(): void
    {
        $user = CrmTestAccounts::superAdmin() ?? CrmTestAccounts::admin();
        $this->actingAs($user);

        $city = City::query()->whereNotNull('state_id')->first();
        $this->assertNotNull($city);

        $response = $this->getJson('/lookups/cities')->assertOk();
        $items = $response->json('data');
        $this->assertIsArray($items);
        $this->assertNotEmpty($items);

        $match = collect($items)->firstWhere('city_id', $city->city_id);
        $this->assertNotNull($match);
        $this->assertSame((int) $city->state_id, (int) $match['state_id']);
    }

    public function test_update_lead_without_gst_and_rating_preserves_existing_values(): void
    {
        $user = CrmTestAccounts::superAdmin() ?? CrmTestAccounts::admin();
        $this->actingAs($user);

        $state = State::query()->first();
        $city = City::query()->where('state_id', $state->state_id)->first();
        $this->assertNotNull($state);
        $this->assertNotNull($city);

        $lead = CaMaster::query()->create([
            'firm_name' => 'City State Form Firm '.uniqid(),
            'ca_name' => 'City State Form CA',
            'state_id' => $state->state_id,
            'city_id' => $city->city_id,
            'gst_no' => '27AAAAA0000A1Z5',
            'rating' => 4,
            'status' => 'New',
        ]);

        $this->putJson('/ca-masters/'.$lead->ca_id, [
            'firm_name' => $lead->firm_name,
            'ca_name' => 'Updated CA Name',
            'state_id' => $state->state_id,
            'city_id' => $city->city_id,
            'status' => 'Interested',
        ])->assertOk();

        $lead->refresh();
        $this->assertSame('Updated CA Name', $lead->ca_name);
        $this->assertSame('27AAAAA0000A1Z5', $lead->gst_no);
        $this->assertSame(4, (int) $lead->rating);
        $this->assertSame('Interested', $lead->status);
    }

    public function test_add_lead_form_markup_omits_gst_and_rating(): void
    {
        $user = CrmTestAccounts::superAdmin() ?? CrmTestAccounts::admin();
        $this->actingAs($user);

        $html = $this->get('/ca-masters')->assertOk()->getContent();
        $this->assertStringContainsString('id="form-add-lead"', $html);

        if (! preg_match('/<form[^>]*id="form-add-lead"[^>]*>(.*?)<\/form>/s', $html, $matches)) {
            $this->fail('form-add-lead not found');
        }

        $formHtml = $matches[1];
        $this->assertStringNotContainsString('name="gst_no"', $formHtml);
        $this->assertStringNotContainsString('name="rating"', $formHtml);
        $this->assertStringNotContainsString('GST No.', $formHtml);
        $this->assertStringNotContainsString('Rating (1–5)', $formHtml);
    }

    public function test_show_lead_returns_city_and_state_names_when_city_id_missing(): void
    {
        $user = CrmTestAccounts::superAdmin() ?? CrmTestAccounts::admin();
        $this->actingAs($user);

        if (! \Illuminate\Support\Facades\Schema::hasTable('ocr_parsed_firms')) {
            $this->markTestSkipped('ocr_parsed_firms table missing');
        }

        $state = State::query()->first();
        $this->assertNotNull($state);

        $lead = CaMaster::query()->create([
            'firm_name' => 'Modal City Prefill '.uniqid(),
            'ca_name' => 'Modal City CA',
            'mobile_no' => '9'.random_int(100000000, 999999999),
            'state_id' => null,
            'city_id' => null,
            'status' => 'New',
        ]);

        $document = \App\Models\OcrDocument::query()->create([
            'uploaded_by' => $user->id,
            'original_filename' => 'modal-city.pdf',
            'stored_filename' => 'modal-city.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'ocr-documents/modal-city.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'file_size' => 100,
            'checksum' => hash('sha256', 'modal-city-'.uniqid()),
            'status' => 'completed',
            'parse_status' => 'completed',
            'import_type' => \App\Models\OcrDocument::IMPORT_MASTER_CA,
            'extracted_text' => 'test',
        ]);

        \App\Models\OcrParsedFirm::query()->create([
            'ocr_document_id' => $document->id,
            'sequence_no' => 1,
            'firm_name' => $lead->firm_name,
            'raw_firm_name' => $lead->firm_name,
            'city' => 'ABHANPUR',
            'state' => $state->state_name,
            'crm_ca_id' => $lead->ca_id,
            'matched_ca_id' => $lead->ca_id,
            'review_status' => 'approved',
        ]);

        $response = $this->getJson('/ca-masters/'.$lead->ca_id)->assertOk();
        $this->assertNull($response->json('data.city_id'));
        $this->assertSame('ABHANPUR', $response->json('data.city'));
        $this->assertSame('ABHANPUR', $response->json('data.city_name'));
        $this->assertSame($state->state_name, $response->json('data.state'));
        $this->assertSame($state->state_name, $response->json('data.state_name'));
    }

    public function test_show_lead_returns_master_city_and_state_when_ids_present(): void
    {
        $user = CrmTestAccounts::superAdmin() ?? CrmTestAccounts::admin();
        $this->actingAs($user);

        $state = State::query()->first();
        $city = City::query()->where('state_id', $state->state_id)->first();
        $this->assertNotNull($state);
        $this->assertNotNull($city);

        $lead = CaMaster::query()->create([
            'firm_name' => 'Master City Firm '.uniqid(),
            'ca_name' => 'Master City CA',
            'mobile_no' => '9'.random_int(100000000, 999999999),
            'state_id' => $state->state_id,
            'city_id' => $city->city_id,
            'status' => 'New',
        ]);

        $response = $this->getJson('/ca-masters/'.$lead->ca_id)->assertOk();
        $this->assertSame((int) $city->city_id, (int) $response->json('data.city_id'));
        $this->assertSame((int) $state->state_id, (int) $response->json('data.state_id'));
        $this->assertSame($city->city_name, $response->json('data.city'));
        $this->assertSame($state->state_name, $response->json('data.state'));
    }
}

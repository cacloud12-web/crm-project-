<?php

namespace Tests\Feature;

use App\Models\CaMaster;
use App\Models\City;
use App\Services\Mapping\MasterDataMappingService;
use App\Services\Mapping\MasterDataMatchingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CrmTestAccounts;
use Tests\TestCase;

class BulkImportCityPersistenceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_normalize_payload_promotes_city_name_stored_in_city_id_key(): void
    {
        $payload = app(MasterDataMatchingService::class)->normalizePayload([
            'firm_name' => 'City Map Firm',
            'ca_name' => 'City Map CA',
            'city_id' => 'Pune BulkCity',
            'state_id' => 'Maharashtra',
        ]);

        $this->assertSame('Pune BulkCity', $payload['city']);
        $this->assertNull($payload['city_id']);
        $this->assertSame('Maharashtra', $payload['state']);
        $this->assertNull($payload['state_id']);
    }

    public function test_mapping_engine_persists_city_from_csv_city_id_name(): void
    {
        $this->actingAs(CrmTestAccounts::admin());

        $suffix = str_replace('.', '', (string) microtime(true));
        $cityName = 'BulkCity'.$suffix;

        $stats = app(MasterDataMappingService::class)->processBatch('csv', 'city-persist-'.$suffix, [
            [
                'firm_name' => 'Bulk City Firm '.$suffix,
                'ca_name' => 'Bulk City CA '.$suffix,
                'email' => 'bulk.city.'.$suffix.'@test.local',
                // Same shape the bulk importer sends when CSV City maps to city_id.
                'city_id' => $cityName,
            ],
        ], CrmTestAccounts::admin()->id);

        $this->assertGreaterThan(0, (int) ($stats['auto_created'] ?? 0) + (int) ($stats['auto_updated'] ?? 0));

        $lead = CaMaster::query()->where('email_id', 'bulk.city.'.$suffix.'@test.local')->first();
        $this->assertNotNull($lead);
        $this->assertNotNull($lead->city_id, 'city_id must be saved from CSV city name');

        $city = City::query()->where('city_id', $lead->city_id)->first();
        $this->assertNotNull($city);
        $this->assertSame(mb_strtolower($cityName), mb_strtolower((string) $city->city_name));

        if (Schema::hasColumn('ca_masters', 'ocr_city_text')) {
            $this->assertSame(mb_strtolower($cityName), mb_strtolower((string) $lead->ocr_city_text));
        }
    }

    public function test_bulk_import_http_flow_saves_mapped_city_column(): void
    {
        $this->actingAs(CrmTestAccounts::admin());
        $ts = (string) microtime(true);
        $cityName = 'HttpCity'.$ts;

        $csv = "CA Name,Firm Name,Email,City\n";
        $csv .= '"City CA '.$ts.'","City Firm '.$ts.'","city.http.'.$ts.'@test.local","'.$cityName.'"'."\n";

        $parse = $this->post('/ca-masters/bulk-import/parse', [
            'file' => UploadedFile::fake()->createWithContent('city-http.csv', $csv),
        ], ['Accept' => 'application/json'])->assertOk();

        $sessionId = $parse->json('data.session_id');
        $mapping = [
            'ca_name' => 'CA Name',
            'firm_name' => 'Firm Name',
            'email_id' => 'Email',
            'city_id' => 'City',
        ];

        $this->postJson('/ca-masters/bulk-import/validate', [
            'session_id' => $sessionId,
            'mapping' => $mapping,
        ])->assertOk()->assertJsonPath('data.valid_rows', 1);

        $this->postJson('/ca-masters/bulk-import', [
            'session_id' => $sessionId,
            'mapping' => $mapping,
        ])->assertOk()->assertJsonPath('data.inserted_rows', 1);

        $lead = CaMaster::query()->where('email_id', 'city.http.'.$ts.'@test.local')->first();
        $this->assertNotNull($lead);
        $this->assertNotNull($lead->city_id);
        $this->assertSame(
            mb_strtolower($cityName),
            mb_strtolower((string) City::query()->where('city_id', $lead->city_id)->value('city_name'))
        );
    }
}

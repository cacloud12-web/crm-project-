<?php

namespace Tests\Feature;

use App\Models\CaMaster;
use App\Models\City;
use App\Models\SalesImportRow;
use App\Models\State;
use App\Services\SalesMapping\SalesEnrichmentWriter;
use App\Support\Ocr\CaMasterCityQuality;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SalesCityEnrichmentTest extends TestCase
{
    use DatabaseTransactions;

    public function test_sales_enrichment_fills_empty_master_city_from_csv(): void
    {
        if (! Schema::hasTable('sales_import_rows') || ! Schema::hasTable('ca_masters')) {
            $this->markTestSkipped('sales/master tables missing');
        }

        $suffix = uniqid();
        $state = State::query()->first() ?: State::query()->create([
            'state_name' => 'Rajasthan '.$suffix,
            'is_active' => true,
        ]);
        $city = City::query()->firstOrCreate(
            ['state_id' => $state->state_id, 'city_name' => 'JaipurSales'.$suffix],
            ['state_id' => $state->state_id, 'city_name' => 'JaipurSales'.$suffix, 'is_active' => true],
        );

        $master = CaMaster::query()->create([
            'ca_name' => 'City Enrich CA '.$suffix,
            'firm_name' => 'City Enrich Firm '.$suffix,
            'status' => 'New',
            'rating' => 1,
            'city_id' => null,
            'state_id' => null,
        ]);

        $row = SalesImportRow::query()->create([
            'source_file_name' => 'CA CloudDesk Leads - CITYTEST.csv',
            'source_row_number' => 2,
            'ca_name' => $master->ca_name,
            'firm_name' => $master->firm_name,
            'city_name' => $city->city_name,
            'mapping_status' => 'matched',
            'matched_ca_id' => $master->ca_id,
            'mapped_at' => now(),
        ]);

        app(SalesEnrichmentWriter::class)->applyForRow($row->fresh());

        $master->refresh();
        $this->assertSame((int) $city->city_id, (int) $master->city_id);
        if (Schema::hasColumn('ca_masters', 'ocr_city_text')) {
            $this->assertSame($city->city_name, $master->ocr_city_text);
        }
    }

    public function test_sales_enrichment_replaces_placeholder_city_not_real_city(): void
    {
        if (! Schema::hasTable('sales_import_rows') || ! Schema::hasTable('ca_masters')) {
            $this->markTestSkipped('sales/master tables missing');
        }

        $suffix = uniqid();
        $state = State::query()->first() ?: State::query()->create([
            'state_name' => 'Delhi '.$suffix,
            'is_active' => true,
        ]);
        $placeholder = City::query()->firstOrCreate(
            ['state_id' => $state->state_id, 'city_name' => CaMasterCityQuality::PLACEHOLDER_CITY_NAME],
            ['state_id' => $state->state_id, 'city_name' => CaMasterCityQuality::PLACEHOLDER_CITY_NAME, 'is_active' => true],
        );
        $real = City::query()->firstOrCreate(
            ['state_id' => $state->state_id, 'city_name' => 'DelhiSales'.$suffix],
            ['state_id' => $state->state_id, 'city_name' => 'DelhiSales'.$suffix, 'is_active' => true],
        );

        $master = CaMaster::query()->create([
            'ca_name' => 'Placeholder CA '.$suffix,
            'firm_name' => 'Placeholder Firm '.$suffix,
            'status' => 'New',
            'rating' => 1,
            'city_id' => $placeholder->city_id,
            'state_id' => $state->state_id,
            'data_quality_issue' => CaMasterCityQuality::ISSUE_MISSING_CITY,
        ]);

        $keepCity = City::query()->firstOrCreate(
            ['state_id' => $state->state_id, 'city_name' => 'KeepCity'.$suffix],
            ['state_id' => $state->state_id, 'city_name' => 'KeepCity'.$suffix, 'is_active' => true],
        );
        $masterWithReal = CaMaster::query()->create([
            'ca_name' => 'Keep CA '.$suffix,
            'firm_name' => 'Keep Firm '.$suffix,
            'status' => 'New',
            'rating' => 1,
            'city_id' => $keepCity->city_id,
            'state_id' => $state->state_id,
        ]);

        $row = SalesImportRow::query()->create([
            'source_file_name' => 'CA CloudDesk Leads - PLACEHOLDER.csv',
            'source_row_number' => 2,
            'city_name' => $real->city_name,
            'mapping_status' => 'matched',
            'matched_ca_id' => $master->ca_id,
            'mapped_at' => now(),
        ]);
        app(SalesEnrichmentWriter::class)->applyForRow($row->fresh());
        $master->refresh();
        $this->assertSame((int) $real->city_id, (int) $master->city_id);

        $rowKeep = SalesImportRow::query()->create([
            'source_file_name' => 'CA CloudDesk Leads - KEEP.csv',
            'source_row_number' => 3,
            'city_name' => $real->city_name,
            'mapping_status' => 'matched',
            'matched_ca_id' => $masterWithReal->ca_id,
            'mapped_at' => now(),
        ]);
        app(SalesEnrichmentWriter::class)->applyForRow($rowKeep->fresh());
        $masterWithReal->refresh();
        $this->assertSame((int) $keepCity->city_id, (int) $masterWithReal->city_id);
    }

    public function test_master_resource_city_falls_back_to_ocr_city_text(): void
    {
        if (! Schema::hasColumn('ca_masters', 'ocr_city_text')) {
            $this->markTestSkipped('ocr_city_text missing');
        }

        $suffix = uniqid();
        $master = CaMaster::query()->create([
            'ca_name' => 'Fallback CA '.$suffix,
            'firm_name' => 'Fallback Firm '.$suffix,
            'status' => 'New',
            'rating' => 1,
            'city_id' => null,
            'ocr_city_text' => 'Indore Text '.$suffix,
        ]);

        $payload = (new \App\Http\Resources\CaMasterResource($master->fresh()))->toArray(request());
        $this->assertSame('Indore Text '.$suffix, $payload['city']);
    }
}

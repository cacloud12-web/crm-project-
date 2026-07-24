<?php

namespace Tests\Feature;

use App\Models\CaMaster;
use App\Models\City;
use App\Models\OcrDocument;
use App\Models\OcrParsedFirm;
use App\Models\OcrParsedMember;
use App\Models\User;
use App\Services\Ocr\OcrRepairRequiredMasterFieldsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CrmTestAccounts;
use Tests\TestCase;

class OcrRepairRequiredMasterFieldsTest extends TestCase
{
    use DatabaseTransactions;

    private OcrRepairRequiredMasterFieldsService $service;

    private string $recoveryTable = 'ca_masters_recovery_20260723';

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(OcrRepairRequiredMasterFieldsService::class);
        config(['ocr_locality_aliases.recovery_table' => $this->recoveryTable]);
        config(['ocr_locality_aliases.aliases' => []]);
        $this->ensureRecoveryTable();
        DB::table($this->recoveryTable)->delete();
    }

    public function test_does_not_overwrite_existing_non_empty_values(): void
    {
        $this->skipUnlessReady();
        $city = $this->ensureCity('Jaipur');
        $firm = $this->seedFirm(['firm_name' => 'OCR FIRM X', 'city' => 'Surat']);
        OcrParsedMember::query()->create([
            'ocr_parsed_firm_id' => $firm->id,
            'ca_name' => 'OCR Person',
            'sequence_no' => 1,
        ]);
        $master = $this->seedMaster([
            'firm_name' => 'Keep Firm',
            'ca_name' => 'Keep CA',
            'city_id' => $city->city_id,
            'source_ocr_row_id' => $firm->id,
            'verification_status' => 'needs_verification',
            'is_verified' => false,
        ]);
        $this->addRecovery($master->ca_id);

        $report = $this->service->run(['dry_run' => true, 'apply' => false]);
        $row = collect($report['rows'])->firstWhere('ca_id', $master->ca_id);
        $this->assertNotNull($row);
        $this->assertNull($row['proposed_ca_name']);
        $this->assertNull($row['proposed_city_id']);
        $this->assertSame(OcrRepairRequiredMasterFieldsService::DECISION_NO_CHANGE, $row['decision']);

        $before = $master->fresh()->only(['firm_name', 'ca_name', 'city_id', 'verification_status', 'is_verified']);
        $this->service->run(['dry_run' => false, 'apply' => true]);
        $master->refresh();
        $this->assertSame($before, $master->only(['firm_name', 'ca_name', 'city_id', 'verification_status', 'is_verified']));
    }

    public function test_multiple_ocr_members_are_not_auto_selected(): void
    {
        $this->skipUnlessReady();
        $firm = $this->seedFirm(['firm_name' => 'MULTI MEMBER FIRM', 'city' => 'Jaipur']);
        OcrParsedMember::query()->create([
            'ocr_parsed_firm_id' => $firm->id,
            'ca_name' => 'Alpha Partner',
            'sequence_no' => 1,
        ]);
        OcrParsedMember::query()->create([
            'ocr_parsed_firm_id' => $firm->id,
            'ca_name' => 'Beta Partner',
            'sequence_no' => 2,
        ]);
        $master = $this->seedMaster([
            'firm_name' => 'MULTI MEMBER FIRM',
            'ca_name' => null,
            'city_id' => null,
            'source_ocr_row_id' => $firm->id,
        ]);
        $this->addRecovery($master->ca_id);

        $report = $this->service->run(['dry_run' => true]);
        $this->assertSame(0, $report['ca_names_recoverable']);
        $this->assertGreaterThanOrEqual(1, $report['ambiguous_ca_candidates']);
        $master->refresh();
        $this->assertTrue($master->ca_name === null || trim((string) $master->ca_name) === '');
    }

    public function test_unknown_locality_is_not_guessed(): void
    {
        $this->skipUnlessReady();
        $firm = $this->seedFirm([
            'firm_name' => 'LOCALITY FIRM',
            'city' => 'Some Unknown Locality XYZ',
        ]);
        OcrParsedMember::query()->create([
            'ocr_parsed_firm_id' => $firm->id,
            'ca_name' => 'Only Partner',
            'sequence_no' => 1,
        ]);
        $master = $this->seedMaster([
            'firm_name' => 'LOCALITY FIRM',
            'ca_name' => null,
            'city_id' => null,
            'source_ocr_row_id' => $firm->id,
        ]);
        $this->addRecovery($master->ca_id);

        $report = $this->service->run(['dry_run' => true]);
        $row = collect($report['rows'])->firstWhere('ca_id', $master->ca_id);
        $this->assertNull($row['proposed_city_id']);
        $this->assertSame(1, $report['ca_names_recoverable']);
        $this->assertSame(0, $report['cities_recoverable']);
        $this->assertStringContainsString('city_unresolved_locality', (string) $row['reason']);
    }

    public function test_exact_unique_city_match_is_accepted(): void
    {
        $this->skipUnlessReady();
        $city = $this->ensureCity('UniqueRepairCity'.uniqid());
        $firm = $this->seedFirm([
            'firm_name' => 'CITY MATCH FIRM',
            'city' => $city->city_name,
        ]);
        OcrParsedMember::query()->create([
            'ocr_parsed_firm_id' => $firm->id,
            'ca_name' => 'Solo CA',
            'sequence_no' => 1,
        ]);
        $master = $this->seedMaster([
            'firm_name' => 'CITY MATCH FIRM',
            'ca_name' => null,
            'city_id' => null,
            'source_ocr_row_id' => $firm->id,
            'verification_status' => 'needs_verification',
            'is_verified' => false,
        ]);
        $this->addRecovery($master->ca_id);

        $dry = $this->service->run(['dry_run' => true]);
        $this->assertSame(1, $dry['cities_recoverable']);
        $this->assertSame(1, $dry['ca_names_recoverable']);
        $this->assertSame(1, $dry['records_becoming_complete']);

        $this->service->run(['dry_run' => false, 'apply' => true]);
        $master->refresh();
        $this->assertSame('Solo CA', $master->ca_name);
        $this->assertSame((int) $city->city_id, (int) $master->city_id);
        $this->assertSame('needs_verification', $master->verification_status);
        $this->assertFalse((bool) $master->is_verified);
    }

    public function test_only_recovery_table_records_are_processed(): void
    {
        $this->skipUnlessReady();
        $city = $this->ensureCity('ScopeCity'.uniqid());
        $firmIn = $this->seedFirm(['firm_name' => 'IN SCOPE', 'city' => $city->city_name]);
        $firmOut = $this->seedFirm(['firm_name' => 'OUT SCOPE', 'city' => $city->city_name]);
        OcrParsedMember::query()->create([
            'ocr_parsed_firm_id' => $firmIn->id,
            'ca_name' => 'In CA',
            'sequence_no' => 1,
        ]);
        OcrParsedMember::query()->create([
            'ocr_parsed_firm_id' => $firmOut->id,
            'ca_name' => 'Out CA',
            'sequence_no' => 1,
        ]);

        $in = $this->seedMaster([
            'firm_name' => 'IN SCOPE',
            'ca_name' => null,
            'city_id' => null,
            'source_ocr_row_id' => $firmIn->id,
        ]);
        $out = $this->seedMaster([
            'firm_name' => 'OUT SCOPE',
            'ca_name' => null,
            'city_id' => null,
            'source_ocr_row_id' => $firmOut->id,
        ]);
        $this->addRecovery($in->ca_id);
        // intentionally do not add $out

        $report = $this->service->run(['dry_run' => false, 'apply' => true]);
        $ids = collect($report['rows'])->pluck('ca_id')->all();
        $this->assertContains($in->ca_id, $ids);
        $this->assertNotContains($out->ca_id, $ids);

        $in->refresh();
        $out->refresh();
        $this->assertSame('In CA', $in->ca_name);
        $this->assertTrue($out->ca_name === null || trim((string) $out->ca_name) === '');
    }

    public function test_locality_alias_requires_unique_city_id(): void
    {
        $this->skipUnlessReady();
        $city = $this->ensureCity('AliasTargetCity'.uniqid());
        config(['ocr_locality_aliases.aliases' => [
            'miraroad testloc' => $city->city_name,
        ]]);
        // Force service to reload aliases / city index.
        $this->service = app(OcrRepairRequiredMasterFieldsService::class);

        $firm = $this->seedFirm([
            'firm_name' => 'ALIAS FIRM',
            'city' => 'Miraroad Testloc',
        ]);
        OcrParsedMember::query()->create([
            'ocr_parsed_firm_id' => $firm->id,
            'ca_name' => 'Alias CA',
            'sequence_no' => 1,
        ]);
        $master = $this->seedMaster([
            'firm_name' => 'ALIAS FIRM',
            'ca_name' => null,
            'city_id' => null,
            'source_ocr_row_id' => $firm->id,
        ]);
        $this->addRecovery($master->ca_id);

        $report = $this->service->run(['dry_run' => true]);
        $row = collect($report['rows'])->firstWhere('ca_id', $master->ca_id);
        $this->assertSame((int) $city->city_id, (int) $row['proposed_city_id']);
        $this->assertSame(1, $report['cities_recoverable']);
    }

    private function skipUnlessReady(): void
    {
        if (! Schema::hasTable('ca_masters')
            || ! Schema::hasTable('ocr_parsed_firms')
            || ! Schema::hasTable('ocr_parsed_members')
            || ! Schema::hasTable('cities')) {
            $this->markTestSkipped('OCR/Master schema incomplete');
        }
    }

    private function ensureRecoveryTable(): void
    {
        if (Schema::hasTable($this->recoveryTable)) {
            return;
        }
        Schema::create($this->recoveryTable, function ($table) {
            $table->unsignedBigInteger('ca_id')->primary();
            $table->timestamps();
        });
    }

    private function addRecovery(int $caId): void
    {
        DB::table($this->recoveryTable)->insertOrIgnore([
            'ca_id' => $caId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function ensureCity(string $name): City
    {
        $existing = City::query()->whereRaw('LOWER(city_name) = ?', [mb_strtolower($name)])->first();
        if ($existing) {
            return $existing;
        }

        $stateId = \App\Models\State::query()->value('state_id');
        if (! $stateId) {
            $state = \App\Models\State::query()->create([
                'state_name' => 'Repair Test State '.uniqid(),
                'is_active' => true,
            ]);
            $stateId = $state->state_id;
        }

        return City::query()->create([
            'city_name' => $name,
            'state_id' => $stateId,
            'is_active' => true,
        ]);
    }

    /** @param  array<string, mixed>  $attrs */
    private function seedFirm(array $attrs): OcrParsedFirm
    {
        $admin = User::query()->first() ?: CrmTestAccounts::admin();
        $document = OcrDocument::query()->create([
            'ca_id' => null,
            'uploaded_by' => $admin->id,
            'original_filename' => 'repair-test.pdf',
            'stored_filename' => 'repair-test.pdf',
            'storage_disk' => 'local',
            'storage_path' => 'ocr-documents/test/repair-test.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 100,
            'status' => OcrDocument::STATUS_COMPLETED,
            'import_type' => OcrDocument::IMPORT_MASTER_CA,
            'parse_status' => 'completed',
            'processing_progress' => 'Completed',
            'checksum' => hash('sha256', uniqid('repair', true)),
            'processed_at' => now(),
        ]);

        return OcrParsedFirm::query()->create(array_merge([
            'ocr_document_id' => $document->id,
            'sequence_no' => random_int(1, 999999),
            'firm_name' => 'DEFAULT FIRM',
            'review_status' => OcrParsedFirm::REVIEW_PENDING,
            'match_status' => 'needs_review',
            'overall_confidence' => 0.8,
            'crm_ca_id' => null,
            'is_noise' => false,
        ], $attrs));
    }

    /** @param  array<string, mixed>  $attrs */
    private function seedMaster(array $attrs): CaMaster
    {
        if (array_key_exists('ca_name', $attrs) && $attrs['ca_name'] === null) {
            $attrs['ca_name'] = '';
        }
        if (array_key_exists('firm_name', $attrs) && $attrs['firm_name'] === null) {
            $attrs['firm_name'] = '';
        }

        $payload = array_merge([
            'firm_name' => 'Master Firm',
            'ca_name' => '',
            'status' => 'New',
            'rating' => 1,
        ], $attrs);

        return CaMaster::query()->create($payload);
    }
}

<?php

namespace App\Services\Ocr;

use App\Models\CaMaster;
use App\Models\City;
use App\Models\OcrParsedFirm;
use App\Models\OcrParsedMember;
use App\Services\Mapping\DataNormalizationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Repair blank firm_name / ca_name / city_id on recovery-table Masters only.
 * Never overwrites non-empty values; never changes verification_status.
 */
class OcrRepairRequiredMasterFieldsService
{
    public const DECISION_COMPLETE = 'becomes_complete';

    public const DECISION_PARTIAL = 'partial_repair';

    public const DECISION_SKIP = 'skip';

    public const DECISION_NO_CHANGE = 'no_change';

    /** @var array<string, int|null>|null */
    private ?array $cityNameIndex = null;

    /** @var array<int, true>|null */
    private ?array $validCityIds = null;

    /** @var array<string, int|string>|null */
    private ?array $localityAliases = null;

    public function __construct(
        private readonly DataNormalizationService $normalizer,
    ) {}

    public function recoveryTable(): string
    {
        return (string) config('ocr_locality_aliases.recovery_table', 'ca_masters_recovery_20260723');
    }

    /**
     * @param  array{
     *   dry_run?: bool,
     *   apply?: bool,
     *   limit?: int,
     *   ca_id?: int|null,
     *   chunk?: int,
     *   export?: string|null,
     *   progress?: callable(int $scanned, int $total): void|null
     * }  $options
     * @return array<string, mixed>
     */
    public function run(array $options = []): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? true);
        $apply = (bool) ($options['apply'] ?? false) && ! $dryRun;
        $limit = max(0, (int) ($options['limit'] ?? 0));
        $caIdFilter = isset($options['ca_id']) && $options['ca_id'] !== null && $options['ca_id'] !== ''
            ? (int) $options['ca_id']
            : null;
        $chunk = max(50, min(2000, (int) ($options['chunk'] ?? 500)));
        $export = isset($options['export']) && is_string($options['export']) && trim($options['export']) !== ''
            ? trim($options['export'])
            : null;
        $progress = $options['progress'] ?? null;

        $table = $this->recoveryTable();
        if (! Schema::hasTable($table)) {
            throw new RuntimeException("Recovery table missing: {$table}");
        }
        if (! Schema::hasTable('ca_masters') || ! Schema::hasTable('ocr_parsed_firms')) {
            throw new RuntimeException('Required tables ca_masters / ocr_parsed_firms are missing.');
        }

        $counts = $this->emptyCounts();
        $rows = [];

        $recoveryQuery = DB::table($table)
            ->when($caIdFilter !== null, fn ($q) => $q->where('ca_id', $caIdFilter))
            ->orderBy('ca_id');

        $total = (clone $recoveryQuery)->count();
        $counts['total_eligible'] = $total;
        $scanned = 0;
        $pendingUpdates = [];
        $stop = false;

        $recoveryQuery->chunkById($chunk, function ($recoveryRows) use (
            &$counts,
            &$rows,
            &$scanned,
            &$pendingUpdates,
            &$stop,
            $limit,
            $apply,
            $chunk,
            $progress,
            $total,
        ) {
            if ($stop) {
                return false;
            }

            $ids = $recoveryRows->pluck('ca_id')->map(fn ($id) => (int) $id)->all();
            $masters = CaMaster::query()
                ->whereIn('ca_id', $ids)
                ->get([
                    'ca_id',
                    'firm_name',
                    'ca_name',
                    'city_id',
                    'source_ocr_row_id',
                    'verification_status',
                    'is_verified',
                ])
                ->keyBy('ca_id');

            foreach ($ids as $id) {
                if ($limit > 0 && $scanned >= $limit) {
                    $stop = true;

                    return false;
                }

                $master = $masters->get($id);
                if (! $master) {
                    continue;
                }

                $scanned++;
                $counts['total_scanned'] = $scanned;

                $plan = $this->planForMaster($master);
                $rows[] = $plan['report'];
                $this->tally($counts, $plan);

                if ($apply && $plan['updates'] !== []) {
                    $pendingUpdates[] = [
                        'ca_id' => (int) $master->ca_id,
                        'updates' => $plan['updates'],
                        'before' => [
                            'firm_name' => $master->firm_name,
                            'ca_name' => $master->ca_name,
                            'city_id' => $master->city_id,
                            'verification_status' => $master->verification_status ?? null,
                            'is_verified' => $master->is_verified ?? null,
                        ],
                    ];
                    if (count($pendingUpdates) >= $chunk) {
                        $this->applyChunk($pendingUpdates);
                        $counts['applied'] += count($pendingUpdates);
                        $pendingUpdates = [];
                    }
                }

                if (is_callable($progress) && ($scanned % $chunk === 0 || ($limit > 0 && $scanned >= $limit))) {
                    $progress($scanned, $total);
                }
            }

            return ! $stop;
        }, 'ca_id');

        if ($apply && $pendingUpdates !== []) {
            $this->applyChunk($pendingUpdates);
            $counts['applied'] += count($pendingUpdates);
        }

        $exportPath = null;
        if ($export !== null) {
            $exportPath = $this->writeCsv($export, $rows);
            $counts['export_path'] = $exportPath;
        }

        $counts['dry_run'] = ! $apply;
        $counts['apply'] = $apply;
        $counts['rows'] = $rows;

        return $counts;
    }

    /**
     * @param  object{
     *   ca_id: mixed,
     *   firm_name: mixed,
     *   ca_name: mixed,
     *   city_id: mixed,
     *   source_ocr_row_id: mixed,
     *   verification_status?: mixed,
     *   is_verified?: mixed
     * }  $master
     * @return array{
     *   updates: array<string, mixed>,
     *   report: array<string, mixed>,
     *   firm_recoverable: bool,
     *   ca_recoverable: bool,
     *   city_recoverable: bool,
     *   becomes_complete: bool,
     *   unresolved_missing_ca: bool,
     *   unresolved_missing_city: bool,
     *   ambiguous_ca: bool,
     *   ambiguous_city: bool
     * }
     */
    public function planForMaster(object $master): array
    {
        $caId = (int) $master->ca_id;
        $currentFirm = $this->blankToNull($master->firm_name);
        $currentCa = $this->blankToNull($master->ca_name);
        $currentCityId = $this->validCityId($master->city_id);
        $ocrRowId = $master->source_ocr_row_id !== null ? (int) $master->source_ocr_row_id : null;

        $proposedFirm = null;
        $proposedCa = null;
        $proposedCityId = null;
        $ocrCityText = null;
        $reasons = [];
        $updates = [];

        $firmRecoverable = false;
        $caRecoverable = false;
        $cityRecoverable = false;
        $ambiguousCa = false;
        $ambiguousCity = false;

        $firm = null;
        if ($ocrRowId && Schema::hasTable('ocr_parsed_firms')) {
            $firm = OcrParsedFirm::query()->find($ocrRowId);
        }

        if (! $firm) {
            $reasons[] = 'no_ocr_firm_link';
        } else {
            $ocrCityText = $this->blankToNull($firm->city);

            // Firm name: fill only when Master firm_name is blank.
            if ($currentFirm === null) {
                $ocrFirm = $this->blankToNull($firm->firm_name ?: $firm->raw_firm_name);
                if ($ocrFirm !== null) {
                    $proposedFirm = $ocrFirm;
                    $firmRecoverable = true;
                    $updates['firm_name'] = $ocrFirm;
                    if (Schema::hasColumn('ca_masters', 'normalized_firm_name')) {
                        $updates['normalized_firm_name'] = $this->normalizer->firmName($ocrFirm);
                    }
                    $reasons[] = 'firm_filled_from_ocr';
                } else {
                    $reasons[] = 'firm_blank_ocr_also_blank';
                }
            } else {
                $reasons[] = 'firm_kept_existing';
            }

            // CA name: exactly one distinct normalized member ca_name.
            if ($currentCa === null) {
                $caResult = $this->resolveUniqueMemberCaName((int) $firm->id);
                if ($caResult['status'] === 'unique') {
                    $proposedCa = $caResult['ca_name'];
                    $caRecoverable = true;
                    $updates['ca_name'] = $proposedCa;
                    if (Schema::hasColumn('ca_masters', 'normalized_ca_name')) {
                        $updates['normalized_ca_name'] = $this->normalizer->caName($proposedCa);
                    }
                    $reasons[] = 'ca_unique_member';
                } elseif ($caResult['status'] === 'ambiguous') {
                    $ambiguousCa = true;
                    $reasons[] = 'ca_ambiguous_members:'.implode('|', $caResult['candidates']);
                } else {
                    $reasons[] = 'ca_no_member_candidates';
                }
            } else {
                $reasons[] = 'ca_kept_existing';
            }

            // City: exact unique city_name match, else reviewed locality alias → unique city_id.
            if ($currentCityId === null) {
                $cityResult = $this->resolveCity($ocrCityText);
                if ($cityResult['status'] === 'unique') {
                    $proposedCityId = $cityResult['city_id'];
                    $cityRecoverable = true;
                    $updates['city_id'] = $proposedCityId;
                    $reasons[] = 'city_'.$cityResult['via'];
                } elseif ($cityResult['status'] === 'ambiguous') {
                    $ambiguousCity = true;
                    $reasons[] = 'city_ambiguous:'.($cityResult['detail'] ?? 'multiple');
                } elseif ($cityResult['status'] === 'unknown') {
                    $reasons[] = 'city_unresolved_locality';
                } else {
                    $reasons[] = 'city_blank_ocr';
                }
            } else {
                $reasons[] = 'city_kept_existing';
            }
        }

        $finalFirm = $updates['firm_name'] ?? $currentFirm;
        $finalCa = $updates['ca_name'] ?? $currentCa;
        $finalCity = array_key_exists('city_id', $updates) ? (int) $updates['city_id'] : $currentCityId;

        $becomesComplete = $finalFirm !== null && $finalCa !== null && $finalCity !== null
            && ($currentFirm === null || $currentCa === null || $currentCityId === null)
            && $updates !== [];

        $alreadyComplete = $currentFirm !== null && $currentCa !== null && $currentCityId !== null;
        $unresolvedMissingCa = ($updates['ca_name'] ?? $currentCa) === null;
        $unresolvedMissingCity = ! array_key_exists('city_id', $updates) && $currentCityId === null
            && ($ocrCityText !== null || $firm !== null);

        if ($alreadyComplete) {
            $decision = self::DECISION_NO_CHANGE;
            if (! in_array('already_complete', $reasons, true)) {
                $reasons[] = 'already_complete';
            }
        } elseif ($updates === []) {
            $decision = self::DECISION_SKIP;
        } elseif ($becomesComplete) {
            $decision = self::DECISION_COMPLETE;
        } else {
            $decision = self::DECISION_PARTIAL;
        }

        return [
            'updates' => $updates,
            'firm_recoverable' => $firmRecoverable,
            'ca_recoverable' => $caRecoverable,
            'city_recoverable' => $cityRecoverable,
            'becomes_complete' => $becomesComplete,
            'unresolved_missing_ca' => $unresolvedMissingCa && ! $alreadyComplete,
            'unresolved_missing_city' => $unresolvedMissingCity && ! $alreadyComplete,
            'ambiguous_ca' => $ambiguousCa,
            'ambiguous_city' => $ambiguousCity,
            'report' => [
                'ca_id' => $caId,
                'current_firm_name' => $currentFirm,
                'current_ca_name' => $currentCa,
                'current_city_id' => $currentCityId,
                'proposed_ca_name' => $proposedCa,
                'proposed_city_id' => $proposedCityId,
                'proposed_firm_name' => $proposedFirm,
                'ocr_city_text' => $ocrCityText,
                'decision' => $decision,
                'reason' => implode('; ', $reasons),
            ],
        ];
    }

    /**
     * @return array{status: string, ca_name?: string|null, candidates?: list<string>}
     */
    public function resolveUniqueMemberCaName(int $ocrParsedFirmId): array
    {
        if (! Schema::hasTable('ocr_parsed_members')) {
            return ['status' => 'none', 'candidates' => []];
        }

        $names = OcrParsedMember::query()
            ->where('ocr_parsed_firm_id', $ocrParsedFirmId)
            ->whereNotNull('ca_name')
            ->where('ca_name', '!=', '')
            ->pluck('ca_name')
            ->map(fn ($n) => $this->blankToNull($n))
            ->filter()
            ->values();

        if ($names->isEmpty()) {
            return ['status' => 'none', 'candidates' => []];
        }

        $byNorm = [];
        foreach ($names as $name) {
            $norm = mb_strtoupper((string) ($this->normalizer->caName($name) ?? $name));
            if ($norm === '') {
                continue;
            }
            $byNorm[$norm] ??= $name;
        }

        $distinct = array_values($byNorm);
        if (count($distinct) === 1) {
            return ['status' => 'unique', 'ca_name' => $distinct[0], 'candidates' => $distinct];
        }
        if (count($distinct) === 0) {
            return ['status' => 'none', 'candidates' => []];
        }

        return ['status' => 'ambiguous', 'candidates' => $distinct];
    }

    /**
     * Exact unique city_name match, then reviewed locality alias → unique city_id.
     * Never guesses.
     *
     * @return array{status: string, city_id?: int|null, via?: string, detail?: string}
     */
    public function resolveCity(?string $ocrCityText): array
    {
        $text = $this->blankToNull($ocrCityText);
        if ($text === null) {
            return ['status' => 'blank'];
        }

        $exact = $this->exactUniqueCityId($text);
        if ($exact['status'] === 'unique') {
            return ['status' => 'unique', 'city_id' => $exact['city_id'], 'via' => 'exact_city_name'];
        }
        if ($exact['status'] === 'ambiguous') {
            return ['status' => 'ambiguous', 'detail' => 'exact_name_multiple_city_ids'];
        }

        $aliasKey = $this->localityKey($text);
        $aliases = $this->localityAliases();
        if (! isset($aliases[$aliasKey])) {
            return ['status' => 'unknown'];
        }

        $target = $aliases[$aliasKey];
        if (is_int($target) || (is_string($target) && ctype_digit($target))) {
            $id = (int) $target;
            if ($this->isValidCityId($id)) {
                return ['status' => 'unique', 'city_id' => $id, 'via' => 'locality_alias_id'];
            }

            return ['status' => 'unknown', 'detail' => 'alias_city_id_invalid'];
        }

        $aliased = $this->exactUniqueCityId((string) $target);
        if ($aliased['status'] === 'unique') {
            return ['status' => 'unique', 'city_id' => $aliased['city_id'], 'via' => 'locality_alias'];
        }
        if ($aliased['status'] === 'ambiguous') {
            return ['status' => 'ambiguous', 'detail' => 'alias_maps_to_multiple_city_ids'];
        }

        return ['status' => 'unknown', 'detail' => 'alias_city_name_not_found'];
    }

    /**
     * @return array{status: string, city_id?: int|null}
     */
    public function exactUniqueCityId(string $name): array
    {
        $index = $this->cityNameIndex();
        $key = $this->localityKey($name);
        if (! array_key_exists($key, $index)) {
            return ['status' => 'none'];
        }
        $id = $index[$key];
        if ($id === null) {
            return ['status' => 'ambiguous'];
        }

        return ['status' => 'unique', 'city_id' => $id];
    }

    /**
     * @param  list<array{ca_id: int, updates: array<string, mixed>, before: array<string, mixed>}>  $chunk
     */
    private function applyChunk(array $chunk): void
    {
        DB::transaction(function () use ($chunk) {
            foreach ($chunk as $item) {
                $master = CaMaster::query()->lockForUpdate()->find($item['ca_id']);
                if (! $master) {
                    continue;
                }

                // Re-check: never overwrite non-empty / valid values at apply time.
                $safe = [];
                if (isset($item['updates']['firm_name']) && $this->blankToNull($master->firm_name) === null) {
                    $safe['firm_name'] = $item['updates']['firm_name'];
                    if (isset($item['updates']['normalized_firm_name'])) {
                        $safe['normalized_firm_name'] = $item['updates']['normalized_firm_name'];
                    }
                }
                if (isset($item['updates']['ca_name']) && $this->blankToNull($master->ca_name) === null) {
                    $safe['ca_name'] = $item['updates']['ca_name'];
                    if (isset($item['updates']['normalized_ca_name'])) {
                        $safe['normalized_ca_name'] = $item['updates']['normalized_ca_name'];
                    }
                }
                if (isset($item['updates']['city_id']) && $this->validCityId($master->city_id) === null) {
                    $safe['city_id'] = $item['updates']['city_id'];
                }

                if ($safe === []) {
                    continue;
                }

                $master->fill($safe);
                $master->save();

                // Assert verification fields untouched.
                $master->refresh();
                foreach (['verification_status', 'is_verified'] as $col) {
                    if (! Schema::hasColumn('ca_masters', $col)) {
                        continue;
                    }
                    $before = $item['before'][$col] ?? null;
                    $after = $master->getAttribute($col);
                    if ($before != $after) {
                        throw new RuntimeException(
                            "Repair unexpectedly changed ca_masters.{$col} for ca_id={$master->ca_id}"
                        );
                    }
                }
            }
        });
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function writeCsv(string $path, array $rows): string
    {
        $dir = dirname($path);
        if ($dir !== '' && $dir !== '.' && ! is_dir($dir)) {
            if (! mkdir($dir, 0755, true) && ! is_dir($dir)) {
                throw new RuntimeException("Unable to create export directory: {$dir}");
            }
        }

        $fh = fopen($path, 'wb');
        if ($fh === false) {
            throw new RuntimeException("Unable to open export path: {$path}");
        }

        try {
            fputcsv($fh, [
                'ca_id',
                'current_firm_name',
                'current_ca_name',
                'current_city_id',
                'proposed_ca_name',
                'proposed_city_id',
                'ocr_city_text',
                'decision',
                'reason',
            ]);
            foreach ($rows as $row) {
                fputcsv($fh, [
                    $row['ca_id'] ?? '',
                    $row['current_firm_name'] ?? '',
                    $row['current_ca_name'] ?? '',
                    $row['current_city_id'] ?? '',
                    $row['proposed_ca_name'] ?? '',
                    $row['proposed_city_id'] ?? '',
                    $row['ocr_city_text'] ?? '',
                    $row['decision'] ?? '',
                    $row['reason'] ?? '',
                ]);
            }
        } finally {
            fclose($fh);
        }

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyCounts(): array
    {
        return [
            'total_eligible' => 0,
            'total_scanned' => 0,
            'firm_names_recoverable' => 0,
            'ca_names_recoverable' => 0,
            'cities_recoverable' => 0,
            'records_becoming_complete' => 0,
            'unresolved_missing_ca' => 0,
            'unresolved_missing_city' => 0,
            'ambiguous_ca_candidates' => 0,
            'ambiguous_city_locality_candidates' => 0,
            'applied' => 0,
            'dry_run' => true,
            'apply' => false,
            'export_path' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $counts
     * @param  array<string, mixed>  $plan
     */
    private function tally(array &$counts, array $plan): void
    {
        if ($plan['firm_recoverable']) {
            $counts['firm_names_recoverable']++;
        }
        if ($plan['ca_recoverable']) {
            $counts['ca_names_recoverable']++;
        }
        if ($plan['city_recoverable']) {
            $counts['cities_recoverable']++;
        }
        if ($plan['becomes_complete']) {
            $counts['records_becoming_complete']++;
        }
        if ($plan['unresolved_missing_ca']) {
            $counts['unresolved_missing_ca']++;
        }
        if ($plan['unresolved_missing_city']) {
            $counts['unresolved_missing_city']++;
        }
        if ($plan['ambiguous_ca']) {
            $counts['ambiguous_ca_candidates']++;
        }
        if ($plan['ambiguous_city']) {
            $counts['ambiguous_city_locality_candidates']++;
        }
    }

    private function blankToNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function validCityId(mixed $cityId): ?int
    {
        if ($cityId === null || $cityId === '' || (int) $cityId <= 0) {
            return null;
        }
        $id = (int) $cityId;

        return $this->isValidCityId($id) ? $id : null;
    }

    private function isValidCityId(int $cityId): bool
    {
        if ($cityId <= 0) {
            return false;
        }
        $this->validCityIds ??= City::query()->pluck('city_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();

        return isset($this->validCityIds[$cityId]);
    }

    private function localityKey(string $text): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);

        return mb_strtolower($collapsed);
    }

    /**
     * @return array<string, int|null> lowercase city_name => city_id (null = ambiguous)
     */
    private function cityNameIndex(): array
    {
        if ($this->cityNameIndex !== null) {
            return $this->cityNameIndex;
        }

        $index = [];
        City::query()->orderBy('city_id')->get(['city_id', 'city_name'])->each(function (City $city) use (&$index) {
            $key = $this->localityKey((string) $city->city_name);
            if ($key === '') {
                return;
            }
            if (array_key_exists($key, $index) && $index[$key] !== (int) $city->city_id) {
                $index[$key] = null; // ambiguous
            } else {
                $index[$key] = (int) $city->city_id;
            }
        });

        return $this->cityNameIndex = $index;
    }

    /**
     * @return array<string, int|string>
     */
    private function localityAliases(): array
    {
        if ($this->localityAliases !== null) {
            return $this->localityAliases;
        }

        $raw = config('ocr_locality_aliases.aliases', []);
        $map = [];
        if (is_array($raw)) {
            foreach ($raw as $locality => $target) {
                $key = $this->localityKey((string) $locality);
                if ($key === '' || $target === null || $target === '') {
                    continue;
                }
                $map[$key] = is_numeric($target) ? (int) $target : (string) $target;
            }
        }

        return $this->localityAliases = $map;
    }
}

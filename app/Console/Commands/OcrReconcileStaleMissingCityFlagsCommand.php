<?php

namespace App\Console\Commands;

use App\Models\CaMaster;
use App\Models\City;
use App\Support\Ocr\CaMasterCityQuality;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Clear stale data_quality_issue=missing_city when a real city is already linked or displayable from OCR.
 */
class OcrReconcileStaleMissingCityFlagsCommand extends Command
{
    protected $signature = 'ocr:reconcile-stale-missing-city-flags
                            {--dry-run : Report only, do not update}
                            {--chunk=500 : Chunk size}';

    protected $description = 'Clear missing_city badges when Master already has a real city (or OCR city text)';

    public function handle(): int
    {
        if (! Schema::hasColumn('ca_masters', 'data_quality_issue')) {
            $this->warn('data_quality_issue column missing.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $chunk = max(50, (int) $this->option('chunk'));

        $placeholderIds = City::query()
            ->whereRaw('UPPER(TRIM(city_name)) = ?', [strtoupper(CaMasterCityQuality::PLACEHOLDER_CITY_NAME)])
            ->pluck('city_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $cleared = 0;
        $toMissingCa = 0;
        $kept = 0;

        CaMaster::query()
            ->where('data_quality_issue', CaMasterCityQuality::ISSUE_MISSING_CITY)
            ->with('city:city_id,city_name')
            ->orderBy('ca_id')
            ->chunkById($chunk, function ($masters) use ($dryRun, $placeholderIds, &$cleared, &$toMissingCa, &$kept) {
                foreach ($masters as $master) {
                    /** @var CaMaster $master */
                    $cityId = $master->city_id !== null ? (int) $master->city_id : 0;
                    $linkedReal = $cityId > 0 && ! in_array($cityId, $placeholderIds, true);
                    $cityName = trim((string) ($master->city?->city_name ?? ''));
                    $ocrCity = trim((string) ($master->ocr_city_text ?? ''));
                    $displayable = $linkedReal
                        || ($cityName !== '' && ! CaMasterCityQuality::isPlaceholderCityName($cityName))
                        || ($ocrCity !== '' && ! CaMasterCityQuality::isPlaceholderCityName($ocrCity));

                    if (! $displayable) {
                        $kept++;

                        continue;
                    }

                    $attrs = CaMasterCityQuality::attributesAfterRealCityLinked($master);
                    // For OCR-text-only (no linked city_id), still suppress missing_city in DB
                    // when OCR city text is present — matches UI city column.
                    if ($attrs === [] && $ocrCity !== '' && $cityId < 1) {
                        if (trim((string) ($master->ca_name ?? '')) === '') {
                            $attrs = [
                                'data_quality_issue' => CaMasterCityQuality::ISSUE_MISSING_CA,
                            ];
                        } else {
                            $attrs = [
                                'data_quality_issue' => null,
                            ];
                        }
                        if (Schema::hasColumn('ca_masters', 'data_quality_status') && ($attrs['data_quality_issue'] ?? false) === null) {
                            $attrs['data_quality_status'] = 'complete';
                        }
                    }

                    if ($attrs === []) {
                        $kept++;

                        continue;
                    }

                    if (($attrs['data_quality_issue'] ?? null) === CaMasterCityQuality::ISSUE_MISSING_CA) {
                        $toMissingCa++;
                    } else {
                        $cleared++;
                    }

                    if (! $dryRun) {
                        DB::table('ca_masters')->where('ca_id', $master->ca_id)->update($attrs);
                    }
                }
            }, 'ca_id');

        $this->table(
            ['Metric', 'Value'],
            [
                ['Mode', $dryRun ? 'dry-run' : 'apply'],
                ['Cleared missing_city (city present)', number_format($cleared)],
                ['Retagged to missing_ca_name', number_format($toMissingCa)],
                ['Kept missing_city', number_format($kept)],
            ]
        );

        return self::SUCCESS;
    }
}

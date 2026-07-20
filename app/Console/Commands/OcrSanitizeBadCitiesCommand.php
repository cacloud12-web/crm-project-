<?php

namespace App\Console\Commands;

use App\Models\OcrParsedFirm;
use App\Services\Ocr\OcrCityResolverService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Clears street/district values wrongly stored in ocr_parsed_firms.city
 * (e.g. BALKESHWAR ROAD, SETLA ROAD, 24 PARAGANAS).
 */
class OcrSanitizeBadCitiesCommand extends Command
{
    protected $signature = 'ocr:sanitize-bad-cities
                            {--document= : Limit to one OCR document ID}
                            {--dry-run : Report only, do not update}';

    protected $description = 'Null out ROAD / PARAGANAS / locality values wrongly stored as City';

    public function handle(): int
    {
        $resolver = new OcrCityResolverService;
        $documentId = $this->option('document');
        $dryRun = (bool) $this->option('dry-run');

        $query = OcrParsedFirm::query()
            ->whereNotNull('city')
            ->where('city', '!=', '');
        if ($documentId !== null && $documentId !== '') {
            $query->where('ocr_document_id', (int) $documentId);
        }

        $scanned = 0;
        $cleared = 0;
        $samples = [];

        $query->orderBy('id')->chunkById(500, function ($firms) use ($resolver, $dryRun, &$scanned, &$cleared, &$samples) {
            foreach ($firms as $firm) {
                $scanned++;
                $city = trim((string) $firm->city);
                if ($city === '' || ! $resolver->isForbiddenLocalityShape($city)) {
                    continue;
                }
                $cleared++;
                if (count($samples) < 30) {
                    $samples[] = sprintf('#%d doc=%s city=%s firm=%s', $firm->id, $firm->ocr_document_id, $city, $firm->firm_name);
                }
                if ($dryRun) {
                    continue;
                }

                $errors = is_array($firm->validation_errors) ? $firm->validation_errors : [];
                $errors = array_values(array_filter($errors, static function ($e) {
                    $s = is_string($e) ? $e : (string) ($e['message'] ?? $e['code'] ?? '');

                    return ! str_contains(mb_strtolower($s), 'city is required')
                        && ! str_contains(mb_strtoupper($s), 'MISSING_CITY');
                }));
                $errors[] = 'City cleared: address/locality was wrongly stored as city ('.$city.')';

                $firm->city = null;
                $firm->validation_errors = $errors;
                if (in_array($firm->review_status, ['approved', 'verified'], true)) {
                    $firm->review_status = OcrParsedFirm::REVIEW_PENDING;
                }
                $firm->save();
            }
        });

        $this->info(($dryRun ? '[dry-run] ' : '')."scanned={$scanned} cleared={$cleared}");
        foreach ($samples as $line) {
            $this->line($line);
        }

        if (! $dryRun && $cleared > 0) {
            DB::table('ocr_documents')
                ->when($documentId, fn ($q) => $q->where('id', (int) $documentId))
                ->update(['updated_at' => now()]);
        }

        return self::SUCCESS;
    }
}

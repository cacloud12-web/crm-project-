<?php

namespace App\Services\Ocr;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Read-only offline matcher: Masters without OCR link ↔ ocr_parsed_firms.
 * Never writes. Never runs production joins.
 */
class OcrOfflineNoLinkFirmMatchService
{
    /**
     * Normalize firm name: trim, uppercase, collapse spaces, & → AND, strip punctuation.
     */
    public function normalizeFirmName(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $value = str_replace('&', ' AND ', $value);
        $value = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return mb_strtoupper($value, 'UTF-8');
    }

    /**
     * Stronger key: drop leading M/S and trailing legal suffixes for weak matching.
     */
    public function normalizeFirmNameWeak(?string $value): ?string
    {
        $base = $this->normalizeFirmName($value);
        if ($base === null) {
            return null;
        }
        $base = preg_replace('/^M\s*S\s+/u', '', $base) ?? $base;
        $base = preg_replace('/\s+CHARTERED\s+ACCOUNTANTS?.*$/u', '', $base) ?? $base;
        $base = preg_replace('/\s+AND\s+ASSOCIATES$/u', '', $base) ?? $base;
        $base = preg_replace('/\s+ASSOCIATES$/u', '', $base) ?? $base;
        $base = preg_replace('/\s+AND\s+CO(?:MPANY)?$/u', '', $base) ?? $base;
        $base = preg_replace('/\s+CO(?:MPANY)?$/u', '', $base) ?? $base;
        $base = preg_replace('/\s+LLP$/u', '', $base) ?? $base;
        $base = preg_replace('/\s+/u', ' ', trim($base)) ?? trim($base);

        return $base !== '' ? $base : null;
    }

    /**
     * @param  array{
     *   masters_csv: string,
     *   firms_csv?: string|null,
     *   use_local_db?: bool,
     *   export?: string|null,
     *   limit?: int
     * }  $options
     * @return array<string, mixed>
     */
    public function run(array $options): array
    {
        $mastersPath = (string) ($options['masters_csv'] ?? '');
        $firmsCsv = $options['firms_csv'] ?? null;
        $useLocalDb = (bool) ($options['use_local_db'] ?? ($firmsCsv === null || $firmsCsv === ''));
        $export = (string) ($options['export']
            ?? storage_path('app/audits/no-ocr-link-offline-firm-matches.csv'));
        $limit = max(0, (int) ($options['limit'] ?? 0));

        if ($mastersPath === '' || ! is_file($mastersPath)) {
            throw new RuntimeException('Masters CSV not found: '.$mastersPath);
        }

        $index = $useLocalDb
            ? $this->indexFirmsFromDatabase()
            : $this->indexFirmsFromCsv((string) $firmsCsv);

        $masters = $this->loadMastersCsv($mastersPath, $limit);

        $dir = dirname($export);
        if ($dir !== '' && $dir !== '.' && ! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $fh = fopen($export, 'wb');
        if ($fh === false) {
            throw new RuntimeException('Unable to open export: '.$export);
        }

        fputcsv($fh, [
            'CA ID',
            'Master Firm Name',
            'Matched OCR Firm',
            'OCR City',
            'Confidence',
            'Normalized Key',
            'OCR Firm ID',
            'OCR Document ID',
            'Match Count',
        ]);

        $counts = [
            'masters' => 0,
            'Exact' => 0,
            'Strong' => 0,
            'Weak' => 0,
            'No Match' => 0,
        ];

        foreach ($masters as $m) {
            $counts['masters']++;
            $match = $this->matchOne((string) ($m['firm_name'] ?? ''), $index);
            $counts[$match['confidence']]++;

            fputcsv($fh, [
                $m['ca_id'] ?? '',
                $m['firm_name'] ?? '',
                $match['matched_firm'] ?? '',
                $match['ocr_city'] ?? '',
                $match['confidence'],
                $match['normalized_key'] ?? '',
                $match['ocr_firm_id'] ?? '',
                $match['ocr_document_id'] ?? '',
                $match['match_count'] ?? 0,
            ]);
        }

        fclose($fh);

        return [
            'export_path' => $export,
            'counts' => $counts,
            'ocr_index_keys' => count($index['exact']),
            'ocr_firm_rows' => $index['row_count'],
            'firms_source' => $useLocalDb ? 'local_db:ocr_parsed_firms' : (string) $firmsCsv,
            'masters_csv' => $mastersPath,
        ];
    }

    /**
     * @param  array{
     *   exact: array<string, list<array<string, mixed>>>,
     *   weak: array<string, list<array<string, mixed>>>,
     *   row_count: int
     * }  $index
     * @return array<string, mixed>
     */
    public function matchOne(string $firmName, array $index): array
    {
        $exactKey = $this->normalizeFirmName($firmName);
        $weakKey = $this->normalizeFirmNameWeak($firmName);

        if ($exactKey !== null && isset($index['exact'][$exactKey])) {
            return $this->pickConfidence($index['exact'][$exactKey], $exactKey, true);
        }

        if ($weakKey !== null && isset($index['weak'][$weakKey])) {
            $hits = $index['weak'][$weakKey];
            // Prefer hits whose exact key equals master's exact key after weak expand — already weak bucket.
            return $this->pickConfidence($hits, $weakKey, false);
        }

        return [
            'confidence' => 'No Match',
            'matched_firm' => '',
            'ocr_city' => '',
            'normalized_key' => $exactKey ?? '',
            'ocr_firm_id' => '',
            'ocr_document_id' => '',
            'match_count' => 0,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $hits
     * @return array<string, mixed>
     */
    private function pickConfidence(array $hits, string $key, bool $exactBucket): array
    {
        $count = count($hits);
        if ($count === 0) {
            return [
                'confidence' => 'No Match',
                'matched_firm' => '',
                'ocr_city' => '',
                'normalized_key' => $key,
                'ocr_firm_id' => '',
                'ocr_document_id' => '',
                'match_count' => 0,
            ];
        }

        $cities = [];
        foreach ($hits as $h) {
            $c = trim((string) ($h['city'] ?? ''));
            if ($c !== '') {
                $cities[$this->normalizeFirmName($c) ?? $c] = $c;
            }
        }
        // Prefer a hit that has a city.
        usort($hits, static function ($a, $b) {
            $ac = trim((string) ($a['city'] ?? '')) !== '' ? 0 : 1;
            $bc = trim((string) ($b['city'] ?? '')) !== '' ? 0 : 1;

            return $ac <=> $bc;
        });
        $best = $hits[0];

        if ($exactBucket) {
            if ($count === 1 || count($cities) <= 1) {
                $confidence = 'Exact';
            } else {
                $confidence = 'Strong'; // same firm name, multiple distinct OCR cities
            }
        } else {
            $confidence = 'Weak';
        }

        return [
            'confidence' => $confidence,
            'matched_firm' => (string) ($best['firm_name'] ?? ''),
            'ocr_city' => (string) ($best['city'] ?? ''),
            'normalized_key' => $key,
            'ocr_firm_id' => $best['id'] ?? '',
            'ocr_document_id' => $best['ocr_document_id'] ?? '',
            'match_count' => $count,
        ];
    }

    /**
     * @return array{exact: array<string, list<array<string, mixed>>>, weak: array<string, list<array<string, mixed>>>, row_count: int}
     */
    private function indexFirmsFromDatabase(): array
    {
        $exact = [];
        $weak = [];
        $n = 0;
        foreach (DB::table('ocr_parsed_firms')->select([
            'id', 'firm_name', 'city', 'ocr_document_id',
        ])->orderBy('id')->cursor() as $row) {
            $n++;
            $entry = [
                'id' => (int) $row->id,
                'firm_name' => (string) $row->firm_name,
                'city' => (string) ($row->city ?? ''),
                'ocr_document_id' => (int) ($row->ocr_document_id ?? 0),
            ];
            $ek = $this->normalizeFirmName($entry['firm_name']);
            if ($ek !== null) {
                $exact[$ek][] = $entry;
            }
            $wk = $this->normalizeFirmNameWeak($entry['firm_name']);
            if ($wk !== null) {
                $weak[$wk][] = $entry;
            }
        }

        return ['exact' => $exact, 'weak' => $weak, 'row_count' => $n];
    }

    /**
     * @return array{exact: array<string, list<array<string, mixed>>>, weak: array<string, list<array<string, mixed>>>, row_count: int}
     */
    private function indexFirmsFromCsv(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('OCR firms CSV not found: '.$path);
        }
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw new RuntimeException('Unable to open OCR firms CSV: '.$path);
        }
        $header = fgetcsv($fh);
        $exact = [];
        $weak = [];
        $n = 0;
        while (($row = fgetcsv($fh)) !== false) {
            $map = array_combine($header ?: [], $row);
            if ($map === false) {
                continue;
            }
            $n++;
            $entry = [
                'id' => (int) ($map['id'] ?? $map['ocr_firm_id'] ?? 0),
                'firm_name' => (string) ($map['firm_name'] ?? ''),
                'city' => (string) ($map['city'] ?? ''),
                'ocr_document_id' => (int) ($map['ocr_document_id'] ?? 0),
            ];
            $ek = $this->normalizeFirmName($entry['firm_name']);
            if ($ek !== null) {
                $exact[$ek][] = $entry;
            }
            $wk = $this->normalizeFirmNameWeak($entry['firm_name']);
            if ($wk !== null) {
                $weak[$wk][] = $entry;
            }
        }
        fclose($fh);

        return ['exact' => $exact, 'weak' => $weak, 'row_count' => $n];
    }

    /**
     * @return list<array<string, string>>
     */
    private function loadMastersCsv(string $path, int $limit = 0): array
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw new RuntimeException('Unable to open masters CSV: '.$path);
        }
        $header = fgetcsv($fh);
        if ($header === false) {
            fclose($fh);
            throw new RuntimeException('Masters CSV empty: '.$path);
        }
        // Normalize header aliases
        $header = array_map(static function ($h) {
            $h = strtolower(trim((string) $h));
            $h = str_replace([' ', '-'], '_', $h);

            return $h;
        }, $header);

        $rows = [];
        while (($row = fgetcsv($fh)) !== false) {
            $map = array_combine($header, $row);
            if ($map === false) {
                continue;
            }
            $rows[] = [
                'ca_id' => (string) ($map['ca_id'] ?? $map['caid'] ?? ''),
                'firm_name' => (string) ($map['firm_name'] ?? $map['master_firm_name'] ?? ''),
            ];
            if ($limit > 0 && count($rows) >= $limit) {
                break;
            }
        }
        fclose($fh);

        return $rows;
    }
}

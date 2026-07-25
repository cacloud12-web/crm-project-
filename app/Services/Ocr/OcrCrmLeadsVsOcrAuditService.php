<?php

namespace App\Services\Ocr;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only audit: CRM Master leads (ca_masters) vs OCR parsed firms/members.
 * Does NOT infer CA names from firm titles. Never writes business data.
 */
class OcrCrmLeadsVsOcrAuditService
{
    public const EXACT_MATCH = 'EXACT_MATCH';

    public const FIRM_MATCH_ONLY = 'FIRM_MATCH_ONLY';

    public const CA_DIFFERENT = 'CA_DIFFERENT';

    public const CITY_DIFFERENT = 'CITY_DIFFERENT';

    public const OCR_MEMBER_MISSING = 'OCR_MEMBER_MISSING';

    public const NOT_FOUND_IN_OCR = 'NOT_FOUND_IN_OCR';

    /**
     * @param  array{
     *   export?: string|null,
     *   limit?: int,
     *   include_deleted?: bool,
     *   document_ids?: list<int>|null
     * }  $options
     * @return array<string, mixed>
     */
    public function audit(array $options = []): array
    {
        $export = $options['export'] ?? storage_path('app/audits/crm-leads-vs-ocr-audit.csv');
        $limit = max(0, (int) ($options['limit'] ?? 0));
        $includeDeleted = (bool) ($options['include_deleted'] ?? false);
        $documentIds = isset($options['document_ids']) && is_array($options['document_ids'])
            ? array_values(array_map('intval', $options['document_ids']))
            : null;

        $ocrIndex = $this->buildOcrFirmIndex($documentIds);

        $counts = [
            self::EXACT_MATCH => 0,
            self::FIRM_MATCH_ONLY => 0,
            self::CA_DIFFERENT => 0,
            self::CITY_DIFFERENT => 0,
            self::OCR_MEMBER_MISSING => 0,
            self::NOT_FOUND_IN_OCR => 0,
        ];

        $dir = dirname((string) $export);
        if ($dir !== '' && $dir !== '.' && ! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $fh = fopen((string) $export, 'wb');
        if ($fh === false) {
            throw new \RuntimeException('Unable to open CSV: '.$export);
        }
        fputcsv($fh, [
            'Lead ID',
            'Firm Name',
            'CRM CA Name',
            'OCR CA Name',
            'CRM City',
            'OCR City',
            'FRN',
            'Membership Number',
            'Match Category',
            'Reason',
        ]);

        $total = 0;
        $cityNames = $this->loadCityNames();

        $query = DB::table('ca_masters')
            ->select([
                'ca_id',
                'firm_name',
                'ca_name',
                'city_id',
                'ocr_city_text',
            ]);
        if (Schema::hasColumn('ca_masters', 'frn')) {
            $query->addSelect('frn');
        }
        if (Schema::hasColumn('ca_masters', 'membership_no')) {
            $query->addSelect('membership_no');
        }
        if (! $includeDeleted && Schema::hasColumn('ca_masters', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }
        $query->orderBy('ca_id');

        $query->chunkById(1000, function ($leads) use (
            &$total, &$counts, $limit, $ocrIndex, $cityNames, $fh
        ) {
            foreach ($leads as $lead) {
                if ($limit > 0 && $total >= $limit) {
                    return false;
                }
                $total++;
                $row = $this->classifyLead($lead, $ocrIndex, $cityNames);
                $counts[$row['category']]++;
                fputcsv($fh, [
                    $row['lead_id'],
                    $row['firm_name'],
                    $row['crm_ca'],
                    $row['ocr_ca'],
                    $row['crm_city'],
                    $row['ocr_city'],
                    $row['frn'],
                    $row['membership_no'],
                    $row['category'],
                    $row['reason'],
                ]);
            }

            return $limit <= 0 || $total < $limit;
        }, 'ca_id');

        fclose($fh);

        $pct = static function (int $n, int $t): string {
            if ($t <= 0) {
                return '0.00%';
            }

            return number_format(($n / $t) * 100, 2).'%';
        };

        return [
            'total' => $total,
            'counts' => $counts,
            'percentages' => [
                self::EXACT_MATCH => $pct($counts[self::EXACT_MATCH], $total),
                self::FIRM_MATCH_ONLY => $pct($counts[self::FIRM_MATCH_ONLY], $total),
                self::CA_DIFFERENT => $pct($counts[self::CA_DIFFERENT], $total),
                self::CITY_DIFFERENT => $pct($counts[self::CITY_DIFFERENT], $total),
                self::OCR_MEMBER_MISSING => $pct($counts[self::OCR_MEMBER_MISSING], $total),
                self::NOT_FOUND_IN_OCR => $pct($counts[self::NOT_FOUND_IN_OCR], $total),
            ],
            'export_path' => $export,
            'ocr_firm_keys' => count($ocrIndex),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $ocrIndex
     * @param  array<int, string>  $cityNames
     * @return array<string, mixed>
     */
    private function classifyLead(object $lead, array $ocrIndex, array $cityNames): array
    {
        $firmRaw = trim((string) ($lead->firm_name ?? ''));
        $crmCa = trim((string) ($lead->ca_name ?? ''));
        $crmCityRaw = trim((string) ($lead->ocr_city_text ?? ''));
        if ($crmCityRaw === '' && ! empty($lead->city_id)) {
            $crmCityRaw = $cityNames[(int) $lead->city_id] ?? '';
        }
        $frn = trim((string) ($lead->frn ?? ''));
        $membership = trim((string) ($lead->membership_no ?? ''));

        $firmKey = $this->normalize($firmRaw);
        $caKey = $this->normalize($crmCa);
        $cityKey = $this->normalize($crmCityRaw);

        $base = [
            'lead_id' => $lead->ca_id,
            'firm_name' => $firmRaw,
            'crm_ca' => $crmCa,
            'ocr_ca' => '',
            'crm_city' => $crmCityRaw,
            'ocr_city' => '',
            'frn' => $frn,
            'membership_no' => $membership,
        ];

        if ($firmKey === null || ! isset($ocrIndex[$firmKey])) {
            return $base + [
                'category' => self::NOT_FOUND_IN_OCR,
                'reason' => 'Normalized firm name not found in ocr_parsed_firms',
            ];
        }

        $ocr = $ocrIndex[$firmKey];
        $ocrCas = $ocr['cas']; // list of display strings keyed by normalized
        $ocrCities = $ocr['cities'];
        $memberCount = (int) $ocr['member_count'];
        $ocrCaDisplay = $ocrCas !== [] ? implode(' | ', array_slice(array_values($ocrCas), 0, 3)) : '';
        $ocrCityDisplay = $ocrCities !== [] ? implode(' | ', array_slice(array_values($ocrCities), 0, 3)) : '';
        if ($ocr['frns'] !== [] && $frn === '') {
            $base['frn'] = implode('|', array_slice($ocr['frns'], 0, 3));
        }
        if ($ocr['memberships'] !== [] && $membership === '') {
            $base['membership_no'] = implode('|', array_slice($ocr['memberships'], 0, 3));
        }

        $base['ocr_ca'] = $ocrCaDisplay;
        $base['ocr_city'] = $ocrCityDisplay;

        // No OCR member rows and no explicit CA field on the firm.
        if ($memberCount === 0 && $ocrCas === []) {
            return $base + [
                'category' => self::OCR_MEMBER_MISSING,
                'reason' => 'Firm found in ocr_parsed_firms but no ocr_parsed_members and no explicit OCR CA field',
            ];
        }

        // CA not present in OCR at all (members may exist but without usable CA names).
        if ($ocrCas === []) {
            return $base + [
                'category' => self::FIRM_MATCH_ONLY,
                'reason' => $memberCount === 0
                    ? 'Firm found in OCR; no explicit CA name in members or source_data'
                    : 'Firm found; member rows exist but no usable CA names',
            ];
        }

        // CRM has no CA — firm exists, OCR has CAs → firm match only.
        if ($caKey === null) {
            return $base + [
                'category' => self::FIRM_MATCH_ONLY,
                'reason' => 'Firm found in OCR; CRM CA name empty',
            ];
        }

        $caMatched = isset($ocrCas[$caKey]);
        if (! $caMatched) {
            return $base + [
                'category' => self::CA_DIFFERENT,
                'reason' => 'Firm found; CRM CA not among explicit OCR CA names',
            ];
        }

        // Prefer matched CA display for OCR CA column.
        $base['ocr_ca'] = $ocrCas[$caKey];

        $cityMatched = false;
        $cityComparable = $cityKey !== null && $ocrCities !== [];
        if ($cityComparable) {
            $cityMatched = isset($ocrCities[$cityKey]);
            if ($cityMatched) {
                $base['ocr_city'] = $ocrCities[$cityKey];
            } else {
                $base['ocr_city'] = $ocrCityDisplay;
            }
        }

        if ($cityComparable && ! $cityMatched) {
            return $base + [
                'category' => self::CITY_DIFFERENT,
                'reason' => 'Firm and CA match; CRM city differs from OCR city',
            ];
        }

        if ($cityKey !== null && $cityMatched) {
            return $base + [
                'category' => self::EXACT_MATCH,
                'reason' => 'Firm + CA + City match OCR extracted data',
            ];
        }

        // Firm + CA match but city missing on CRM or OCR — not full EXACT.
        if ($cityKey === null) {
            return $base + [
                'category' => self::FIRM_MATCH_ONLY,
                'reason' => 'Firm and CA match; CRM city empty so Exact Match not claimed',
            ];
        }

        return $base + [
            'category' => self::FIRM_MATCH_ONLY,
            'reason' => 'Firm and CA match; OCR city empty so Exact Match not claimed',
        ];
    }

    /**
     * Build OCR index by normalized firm name.
     * CA names come ONLY from ocr_parsed_members and explicit source_data ca fields.
     * Never peels CA from firm title.
     *
     * @param  list<int>|null  $documentIds
     * @return array<string, array{cas: array<string, string>, cities: array<string, string>, member_count: int, frns: list<string>, memberships: list<string>, firm_ids: list<int>}>
     */
    private function buildOcrFirmIndex(?array $documentIds): array
    {
        $index = [];
        $firmIdToKey = [];

        $firms = DB::table('ocr_parsed_firms')
            ->whereNotNull('firm_name')
            ->where('firm_name', '!=', '');
        if ($documentIds !== null && $documentIds !== []) {
            $firms->whereIn('ocr_document_id', $documentIds);
        }

        $firms->orderBy('id')
            ->select(['id', 'firm_name', 'city', 'frn', 'source_data'])
            ->chunkById(1500, function ($chunk) use (&$index, &$firmIdToKey) {
                foreach ($chunk as $firm) {
                    $key = $this->normalize((string) $firm->firm_name);
                    if ($key === null) {
                        continue;
                    }
                    if (! isset($index[$key])) {
                        $index[$key] = [
                            'cas' => [],
                            'cities' => [],
                            'member_count' => 0,
                            'frns' => [],
                            'memberships' => [],
                            'firm_ids' => [],
                        ];
                    }
                    $index[$key]['firm_ids'][] = (int) $firm->id;
                    $firmIdToKey[(int) $firm->id] = $key;

                    $city = trim((string) ($firm->city ?? ''));
                    $cityKey = $this->normalize($city);
                    if ($cityKey !== null) {
                        $index[$key]['cities'][$cityKey] = $city;
                    }

                    $frn = trim((string) ($firm->frn ?? ''));
                    if ($frn !== '' && ! in_array($frn, $index[$key]['frns'], true)) {
                        $index[$key]['frns'][] = $frn;
                    }

                    // Explicit CA from source_data only (never firm-title peel).
                    $sd = is_string($firm->source_data)
                        ? (json_decode($firm->source_data, true) ?: [])
                        : (array) ($firm->source_data ?? []);
                    $reason = (string) ($sd['classification_reason'] ?? '');
                    $explicitCa = trim((string) (($sd['parsed']['ca_name'] ?? '') ?: ($sd['raw']['ca_name'] ?? '') ?: ($sd['ca_name'] ?? '')));
                    // Skip firm-derived peel values — not explicit OCR person/member evidence.
                    if ($explicitCa !== '' && $reason !== 'firm_derived_missing_raw_ca') {
                        $metaReason = (string) (($sd['field_meta']['ca_name']['reason'] ?? '') ?: ($sd['field_meta']['ca_name']['evidence'] ?? ''));
                        if ($metaReason !== 'firm_derived_missing_raw_ca') {
                            $caKey = $this->normalize($explicitCa);
                            if ($caKey !== null && ! $this->looksLikeAddressNoise($explicitCa)) {
                                $index[$key]['cas'][$caKey] = $explicitCa;
                            }
                        }
                    }
                }
            });

        // Members — authoritative CA evidence.
        if ($firmIdToKey !== []) {
            $idChunks = array_chunk(array_keys($firmIdToKey), 2000);
            foreach ($idChunks as $ids) {
                DB::table('ocr_parsed_members')
                    ->whereIn('ocr_parsed_firm_id', $ids)
                    ->orderBy('id')
                    ->select(['id', 'ocr_parsed_firm_id', 'ca_name', 'raw_ca_name', 'membership_no'])
                    ->chunkById(2000, function ($members) use (&$index, $firmIdToKey) {
                        foreach ($members as $m) {
                            $fid = (int) $m->ocr_parsed_firm_id;
                            $key = $firmIdToKey[$fid] ?? null;
                            if ($key === null || ! isset($index[$key])) {
                                continue;
                            }
                            $index[$key]['member_count']++;
                            $ca = trim((string) ($m->ca_name ?: $m->raw_ca_name));
                            if ($ca !== '' && ! $this->looksLikeAddressNoise($ca)) {
                                $caKey = $this->normalize($ca);
                                if ($caKey !== null) {
                                    $index[$key]['cas'][$caKey] = $ca;
                                }
                            }
                            $mem = trim((string) ($m->membership_no ?? ''));
                            if ($mem !== '' && ! in_array($mem, $index[$key]['memberships'], true)) {
                                $index[$key]['memberships'][] = $mem;
                            }
                        }
                    });
            }
        }

        return $index;
    }

    /**
     * @return array<int, string>
     */
    private function loadCityNames(): array
    {
        if (! Schema::hasTable('cities')) {
            return [];
        }
        $col = Schema::hasColumn('cities', 'city_name')
            ? 'city_name'
            : (Schema::hasColumn('cities', 'name') ? 'name' : null);
        if ($col === null) {
            return [];
        }
        $out = [];
        DB::table('cities')->select(['city_id', $col])->orderBy('city_id')->chunkById(2000, function ($rows) use (&$out, $col) {
            foreach ($rows as $r) {
                $out[(int) $r->city_id] = trim((string) $r->{$col});
            }
        }, 'city_id', 'city_id');

        return $out;
    }

    public function normalize(?string $value): ?string
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

    private function looksLikeAddressNoise(string $value): bool
    {
        if (preg_match('/\d/', $value)) {
            return true;
        }

        return (bool) preg_match('/(?:FLOOR|ROAD|NAGAR|PLOT|APARTMENT|COMPLEX|TOWER|STREET|BUILDING)\b/iu', $value);
    }
}

<?php

namespace App\Services\Bulk;

use App\Models\CaMaster;
use App\Models\SourceLead;
use App\Repositories\Leads\LeadPhoneNumberRepository;
use App\Services\Leads\DuplicateLeadDetectionService;
use App\Services\Leads\PhoneClassificationService;
use App\Services\Leads\PhoneNormalizationService;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Safe repair: recover Sales CSV mobiles onto empty ca_masters.mobile_no slots.
 * Never overwrites a filled primary; never creates duplicate masters.
 */
class SalesMobileRepairService
{
    public function __construct(
        private readonly PhoneNormalizationService $phones,
        private readonly PhoneClassificationService $phoneClassification,
        private readonly LeadPhoneNumberRepository $phoneNumbers,
        private readonly DuplicateLeadDetectionService $duplicateLeadDetection,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function repair(?string $csvPath, bool $dryRun = true): array
    {
        $before = $this->snapshotStats();
        $recovered = [];
        $skipped = [];

        // Pass 1: promote alternate → primary when primary empty.
        $promoteCandidates = CaMaster::query()
            ->whereNull('deleted_at')
            ->where(function ($q) {
                $q->whereNull('mobile_no')->orWhere('mobile_no', '=', '');
            })
            ->whereNotNull('alternate_mobile_no')
            ->where('alternate_mobile_no', '!=', '')
            ->orderBy('ca_id')
            ->get();

        foreach ($promoteCandidates as $lead) {
            $result = $this->promoteAlternateToPrimary($lead, $dryRun);
            if ($result['status'] === 'recovered') {
                $recovered[] = $result;
            } else {
                $skipped[] = $result;
            }
        }

        // Pass 2: re-read Sales CSV and empty-fill primary from valid Mobile/Alt values.
        if ($csvPath !== null && $csvPath !== '' && is_file($csvPath)) {
            $salesSourceId = SourceLead::query()
                ->where('source_name', 'Sales CSV Import')
                ->value('source_id');

            foreach ($this->readCsvPhoneRows($csvPath) as $row) {
                $candidatePhones = array_values(array_filter([
                    $row['mobile'],
                    $row['alt'],
                ]));
                if ($candidatePhones === []) {
                    continue;
                }

                foreach ($candidatePhones as $phone) {
                    if ($this->phoneClassification->validateForSave($phone, 'mobile_no') !== null) {
                        continue;
                    }

                    // Prefer existing owner that already has this number on alt / registry.
                    $owner = $this->phoneNumbers->findLeadByNormalizedNumber($phone);
                    if ($owner && ! filled($owner->mobile_no)) {
                        $result = $this->fillPrimary($owner, $phone, $row['raw_mobile'] ?: $phone, 'csv_match_existing_owner', $dryRun, $row);
                        if ($result['status'] === 'recovered') {
                            $recovered[] = $result;
                        } elseif ($result['status'] !== 'already_has_primary') {
                            $skipped[] = $result;
                        }
                        continue;
                    }
                    if ($owner && filled($owner->mobile_no)) {
                        continue; // already placed; do not create duplicates
                    }

                    // Firm match among Sales CSV Import masters with empty primary.
                    if (! $salesSourceId || $row['firm'] === '') {
                        continue;
                    }

                    $firmKey = $this->firmKey($row['firm']);
                    $leads = CaMaster::query()
                        ->whereNull('deleted_at')
                        ->where('source_id', $salesSourceId)
                        ->where(function ($q) {
                            $q->whereNull('mobile_no')->orWhere('mobile_no', '=', '');
                        })
                        ->where(function ($q) use ($row) {
                            $q->where('firm_name', $row['firm'])
                                ->orWhere('ca_name', $row['firm']);
                            if ($row['ca'] !== '') {
                                $q->orWhere('ca_name', $row['ca']);
                            }
                        })
                        ->limit(5)
                        ->get();

                    foreach ($leads as $lead) {
                        if ($this->firmKey((string) $lead->firm_name) !== $firmKey
                            && $this->firmKey((string) $lead->ca_name) !== $firmKey) {
                            continue;
                        }
                        $other = $this->phoneNumbers->findLeadByNormalizedNumber($phone, (int) $lead->ca_id);
                        if ($other !== null) {
                            $skipped[] = [
                                'status' => 'skipped_owned_elsewhere',
                                'ca_id' => (int) $lead->ca_id,
                                'firm_name' => $lead->firm_name,
                                'csv_mobile' => $row['raw_mobile'],
                                'imported_mobile' => null,
                                'reason' => 'phone_owned_by_ca_'.$other->ca_id,
                            ];
                            continue;
                        }
                        $result = $this->fillPrimary($lead, $phone, $row['raw_mobile'] ?: $phone, 'csv_firm_match', $dryRun, $row);
                        if ($result['status'] === 'recovered') {
                            $recovered[] = $result;
                            break;
                        }
                        $skipped[] = $result;
                    }
                }
            }
        }

        // De-dupe recovered by ca_id (keep last).
        $byCa = [];
        foreach ($recovered as $row) {
            $byCa[(int) $row['ca_id']] = $row;
        }
        $recovered = array_values($byCa);

        $after = $dryRun ? $before : $this->snapshotStats();

        $report = [
            'dry_run' => $dryRun,
            'csv_path' => $csvPath,
            'before' => $before,
            'after' => $after,
            'recovered_count' => count($recovered),
            'skipped_count' => count($skipped),
            'recovered' => $recovered,
            'skipped_sample' => array_slice($skipped, 0, 100),
        ];

        $dir = storage_path('app/audits');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $stamp = now()->format('Ymd-His');
        $jsonPath = $dir.'/sales-mobile-repair-'.$stamp.($dryRun ? '-dryrun' : '').'.json';
        file_put_contents($jsonPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $report['report_path'] = $jsonPath;

        return $report;
    }

    /**
     * @return array{empty_primary: int, empty_primary_with_alt: int, empty_primary_and_alt: int, empty_primary_with_remarks: int}
     */
    public function snapshotStats(): array
    {
        $base = CaMaster::query()->whereNull('deleted_at');
        $emptyPrimary = (clone $base)->where(function ($q) {
            $q->whereNull('mobile_no')->orWhere('mobile_no', '=', '');
        });

        return [
            'empty_primary' => (clone $emptyPrimary)->count(),
            'empty_primary_with_alt' => (clone $emptyPrimary)
                ->whereNotNull('alternate_mobile_no')
                ->where('alternate_mobile_no', '!=', '')
                ->count(),
            'empty_primary_and_alt' => (clone $emptyPrimary)
                ->where(function ($q) {
                    $q->whereNull('alternate_mobile_no')->orWhere('alternate_mobile_no', '=', '');
                })
                ->count(),
            'empty_primary_with_remarks' => Schema::hasColumn('ca_masters', 'sales_remarks')
                ? (clone $emptyPrimary)
                    ->whereNotNull('sales_remarks')
                    ->where('sales_remarks', '!=', '')
                    ->count()
                : 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function promoteAlternateToPrimary(CaMaster $lead, bool $dryRun): array
    {
        $norm = $this->phones->normalize($lead->alternate_mobile_no);
        if ($norm === null || $this->phoneClassification->validateForSave($norm, 'mobile_no') !== null) {
            return [
                'status' => 'skipped_invalid_alt',
                'ca_id' => (int) $lead->ca_id,
                'firm_name' => $lead->firm_name,
                'csv_mobile' => null,
                'imported_mobile' => null,
                'reason' => 'alternate_not_valid_for_primary',
                'alternate_before' => $lead->alternate_mobile_no,
            ];
        }

        if (filled($lead->mobile_no)) {
            return [
                'status' => 'already_has_primary',
                'ca_id' => (int) $lead->ca_id,
                'firm_name' => $lead->firm_name,
                'csv_mobile' => null,
                'imported_mobile' => $lead->mobile_no,
                'reason' => 'primary_already_filled',
            ];
        }

        $owner = $this->phoneNumbers->findLeadByNormalizedNumber($norm, (int) $lead->ca_id);
        if ($owner !== null) {
            return [
                'status' => 'skipped_owned_elsewhere',
                'ca_id' => (int) $lead->ca_id,
                'firm_name' => $lead->firm_name,
                'csv_mobile' => null,
                'imported_mobile' => null,
                'reason' => 'phone_owned_by_ca_'.$owner->ca_id,
                'alternate_before' => $lead->alternate_mobile_no,
            ];
        }

        $beforeMobile = $lead->mobile_no;
        $beforeAlt = $lead->alternate_mobile_no;

        if (! $dryRun) {
            $lead->mobile_no = $beforeAlt;
            $lead->normalized_mobile = $norm;
            $lead->mobile_no_type = $this->phoneClassification->classify($beforeAlt);
            $lead->alternate_mobile_no = null;
            $lead->normalized_alternate_mobile = null;
            $lead->alternate_mobile_no_type = null;
            $lead->save();
            $this->duplicateLeadDetection->syncLeadPhones($lead->fresh());
        }

        return [
            'status' => 'recovered',
            'action' => 'promote_alternate_to_primary',
            'ca_id' => (int) $lead->ca_id,
            'firm_name' => $lead->firm_name,
            'csv_mobile' => $beforeAlt,
            'imported_mobile' => $beforeAlt,
            'mobile_before' => $beforeMobile,
            'alternate_before' => $beforeAlt,
            'sales_remarks_preserved' => true,
            'reason' => 'promoted_alternate_to_empty_primary',
        ];
    }

    /**
     * @param  array<string, mixed>  $csvRow
     * @return array<string, mixed>
     */
    private function fillPrimary(CaMaster $lead, string $normalized, string $display, string $action, bool $dryRun, array $csvRow): array
    {
        if (filled($lead->mobile_no)) {
            return [
                'status' => 'already_has_primary',
                'ca_id' => (int) $lead->ca_id,
                'firm_name' => $lead->firm_name,
                'csv_mobile' => $csvRow['raw_mobile'] ?? $display,
                'imported_mobile' => $lead->mobile_no,
                'reason' => 'primary_already_filled',
            ];
        }

        // If same number already on alternate, promote instead of duplicating.
        $altNorm = $this->phones->normalize($lead->alternate_mobile_no);
        if ($altNorm === $normalized) {
            return $this->promoteAlternateToPrimary($lead, $dryRun);
        }

        $owner = $this->phoneNumbers->findLeadByNormalizedNumber($normalized, (int) $lead->ca_id);
        if ($owner !== null) {
            return [
                'status' => 'skipped_owned_elsewhere',
                'ca_id' => (int) $lead->ca_id,
                'firm_name' => $lead->firm_name,
                'csv_mobile' => $csvRow['raw_mobile'] ?? $display,
                'imported_mobile' => null,
                'reason' => 'phone_owned_by_ca_'.$owner->ca_id,
            ];
        }

        if (! $dryRun) {
            $lead->mobile_no = $display;
            $lead->normalized_mobile = $normalized;
            $lead->mobile_no_type = $this->phoneClassification->classify($display);
            $lead->save();
            $this->duplicateLeadDetection->syncLeadPhones($lead->fresh());
        }

        return [
            'status' => 'recovered',
            'action' => $action,
            'ca_id' => (int) $lead->ca_id,
            'firm_name' => $lead->firm_name,
            'csv_mobile' => $csvRow['raw_mobile'] ?? $display,
            'csv_alt' => $csvRow['raw_alt'] ?? null,
            'imported_mobile' => $display,
            'mobile_before' => null,
            'sales_remarks_preserved' => true,
            'reason' => 'empty_filled_from_csv',
        ];
    }

    /**
     * @return list<array{firm: string, ca: string, mobile: ?string, alt: ?string, raw_mobile: string, raw_alt: string}>
     */
    private function readCsvPhoneRows(string $path): array
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw new RuntimeException('Unable to open CSV: '.$path);
        }
        $headers = fgetcsv($fh);
        if (! is_array($headers)) {
            fclose($fh);
            throw new RuntimeException('CSV has no header');
        }
        $headers = array_map(fn ($h) => trim((string) $h), $headers);
        $idx = static function (array $headers, string ...$names): ?int {
            foreach ($names as $name) {
                foreach ($headers as $i => $h) {
                    if (strtolower($h) === strtolower($name)) {
                        return $i;
                    }
                }
            }

            return null;
        };
        $iFirm = $idx($headers, 'Firm Name', 'firm_name');
        $iCa = $idx($headers, 'CA NAME', 'CA Name', 'ca_name');
        $iMobile = $idx($headers, 'Mobile No', 'Mobile', 'mobile_no');
        $iAlt = $idx($headers, 'Alternate Mobile No', 'Alt Mobile', 'alternate_mobile_no');

        $out = [];
        while (($data = fgetcsv($fh)) !== false) {
            $get = static function (?int $i) use ($data): string {
                return $i !== null && isset($data[$i]) ? trim((string) $data[$i]) : '';
            };
            $rawMobile = $get($iMobile);
            $rawAlt = $get($iAlt);
            $mobile = $this->phones->normalize($rawMobile);
            $alt = $this->phones->normalize($rawAlt);
            if ($mobile === null && $alt === null) {
                continue;
            }
            if ($mobile === null && $alt !== null) {
                $mobile = $alt;
                $rawMobile = $rawAlt !== '' ? $rawAlt : $alt;
                $alt = null;
                $rawAlt = '';
            }
            $out[] = [
                'firm' => $get($iFirm),
                'ca' => $get($iCa),
                'mobile' => $mobile,
                'alt' => $alt,
                'raw_mobile' => $rawMobile,
                'raw_alt' => $rawAlt,
            ];
        }
        fclose($fh);

        return $out;
    }

    private function firmKey(string $name): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]/i', '', $name));
    }
}

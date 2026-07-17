<?php

namespace App\Services\Mapping;

use App\Models\CaFirm;
use App\Models\CaMaster;
use App\Models\CaPartner;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Deduped partner upsert into ca_reference for mapped Master firms.
 */
class PartnerMappingService
{
    public function __construct(
        private readonly DataNormalizationService $normalizer,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $members
     */
    public function syncForMaster(CaMaster $lead, array $members): void
    {
        try {
            if (! Schema::connection('ca_reference')->hasTable('ca_firms')
                || ! Schema::connection('ca_reference')->hasTable('ca_partners')) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        try {
            $firm = $this->resolveReferenceFirm($lead);
            if (! $firm) {
                return;
            }

            foreach ($members as $member) {
                $name = trim((string) ($member['ca_name'] ?? ($member['partner_name'] ?? '')));
                if ($name === '') {
                    continue;
                }
                $membership = $this->normalizer->membershipNumber(
                    isset($member['membership_no']) ? (string) $member['membership_no'] : ($member['membership_number'] ?? null)
                );
                $query = CaPartner::query()->where('firm_id', $firm->id);
                $partner = $membership
                    ? (clone $query)->where('membership_number', $membership)->first()
                    : (clone $query)->whereRaw('LOWER(TRIM(partner_name)) = ?', [mb_strtolower($name)])->first();

                $attrs = [
                    'firm_id' => $firm->id,
                    'partner_name' => $name,
                    'membership_number' => $membership,
                    'mobile' => $this->normalizer->phone($member['mobile'] ?? null),
                    'email' => $this->normalizer->email($member['email'] ?? null),
                    'status' => 'active',
                ];

                if ($partner) {
                    $partner->fill(array_filter($attrs, fn ($v) => $v !== null && $v !== ''))->save();
                } else {
                    CaPartner::query()->create($attrs);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('mapping.partner_sync_failed', [
                'ca_id' => $lead->ca_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function resolveReferenceFirm(CaMaster $lead): ?CaFirm
    {
        $gst = $this->normalizer->gst($lead->gst_no);
        $frn = $this->normalizer->frn($lead->frn);
        $existing = null;
        if ($frn) {
            $existing = CaFirm::query()->where('frn', $frn)->first();
        }
        if (! $existing && $gst) {
            $existing = CaFirm::query()->where('gst_number', $gst)->first();
        }
        if ($existing) {
            return $existing;
        }

        return CaFirm::query()->create([
            'firm_name' => (string) ($lead->firm_name ?: $lead->ca_name),
            'frn' => $frn,
            'gst_number' => $gst,
            'email' => $this->normalizer->email($lead->email_id),
            'phone' => $this->normalizer->phone($lead->mobile_no),
            'address' => $lead->address,
            'pin_code' => $this->normalizer->postalCode($lead->pincode),
            'status' => 'active',
        ]);
    }
}

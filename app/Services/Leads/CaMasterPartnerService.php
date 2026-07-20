<?php

namespace App\Services\Leads;

use App\Models\CaMaster;
use App\Models\CaMasterPartner;
use App\Models\OcrParsedFirm;
use App\Services\Activity\ActivityLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Merchant Centre partner CAs (editable children of ca_masters).
 */
class CaMasterPartnerService
{
    public function __construct(
        private readonly ActivityLogService $activityLogService,
        private readonly PhoneNormalizationService $phoneNormalization,
        private readonly PhoneClassificationService $phoneClassification,
        private readonly DuplicateLeadDetectionService $duplicateLeadDetection,
    ) {}

    public function tableReady(): bool
    {
        try {
            return Schema::hasTable('ca_master_partners');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $members
     */
    public function syncFromMembers(CaMaster $lead, array $members): void
    {
        if (! $this->tableReady() || $members === []) {
            return;
        }

        DB::transaction(function () use ($lead, $members) {
            $keptIds = [];
            $seq = 0;
            $primarySet = false;
            foreach ($members as $i => $member) {
                $name = trim((string) ($member['ca_name'] ?? ($member['partner_name'] ?? '')));
                if ($name === '') {
                    continue;
                }
                $isPrimary = ! empty($member['is_primary']) || (! $primarySet && $i === 0);
                if ($isPrimary) {
                    $primarySet = true;
                }
                $membership = trim((string) ($member['membership_no'] ?? ($member['membership_number'] ?? '')));
                $mobile = $this->normalizeMobile($member['mobile'] ?? ($member['mobile_no'] ?? null));
                $email = trim((string) ($member['email'] ?? ($member['email_id'] ?? '')));
                $designation = trim((string) ($member['designation'] ?? ($member['role'] ?? '')));

                $existing = null;
                if ($membership !== '') {
                    $existing = CaMasterPartner::query()
                        ->where('ca_id', $lead->ca_id)
                        ->where('membership_no', $membership)
                        ->first();
                }
                if ($existing === null) {
                    $existing = CaMasterPartner::query()
                        ->where('ca_id', $lead->ca_id)
                        ->whereRaw('LOWER(TRIM(ca_name)) = ?', [mb_strtolower($name)])
                        ->first();
                }

                $attrs = [
                    'ca_id' => $lead->ca_id,
                    'ca_name' => $name,
                    'membership_no' => $membership !== '' ? $membership : null,
                    'mobile' => $mobile,
                    'alternate_mobile' => $this->normalizeMobile($member['alternate_mobile'] ?? ($member['alternate_mobile_no'] ?? null)),
                    'email' => $email !== '' ? $email : null,
                    'designation' => $designation !== '' ? $designation : null,
                    'is_primary' => $isPrimary,
                    'status' => 'active',
                    'sequence_no' => $seq++,
                ];

                if ($existing) {
                    $existing->fill($attrs)->save();
                    $keptIds[] = $existing->id;
                } else {
                    $created = CaMasterPartner::query()->create($attrs);
                    $keptIds[] = $created->id;
                }
            }

            if ($keptIds !== []) {
                CaMasterPartner::query()
                    ->where('ca_id', $lead->ca_id)
                    ->whereNotIn('id', $keptIds)
                    ->delete();
                $this->ensureSinglePrimary($lead->ca_id);
                $this->syncPrimaryOntoMaster($lead->fresh());
            }
        });
    }

    /**
     * Build partner member rows from an OCR staging firm (members table + source partners).
     *
     * @return list<array<string, mixed>>
     */
    public function membersFromOcrFirm(OcrParsedFirm $firm): array
    {
        $firm->loadMissing('members');
        $members = [];
        foreach ($firm->members as $i => $m) {
            $name = trim((string) ($m->ca_name ?: $m->raw_ca_name ?: ''));
            if ($name === '') {
                continue;
            }
            $members[] = [
                'ca_name' => $name,
                'membership_no' => $m->membership_no,
                'mobile' => $m->mobile,
                'email' => $m->email,
                'designation' => $m->role,
                'is_primary' => (bool) $m->is_primary,
            ];
        }
        if ($members !== []) {
            return $members;
        }

        $source = is_array($firm->source_data) ? $firm->source_data : [];
        $parsed = is_array($source['parsed'] ?? null) ? $source['parsed'] : [];
        $raw = is_array($source['raw'] ?? null) ? $source['raw'] : [];
        $primary = trim((string) ($parsed['ca_name'] ?? ($source['ca_name'] ?? ($raw['ca_name'] ?? ''))));
        $partnerNames = $parsed['partners'] ?? ($source['partners'] ?? ($raw['partners'] ?? []));
        if (! is_array($partnerNames)) {
            $partnerNames = [];
        }

        $out = [];
        if ($primary !== '') {
            $out[] = ['ca_name' => $primary, 'is_primary' => true];
        }
        foreach ($partnerNames as $name) {
            $name = trim((string) $name);
            if ($name === '' || ($primary !== '' && mb_strtolower($name) === mb_strtolower($primary))) {
                continue;
            }
            $out[] = ['ca_name' => $name, 'is_primary' => false];
        }

        return $out;
    }

    /**
     * Sync CRM partners for a master firm from its linked OCR staging row(s).
     */
    public function syncFromLinkedOcr(CaMaster $lead): int
    {
        if (! $this->tableReady() || ! Schema::hasTable('ocr_parsed_firms')) {
            return 0;
        }

        $query = OcrParsedFirm::query()
            ->with('members')
            ->where(function ($q) use ($lead) {
                $q->where('crm_ca_id', $lead->ca_id)->orWhere('matched_ca_id', $lead->ca_id);
            })
            ->orderByDesc('id');

        $firm = $query->first();
        if (! $firm) {
            return 0;
        }

        $members = $this->membersFromOcrFirm($firm);
        if ($members === []) {
            return 0;
        }

        // Partner FK still works for soft-deleted masters; restore visibility when OCR-linked.
        if (method_exists($lead, 'trashed') && $lead->trashed()) {
            $lead->restore();
        }

        $this->syncFromMembers($lead, $members);

        return CaMasterPartner::query()->where('ca_id', $lead->ca_id)->count();
    }

    /**
     * Ensure every firm has at least a primary partner row from ca_name.
     */
    public function ensurePrimaryFromMaster(CaMaster $lead): ?CaMasterPartner
    {
        if (! $this->tableReady()) {
            return null;
        }
        $existing = CaMasterPartner::query()->where('ca_id', $lead->ca_id)->count();
        if ($existing > 0) {
            $this->ensureSinglePrimary((int) $lead->ca_id);

            return CaMasterPartner::query()->where('ca_id', $lead->ca_id)->where('is_primary', true)->first();
        }
        $name = trim((string) ($lead->ca_name ?: ''));
        if ($name === '') {
            return null;
        }

        return CaMasterPartner::query()->create([
            'ca_id' => $lead->ca_id,
            'ca_name' => $name,
            'membership_no' => $lead->membership_no,
            'mobile' => $lead->mobile_no,
            'alternate_mobile' => $lead->alternate_mobile_no,
            'email' => $lead->email_id,
            'team_size' => 0,
            'is_primary' => true,
            'status' => 'active',
            'sequence_no' => 0,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(CaMaster $lead, array $data): CaMasterPartner
    {
        $this->assertTable();
        $payload = $this->normalizePartnerPayload($data, $lead);
        $partner = DB::transaction(function () use ($lead, $payload) {
            if (! empty($payload['is_primary'])) {
                CaMasterPartner::query()->where('ca_id', $lead->ca_id)->update(['is_primary' => false]);
            }
            $maxSeq = (int) CaMasterPartner::query()->where('ca_id', $lead->ca_id)->max('sequence_no');
            $payload['ca_id'] = $lead->ca_id;
            $payload['sequence_no'] = $maxSeq + 1;
            if (CaMasterPartner::query()->where('ca_id', $lead->ca_id)->count() === 0) {
                $payload['is_primary'] = true;
            }
            $created = CaMasterPartner::query()->create($payload);
            $this->ensureSinglePrimary((int) $lead->ca_id);
            $this->syncPrimaryOntoMaster($lead->fresh());

            return $created->fresh();
        });

        $this->activityLogService->log(
            'CA_MASTER',
            'Add Partner',
            (string) $lead->ca_id,
            ($lead->firm_name ?: $lead->ca_name).' — '.$partner->ca_name,
        );

        return $partner;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(CaMaster $lead, CaMasterPartner $partner, array $data): CaMasterPartner
    {
        $this->assertTable();
        if ((int) $partner->ca_id !== (int) $lead->ca_id) {
            throw ValidationException::withMessages(['partner' => 'Partner does not belong to this firm.']);
        }
        $payload = $this->normalizePartnerPayload($data, $lead, $partner);
        $before = $partner->only(['ca_name', 'membership_no', 'mobile', 'alternate_mobile', 'email', 'designation', 'is_primary']);

        $partner = DB::transaction(function () use ($lead, $partner, $payload) {
            if (! empty($payload['is_primary'])) {
                CaMasterPartner::query()->where('ca_id', $lead->ca_id)->where('id', '!=', $partner->id)->update(['is_primary' => false]);
            }
            $partner->fill($payload)->save();
            $this->ensureSinglePrimary((int) $lead->ca_id);
            $this->syncPrimaryOntoMaster($lead->fresh());

            return $partner->fresh();
        });

        $this->activityLogService->log(
            'CA_MASTER',
            'Update Partner',
            (string) $lead->ca_id,
            ($lead->firm_name ?: $lead->ca_name).' — '.$partner->ca_name,
            beforeValue: $before,
            afterValue: $partner->only(['ca_name', 'membership_no', 'mobile', 'alternate_mobile', 'email', 'designation', 'is_primary']),
        );

        return $partner;
    }

    public function delete(CaMaster $lead, CaMasterPartner $partner): void
    {
        $this->assertTable();
        if ((int) $partner->ca_id !== (int) $lead->ca_id) {
            throw ValidationException::withMessages(['partner' => 'Partner does not belong to this firm.']);
        }
        $name = $partner->ca_name;
        DB::transaction(function () use ($lead, $partner) {
            $wasPrimary = (bool) $partner->is_primary;
            $partner->delete();
            if ($wasPrimary) {
                $next = CaMasterPartner::query()->where('ca_id', $lead->ca_id)->orderBy('sequence_no')->first();
                if ($next) {
                    $next->update(['is_primary' => true]);
                }
            }
            $this->ensureSinglePrimary((int) $lead->ca_id);
            $this->syncPrimaryOntoMaster($lead->fresh());
        });

        $this->activityLogService->log(
            'CA_MASTER',
            'Remove Partner',
            (string) $lead->ca_id,
            ($lead->firm_name ?: $lead->ca_name).' — '.$name,
        );
    }

    public function setPrimary(CaMaster $lead, CaMasterPartner $partner): CaMasterPartner
    {
        return $this->update($lead, $partner, ['is_primary' => true]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateMobile(CaMaster $lead, CaMasterPartner $partner, array $data): CaMasterPartner
    {
        return $this->update($lead, $partner, [
            'mobile' => $data['mobile'] ?? ($data['mobile_no'] ?? null),
            'alternate_mobile' => $data['alternate_mobile'] ?? ($data['alternate_mobile_no'] ?? null),
        ]);
    }

    public function updateTeamSize(CaMaster $lead, CaMasterPartner $partner, int $teamSize): CaMasterPartner
    {
        $this->assertTable();
        if ((int) $partner->ca_id !== (int) $lead->ca_id) {
            throw ValidationException::withMessages(['partner' => 'Partner does not belong to this firm.']);
        }

        $before = max(0, (int) ($partner->team_size ?? 0));
        $teamSize = max(0, $teamSize);
        $partner->update(['team_size' => $teamSize]);
        $partner = $partner->fresh();

        $this->activityLogService->log(
            'CA_MASTER',
            'Update Partner Team Size',
            (string) $lead->ca_id,
            ($lead->firm_name ?: $lead->ca_name).' — '.$partner->ca_name.' ('.$before.' → '.$teamSize.')',
        );

        return $partner;
    }

    private function assertTable(): void
    {
        if (! $this->tableReady()) {
            throw ValidationException::withMessages([
                'partners' => 'Partner storage is not available. Run pending migrations.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizePartnerPayload(array $data, CaMaster $lead, ?CaMasterPartner $existing = null): array
    {
        $name = trim((string) ($data['ca_name'] ?? ($existing?->ca_name ?? '')));
        if ($name === '') {
            throw ValidationException::withMessages(['ca_name' => 'Partner name is required.']);
        }

        $payload = [
            'ca_name' => $name,
        ];
        if (array_key_exists('membership_no', $data)) {
            $mem = trim((string) $data['membership_no']);
            $payload['membership_no'] = $mem !== '' ? $mem : null;
        }
        if (array_key_exists('mobile', $data) || array_key_exists('mobile_no', $data)) {
            $raw = $data['mobile'] ?? $data['mobile_no'];
            if ($raw !== null && trim((string) $raw) !== '') {
                $this->phoneClassification->assertValidForSave($raw, 'mobile');
                $check = ['mobile_no' => $raw];
                $this->duplicateLeadDetection->assertNoDuplicatesForSave($check, $lead, auth()->user());
            }
            $payload['mobile'] = $this->normalizeMobile($raw);
        }
        if (array_key_exists('alternate_mobile', $data) || array_key_exists('alternate_mobile_no', $data)) {
            $raw = $data['alternate_mobile'] ?? $data['alternate_mobile_no'];
            if ($raw !== null && trim((string) $raw) !== '') {
                $this->phoneClassification->assertValidForSave($raw, 'alternate_mobile');
            }
            $payload['alternate_mobile'] = $this->normalizeMobile($raw);
        }
        if (array_key_exists('email', $data)) {
            $email = trim((string) $data['email']);
            $payload['email'] = $email !== '' ? $email : null;
        }
        if (array_key_exists('designation', $data)) {
            $des = trim((string) $data['designation']);
            $payload['designation'] = $des !== '' ? $des : null;
        }
        if (array_key_exists('is_primary', $data)) {
            $payload['is_primary'] = (bool) $data['is_primary'];
        }
        if (array_key_exists('status', $data)) {
            $payload['status'] = trim((string) $data['status']) ?: 'active';
        }
        if (array_key_exists('team_size', $data)) {
            $payload['team_size'] = max(0, (int) $data['team_size']);
        } elseif ($existing === null) {
            $payload['team_size'] = 0;
        }

        return $payload;
    }

    private function normalizeMobile(mixed $raw): ?string
    {
        if ($raw === null || trim((string) $raw) === '') {
            return null;
        }
        $digits = $this->phoneNormalization->normalize((string) $raw);

        return $digits !== null && $digits !== '' ? $digits : trim((string) $raw);
    }

    private function ensureSinglePrimary(int $caId): void
    {
        $primaries = CaMasterPartner::query()->where('ca_id', $caId)->where('is_primary', true)->orderBy('id')->get();
        if ($primaries->count() > 1) {
            $keep = $primaries->first();
            CaMasterPartner::query()
                ->where('ca_id', $caId)
                ->where('is_primary', true)
                ->where('id', '!=', $keep->id)
                ->update(['is_primary' => false]);
        } elseif ($primaries->count() === 0) {
            $first = CaMasterPartner::query()->where('ca_id', $caId)->orderBy('sequence_no')->orderBy('id')->first();
            if ($first) {
                $first->update(['is_primary' => true]);
            }
        }
    }

    private function syncPrimaryOntoMaster(?CaMaster $lead): void
    {
        if ($lead === null) {
            return;
        }
        $primary = CaMasterPartner::query()->where('ca_id', $lead->ca_id)->where('is_primary', true)->first();
        if ($primary === null) {
            return;
        }
        $updates = ['ca_name' => $primary->ca_name];
        if ($primary->membership_no) {
            $updates['membership_no'] = $primary->membership_no;
        }
        if ($primary->mobile && (trim((string) $lead->mobile_no) === '' || $lead->mobile_no === null)) {
            $updates['mobile_no'] = $primary->mobile;
        }
        $lead->fill($updates)->save();
    }
}

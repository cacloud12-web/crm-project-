<?php

namespace App\Services\Communication;

use App\Models\CaMaster;
use App\Models\ConsentTracking;
use App\Models\DndManagement;

class CommunicationEligibilityService
{
    public const CHANNEL_WHATSAPP = 'WhatsApp';

    public const CHANNEL_EMAIL = 'Email';

    public const CHANNEL_SMS = 'SMS';

    public const SKIP_NO_CONSENT = 'no_consent';

    public const SKIP_DND = 'dnd_optout';

    public const SKIP_MISSING_MOBILE = 'missing_mobile';

    public const SKIP_MISSING_EMAIL = 'missing_email';

    public const SKIP_INVALID_CHANNEL = 'invalid_channel';

    private const VALID_CHANNELS = [
        self::CHANNEL_WHATSAPP,
        self::CHANNEL_EMAIL,
        self::CHANNEL_SMS,
    ];

    private const CHANNEL_DND_MAP = [
        self::CHANNEL_WHATSAPP => 'WA',
        self::CHANNEL_EMAIL => 'Email',
        self::CHANNEL_SMS => 'SMS',
    ];

    public function assess(?CaMaster $lead, string $channel, bool $requireConsent = true): array
    {
        if (! in_array($channel, self::VALID_CHANNELS, true)) {
            return $this->ineligible(self::SKIP_INVALID_CHANNEL);
        }

        if (! $lead) {
            return $this->ineligible(self::SKIP_INVALID_CHANNEL);
        }

        if (in_array($channel, [self::CHANNEL_WHATSAPP, self::CHANNEL_SMS], true)
            && ! $this->hasMobile($lead->mobile_no)) {
            return $this->ineligible(self::SKIP_MISSING_MOBILE);
        }

        if ($channel === self::CHANNEL_EMAIL && ! $this->hasEmail($lead->email_id)) {
            return $this->ineligible(self::SKIP_MISSING_EMAIL);
        }

        if ($this->isOnDnd($lead, $channel)) {
            return $this->ineligible(self::SKIP_DND);
        }

        if ($requireConsent && ! $this->hasConsent($lead->ca_id, $channel)) {
            return $this->ineligible(self::SKIP_NO_CONSENT);
        }

        return ['eligible' => true, 'skip_reason' => null];
    }

    /**
     * Campaign sends: selecting a lead as campaign audience is treated as send intent.
     * DND and contact validity still block delivery.
     */
    public function assessForCampaign(?CaMaster $lead, string $channel): array
    {
        return $this->assess($lead, $channel, requireConsent: false);
    }

    public function assessByCaId(int $caId, string $channel): array
    {
        $lead = CaMaster::query()->find($caId);

        return $this->assess($lead, $channel);
    }

    public function skipReasonLabel(string $reason): string
    {
        return match ($reason) {
            self::SKIP_NO_CONSENT => 'No consent',
            self::SKIP_DND => 'DND / opt-out',
            self::SKIP_MISSING_MOBILE => 'Missing mobile',
            self::SKIP_MISSING_EMAIL => 'Missing email',
            self::SKIP_INVALID_CHANNEL => 'Invalid channel',
            default => $reason,
        };
    }

    private function hasConsent(int $caId, string $channel): bool
    {
        $consent = ConsentTracking::query()
            ->where('ca_id', $caId)
            ->where('consent_type', $channel)
            ->first();

        return $consent?->consent_status === 'Yes';
    }

    private function isOnDnd(CaMaster $lead, string $channel): bool
    {
        $dndType = self::CHANNEL_DND_MAP[$channel];

        return DndManagement::query()
            ->where(function ($query) use ($lead) {
                $query->where('ca_id', $lead->ca_id);

                if ($this->hasMobile($lead->mobile_no)) {
                    $query->orWhere('mobile_no', $lead->mobile_no);
                }

                if ($this->hasEmail($lead->email_id)) {
                    $query->orWhere('email_id', $lead->email_id);
                }
            })
            ->where(function ($query) use ($dndType) {
                $query->where('dnd_type', 'All')
                    ->orWhere('dnd_type', $dndType);
            })
            ->exists();
    }

    private function hasMobile(?string $mobile): bool
    {
        return is_string($mobile) && trim($mobile) !== '';
    }

    private function hasEmail(?string $email): bool
    {
        return is_string($email) && trim($email) !== '';
    }

    private function ineligible(string $reason): array
    {
        return ['eligible' => false, 'skip_reason' => $reason];
    }
}

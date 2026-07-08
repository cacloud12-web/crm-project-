<?php

namespace App\Services\FollowUp\Concerns;

use App\Models\CaMaster;
use App\Models\FollowUp;
use App\Services\DemoConfirmation\DemoConfirmationService;
use App\Support\Demo\DemoProviderResolver;

trait ResolvesFollowUpDemoFields
{
    /**
     * @param  array<string, mixed>  $data
     * @return array{team_size: ?int, demo_provider_name: ?string, meeting_link: ?string}
     */
    protected function resolveFollowUpDemoFields(array $data, ?FollowUp $existing = null): array
    {
        $type = (string) ($data['followup_type'] ?? $existing?->followup_type ?? '');
        if ($type !== DemoConfirmationService::DEMO_FOLLOWUP_TYPE) {
            return [
                'team_size' => null,
                'demo_provider_name' => null,
                'meeting_link' => null,
            ];
        }

        $caId = (int) ($data['ca_id'] ?? $existing?->ca_id ?? 0);
        $previousTeamSize = $existing?->team_size;
        $teamSize = $this->resolveTeamSizeValue($data, $existing, $caId);
        $teamSizeChanged = $existing !== null
            && array_key_exists('team_size', $data)
            && (int) ($teamSize ?? 0) !== (int) ($previousTeamSize ?? 0);

        $provider = array_key_exists('demo_provider_name', $data)
            ? ($data['demo_provider_name'] !== '' ? (string) $data['demo_provider_name'] : null)
            : $existing?->demo_provider_name;
        $link = array_key_exists('meeting_link', $data)
            ? ($data['meeting_link'] !== '' ? (string) $data['meeting_link'] : null)
            : $existing?->meeting_link;

        if ($teamSize !== null && $teamSize > 0) {
            $resolved = DemoProviderResolver::resolve($teamSize);
            if ($resolved) {
                if ($existing === null || $teamSizeChanged) {
                    $provider = $resolved['provider'];
                    $link = $resolved['meeting_link'];
                }
            }
        }

        return [
            'team_size' => $teamSize,
            'demo_provider_name' => $provider,
            'meeting_link' => $link,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveTeamSizeValue(array $data, ?FollowUp $existing, int $caId): ?int
    {
        if (array_key_exists('team_size', $data)) {
            $value = $data['team_size'];
            if ($value === null || $value === '') {
                return null;
            }

            return max(1, (int) $value);
        }

        if ($existing?->team_size) {
            return (int) $existing->team_size;
        }

        if ($caId > 0) {
            $fromLead = CaMaster::query()->where('ca_id', $caId)->value('team_size');
            if ($fromLead !== null && (int) $fromLead > 0) {
                return (int) $fromLead;
            }
        }

        return null;
    }
}

<?php

namespace App\Support\Demo;

use App\Models\DemoProvider;

class DemoProviderResolver
{
    /**
     * @return array{provider: string, meeting_link: string, demo_provider_id?: int}|null
     */
    public static function resolve(?int $teamSize): ?array
    {
        if ($teamSize === null || $teamSize < 1) {
            return null;
        }

        $fromDb = DemoProvider::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->first(function (DemoProvider $provider) use ($teamSize) {
                $min = $provider->min_team_size !== null ? (int) $provider->min_team_size : 1;
                $max = $provider->max_team_size !== null ? (int) $provider->max_team_size : null;
                if ($teamSize < $min) {
                    return false;
                }

                return $max === null || $teamSize <= $max;
            });

        if ($fromDb) {
            return [
                'provider' => $fromDb->name,
                'meeting_link' => (string) ($fromDb->default_meeting_link ?? ''),
                'demo_provider_id' => $fromDb->id,
            ];
        }

        foreach (config('demo_providers.tiers', []) as $tier) {
            $min = (int) ($tier['min'] ?? 1);
            $max = $tier['max'] ?? null;

            if ($teamSize < $min) {
                continue;
            }

            if ($max !== null && $teamSize > (int) $max) {
                continue;
            }

            return [
                'provider' => (string) ($tier['provider'] ?? ''),
                'meeting_link' => (string) ($tier['meeting_link'] ?? ''),
            ];
        }

        return null;
    }
}

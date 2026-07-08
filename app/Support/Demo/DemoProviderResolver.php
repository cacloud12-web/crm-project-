<?php

namespace App\Support\Demo;

class DemoProviderResolver
{
    /**
     * @return array{provider: string, meeting_link: string}|null
     */
    public static function resolve(?int $teamSize): ?array
    {
        if ($teamSize === null || $teamSize < 1) {
            return null;
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

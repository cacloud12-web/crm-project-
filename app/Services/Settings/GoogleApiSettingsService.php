<?php

namespace App\Services\Settings;

use App\Models\CrmSetting;
use App\Models\User;
use App\Services\Activity\ActivityLogService;
use App\Services\Leads\GooglePlacesApiService;
use App\Services\Rbac\RbacService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;

class GoogleApiSettingsService
{
    private const GROUP = 'google_api';

    private const KEY = 'places_api_key';

    private const CACHE_KEY = 'crm:settings:google_api';

    public function __construct(
        private readonly ActivityLogService $activityLogService,
        private readonly RbacService $rbacService,
    ) {}

    public function ensureCanView(?User $user): void
    {
        if (! $user || ! $this->rbacService->can($user, 'settings', 'view')) {
            throw new AuthorizationException('You do not have permission to access this action.');
        }
    }

    public function ensureCanManage(?User $user): void
    {
        if (! $user || ! $this->rbacService->can($user, 'settings', 'edit')) {
            throw new AuthorizationException('You do not have permission to access this action.');
        }
    }

    /**
     * @return array{places_api_key_configured: bool, places_api_key_masked: string|null, source: string}
     */
    public function toPublicArray(): array
    {
        $stored = $this->storedKey();
        $envKey = (string) (config('services.google.places_api_key')
            ?: config('crm_research.google_places_api_key'));
        $effective = filled($envKey) ? $envKey : ($stored ?: '');

        return [
            'places_api_key_configured' => filled($effective),
            'places_api_key_masked' => $effective ? $this->maskKey($effective) : null,
            'source' => filled($envKey) ? 'environment' : ($stored ? 'database' : 'none'),
            'stored_key_configured' => filled($stored),
        ];
    }

    public function resolvedApiKey(): string
    {
        $envKey = (string) (config('services.google.places_api_key')
            ?: config('crm_research.google_places_api_key'));
        if (filled($envKey)) {
            return $envKey;
        }

        return $this->storedKey() ?: '';
    }

    /**
     * @return array<string, mixed>
     */
    public function update(array $data, ?User $user): array
    {
        $this->ensureCanManage($user);

        if (array_key_exists('places_api_key', $data)) {
            $key = trim((string) ($data['places_api_key'] ?? ''));
            if ($key === '') {
                CrmSetting::query()
                    ->where('group', self::GROUP)
                    ->where('key', self::KEY)
                    ->delete();
            } else {
                CrmSetting::query()->updateOrCreate(
                    ['group' => self::GROUP, 'key' => self::KEY],
                    ['value' => $key],
                );
            }
            Cache::forget(self::CACHE_KEY);
            Cache::forget('crm:settings:all');
        }

        $this->activityLogService->log(
            'SETTINGS',
            'Google API Settings Updated',
            'google_api',
            'Google Places API key updated',
            $user?->name ?? $user?->email ?? 'System',
        );

        return $this->toPublicArray();
    }

    /**
     * @return array{valid: bool, message: string, sample_results: int}
     */
    public function testConnection(GooglePlacesApiService $placesApi): array
    {
        $probe = $placesApi->probe('CA firm Mumbai');

        return [
            'valid' => (bool) ($probe['success'] ?? false),
            'message' => (string) ($probe['message'] ?? 'Google Places API test failed.'),
            'sample_results' => (int) ($probe['sample_results'] ?? 0),
            'http_status' => $probe['http_status'] ?? null,
            'google_status' => $probe['google_status'] ?? null,
            'google_reason' => $probe['google_reason'] ?? null,
            'recommendation' => $probe['recommendation'] ?? null,
            'endpoint' => $probe['endpoint'] ?? null,
            'api_key_masked' => $probe['api_key_masked'] ?? null,
            'api_key_source' => $probe['api_key_source'] ?? null,
        ];
    }

    private function storedKey(): ?string
    {
        return Cache::remember(self::CACHE_KEY, 300, function () {
            $value = CrmSetting::query()
                ->where('group', self::GROUP)
                ->where('key', self::KEY)
                ->value('value');

            return filled($value) ? (string) $value : null;
        });
    }

    private function maskKey(string $key): string
    {
        $len = strlen($key);
        if ($len <= 8) {
            return str_repeat('•', $len);
        }

        return substr($key, 0, 4).str_repeat('•', max(4, $len - 8)).substr($key, -4);
    }
}

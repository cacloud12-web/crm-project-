<?php

namespace App\Support\Ocr;

use App\Models\CaMaster;
use App\Models\City;
use Illuminate\Support\Facades\Schema;

/**
 * City / data_quality_issue helpers for OCR Needs Verification masters.
 */
final class CaMasterCityQuality
{
    public const PLACEHOLDER_CITY_NAME = 'UNKNOWN - CITY NOT IN SOURCE';

    public const ISSUE_MISSING_CITY = 'missing_city';

    public const ISSUE_MISSING_CA = 'missing_ca_name';

    public static function isPlaceholderCityName(?string $cityName): bool
    {
        $name = strtoupper(trim((string) $cityName));

        return $name !== '' && $name === strtoupper(self::PLACEHOLDER_CITY_NAME);
    }

    public static function hasLinkedRealCity(CaMaster $master): bool
    {
        $cityId = $master->city_id !== null ? (int) $master->city_id : 0;
        if ($cityId < 1) {
            return false;
        }

        $name = null;
        if ($master->relationLoaded('city')) {
            $name = $master->city?->city_name;
        } elseif (Schema::hasTable('cities')) {
            $name = City::query()->where('city_id', $cityId)->value('city_name');
        }

        return ! self::isPlaceholderCityName($name !== null ? (string) $name : null);
    }

    /**
     * True when Master shows a usable city (linked non-placeholder, or OCR city text).
     */
    public static function hasDisplayableCity(?string $displayCity, CaMaster $master): bool
    {
        $display = trim((string) ($displayCity ?? ''));
        if ($display !== ''
            && ! self::isPlaceholderCityName($display)
            && ! in_array(strtolower($display), ['—', '-', 'city missing'], true)) {
            return true;
        }

        return self::hasLinkedRealCity($master);
    }

    /**
     * Effective quality issue for UI — never report missing_city when a real city is shown/linked.
     */
    public static function effectiveDataQualityIssue(CaMaster $master, ?string $displayCity = null): ?string
    {
        $issue = $master->data_quality_issue ? trim((string) $master->data_quality_issue) : null;
        if ($issue === null || $issue === '') {
            return null;
        }

        if ($issue === self::ISSUE_MISSING_CITY && self::hasDisplayableCity($displayCity, $master)) {
            if (trim((string) ($master->ca_name ?? '')) === '') {
                return self::ISSUE_MISSING_CA;
            }

            return null;
        }

        return $issue;
    }

    /**
     * After city_id is set to a real city, clear stale missing_city flags on the model (unsaved).
     *
     * @return array<string, mixed> attributes to persist
     */
    public static function attributesAfterRealCityLinked(CaMaster $master): array
    {
        if (! Schema::hasColumn('ca_masters', 'data_quality_issue')) {
            return [];
        }

        $issue = trim((string) ($master->data_quality_issue ?? ''));
        if ($issue !== self::ISSUE_MISSING_CITY) {
            return [];
        }

        $attrs = [];
        if (trim((string) ($master->ca_name ?? '')) === '') {
            $attrs['data_quality_issue'] = self::ISSUE_MISSING_CA;
            if (Schema::hasColumn('ca_masters', 'data_quality_status')) {
                $attrs['data_quality_status'] = 'incomplete';
            }
        } else {
            $attrs['data_quality_issue'] = null;
            if (Schema::hasColumn('ca_masters', 'data_quality_status')) {
                $attrs['data_quality_status'] = 'complete';
            }
        }

        return $attrs;
    }
}

<?php

namespace App\Services\Ocr;

/**
 * Shared canonical city resolver for PROP and PART OCR profiles.
 * Prefers master/alias evidence over loose place-signal heuristics.
 */
class OcrCityResolverService
{
    /** @var array<string, string>|null */
    private static ?array $aliasMap = null;

    /** @var array<string, true>|null */
    private static ?array $directorySet = null;

    /** @var array<string, true>|null */
    private static ?array $roadSet = null;

    /** @var array<string, true>|null */
    private static ?array $masterCache = null;

    public function resolve(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $stripped = $this->stripDecorations($raw);
        if ($stripped === null || $this->isForbiddenLocalityShape($stripped)) {
            return null;
        }

        $key = mb_strtolower($stripped);
        $joined = mb_strtolower(preg_replace('/\s+/u', '', $stripped) ?? $stripped);

        // Only ABU ROAD may contain the word ROAD (spaced or OCR-glued).
        if ((preg_match('/\broad\b/iu', $stripped) || preg_match('/road$/iu', $joined))
            && ! $this->isApprovedRoadCity($key) && ! $this->isApprovedRoadCity($joined) && $joined !== 'aburoad') {
            return null;
        }
        if ($joined === 'aburoad') {
            return $this->hit($raw, 'ABU ROAD', 'approved_road_city', 0.93);
        }

        $aliases = $this->aliases();
        if (isset($aliases[$key])) {
            $canon = $aliases[$key];
            if ($this->isForbiddenLocalityShape($canon)) {
                return null;
            }

            return $this->hit($raw, $canon, 'alias', 0.95);
        }
        if (isset($aliases[$joined])) {
            $canon = $aliases[$joined];
            if ($this->isForbiddenLocalityShape($canon)) {
                return null;
            }

            return $this->hit($raw, $canon, 'alias_joined', 0.94);
        }

        if ($this->isApprovedRoadCity($key)) {
            return $this->hit($raw, mb_strtoupper($stripped), 'approved_road_city', 0.93);
        }

        if ($this->inDirectoryList($key) || $this->inDirectoryList($joined)) {
            $canon = $aliases[$joined] ?? $aliases[$key] ?? mb_strtoupper($stripped);
            if ($this->isForbiddenLocalityShape($canon)) {
                return null;
            }

            return $this->hit($raw, $canon, 'directory_list', 0.9);
        }

        if ($this->inMaster($key) || $this->inMaster($joined)) {
            $name = $this->masterDisplayName($key) ?? $this->masterDisplayName($joined) ?? mb_strtoupper($stripped);
            if ($this->isForbiddenLocalityShape((string) $name)) {
                return null;
            }

            return $this->hit($raw, mb_strtoupper($name), 'city_master', 0.92);
        }

        // Single-token ICAI place names with classic suffixes (ADIPUR, AHILYANAGAR) —
        // never multi-word * ROAD streets.
        $words = preg_split('/\s+/u', $stripped) ?: [];
        if (count($words) === 1 && $this->hasSafePlaceSuffix($words[0]) && ! preg_match('/\d/u', $words[0])) {
            return $this->hit($raw, mb_strtoupper($stripped), 'place_suffix', 0.78);
        }

        return null;
    }

    /**
     * Final gate for persisted/display city — null out streets, districts, localities.
     */
    public function sanitizeCity(?string $city): ?string
    {
        $city = trim((string) $city);
        if ($city === '') {
            return null;
        }
        if ($this->isForbiddenLocalityShape($city)) {
            return null;
        }
        $canonical = $this->canonical($city);
        if ($canonical === null) {
            // Already-stored bad values (BALKESHWAR ROAD / MIRAROAD) must clear.
            $compact = preg_replace('/\s+/u', '', mb_strtolower($city)) ?? '';
            if (preg_match('/\b(?:road|street|lane|marg|sadak|paraganas)\b/iu', $city)
                || preg_match('/(?:road|street|lane|marg|sadak|paraganas)$/u', $compact)) {
                if ($compact !== 'aburoad' && mb_strtolower(trim($city)) !== 'abu road') {
                    return null;
                }
            }

            return $city;
        }
        if ($this->isForbiddenLocalityShape($canonical)) {
            return null;
        }

        return $canonical;
    }

    /** Streets / district labels that must never become City. */
    public function isForbiddenLocalityShape(string $text): bool
    {
        $t = trim($text);
        if ($t === '') {
            return true;
        }
        $lower = mb_strtolower($t);
        $compact = preg_replace('/\s+/u', '', $lower) ?? $lower;
        if (str_contains($lower, 'paraganas') || str_contains($compact, 'paraganas')) {
            return true;
        }
        // Spaced or OCR-glued roads: "BALKESHWAR ROAD", "MIRAROAD", "tagoreroad".
        $looksLikeRoad = (bool) preg_match('/\b(?:road|street|lane|marg|sadak)\b/u', $lower)
            || (bool) preg_match('/(?:road|street|lane|marg|sadak)$/u', $compact);
        if ($looksLikeRoad) {
            if ($this->isApprovedRoadCity($lower) || $this->isApprovedRoadCity($compact)
                || $compact === 'aburoad') {
                return false;
            }

            return true;
        }
        if (preg_match('/\b(?:floor|plot|ward|shop|house|near|opp|hospital|school|society|apartment|complex)\b/u', $lower)) {
            return true;
        }

        return false;
    }

    public function canonical(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        $hit = $this->resolve($raw);

        return $hit['canonical_city'] ?? null;
    }

    public function isResolvableCity(?string $raw): bool
    {
        return $this->canonical($raw) !== null;
    }

    private function stripDecorations(string $raw): ?string
    {
        $raw = trim($raw);
        if (preg_match('/^(.+?)\s*\(([MCWE])\)\s*$/iu', $raw, $m)) {
            $raw = trim($m[1]);
        }
        if (preg_match('/^(.+?)\s*[-–]\s*\d{5,6}[A-Z]?\s*$/u', $raw, $m)) {
            $raw = trim($m[1]);
        }
        $raw = trim((string) preg_replace('/\s+/u', ' ', $raw));

        return $raw !== '' ? $raw : null;
    }

    /** @return array{raw_city_heading: string, canonical_city: string, city_match_type: string, city_confidence: float} */
    private function hit(string $raw, string $canonical, string $type, float $confidence): array
    {
        return [
            'raw_city_heading' => $raw,
            'canonical_city' => mb_strtoupper(trim($canonical)),
            'normalized_city' => mb_strtolower(trim($canonical)),
            'city_match_type' => $type,
            'city_confidence' => $confidence,
        ];
    }

    private function hasSafePlaceSuffix(string $token): bool
    {
        $w = mb_strtolower($token);
        foreach (['nagar', 'pur', 'bad', 'garh', 'ganj', 'vihar', 'bagh', 'cantt', 'pete', 'halli'] as $suffix) {
            if (str_ends_with($w, $suffix) && mb_strlen($w) > mb_strlen($suffix) + 2) {
                return true;
            }
        }

        return false;
    }

    private function isApprovedRoadCity(string $lower): bool
    {
        return isset($this->roadCities()[$lower]);
    }

    private function inDirectoryList(string $lower): bool
    {
        return isset($this->directoryCities()[$lower]);
    }

    private function inMaster(string $lower): bool
    {
        return isset($this->masterCities()[$lower]);
    }

    private function masterDisplayName(string $lower): ?string
    {
        $this->masterCities();
        foreach (self::$masterCache ?? [] as $name => $_) {
            if ($name === $lower) {
                return $name;
            }
        }

        try {
            if (function_exists('app') && app()->bound('config')) {
                return \App\Models\City::query()
                    ->whereRaw('LOWER(city_name) = ?', [$lower])
                    ->value('city_name');
            }
        } catch (\Throwable) {
        }

        return null;
    }

    /** @return array<string, string> */
    private function aliases(): array
    {
        if (self::$aliasMap !== null) {
            return self::$aliasMap;
        }
        $map = [];
        foreach ($this->configArray('aliases', $this->defaultAliases()) as $from => $to) {
            $map[mb_strtolower((string) $from)] = (string) $to;
        }
        self::$aliasMap = $map;

        return self::$aliasMap;
    }

    /** @return array<string, true> */
    private function directoryCities(): array
    {
        if (self::$directorySet !== null) {
            return self::$directorySet;
        }
        $set = [];
        foreach ($this->configArray('directory_cities', $this->defaultDirectoryCities()) as $city) {
            $set[mb_strtolower((string) $city)] = true;
        }
        self::$directorySet = $set;

        return self::$directorySet;
    }

    /** @return array<string, true> */
    private function roadCities(): array
    {
        if (self::$roadSet !== null) {
            return self::$roadSet;
        }
        $set = [];
        foreach ($this->configArray('approved_road_cities', ['abu road']) as $city) {
            $set[mb_strtolower((string) $city)] = true;
        }
        self::$roadSet = $set;

        return self::$roadSet;
    }

    /** @param  list<string>|array<string, string>  $fallback */
    private function configArray(string $key, array $fallback): array
    {
        try {
            if (function_exists('config')) {
                $value = config('ocr_city_aliases.'.$key);
                if (is_array($value) && $value !== []) {
                    return $value;
                }
            }
        } catch (\Throwable) {
        }

        return $fallback;
    }

    /** @return array<string, string> */
    private function defaultAliases(): array
    {
        return [
            'ahily nagar' => 'AHILYANAGAR',
            'ahilya nagar' => 'AHILYANAGAR',
            'ahilyanagar' => 'AHILYANAGAR',
            'ahmed nagar' => 'AHMEDNAGAR',
            'abu road' => 'ABU ROAD',
            'aburoad' => 'ABU ROAD',
            'ambala city' => 'AMBALA',
            'ambala cantt' => 'AMBALA CANTT',
            'new delhi' => 'NEW DELHI',
            'bengaluru' => 'BENGALURU',
            'bangalore' => 'BENGALURU',
            'mumbai' => 'MUMBAI',
            'kolkata' => 'KOLKATA',
            'chennai' => 'CHENNAI',
            'hyderabad' => 'HYDERABAD',
            'pune' => 'PUNE',
        ];
    }

    /** @return list<string> */
    private function defaultDirectoryCities(): array
    {
        return [
            'abohar', 'adipur', 'ahilyanagar', 'ahmednagar', 'ahmedabad', 'ambala', 'ambala cantt',
            'amritsar', 'bengaluru', 'chandigarh', 'chennai', 'delhi', 'new delhi', 'hyderabad',
            'jaipur', 'kolkata', 'ludhiana', 'mumbai', 'patna', 'pune', 'ranchi', 'surat',
            'abu road', 'supaul', 'sangamner', 'bhatpara', 'barrackpore', 'dhanbad',
            'abhoynagar', 'dakshineswar',
            'siddhiashram', 'agartala', 'naraingarh', 'ahmedgarh',
        ];
    }

    /** @return array<string, true> */
    private function masterCities(): array
    {
        if (self::$masterCache !== null) {
            return self::$masterCache;
        }
        $set = [];
        try {
            if (function_exists('app') && app()->bound('config')) {
                foreach (\App\Models\City::query()->pluck('city_name') as $name) {
                    $set[mb_strtolower((string) $name)] = true;
                }
            }
        } catch (\Throwable) {
        }
        self::$masterCache = $set;

        return self::$masterCache;
    }

    /** Clear static caches (tests). */
    public static function clearCache(): void
    {
        self::$aliasMap = null;
        self::$directorySet = null;
        self::$roadSet = null;
        self::$masterCache = null;
    }
}

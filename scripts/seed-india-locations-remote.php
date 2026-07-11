#!/usr/bin/env php
<?php

/**
 * Seed India states/cities onto a remote CRM via authenticated HTTP API.
 * Usage: php scripts/seed-india-locations-remote.php https://crm.caclouddesk.com superadmin@ca.local password
 */

declare(strict_types=1);

if ($argc < 4) {
    fwrite(STDERR, "Usage: php scripts/seed-india-locations-remote.php <base_url> <email> <password>\n");
    exit(1);
}

[$script, $baseUrl, $email, $password] = $argv;
$baseUrl = rtrim($baseUrl, '/');
$dataset = require dirname(__DIR__).'/database/data/india_states_cities.php';

$cookieFile = tempnam(sys_get_temp_dir(), 'crm_cookies_');
$resolve = getenv('CRM_CURL_RESOLVE') ?: 'crm.caclouddesk.com:443:104.21.84.170';

function http(string $method, string $url, ?array $json, string $cookieFile, string $resolve, array $extraHeaders = []): array
{
    $ch = curl_init($url);
    $headers = array_merge([
        'Accept: application/json',
        'X-Requested-With: XMLHttpRequest',
    ], $extraHeaders);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_RESOLVE => [$resolve],
        CURLOPT_PROXY => '',
        CURLOPT_NOPROXY => '*',
    ]);

    if ($json !== null) {
        $body = json_encode($json, JSON_UNESCAPED_UNICODE);
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $raw = curl_exec($ch);
    if ($raw === false) {
        throw new RuntimeException(curl_error($ch));
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $headerText = substr($raw, 0, $headerSize);
    $bodyText = substr($raw, $headerSize);

    return [$status, $headerText, $bodyText];
}

function extractXsrf(string $cookieFile): ?string
{
    if (! is_file($cookieFile)) {
        return null;
    }
    foreach (file($cookieFile) as $line) {
        if (str_contains($line, 'XSRF-TOKEN')) {
            $parts = preg_split('/\s+/', trim($line));
            $value = urldecode((string) end($parts));

            return $value;
        }
    }

    return null;
}

echo "Logging in to {$baseUrl}...\n";
[, , $loginHtml] = http('GET', $baseUrl.'/login', null, $cookieFile, $resolve);
if (! preg_match('/name="csrf-token" content="([^"]+)"/', $loginHtml, $m)
    && ! preg_match('/name="_token" value="([^"]+)"/', $loginHtml, $m)) {
    throw new RuntimeException('CSRF token not found on login page');
}
$csrf = $m[1];
$xsrf = extractXsrf($cookieFile) ?: $csrf;

[$loginStatus, , $loginBody] = http('POST', $baseUrl.'/login', [
    'email' => $email,
    'password' => $password,
    '_token' => $csrf,
], $cookieFile, $resolve, [
    'X-CSRF-TOKEN: '.$csrf,
    'X-XSRF-TOKEN: '.$xsrf,
]);

$loginJson = json_decode($loginBody, true);
if ($loginStatus >= 400 || ! ($loginJson['success'] ?? false)) {
    throw new RuntimeException("Login failed ({$loginStatus}): ".$loginBody);
}

$xsrf = extractXsrf($cookieFile) ?: $csrf;
echo "Login OK. Seeding ".count($dataset)." states...\n";

$createdStates = 0;
$createdCities = 0;
$skipped = 0;

foreach ($dataset as $stateName => $cities) {
    [$status, , $body] = http('POST', $baseUrl.'/states', [
        'state_name' => $stateName,
    ], $cookieFile, $resolve, [
        'X-CSRF-TOKEN: '.$csrf,
        'X-XSRF-TOKEN: '.$xsrf,
    ]);
    $json = json_decode($body, true);
    $stateId = $json['data']['state_id'] ?? null;

    if ($status === 201 && $stateId) {
        $createdStates++;
    } elseif ($status === 422) {
        // Already exists — fetch id from listing
        [$listStatus, , $listBody] = http('GET', $baseUrl.'/states?all=1&search='.rawurlencode($stateName), null, $cookieFile, $resolve, [
            'X-CSRF-TOKEN: '.$csrf,
            'X-XSRF-TOKEN: '.$xsrf,
        ]);
        $listJson = json_decode($listBody, true);
        $items = $listJson['data']['items'] ?? $listJson['data'] ?? [];
        foreach ($items as $item) {
            if (($item['state_name'] ?? '') === $stateName) {
                $stateId = $item['state_id'];
                break;
            }
        }
        $skipped++;
        if (! $stateId) {
            echo "WARN: could not resolve state_id for {$stateName} ({$status})\n";
            continue;
        }
    } else {
        echo "WARN: state {$stateName} failed ({$status}): {$body}\n";
        continue;
    }

    foreach ($cities as $cityName) {
        [$cStatus, , $cBody] = http('POST', $baseUrl.'/cities', [
            'city_name' => $cityName,
            'state_id' => (int) $stateId,
        ], $cookieFile, $resolve, [
            'X-CSRF-TOKEN: '.$csrf,
            'X-XSRF-TOKEN: '.$xsrf,
        ]);
        if ($cStatus === 201) {
            $createdCities++;
        } elseif ($cStatus === 422) {
            $skipped++;
        } else {
            echo "WARN: city {$cityName} / {$stateName} failed ({$cStatus}): {$cBody}\n";
        }
    }
}

[$sStatus, , $sBody] = http('GET', $baseUrl.'/lookups/states', null, $cookieFile, $resolve, [
    'X-CSRF-TOKEN: '.$csrf,
    'X-XSRF-TOKEN: '.$xsrf,
]);
$sJson = json_decode($sBody, true);
$finalStates = is_array($sJson['data'] ?? null) ? count($sJson['data']) : 0;

echo "Done. created_states={$createdStates} created_cities={$createdCities} skipped={$skipped} lookups_states={$finalStates}\n";
@unlink($cookieFile);

exit($finalStates > 0 ? 0 : 1);

<?php

namespace App\Services\Leads;

class LeadFieldNormalizationService
{
  public function normalizeEmail(mixed $value): ?string
  {
    if ($value === null || trim((string) $value) === '') {
      return null;
    }

    return strtolower(trim((string) $value));
  }

  public function normalizeGst(mixed $value): ?string
  {
    if ($value === null || trim((string) $value) === '') {
      return null;
    }

    $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $value) ?? '');

    return $normalized !== '' ? $normalized : null;
  }

  public function normalizePan(mixed $value): ?string
  {
    if ($value === null || trim((string) $value) === '') {
      return null;
    }

    $normalized = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) $value) ?? '');

    return strlen($normalized) === 10 ? $normalized : null;
  }

  public function normalizeWebsite(mixed $value): ?string
  {
    if ($value === null || trim((string) $value) === '') {
      return null;
    }

    $url = strtolower(trim((string) $value));
    $url = preg_replace('#^https?://#', '', $url) ?? $url;
    $url = preg_replace('#^www\.#', '', $url) ?? $url;
    $url = rtrim($url, '/');

    return $url !== '' ? $url : null;
  }

  public function normalizePlaceId(mixed $value): ?string
  {
    if ($value === null || trim((string) $value) === '') {
      return null;
    }

    return trim((string) $value);
  }

  /**
   * @param  array<string, mixed>  $data
   * @return array<string, mixed>
   */
  public function applyToLeadData(array $data, ?\App\Models\CaMaster $existing = null): array
  {
    if (array_key_exists('email_id', $data)) {
      $data['normalized_email'] = $this->normalizeEmail($data['email_id']);
    } elseif ($existing) {
      $data['normalized_email'] = $existing->normalized_email;
    }

    if (array_key_exists('website', $data)) {
      $data['normalized_website'] = $this->normalizeWebsite($data['website']);
    } elseif ($existing) {
      $data['normalized_website'] = $existing->normalized_website;
    }

    if (array_key_exists('gst_no', $data)) {
      $data['gst_no'] = $this->normalizeGst($data['gst_no']) ?? $data['gst_no'];
    }

    if (array_key_exists('pan_no', $data)) {
      $data['pan_no'] = $this->normalizePan($data['pan_no']);
    } elseif ($existing) {
      $data['pan_no'] = $existing->pan_no;
    }

    if (array_key_exists('google_place_id', $data)) {
      $data['google_place_id'] = $this->normalizePlaceId($data['google_place_id']);
    } elseif ($existing) {
      $data['google_place_id'] = $existing->google_place_id;
    }

    return $data;
  }
}

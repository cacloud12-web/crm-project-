<?php

namespace App\Services\Bulk;

use Illuminate\Support\Str;

class BulkImportMappingService
{
    public const CRM_FIELDS = [
        ['key' => 'ca_name', 'label' => 'CA Name', 'required' => true],
        ['key' => 'firm_name', 'label' => 'Firm Name', 'required' => true],
        ['key' => 'mobile_no', 'label' => 'Mobile No', 'required' => false],
        ['key' => 'alternate_mobile_no', 'label' => 'Alternate Mobile No', 'required' => false],
        ['key' => 'email_id', 'label' => 'Email', 'required' => false],
        ['key' => 'gst_no', 'label' => 'GST No', 'required' => false],
        ['key' => 'state_id', 'label' => 'State', 'required' => false],
        ['key' => 'city_id', 'label' => 'City', 'required' => false],
        ['key' => 'source_id', 'label' => 'Source', 'required' => false],
        ['key' => 'team_size', 'label' => 'Team Size', 'required' => false],
        ['key' => 'team_size_id', 'label' => 'Team Size ID', 'required' => false],
        ['key' => 'existing_software', 'label' => 'Existing Software', 'required' => false],
        ['key' => 'website', 'label' => 'Website', 'required' => false],
        ['key' => 'rating', 'label' => 'Rating', 'required' => false],
        ['key' => 'status', 'label' => 'Status', 'required' => false],
    ];

    private const HEADER_ALIASES = [
        'ca_name' => ['ca_name', 'ca name', 'caname', 'ca'],
        'firm_name' => ['firm_name', 'firm name', 'firm'],
        'mobile_no' => ['mobile_no', 'mobile no', 'mobile', 'phone', 'phone_no', 'phone no'],
        'alternate_mobile_no' => ['alternate_mobile_no', 'alternate mobile no', 'alternate mobile', 'alt mobile', 'secondary mobile', 'alternate phone'],
        'email_id' => ['email_id', 'email id', 'email'],
        'gst_no' => ['gst_no', 'gst no', 'gst'],
        'team_size' => ['team_size', 'team size'],
        'team_size_id' => ['team_size_id', 'team size id'],
        'existing_software' => ['existing_software', 'existing software', 'software'],
        'website' => ['website', 'url'],
        'rating' => ['rating'],
        'status' => ['status'],
        'state_id' => ['state_id', 'state id', 'state'],
        'city_id' => ['city_id', 'city id', 'city'],
        'source_id' => ['source_id', 'source id', 'source'],
    ];

    public function crmFields(): array
    {
        return self::CRM_FIELDS;
    }

    private const OPTIONAL_HEADER_FIELDS = ['mobile_no', 'alternate_mobile_no'];

    public function crmFieldsForHeaders(array $headers): array
    {
        return array_values(array_filter(
            self::CRM_FIELDS,
            function (array $field) use ($headers) {
                if (! in_array($field['key'], self::OPTIONAL_HEADER_FIELDS, true)) {
                    return true;
                }

                return $this->fileHasColumn($headers, $field['key']);
            },
        ));
    }

    public function fileHasMobileColumn(array $headers): bool
    {
        return $this->fileHasColumn($headers, 'mobile_no');
    }

    public function fileHasAlternateMobileColumn(array $headers): bool
    {
        return $this->fileHasColumn($headers, 'alternate_mobile_no');
    }

    public function fileHasColumn(array $headers, string $fieldKey): bool
    {
        $aliases = self::HEADER_ALIASES[$fieldKey] ?? [];
        if ($aliases === []) {
            return false;
        }

        $normalizedHeaders = [];
        foreach ($headers as $header) {
            $normalizedHeaders[$this->normalizeKey((string) $header)] = true;
        }

        foreach ($aliases as $alias) {
            if (isset($normalizedHeaders[$this->normalizeKey($alias)])) {
                return true;
            }
        }

        return false;
    }

    public function mobileMappingIsActive(array $headers, array $mapping): bool
    {
        return $this->mappingIsActive($headers, $mapping, 'mobile_no');
    }

    public function alternateMobileMappingIsActive(array $headers, array $mapping): bool
    {
        return $this->mappingIsActive($headers, $mapping, 'alternate_mobile_no');
    }

    public function mappingIsActive(array $headers, array $mapping, string $fieldKey): bool
    {
        if (! $this->fileHasColumn($headers, $fieldKey)) {
            return false;
        }

        $mappedHeader = trim((string) ($mapping[$fieldKey] ?? ''));

        return $mappedHeader !== '';
    }

    public function suggestMapping(array $headers): array
    {
        $normalizedHeaders = [];
        foreach ($headers as $header) {
            $normalizedHeaders[$this->normalizeKey($header)] = $header;
        }

        $mapping = [];
        foreach (self::CRM_FIELDS as $field) {
            $mapping[$field['key']] = null;
        }

        foreach (self::HEADER_ALIASES as $field => $aliases) {
            foreach ($aliases as $alias) {
                $key = $this->normalizeKey($alias);
                if (isset($normalizedHeaders[$key])) {
                    $mapping[$field] = $normalizedHeaders[$key];
                    break;
                }
            }
        }

        return $mapping;
    }

    public function applyMapping(array $rows, array $mapping): array
    {
        $mappedRows = [];

        foreach ($rows as $index => $row) {
            $mapped = [];
            foreach (self::CRM_FIELDS as $field) {
                $fieldKey = $field['key'];
                $sourceHeader = $mapping[$fieldKey] ?? null;
                $mapped[$fieldKey] = ($sourceHeader && array_key_exists($sourceHeader, $row))
                    ? trim((string) $row[$sourceHeader])
                    : '';
            }
            $mappedRows[$index] = $mapped;
        }

        return $mappedRows;
    }

    private function normalizeKey(string $value): string
    {
        return Str::snake(str_replace(['-', ' '], '_', strtolower(trim($value))));
    }
}

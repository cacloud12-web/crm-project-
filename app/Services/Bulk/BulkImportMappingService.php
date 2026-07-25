<?php

namespace App\Services\Bulk;

use Illuminate\Support\Str;

class BulkImportMappingService
{
    public const CRM_FIELDS = [
        ['key' => 'ca_name', 'label' => 'CA Name', 'required' => false],
        ['key' => 'firm_name', 'label' => 'Firm Name', 'required' => true],
        ['key' => 'membership_no', 'label' => 'Membership No', 'required' => false],
        ['key' => 'frn', 'label' => 'FRN', 'required' => false],
        ['key' => 'address', 'label' => 'Address', 'required' => false],
        ['key' => 'mobile_no', 'label' => 'Mobile Number', 'required' => false],
        ['key' => 'alternate_mobile_no', 'label' => 'Alternate Mobile No', 'required' => false],
        ['key' => 'email_id', 'label' => 'Email ID', 'required' => false],
        ['key' => 'sales_remarks', 'label' => 'Sales Remarks', 'required' => false],
        ['key' => 'gst_no', 'label' => 'GST No', 'required' => false],
        ['key' => 'state_id', 'label' => 'State', 'required' => false],
        ['key' => 'city_id', 'label' => 'City', 'required' => false],
        ['key' => 'pincode', 'label' => 'Pincode', 'required' => false],
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
        'membership_no' => ['membership_no', 'membership no', 'membership number', 'membership', 'icai membership'],
        'frn' => ['frn', 'firm registration number', 'firm reg no', 'firm registration no'],
        'address' => ['address', 'firm address', 'office address'],
        'pincode' => ['pincode', 'pin code', 'pin_code', 'zip', 'zip code', 'postal code'],
        'mobile_no' => [
            'mobile_no',
            'mobile number',
            'mobile no',
            'mobile',
            'phone',
            'phone number',
            'phone_no',
            'phone no',
            'contact number',
            'primary mobile',
            'number',
        ],
        'alternate_mobile_no' => ['alternate_mobile_no', 'alternate mobile no', 'alternate mobile', 'alt mobile', 'secondary mobile', 'alternate phone'],
        'email_id' => ['email_id', 'email id', 'email', 'e mail', 'e-mail', 'mail id', 'mail'],
        'sales_remarks' => ['sales_remarks', 'sales remarks', 'sales remark', 'sales notes'],
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

    private const CONDITIONAL_HEADER_FIELDS = ['alternate_mobile_no', 'sales_remarks'];

    public function crmFields(): array
    {
        return self::CRM_FIELDS;
    }

    /**
     * CRM mapping rows for the wizard, including one row per detected Remarks N column.
     *
     * @param  list<string>  $headers
     * @return list<array{key: string, label: string, required: bool, group?: string}>
     */
    public function crmFieldsForHeaders(array $headers): array
    {
        $fields = array_values(array_filter(
            self::CRM_FIELDS,
            function (array $field) use ($headers) {
                if ($field['key'] === 'mobile_no') {
                    return true;
                }

                if ($field['key'] === 'email_id') {
                    return true;
                }

                if (in_array($field['key'], self::CONDITIONAL_HEADER_FIELDS, true)) {
                    if ($field['key'] === 'sales_remarks') {
                        // Expose when a dedicated Sales Remarks column exists OR any Remarks N columns.
                        return $this->fileHasColumn($headers, 'sales_remarks')
                            || $this->detectRemarkHeaders($headers) !== [];
                    }

                    return $this->fileHasColumn($headers, $field['key']);
                }

                return true;
            },
        ));

        foreach ($this->detectRemarkHeaders($headers) as $index => $header) {
            $fields[] = [
                'key' => $this->remarkFieldKey($index + 1),
                'label' => $header,
                'required' => false,
                'group' => 'sales_remarks',
            ];
        }

        return $fields;
    }

    public function fileHasMobileColumn(array $headers): bool
    {
        return $this->fileHasColumn($headers, 'mobile_no');
    }

    public function fileHasAlternateMobileColumn(array $headers): bool
    {
        return $this->fileHasColumn($headers, 'alternate_mobile_no');
    }

    public function fileHasEmailColumn(array $headers): bool
    {
        return $this->fileHasColumn($headers, 'email_id');
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

    /**
     * Detect remark-like headers in file order, sorted by remark number when present.
     * Supports Remarks, Remark, Remarks 1..N, Remark_2, remarks3, etc.
     *
     * @param  list<string>  $headers
     * @return list<string> original header labels
     */
    public function detectRemarkHeaders(array $headers): array
    {
        $found = [];
        foreach ($headers as $header) {
            $original = trim((string) $header);
            if ($original === '') {
                continue;
            }
            $meta = $this->remarkHeaderMeta($original);
            if ($meta === null) {
                continue;
            }
            $found[] = [
                'header' => $original,
                'number' => $meta['number'],
                'index' => count($found),
            ];
        }

        usort($found, static function (array $a, array $b): int {
            $an = $a['number'];
            $bn = $b['number'];
            if ($an === null && $bn === null) {
                return $a['index'] <=> $b['index'];
            }
            if ($an === null) {
                return -1;
            }
            if ($bn === null) {
                return 1;
            }
            if ($an === $bn) {
                return $a['index'] <=> $b['index'];
            }

            return $an <=> $bn;
        });

        return array_values(array_map(static fn (array $row) => $row['header'], $found));
    }

    public function isRemarkHeader(string $header): bool
    {
        return $this->remarkHeaderMeta($header) !== null;
    }

    public function remarkFieldKey(int $oneBasedIndex): string
    {
        return 'sales_remark_'.$oneBasedIndex;
    }

    public function isRemarkFieldKey(string $key): bool
    {
        return (bool) preg_match('/^sales_remark_\d+$/', $key);
    }

    /**
     * Merge non-empty remark values in order, preserving internal line breaks.
     *
     * @param  list<string|null>  $values
     */
    public function mergeRemarkValues(array $values): string
    {
        $parts = [];
        foreach ($values as $value) {
            if ($value === null) {
                continue;
            }
            $trimmed = trim((string) $value);
            if ($trimmed === '') {
                continue;
            }
            // Normalize only extreme outer whitespace; keep internal newlines/dates.
            $parts[] = preg_replace("/[ \t]+\n/", "\n", $trimmed) ?? $trimmed;
        }

        return implode("\n\n", $parts);
    }

    /**
     * @param  list<string>  $headers
     * @return array<string, string|null>
     */
    public function suggestMapping(array $headers): array
    {
        $normalizedHeaders = [];
        foreach ($headers as $header) {
            $normalizedHeaders[$this->normalizeKey((string) $header)] = $header;
        }

        $mapping = [];
        foreach ($this->crmFieldsForHeaders($headers) as $field) {
            $mapping[$field['key']] = null;
        }

        foreach (self::HEADER_ALIASES as $field => $aliases) {
            if (! array_key_exists($field, $mapping)) {
                continue;
            }
            foreach ($aliases as $alias) {
                $key = $this->normalizeKey($alias);
                if (isset($normalizedHeaders[$key])) {
                    // Numbered Remarks stay on dynamic remark fields; do not steal them for sales_remarks.
                    if ($field === 'sales_remarks') {
                        $meta = $this->remarkHeaderMeta((string) $normalizedHeaders[$key]);
                        if ($meta !== null && $meta['number'] !== null) {
                            continue;
                        }
                    }
                    $mapping[$field] = $normalizedHeaders[$key];
                    break;
                }
            }
        }

        foreach ($this->detectRemarkHeaders($headers) as $index => $header) {
            $mapping[$this->remarkFieldKey($index + 1)] = $header;
        }

        // If Sales Remarks was not aliased but a plain Remarks/Remark column exists and
        // was claimed by sales_remark_N, leave it on the dynamic field (merge handles it).
        return $mapping;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, string|null>  $mapping
     * @param  list<string>|null  $headers
     * @return list<array<string, mixed>>
     */
    public function applyMapping(array $rows, array $mapping, ?array $headers = null): array
    {
        $fields = $headers !== null
            ? $this->crmFieldsForHeaders($headers)
            : self::CRM_FIELDS;
        $mappedRows = [];

        foreach ($rows as $index => $row) {
            $mapped = [];
            $remarkValues = [];

            foreach ($fields as $field) {
                $fieldKey = $field['key'];
                $sourceHeader = $mapping[$fieldKey] ?? null;
                $value = ($sourceHeader && is_array($row) && array_key_exists($sourceHeader, $row))
                    ? $this->cellValueAsString($row[$sourceHeader])
                    : '';

                if ($this->isRemarkFieldKey($fieldKey)) {
                    $remarkValues[] = $value;
                    continue;
                }

                if ($fieldKey === 'sales_remarks') {
                    // Collected after numbered remarks so column order stays Remarks 1..N then Sales Remarks.
                    continue;
                }

                $mapped[$fieldKey] = $value;
            }

            $salesRemarksMapped = '';
            $salesRemarksHeader = $mapping['sales_remarks'] ?? null;
            if ($salesRemarksHeader && is_array($row) && array_key_exists($salesRemarksHeader, $row)) {
                $salesRemarksMapped = $this->cellValueAsString($row[$salesRemarksHeader]);
            }
            if ($salesRemarksMapped !== '') {
                $remarkValues[] = $salesRemarksMapped;
            }

            $mapped['sales_remarks'] = $this->mergeRemarkValues($remarkValues);
            $mappedRows[$index] = $mapped;
        }

        return $mappedRows;
    }

    /**
     * @return array{number: int|null}|null
     */
    private function remarkHeaderMeta(string $header): ?array
    {
        $norm = $this->normalizeKey($header);
        if ($norm === 'sales_remarks' || $norm === 'sales_remark' || $norm === 'sales_notes') {
            return null; // handled via sales_remarks CRM field aliases
        }

        if (preg_match('/^remarks?_(\d+)$/', $norm, $m) === 1) {
            return ['number' => (int) $m[1]];
        }
        if (preg_match('/^remarks?(\d+)$/', $norm, $m) === 1) {
            return ['number' => (int) $m[1]];
        }
        if ($norm === 'remarks' || $norm === 'remark') {
            return ['number' => null];
        }

        return null;
    }

    private function cellValueAsString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            if (floor($value) === $value && abs($value) >= 1_000_000_000 && abs($value) < 100_000_000_000) {
                return sprintf('%.0f', $value);
            }

            return rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
        }

        return trim((string) $value);
    }

    private function normalizeKey(string $value): string
    {
        return Str::snake(str_replace(['-', ' '], '_', strtolower(trim($value))));
    }
}

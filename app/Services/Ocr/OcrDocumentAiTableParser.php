<?php

namespace App\Services\Ocr;

/**
 * Maps Google Document AI table rows/cells into firm staging rows.
 * Preserves exact cell text as raw; never invents missing cells.
 */
class OcrDocumentAiTableParser
{
    public function __construct(
        private readonly ?OcrEntityClassificationService $classifier = null,
    ) {}

    private function entities(): OcrEntityClassificationService
    {
        return $this->classifier ?? new OcrEntityClassificationService;
    }

    /** @var array<string, list<string>> */
    private const HEADER_ALIASES = [
        'firm_name' => ['firm name', 'firm', 'name of firm', 'ca firm', 'office name'],
        'ca_name' => ['ca name', 'member name', 'partner name', 'chartered accountant', 'name of ca', 'proprietor'],
        'firm_type' => ['firm type', 'type', 'constitution'],
        'address' => ['address', 'office address', 'registered address'],
        'city' => ['city', 'town', 'district'],
        'state' => ['state', 'province'],
        'pincode' => ['pin', 'pincode', 'pin code', 'postal code', 'zip'],
        'phone' => ['mobile', 'phone', 'contact', 'mobile no', 'phone no', 'mob'],
        'email' => ['email', 'e-mail', 'mail'],
        'frn' => ['frn', 'firm registration', 'firm reg'],
        'membership_no' => ['membership', 'membership no', 'membership number', 'mem no', 'm.no'],
        'gst_no' => ['gst', 'gstin', 'gst no'],
        'pan_no' => ['pan', 'pan no'],
    ];

    /**
     * @param  list<array<string, mixed>>  $tables
     * @return array{firms: list<array<string, mixed>>, rows_detected: int, parse_mode: string}|null
     */
    public function parseTables(array $tables): ?array
    {
        if ($tables === []) {
            return null;
        }

        $firms = [];
        $sequence = 0;
        $rowsDetected = 0;

        foreach ($tables as $table) {
            $headerRows = is_array($table['header_rows'] ?? null) ? $table['header_rows'] : [];
            $bodyRows = is_array($table['body_rows'] ?? null) ? $table['body_rows'] : [];
            if ($bodyRows === []) {
                continue;
            }

            $columnMap = $this->mapColumns($headerRows);
            if ($columnMap === []) {
                // No recognizable headers — do not guess column meanings.
                continue;
            }

            $pageNumber = isset($table['page_number']) ? (int) $table['page_number'] : null;
            foreach ($bodyRows as $rowIndex => $row) {
                if (! is_array($row) || $row === []) {
                    continue;
                }
                $rowsDetected++;
                $mapped = $this->mapRow($row, $columnMap, $pageNumber, $rowIndex + 1, $table);
                if ($mapped === null) {
                    continue;
                }
                $sequence++;
                $mapped['sequence_no'] = $sequence;
                $firms[] = $mapped;
            }
        }

        if ($firms === []) {
            return null;
        }

        return [
            'firms' => $firms,
            'rows_detected' => $rowsDetected,
            'parse_mode' => 'document_ai_tables',
        ];
    }

    /**
     * @param  list<list<array<string, mixed>>>  $headerRows
     * @return array<int, string> column index => field key
     */
    private function mapColumns(array $headerRows): array
    {
        $header = $headerRows[0] ?? [];
        if (! is_array($header) || $header === []) {
            return [];
        }

        $map = [];
        foreach ($header as $index => $cell) {
            $text = mb_strtolower(trim((string) ($cell['text'] ?? '')));
            if ($text === '') {
                continue;
            }
            foreach (self::HEADER_ALIASES as $field => $aliases) {
                foreach ($aliases as $alias) {
                    if ($text === $alias || str_contains($text, $alias)) {
                        $map[$index] = $field;
                        break 2;
                    }
                }
            }
        }

        return $map;
    }

    /**
     * @param  list<array<string, mixed>>  $row
     * @param  array<int, string>  $columnMap
     * @param  array<string, mixed>  $table
     * @return array<string, mixed>|null
     */
    private function mapRow(array $row, array $columnMap, ?int $pageNumber, int $rowNumber, array $table): ?array
    {
        $values = [];
        $fieldMeta = [];
        $bboxes = [];
        $classifications = [];
        $columnMismatch = false;

        foreach ($columnMap as $index => $field) {
            $cell = $row[$index] ?? null;
            if (! is_array($cell)) {
                continue;
            }
            $text = trim((string) ($cell['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $classified = $this->entities()->classify($text);
            $classified['source_column'] = $index;
            $classified['target_field'] = $field;
            $classifications[] = $classified;

            if (! $this->cellMatchesField($classified['entity_type'], $field)) {
                $columnMismatch = true;
                // Re-route by entity type instead of blind column assignment.
                $field = $this->fieldForEntity($classified['entity_type'], $field);
            }
            if (isset($values[$field]) && $values[$field] !== '') {
                continue;
            }
            $values[$field] = $text;
            $confidence = isset($cell['confidence']) ? (float) $cell['confidence'] : 0.92;
            $fieldMeta[$field] = [
                'value' => $text,
                'confidence' => $confidence,
                'source_line' => $rowNumber,
                'page_number' => $pageNumber,
                'source_text' => $text,
                'bounding_box' => $cell['bounding_box'] ?? [],
                'extraction' => 'document_ai_table_cell',
            ];
            if (! empty($cell['bounding_box'])) {
                $bboxes[$field] = $cell['bounding_box'];
            }
        }

        $firmName = $values['firm_name'] ?? null;
        $caName = $values['ca_name'] ?? null;
        if (($firmName === null || $firmName === '') && ($caName === null || $caName === '')) {
            return null;
        }

        $scores = array_map(static fn ($m) => (float) ($m['confidence'] ?? 0), $fieldMeta);
        $overall = $scores !== [] ? round(array_sum($scores) / count($scores), 4) : 0.5;

        if (config('ocr_workflow.mode', 'firm_ca_city') === 'firm_ca_city') {
            if (filled($caName) && ! $this->entities()->isPerson((string) $caName)) {
                $caName = null;
            }
            $missing = [];
            if (! filled($firmName)) {
                $missing[] = 'firm_name';
            }
            if (! filled($caName)) {
                $missing[] = 'ca_name';
            }
            if (! filled($values['city'] ?? null)) {
                $missing[] = 'city';
            }
            $threeMeta = array_intersect_key($fieldMeta, array_flip(['firm_name', 'ca_name', 'city']));

            return [
                'firm_name' => $firmName,
                'raw_firm_name' => $firmName,
                'ca_name' => $caName,
                'raw_ca_name' => $caName,
                'city' => $values['city'] ?? null,
                'raw_city' => $values['city'] ?? null,
                'firm_type' => null,
                'frn' => null,
                'gst_no' => null,
                'pan_no' => null,
                'address' => null,
                'state' => null,
                'pincode' => null,
                'phone' => null,
                'email' => null,
                'membership_no' => null,
                'page_number' => $pageNumber,
                'row_number' => $rowNumber,
                'row_serial' => $rowNumber,
                'bounding_boxes' => array_intersect_key($bboxes, array_flip(['firm_name', 'ca_name', 'city'])),
                'field_meta' => $threeMeta,
                'overall_confidence' => $overall,
                'members' => [],
                'missing_required_fields' => $missing,
                'ambiguous_layout' => false,
                'firm_name_boundary_uncertain' => in_array('firm_name', $missing, true),
                'ca_name_boundary_uncertain' => in_array('ca_name', $missing, true),
                'city_boundary_uncertain' => in_array('city', $missing, true),
                'source_lines' => array_values(array_filter(array_map(
                    static fn ($c) => trim((string) ($c['text'] ?? '')),
                    $row,
                ))),
                'extraction_source' => 'document_ai_table_firm_ca_city',
                'table_index' => $table['table_index'] ?? null,
                'entity_classifications' => $classifications,
                'source_column_mismatch' => $columnMismatch,
            ];
        }

        $members = [];
        if (filled($caName) && $this->entities()->isPerson($caName)) {
            $members[] = [
                'ca_name' => $caName,
                'raw_ca_name' => $caName,
                'membership_no' => $values['membership_no'] ?? null,
                'mobile' => $values['phone'] ?? null,
                'email' => $values['email'] ?? null,
                'is_primary' => true,
                'overall_confidence' => $fieldMeta['ca_name']['confidence'] ?? $overall,
                'page_number' => $pageNumber,
            ];
        }

        return [
            'firm_name' => $firmName ?: $caName,
            'raw_firm_name' => $firmName ?: $caName,
            'firm_type' => $values['firm_type'] ?? null,
            'frn' => $values['frn'] ?? null,
            'gst_no' => $values['gst_no'] ?? null,
            'pan_no' => $values['pan_no'] ?? null,
            'address' => $values['address'] ?? null,
            'city' => $values['city'] ?? null,
            'state' => $values['state'] ?? null,
            'pincode' => $values['pincode'] ?? null,
            'phone' => $values['phone'] ?? null,
            'email' => $values['email'] ?? null,
            'page_number' => $pageNumber,
            'row_number' => $rowNumber,
            'row_serial' => $rowNumber,
            'bounding_boxes' => $bboxes,
            'field_meta' => $fieldMeta,
            'overall_confidence' => $overall,
            'members' => $members,
            'source_lines' => array_values(array_filter(array_map(
                static fn ($c) => trim((string) ($c['text'] ?? '')),
                $row,
            ))),
            'extraction_source' => 'document_ai_table',
            'table_index' => $table['table_index'] ?? null,
            'entity_classifications' => $classifications,
            'source_column_mismatch' => $columnMismatch,
        ];
    }

    private function cellMatchesField(string $entityType, string $field): bool
    {
        return match ($field) {
            'firm_name' => $entityType === OcrEntityClassificationService::FIRM_NAME,
            'ca_name' => $entityType === OcrEntityClassificationService::PERSON,
            'address' => $entityType === OcrEntityClassificationService::ADDRESS,
            'city' => in_array($entityType, [OcrEntityClassificationService::CITY, OcrEntityClassificationService::ADDRESS], true),
            'state' => $entityType === OcrEntityClassificationService::STATE,
            'pincode' => $entityType === OcrEntityClassificationService::PINCODE,
            'phone' => $entityType === OcrEntityClassificationService::PHONE,
            'email' => $entityType === OcrEntityClassificationService::EMAIL,
            'frn' => $entityType === OcrEntityClassificationService::FRN,
            'membership_no' => $entityType === OcrEntityClassificationService::MEMBERSHIP_NUMBER,
            'gst_no' => $entityType === OcrEntityClassificationService::GST,
            'pan_no' => $entityType === OcrEntityClassificationService::PAN,
            default => true,
        };
    }

    private function fieldForEntity(string $entityType, string $fallback): string
    {
        return match ($entityType) {
            OcrEntityClassificationService::FIRM_NAME => 'firm_name',
            OcrEntityClassificationService::PERSON => 'ca_name',
            OcrEntityClassificationService::ADDRESS => 'address',
            OcrEntityClassificationService::CITY => 'city',
            OcrEntityClassificationService::STATE => 'state',
            OcrEntityClassificationService::PINCODE => 'pincode',
            OcrEntityClassificationService::PHONE => 'phone',
            OcrEntityClassificationService::EMAIL => 'email',
            OcrEntityClassificationService::FRN => 'frn',
            OcrEntityClassificationService::MEMBERSHIP_NUMBER => 'membership_no',
            OcrEntityClassificationService::GST => 'gst_no',
            OcrEntityClassificationService::PAN => 'pan_no',
            default => $fallback,
        };
    }
}

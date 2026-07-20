<?php

namespace App\Services\Ocr;

/**
 * Parses spreadsheet-style OCR dumps where Document AI often reads columns
 * out of order. Anchors each table row on: serial number + date (dd-mm-yyyy).
 *
 * Every detected row becomes exactly one firm record — never skipped for
 * missing CA name, blank columns, spaced mobiles, or low confidence.
 */
class OcrSpreadsheetTableParser
{
    public function __construct(
        private readonly ?OcrEntityClassificationService $classifier = null,
    ) {}

    private function entities(): OcrEntityClassificationService
    {
        return $this->classifier ?? new OcrEntityClassificationService;
    }

    private const HEADERS = [
        'date', 'ca name', 'firm name', 'mobile number', 'mobile', 'phone',
        's.no', 's no', 's. no', 'sr no', 'sr.no', 'sr. no', 'serial', 'sno',
    ];

    /**
     * @return bool True when OCR text looks like a spreadsheet/table export.
     */
    public function looksLikeSpreadsheet(string $rawText): bool
    {
        $lower = mb_strtolower($rawText);
        $hits = 0;
        foreach (['firm name', 'mobile number', 'ca name', 'date'] as $header) {
            if (str_contains($lower, $header)) {
                $hits++;
            }
        }
        if ($hits >= 2) {
            return true;
        }

        // Serial + real calendar dates (reject membership/FRN OCR that looks like dd-mm-yyyy).
        if (preg_match_all('/(?:^|\n)\s*\d{1,3}\s*\n\s*(\d{2})-(\d{2})-(\d{4})/', $rawText, $matches, PREG_SET_ORDER) < 3) {
            return false;
        }
        $validDates = 0;
        foreach ($matches as $match) {
            $d = (int) $match[1];
            $m = (int) $match[2];
            $y = (int) $match[3];
            if ($m >= 1 && $m <= 12 && $d >= 1 && $d <= 31 && $y >= 1990 && $y <= 2100) {
                $validDates++;
            }
        }

        return $validDates >= 3;
    }

    /**
     * @return array{
     *   firms: list<array<string, mixed>>,
     *   rows_detected: int,
     *   skipped: list<array{serial: int|null, reason: string, snippet: string}>,
     *   missing_serials: list<int>,
     *   duplicate_serials: list<int>,
     *   mode: string
     * }
     */
    public function parse(string $rawText): array
    {
        $lines = $this->prepareLines($rawText);
        $anchors = $this->findRowAnchors($lines);
        $skipped = [];

        if ($anchors === []) {
            return [
                'firms' => [],
                'rows_detected' => 0,
                'skipped' => [['serial' => null, 'reason' => 'no_serial_date_anchors', 'snippet' => mb_substr($rawText, 0, 120)]],
                'missing_serials' => [],
                'duplicate_serials' => [],
                'mode' => 'spreadsheet_table',
            ];
        }

        $this->attachPhones($anchors, $lines);
        $columnDump = $this->extractColumnDumpFirmsAndPhones($lines, $anchors);

        $firms = [];
        $sequence = 1;
        foreach ($anchors as $index => $anchor) {
            $firm = $this->buildFirmFromAnchor($anchor, $index, $anchors, $lines, $columnDump, $sequence);
            if ($firm === null) {
                // Still emit a minimal record so no row disappears.
                $firm = [
                    'sequence_no' => $sequence,
                    'firm_name' => 'Row '.$anchor['serial'],
                    'firm_type' => null,
                    'frn' => null,
                    'gst_no' => null,
                    'pan_no' => null,
                    'address' => null,
                    'city' => null,
                    'state' => null,
                    'pincode' => null,
                    'phone' => $anchor['phones'][0] ?? null,
                    'email' => null,
                    'website' => null,
                    'review_status' => 'pending',
                    'overall_confidence' => 0.35,
                    'page_number' => $anchor['page'] ?? null,
                    'field_meta' => [],
                    'members' => [],
                    'source_lines' => [$anchor['serial_idx']],
                    'row_serial' => $anchor['serial'],
                    'row_date' => $anchor['date'],
                ];
                $skipped[] = [
                    'serial' => $anchor['serial'],
                    'reason' => 'minimal_row_emitted_empty_name_fields',
                    'snippet' => 'serial '.$anchor['serial'],
                ];
            }
            $firms[] = $firm;
            $sequence++;
        }

        $serials = array_map(static fn (array $a) => (int) $a['serial'], $anchors);
        $counts = array_count_values($serials);
        $duplicateSerials = [];
        foreach ($counts as $serial => $count) {
            if ($count > 1) {
                $duplicateSerials[] = (int) $serial;
            }
        }
        $missingSerials = [];
        if ($serials !== []) {
            for ($s = min($serials); $s <= max($serials); $s++) {
                if (! in_array($s, $serials, true)) {
                    $missingSerials[] = $s;
                }
            }
        }

        return [
            'firms' => $firms,
            'rows_detected' => count($anchors),
            'skipped' => $skipped,
            'missing_serials' => $missingSerials,
            'duplicate_serials' => $duplicateSerials,
            'mode' => 'spreadsheet_table',
        ];
    }

    /**
     * @return list<string>
     */
    private function prepareLines(string $rawText): array
    {
        $rawText = str_replace(["\r\n", "\r"], "\n", $rawText);
        $chunks = preg_split("/\n+/", $rawText) ?: [];
        $lines = [];
        foreach ($chunks as $chunk) {
            // Preserve OCR characters; only collapse runs of whitespace inside a line.
            $text = trim(preg_replace('/[ \t]+/u', ' ', $chunk) ?? '');
            if ($text !== '') {
                $lines[] = $text;
            }
        }

        return $lines;
    }

    /**
     * @param  list<string>  $lines
     * @return list<array<string, mixed>>
     */
    private function findRowAnchors(array $lines): array
    {
        $anchors = [];
        $n = count($lines);
        for ($i = 0; $i < $n; $i++) {
            if (! preg_match('/^\d{1,3}$/', $lines[$i])) {
                continue;
            }
            $serial = (int) $lines[$i];
            if ($serial < 1 || $serial > 500) {
                continue;
            }
            $dateIdx = null;
            $date = null;
            $dateExtra = '';
            for ($j = $i + 1; $j <= min($i + 3, $n - 1); $j++) {
                if (preg_match('/\b(\d{2}-\d{2}-\d{4})\b(.*)$/u', $lines[$j], $m)) {
                    $dateIdx = $j;
                    $date = $m[1];
                    $dateExtra = trim($m[2]);
                    break;
                }
            }
            if ($dateIdx === null) {
                continue;
            }
            $anchors[] = [
                'serial' => $serial,
                'serial_idx' => $i,
                'date_idx' => $dateIdx,
                'date' => $date,
                'date_extra' => $dateExtra,
                'page' => null,
                'phone_idx' => null,
                'phones' => [],
            ];
        }

        return $anchors;
    }

    /**
     * @param  list<array<string, mixed>>  $anchors
     * @param  list<string>  $lines
     */
    private function attachPhones(array &$anchors, array $lines): void
    {
        $n = count($lines);
        foreach ($anchors as $k => &$anchor) {
            $end = $k + 1 < count($anchors) ? (int) $anchors[$k + 1]['serial_idx'] : $n;
            $anchor['phone_idx'] = null;
            $anchor['phones'] = [];
            for ($j = (int) $anchor['date_idx']; $j < $end; $j++) {
                $phones = $this->extractPhones($lines[$j]);
                if ($phones === []) {
                    continue;
                }
                // On the date line, only accept trailing phones.
                if ($j === (int) $anchor['date_idx'] && ! preg_match('/\b\d{2}-\d{2}-\d{4}\b.*[6-9]/u', $lines[$j])) {
                    continue;
                }
                $anchor['phone_idx'] = $j;
                $anchor['phones'] = $phones;
                break;
            }
        }
        unset($anchor);
    }

    /**
     * First page of spreadsheet PDFs often dumps firm-name and mobile columns separately.
     *
     * @param  list<string>  $lines
     * @param  list<array<string, mixed>>  $anchors
     * @return array{firms: list<string>, phones: list<string>}
     */
    private function extractColumnDumpFirmsAndPhones(array $lines, array $anchors): array
    {
        $firms = [];
        $phones = [];
        $inFirmCol = false;
        $inMobileCol = false;
        $buf = [];

        $firstContentSerialIdx = null;
        foreach ($anchors as $anchor) {
            // Compact anchors: next serial immediately after date → column dump likely.
            $firstContentSerialIdx = (int) $anchor['serial_idx'];
            break;
        }

        $dumpEnd = $firstContentSerialIdx ?? count($lines);
        foreach ($anchors as $i => $anchor) {
            $nextIdx = $i + 1 < count($anchors) ? (int) $anchors[$i + 1]['serial_idx'] : null;
            $gap = $nextIdx !== null ? $nextIdx - (int) $anchor['date_idx'] : 99;
            if ($gap <= 2) {
                $dumpEnd = max($dumpEnd, $nextIdx ?? (int) $anchor['date_idx']);
            } else {
                break;
            }
        }

        for ($i = 0; $i < count($lines) && $i < ($dumpEnd + 25); $i++) {
            $t = $lines[$i];
            $low = mb_strtolower($t);
            if ($low === 'firm name' || $low === 'ca name') {
                $inFirmCol = true;
                $inMobileCol = false;
                continue;
            }
            if ($low === 'mobile number' || $low === 'mobile' || $low === 'phone') {
                if ($buf !== []) {
                    $firms[] = trim(implode(' ', $buf));
                    $buf = [];
                }
                $inFirmCol = false;
                $inMobileCol = true;
                continue;
            }
            if ($inMobileCol) {
                foreach ($this->extractPhones($t) as $phone) {
                    $phones[] = $phone;
                }
                if (preg_match('/^\d{1,3}$/', $t) && (int) $t >= 1) {
                    $inMobileCol = false;
                }
                continue;
            }
            if (! $inFirmCol) {
                continue;
            }
            if (preg_match('/^\d{1,3}$/', $t) || $this->isHeader($t) || $this->extractPhones($t) !== []) {
                continue;
            }
            $buf[] = $t;
            $joined = mb_strtolower(implode(' ', $buf));
            if (preg_match('/(&\s*co\.?|and\s+co\.?|associates|company|llp|pvt\.?\s*ltd\.?|private\s+limited|chartered\s+accountants?)\s*$/i', $joined)) {
                $firms[] = trim(implode(' ', $buf));
                $buf = [];
            }
        }
        // Do not keep incomplete trailing tokens (e.g. "DEEPAK") — they belong to later rows.
        unset($buf);

        // Prefer equal firm/phone counts for zipping.
        $zip = min(count($firms), count($phones));
        if ($zip > 0) {
            $firms = array_slice($firms, 0, $zip);
            $phones = array_slice($phones, 0, $zip);
        }

        return ['firms' => $firms, 'phones' => $phones];
    }

    /**
     * @param  array<string, mixed>  $anchor
     * @param  list<array<string, mixed>>  $anchors
     * @param  list<string>  $lines
     * @param  array{firms: list<string>, phones: list<string>}  $columnDump
     * @return array<string, mixed>|null
     */
    private function buildFirmFromAnchor(
        array $anchor,
        int $index,
        array $anchors,
        array $lines,
        array $columnDump,
        int $sequence,
    ): ?array {
        $n = count($lines);
        $prevPhoneIdx = $index > 0
            ? (int) ($anchors[$index - 1]['phone_idx'] ?? $anchors[$index - 1]['date_idx'])
            : -1;
        $preParts = [];
        for ($j = $prevPhoneIdx + 1; $j < (int) $anchor['serial_idx']; $j++) {
            $t = $lines[$j];
            if ($this->isHeader($t) || $this->hasDate($t) || $this->extractPhones($t) !== [] || preg_match('/^\d{1,3}$/', $t)) {
                continue;
            }
            $preParts[] = $t;
        }

        $postParts = [];
        $end = (int) ($anchor['phone_idx'] ?? ($index + 1 < count($anchors) ? $anchors[$index + 1]['serial_idx'] : $n));
        for ($j = (int) $anchor['date_idx'] + 1; $j < $end; $j++) {
            $t = $lines[$j];
            if ($this->extractPhones($t) !== [] || $this->isHeader($t) || preg_match('/^\d{1,3}$/', $t)) {
                continue;
            }
            $postParts[] = $t;
        }

        $dateExtra = trim((string) ($anchor['date_extra'] ?? ''));
        $phone = $anchor['phones'][0] ?? null;
        $altPhones = array_slice($anchor['phones'] ?? [], 1);

        // Leading rows often have serial+date only; firm/mobile columns are dumped once afterward.
        $dumpIndex = $this->columnDumpIndexForAnchor($index, $anchors, $columnDump);
        if ($dumpIndex !== null) {
            $phone = $columnDump['phones'][$dumpIndex] ?? $phone;
            $altPhones = [];
            $preParts = [];
            $postParts = [];
            $dateExtra = $columnDump['firms'][$dumpIndex] ?? $dateExtra;
        }

        [$firmName, $caName, $address, $classifications, $extraPartners] = $this->assembleNames($preParts, $dateExtra, $postParts);
        if (($firmName === null || $firmName === '') && ($caName === null || $caName === '')) {
            $firmName = 'Row '.$anchor['serial'];
        }
        if ($firmName === null || $firmName === '') {
            $firmName = $caName;
        }

        $members = [];
        if ($caName !== null && $caName !== '' && $this->entities()->isPerson($caName)) {
            $members[] = $this->memberRow($caName, $phone, $anchor, true);
        }
        foreach ($extraPartners as $partnerName) {
            if ($this->entities()->isPerson($partnerName)) {
                $members[] = $this->memberRow($partnerName, null, $anchor, false);
            }
        }

        $fieldMeta = array_filter([
            'firm_name' => [
                'value' => $firmName,
                'confidence' => 0.85,
                'source_line' => $anchor['date_idx'] + 1,
                'page_number' => $anchor['page'],
                'source_text' => $firmName,
            ],
            'phone' => $phone ? [
                'value' => $phone,
                'confidence' => 0.9,
                'source_line' => ($anchor['phone_idx'] ?? $anchor['date_idx']) + 1,
                'page_number' => $anchor['page'],
                'source_text' => $phone,
            ] : null,
        ]);

        return [
            'sequence_no' => $sequence,
            'firm_name' => $firmName,
            'firm_type' => $this->inferFirmType($firmName),
            'frn' => null,
            'gst_no' => null,
            'pan_no' => null,
            'address' => $address,
            'city' => null,
            'state' => null,
            'pincode' => null,
            'phone' => $phone,
            'alternate_mobile_no' => $altPhones[0] ?? null,
            'email' => null,
            'website' => null,
            'review_status' => 'pending',
            'overall_confidence' => $phone ? 0.82 : 0.55,
            'page_number' => $anchor['page'],
            'field_meta' => $fieldMeta,
            'members' => $members,
            'source_lines' => range((int) $anchor['serial_idx'], max((int) $anchor['serial_idx'], $end - 1)),
            'row_serial' => $anchor['serial'],
            'row_date' => $anchor['date'],
            'unclassified_lines' => [],
            'entity_classifications' => $classifications,
        ];
    }

    /**
     * Map leading empty serial+date anchors onto the column-dump firm/phone lists.
     *
     * @param  list<array<string, mixed>>  $anchors
     * @param  array{firms: list<string>, phones: list<string>}  $columnDump
     */
    private function columnDumpIndexForAnchor(int $index, array $anchors, array $columnDump): ?int
    {
        $zipCount = min(count($columnDump['firms']), count($columnDump['phones']));
        if ($zipCount < 1) {
            return null;
        }

        $zipped = 0;
        for ($i = 0; $i < count($anchors) && $zipped < $zipCount; $i++) {
            $dateExtra = trim((string) ($anchors[$i]['date_extra'] ?? ''));
            // Stop zipping once rows carry their own firm text on the date line.
            if ($dateExtra !== '' && preg_match('/[A-Za-z]{3,}/', $dateExtra)) {
                break;
            }
            if ($i === $index) {
                return $zipped;
            }
            $zipped++;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $anchor
     * @return array<string, mixed>
     */
    private function memberRow(string $caName, ?string $phone, array $anchor, bool $primary): array
    {
        return [
            'sequence_no' => 1,
            'ca_name' => $caName,
            'membership_no' => null,
            'mobile' => $phone,
            'email' => null,
            'role' => 'Partner',
            'overall_confidence' => 0.75,
            'field_meta' => [
                'ca_name' => [
                    'value' => $caName,
                    'confidence' => 0.75,
                    'source_line' => $anchor['serial_idx'] + 1,
                    'page_number' => $anchor['page'],
                    'source_text' => $caName,
                ],
            ],
            'is_primary' => $primary,
        ];
    }

    /**
     * Content-based field assembly — never assign by line order alone.
     *
     * @param  list<string>  $preParts
     * @param  list<string>  $postParts
     * @return array{0: ?string, 1: ?string, 2: ?string, 3: list<array<string, mixed>>, 4: list<string>}
     */
    private function assembleNames(array $preParts, string $dateExtra, array $postParts): array
    {
        $lines = [];
        foreach ($preParts as $line) {
            $lines[] = $line;
        }
        if (trim($dateExtra) !== '') {
            $lines[] = trim($dateExtra);
        }
        foreach ($postParts as $line) {
            $lines[] = $line;
        }

        if ($lines === []) {
            return [null, null, null, [], []];
        }

        $mapped = $this->entities()->mapLinesToFields($lines);
        $firm = $mapped['firm_name'];
        $ca = $mapped['ca_name'];
        $address = $mapped['address'];
        $classifications = $mapped['classifications'];
        $extraPartners = [];
        foreach ($mapped['partners'] as $i => $partner) {
            if ($i === 0 && $ca !== null) {
                continue;
            }
            $extraPartners[] = $partner['ca_name'];
        }

        // Continuation-only firm suffix glued to firm from classifier.
        if ($firm !== null) {
            $firm = $this->collapseEchoedName($firm);
        }
        if ($ca !== null) {
            $ca = $this->collapseEchoedName($ca);
        }
        if ($firm !== null && $ca !== null && mb_strtolower($ca) === mb_strtolower($firm) && ! preg_match('/(&|associates|company|llp|co\.?)/i', $firm)) {
            $ca = null;
        }

        return [$firm, $ca, $address, $classifications, $extraPartners];
    }

    private function significantTokens(string $text): string
    {
        $words = preg_split('/\s+/', mb_strtolower($text)) ?: [];
        $words = array_values(array_filter($words, static fn ($w) => ! in_array($w, ['&', 'and', 'ca', 'the'], true)));

        return implode(' ', array_slice($words, 0, 2));
    }

    private function collapseEchoedName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
        if ($name === '') {
            return '';
        }
        // "Piyushi Kataria Piyushi Kataria & Co" → "Piyushi Kataria & Co"
        if (preg_match('/^(.{3,40}?)\s+\1\b/ui', $name, $m)) {
            $name = trim($m[1].mb_substr($name, mb_strlen($m[0]) - mb_strlen($m[1])));
        }
        // "Yashvant Yashvant Patidar Patidar & Co" → collapse doubled tokens
        $words = preg_split('/\s+/u', $name) ?: [];
        $out = [];
        foreach ($words as $word) {
            if ($out !== [] && mb_strtolower((string) end($out)) === mb_strtolower($word)) {
                continue;
            }
            $out[] = $word;
        }

        return trim(implode(' ', $out));
    }

    private function inferFirmType(?string $firmName): ?string
    {
        if ($firmName === null || $firmName === '') {
            return null;
        }
        $lower = mb_strtolower($firmName);
        if (str_contains($lower, 'llp')) {
            return 'LLP';
        }
        if (str_contains($lower, 'pvt') || str_contains($lower, 'private limited')) {
            return 'Private Limited';
        }
        if (preg_match('/associates|&\s*co|and\s+co|company/i', $lower)) {
            return 'Partnership';
        }

        return null;
    }

    private function isHeader(string $text): bool
    {
        return in_array(mb_strtolower(trim($text)), self::HEADERS, true);
    }

    private function hasDate(string $text): bool
    {
        return (bool) preg_match('/\b\d{2}-\d{2}-\d{4}\b/', $text);
    }

    /**
     * @return list<string>
     */
    private function extractPhones(string $text): array
    {
        $out = [];
        // 10-digit mobiles with optional spaces/dashes; allow leading 0.
        if (preg_match_all('/(?<!\d)(?:\+91[\-\s]?|0)?([6-9](?:[\s\-]?\d){9})(?!\d)/u', $text, $matches)) {
            foreach ($matches[1] as $raw) {
                $digits = preg_replace('/\D+/', '', $raw) ?? '';
                if (strlen($digits) === 10) {
                    $out[] = $digits;
                }
            }
        }

        return array_values(array_unique($out));
    }
}

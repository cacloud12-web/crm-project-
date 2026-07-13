<?php

namespace App\Services\Bulk;

use Illuminate\Http\UploadedFile;
use RuntimeException;
use ZipArchive;

class BulkImportFileParser
{
    private const SPREADSHEET_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const RELATIONSHIP_NS = 'http://schemas.openxmlformats.org/package/2006/relationships';

    private const OFFICE_REL_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    public function parse(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        if (! $path) {
            throw new RuntimeException('Unable to read the uploaded file.');
        }

        $extension = strtolower($file->getClientOriginalExtension());

        return match ($extension) {
            'csv', 'txt' => $this->parseCsv($path),
            'xlsx' => $this->parseXlsx($path),
            default => throw new RuntimeException('Unsupported file type. Please upload CSV or Excel (.xlsx).'),
        };
    }

    private function parseCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException('Unable to open the CSV file.');
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            throw new RuntimeException('The file is empty.');
        }

        $headers = $this->cleanHeaders($header);
        $rows = [];

        while (($line = fgetcsv($handle)) !== false) {
            if ($this->isEmptyLine($line)) {
                continue;
            }

            $rows[] = $this->combineRow($headers, $line);
        }

        fclose($handle);

        return [
            'headers' => $headers,
            'rows' => $rows,
        ];
    }

    private function parseXlsx(string $path): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('Excel import requires the PHP Zip extension.');
        }

        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Unable to open the Excel file.');
        }

        $sharedStrings = $this->readSharedStrings($zip);
        $sheetXml = $this->resolveWorksheetXml($zip);
        $zip->close();

        if (! $sheetXml) {
            throw new RuntimeException('The Excel file does not contain a readable worksheet.');
        }

        $sheet = simplexml_load_string($sheetXml);
        if ($sheet === false) {
            throw new RuntimeException('Unable to parse the Excel worksheet.');
        }

        $sheet->registerXPathNamespace('m', self::SPREADSHEET_NS);
        $sheetRows = $sheet->xpath('//m:sheetData/m:row');
        if ($sheetRows === false || $sheetRows === []) {
            throw new RuntimeException('The Excel file has no data rows.');
        }

        $matrix = [];

        foreach ($sheetRows as $row) {
            $row->registerXPathNamespace('m', self::SPREADSHEET_NS);
            $rowIndex = (int) ($row['r'] ?? 0);
            $cells = $row->xpath('m:c') ?: [];

            foreach ($cells as $cell) {
                $ref = (string) ($cell['r'] ?? '');
                if ($ref !== '') {
                    [$column, $line] = $this->splitCellReference($ref);
                } else {
                    $line = $rowIndex;
                    $column = 'A';
                }

                if ($line <= 0) {
                    $line = $rowIndex > 0 ? $rowIndex : 1;
                }

                $matrix[$line][$column] = $this->readCellValue($cell, $sharedStrings);
            }
        }

        if ($matrix === []) {
            throw new RuntimeException('The Excel file has no data rows.');
        }

        ksort($matrix);
        $lineNumbers = array_keys($matrix);
        $headerLine = array_shift($lineNumbers);
        $headerRow = $matrix[$headerLine] ?? [];
        ksort($headerRow);
        $headers = $this->cleanHeaders(array_values($headerRow));

        $rows = [];
        foreach ($lineNumbers as $lineNumber) {
            ksort($matrix[$lineNumber]);
            $values = array_values($matrix[$lineNumber]);
            if ($this->isEmptyLine($values)) {
                continue;
            }
            $rows[] = $this->combineRow($headers, $values);
        }

        return [
            'headers' => $headers,
            'rows' => $rows,
        ];
    }

    private function resolveWorksheetXml(ZipArchive $zip): ?string
    {
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        if ($sheetXml) {
            return $sheetXml;
        }

        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if (! $workbookXml || ! $relsXml) {
            return null;
        }

        $workbook = simplexml_load_string($workbookXml);
        $rels = simplexml_load_string($relsXml);
        if ($workbook === false || $rels === false) {
            return null;
        }

        $workbook->registerXPathNamespace('m', self::SPREADSHEET_NS);
        $workbook->registerXPathNamespace('r', self::OFFICE_REL_NS);
        $firstSheet = $workbook->xpath('//m:sheets/m:sheet')[0] ?? null;
        if ($firstSheet === null) {
            return null;
        }

        $relId = (string) ($firstSheet->attributes(self::OFFICE_REL_NS)->id ?? '');
        if ($relId === '') {
            return null;
        }

        $rels->registerXPathNamespace('rel', self::RELATIONSHIP_NS);
        $relationships = $rels->xpath('//rel:Relationship') ?: [];
        foreach ($relationships as $relationship) {
            if ((string) ($relationship['Id'] ?? '') !== $relId) {
                continue;
            }

            $target = (string) ($relationship['Target'] ?? '');
            if ($target === '') {
                return null;
            }

            $path = str_starts_with($target, 'worksheets/')
                ? 'xl/'.$target
                : 'xl/worksheets/'.ltrim($target, '/');

            return $zip->getFromName($path) ?: null;
        }

        return null;
    }

    private function readSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if (! $xml) {
            return [];
        }

        $shared = simplexml_load_string($xml);
        if ($shared === false) {
            return [];
        }

        $shared->registerXPathNamespace('m', self::SPREADSHEET_NS);
        $items = $shared->xpath('//m:si') ?: [];

        $strings = [];
        foreach ($items as $item) {
            $item->registerXPathNamespace('m', self::SPREADSHEET_NS);
            $inline = $item->xpath('m:t');
            if ($inline !== false && $inline !== []) {
                $strings[] = (string) $inline[0];

                continue;
            }

            $text = '';
            foreach ($item->xpath('.//m:t') ?: [] as $part) {
                $text .= (string) $part;
            }
            $strings[] = $text;
        }

        return $strings;
    }

    private function readCellValue(\SimpleXMLElement $cell, array $sharedStrings): string
    {
        $cell->registerXPathNamespace('m', self::SPREADSHEET_NS);
        $type = (string) ($cell['t'] ?? '');
        if ($type === 's') {
            $index = (int) ($cell->xpath('m:v')[0] ?? 0);

            return trim($sharedStrings[$index] ?? '');
        }

        if ($type === 'inlineStr') {
            $inline = $cell->xpath('m:is/m:t');

            return trim((string) ($inline[0] ?? ''));
        }

        $value = $cell->xpath('m:v');
        $raw = trim((string) ($value[0] ?? ''));

        return $this->formatNumericCellValue($raw);
    }

    private function formatNumericCellValue(string $raw): string
    {
        if ($raw === '' || ! is_numeric($raw)) {
            return $raw;
        }

        if (preg_match('/^[+-]?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?$/', $raw) !== 1) {
            return $raw;
        }

        $numeric = (float) $raw;
        if (! is_finite($numeric)) {
            return $raw;
        }

        if (floor($numeric) === $numeric) {
            $digits = strlen(ltrim((string) (int) abs($numeric), '0'));
            if ($digits >= 9 && $digits <= 12) {
                return sprintf('%.0f', $numeric);
            }
        }

        return rtrim(rtrim(sprintf('%.10F', $numeric), '0'), '.');
    }

    private function splitCellReference(string $reference): array
    {
        preg_match('/^([A-Z]+)(\d+)$/', strtoupper($reference), $matches);

        return [$matches[1] ?? 'A', (int) ($matches[2] ?? 1)];
    }

    private function cleanHeaders(array $header): array
    {
        $headers = [];
        $seen = [];

        foreach ($header as $index => $column) {
            $label = trim((string) $column);
            $label = preg_replace('/^\xEF\xBB\xBF/', '', $label) ?? $label;
            if ($label === '') {
                $label = 'Column '.($index + 1);
            }

            $unique = $label;
            $suffix = 2;
            while (isset($seen[$unique])) {
                $unique = $label.' ('.$suffix.')';
                $suffix++;
            }
            $seen[$unique] = true;
            $headers[] = $unique;
        }

        return $headers;
    }

    private function combineRow(array $headers, array $values): array
    {
        $row = [];
        foreach ($headers as $index => $header) {
            $row[$header] = isset($values[$index]) ? trim((string) $values[$index]) : '';
        }

        return $row;
    }

    private function isEmptyLine(array $line): bool
    {
        foreach ($line as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }
}

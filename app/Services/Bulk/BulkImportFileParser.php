<?php

namespace App\Services\Bulk;

use Illuminate\Http\UploadedFile;
use RuntimeException;
use ZipArchive;

class BulkImportFileParser
{
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
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        if (! $sheetXml) {
            throw new RuntimeException('The Excel file does not contain a readable worksheet.');
        }

        $sheet = simplexml_load_string($sheetXml);
        if ($sheet === false) {
            throw new RuntimeException('Unable to parse the Excel worksheet.');
        }

        $sheet->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $matrix = [];

        foreach ($sheet->sheetData->row as $row) {
            $rowIndex = (int) ($row['r'] ?? 0);
            foreach ($row->c as $cell) {
                $ref = (string) $cell['r'];
                [$column, $line] = $this->splitCellReference($ref);
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

        $strings = [];
        foreach ($shared->si as $item) {
            if (isset($item->t)) {
                $strings[] = (string) $item->t;

                continue;
            }

            $text = '';
            foreach ($item->r as $run) {
                $text .= (string) $run->t;
            }
            $strings[] = $text;
        }

        return $strings;
    }

    private function readCellValue(\SimpleXMLElement $cell, array $sharedStrings): string
    {
        $type = (string) ($cell['t'] ?? '');
        if ($type === 's') {
            $index = (int) $cell->v;

            return trim($sharedStrings[$index] ?? '');
        }

        if ($type === 'inlineStr') {
            return trim((string) ($cell->is->t ?? ''));
        }

        return trim((string) ($cell->v ?? ''));
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

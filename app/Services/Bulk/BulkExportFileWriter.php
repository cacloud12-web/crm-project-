<?php

namespace App\Services\Bulk;

use ZipArchive;

class BulkExportFileWriter
{
    public const DEFAULT_COLUMNS = [
        'ca_id' => 'CA ID',
        'ca_name' => 'CA Name',
        'firm_name' => 'Firm Name',
        'mobile_no' => 'Mobile No',
        'alternate_mobile_no' => 'Alternate Mobile No',
        'email_id' => 'Email',
        'gst_no' => 'GST No',
        'state' => 'State',
        'city' => 'City',
        'source' => 'Source',
        'team_size' => 'Team Size',
        'existing_software' => 'Existing Software',
        'website' => 'Website',
        'rating' => 'Rating',
        'status' => 'Status',
        'is_newly_established' => 'New Firm',
        'created_at' => 'Created At',
        'updated_at' => 'Updated At',
    ];

    public function headers(array $columns): array
    {
        $labels = [];
        foreach ($columns as $column) {
            $labels[] = self::DEFAULT_COLUMNS[$column] ?? $column;
        }

        return $labels;
    }

    public function writeCsv(string $path, array $columns, iterable $rows): int
    {
        $handle = fopen($path, 'w');
        if (! $handle) {
            throw new \RuntimeException('Unable to create export file.');
        }

        fputcsv($handle, $this->headers($columns));

        $count = 0;
        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $column) {
                $line[] = $row[$column] ?? '';
            }
            fputcsv($handle, $line);
            $count++;
        }

        fclose($handle);

        return $count;
    }

    public function writeXlsx(string $path, array $columns, iterable $rows): int
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'bulk_export_');
        if (! $zipPath) {
            throw new \RuntimeException('Unable to create temporary export file.');
        }

        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Unable to create Excel export.');
        }

        $sheetRows = '';
        $allRows = array_merge([$this->headers($columns)], $this->materializeRows($columns, $rows));
        foreach ($allRows as $rowIndex => $row) {
            $line = $rowIndex + 1;
            $cells = '';
            foreach ($row as $colIndex => $value) {
                $col = $this->columnLetter($colIndex + 1);
                $cells .= '<c r="'.$col.$line.'" t="inlineStr"><is><t>'
                    .htmlspecialchars((string) $value, ENT_XML1)
                    .'</t></is></c>';
            }
            $sheetRows .= '<row r="'.$line.'">'.$cells.'</row>';
        }

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
<Default Extension="xml" ContentType="application/xml"/>
<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
<sheets><sheet name="CA Master Export" sheetId="1" r:id="rId1"/></sheets>
</workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
</Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$sheetRows.'</sheetData></worksheet>');
        $zip->close();

        if (! copy($zipPath, $path)) {
            @unlink($zipPath);
            throw new \RuntimeException('Unable to save Excel export.');
        }

        @unlink($zipPath);

        return max(count($allRows) - 1, 0);
    }

    private function materializeRows(array $columns, iterable $rows): array
    {
        $materialized = [];
        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $column) {
                $line[] = $row[$column] ?? '';
            }
            $materialized[] = $line;
        }

        return $materialized;
    }

    private function columnLetter(int $index): string
    {
        $letter = '';
        while ($index > 0) {
            $index--;
            $letter = chr(65 + ($index % 26)).$letter;
            $index = intdiv($index, 26);
        }

        return $letter;
    }
}

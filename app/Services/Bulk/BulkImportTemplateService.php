<?php

namespace App\Services\Bulk;

use ZipArchive;

class BulkImportTemplateService
{
    public const TEMPLATE_HEADERS = [
        'ca_name',
        'firm_name',
        'mobile_no',
        'email_id',
        'gst_no',
        'state',
        'city',
        'source',
        'team_size',
        'existing_software',
        'website',
        'rating',
        'status',
    ];

    public const SAMPLE_ROW = [
        'R. Sharma',
        'Sharma & Associates',
        '9876543210',
        'ca@sharma.com',
        '27AABCS1234L1Z5',
        'Maharashtra',
        'Mumbai',
        'Website',
        '12',
        'Tally',
        'https://sharma.in',
        '5',
        'Hot',
    ];

    public function sampleCsv(): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, self::TEMPLATE_HEADERS);
        fputcsv($handle, self::SAMPLE_ROW);
        rewind($handle);
        $csv = stream_get_contents($handle) ?: '';
        fclose($handle);

        return $csv;
    }

    public function sampleXlsx(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bulk_sample_');
        if (! $path) {
            throw new \RuntimeException('Unable to create temporary file.');
        }

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Unable to create Excel template.');
        }

        $rows = [self::TEMPLATE_HEADERS, self::SAMPLE_ROW];
        $sheetRows = '';
        foreach ($rows as $rowIndex => $row) {
            $line = $rowIndex + 1;
            $cells = '';
            foreach ($row as $colIndex => $value) {
                $col = $this->columnLetter($colIndex + 1);
                $cells .= '<c r="'.$col.$line.'" t="inlineStr"><is><t>'.htmlspecialchars($value, ENT_XML1).'</t></is></c>';
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
<sheets><sheet name="CA Master Import" sheetId="1" r:id="rId1"/></sheets>
</workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
</Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$sheetRows.'</sheetData></worksheet>');
        $zip->close();

        $binary = file_get_contents($path) ?: '';
        @unlink($path);

        return $binary;
    }

    public function mapRowToTemplate(array $mappedRow): array
    {
        return [
            $mappedRow['ca_name'] ?? '',
            $mappedRow['firm_name'] ?? '',
            $mappedRow['mobile_no'] ?? '',
            $mappedRow['email_id'] ?? '',
            $mappedRow['gst_no'] ?? '',
            $mappedRow['state_id'] ?? '',
            $mappedRow['city_id'] ?? '',
            $mappedRow['source_id'] ?? '',
            $mappedRow['team_size'] ?? '',
            $mappedRow['existing_software'] ?? '',
            $mappedRow['website'] ?? '',
            $mappedRow['rating'] ?? '',
            $mappedRow['status'] ?? '',
        ];
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

<?php

namespace Tests\Unit\Ocr;

use App\Services\Ocr\OcrOfflineNoLinkFirmMatchService;
use PHPUnit\Framework\TestCase;

class OcrOfflineNoLinkFirmMatchServiceTest extends TestCase
{
    public function test_normalize_firm_name_rules(): void
    {
        $svc = new OcrOfflineNoLinkFirmMatchService;
        $this->assertSame('A AND CO', $svc->normalizeFirmName('A & Co.'));
        $this->assertSame('BHADRESH AND ASSOCIATES', $svc->normalizeFirmName('  Bhadresh & Associates  '));
        $this->assertSame('FOO BAR', $svc->normalizeFirmName('Foo, Bar!'));
    }

    public function test_exact_and_no_match_confidence(): void
    {
        $svc = new OcrOfflineNoLinkFirmMatchService;
        $index = [
            'exact' => [
                'ALPHA AND ASSOCIATES' => [[
                    'id' => 1,
                    'firm_name' => 'Alpha & Associates',
                    'city' => 'Mumbai',
                    'ocr_document_id' => 52,
                ]],
            ],
            'weak' => [],
            'row_count' => 1,
        ];

        $hit = $svc->matchOne('Alpha & Associates', $index);
        $this->assertSame('Exact', $hit['confidence']);
        $this->assertSame('Mumbai', $hit['ocr_city']);

        $miss = $svc->matchOne('Does Not Exist LLP', $index);
        $this->assertSame('No Match', $miss['confidence']);
    }

    public function test_strong_when_same_name_multiple_cities(): void
    {
        $svc = new OcrOfflineNoLinkFirmMatchService;
        $index = [
            'exact' => [
                'BETA AND CO' => [
                    ['id' => 1, 'firm_name' => 'Beta & Co', 'city' => 'Pune', 'ocr_document_id' => 1],
                    ['id' => 2, 'firm_name' => 'Beta & Co', 'city' => 'Mumbai', 'ocr_document_id' => 1],
                ],
            ],
            'weak' => [],
            'row_count' => 2,
        ];
        $hit = $svc->matchOne('Beta & Co', $index);
        $this->assertSame('Strong', $hit['confidence']);
        $this->assertSame(2, $hit['match_count']);
    }

    public function test_offline_csv_end_to_end(): void
    {
        $dir = sys_get_temp_dir().'/ocr_offline_match_'.uniqid();
        mkdir($dir);
        $masters = $dir.'/masters.csv';
        $firms = $dir.'/firms.csv';
        $out = $dir.'/out.csv';

        $mh = fopen($masters, 'wb');
        fputcsv($mh, ['ca_id', 'firm_name', 'city_id', 'source_id']);
        fputcsv($mh, [101, 'Gamma & Co.', '', 1]);
        fputcsv($mh, [102, 'Unknown Firm XYZ', '', 1]);
        fclose($mh);

        $fh = fopen($firms, 'wb');
        fputcsv($fh, ['id', 'firm_name', 'city', 'ocr_document_id']);
        fputcsv($fh, [55, 'Gamma & Co', 'Surat', 52]);
        fclose($fh);

        $report = (new OcrOfflineNoLinkFirmMatchService)->run([
            'masters_csv' => $masters,
            'firms_csv' => $firms,
            'use_local_db' => false,
            'export' => $out,
        ]);

        $this->assertSame(2, $report['counts']['masters']);
        $this->assertSame(1, $report['counts']['Exact']);
        $this->assertSame(1, $report['counts']['No Match']);
        $this->assertFileExists($out);

        @unlink($masters);
        @unlink($firms);
        @unlink($out);
        @rmdir($dir);
    }
}

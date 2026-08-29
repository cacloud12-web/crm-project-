<?php

/**
 * Convert demo-full-report.json to Excel (.xls) — no Python required.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$jsonPath = $argv[1] ?? $root.'/storage/app/audits/demo-full-report.json';

if (! is_file($jsonPath)) {
    fwrite(STDERR, "Missing JSON: {$jsonPath}\n");
    exit(1);
}

$data = json_decode((string) file_get_contents($jsonPath), true, 512, JSON_THROW_ON_ERROR);
$outPath = $argv[2] ?? $root.'/storage/app/audits/Demo_Full_Employee_Report.xls';

function demoXml(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function demoCell($value, string $type = 'String'): string
{
    if ($type === 'Number' && is_numeric($value)) {
        return '<Cell><Data ss:Type="Number">'.demoXml((string) $value).'</Data></Cell>';
    }

    return '<Cell><Data ss:Type="String">'.demoXml((string) $value).'</Data></Cell>';
}

function demoRow(array $values): string
{
    $cells = '';
    foreach ($values as $value) {
        $type = is_int($value) || is_float($value) ? 'Number' : 'String';
        $cells .= demoCell($value, $type);
    }

    return '<Row>'.$cells.'</Row>';
}

function demoTable(array $headers, array $rows): string
{
    $out = '<Table>'.demoRow($headers);
    foreach ($rows as $r) {
        $out .= demoRow($r);
    }

    return $out.'</Table>';
}

function demoSheet(string $name, string $tableXml): string
{
    return '<Worksheet ss:Name="'.demoXml($name).'">'.$tableXml.'</Worksheet>';
}

$headers = [
    'Employee', 'Role', 'Status', 'Total Demos Booked', 'Still Scheduled (Open)',
    'Completed (from booked)', 'Completion %', 'Missed', 'Rescheduled', 'Cancelled', 'Not Interested',
    'Thinking', 'Purchased',
];
$rows = [];
foreach ($data['employees'] as $emp) {
    $s = $emp['summary'];
    $ob = $s['outcome_breakdown'] ?? [];
    $rows[] = [
        $emp['employee_name'], $emp['role'] ?? '', $emp['employee_status'] ?? '',
        $s['total_demos'] ?? 0, $s['still_open'] ?? ($s['still_scheduled'] ?? 0),
        $s['completed_from_booked'] ?? ($s['completed'] ?? 0), $s['completion_pct'] ?? 0,
        $s['missed_from_booked'] ?? ($s['missed'] ?? 0),
        $s['rescheduled'] ?? 0, $s['cancelled'] ?? 0,
        $s['not_interested'] ?? 0, $ob['Thinking'] ?? 0, $ob['Purchased'] ?? 0,
    ];
}
$gt = $data['grand_totals'] ?? [];
$rows[] = [
    'GRAND TOTAL', '', '', $gt['total_demos'] ?? 0, $gt['still_open'] ?? 0,
    $gt['completed'] ?? 0, '', $gt['missed'] ?? 0,
    $gt['rescheduled'] ?? 0, $gt['cancelled'] ?? 0, $gt['not_interested'] ?? 0, '', '',
];

$sheets = [demoSheet('All Employees', demoTable($headers, $rows))];
$used = ['All Employees'];
foreach ($data['employees'] as $emp) {
    if (($emp['summary']['total_demos'] ?? 0) === 0) {
        continue;
    }
    $base = preg_replace('/[\\\\\\/*?:\\[\\]]/', '', $emp['employee_name']) ?: 'Employee';
    $base = mb_substr($base, 0, 28);
    $name = $base;
    $i = 1;
    while (in_array($name, $used, true)) {
        $name = mb_substr($base, 0, 28 - strlen((string) $i)).'_'.$i;
        $i++;
    }
    $used[] = $name;

    $detailHeaders = ['Demo ID', 'Firm', 'CA Name', 'Mobile', 'Demo At', 'Scheduled On', 'Status', 'Outcome', 'Notes'];
    $detailRows = [];
    foreach ($emp['demos'] ?? [] as $d) {
        $detailRows[] = [
            $d['demo_id'] ?? '', $d['firm_name'] ?? '', $d['ca_name'] ?? '', $d['mobile_no'] ?? '',
            $d['demo_at'] ?? '', $d['scheduled_on'] ?? '', $d['status'] ?? '', $d['outcome'] ?? '',
            $d['notes'] ?? ($d['outcome_notes'] ?? ''),
        ];
    }
    $sheets[] = demoSheet($name, demoTable($detailHeaders, $detailRows));
}

$periodTxt = ($data['from'] ?? '').' to '.($data['to'] ?? '');
$xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
$xml .= '<?mso-application progid="Excel.Sheet"?>'."\n";
$xml .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">'."\n";
$xml .= '<DocumentProperties xmlns="urn:schemas-microsoft-com:office:office"><Title>Demo Report</Title><Subject>'.demoXml($periodTxt).'</Subject></DocumentProperties>'."\n";
$xml .= implode("\n", $sheets)."\n</Workbook>\n";

$outDir = dirname($outPath);
if (! is_dir($outDir)) {
    mkdir($outDir, 0755, true);
}
file_put_contents($outPath, $xml);
echo "Excel saved: {$outPath}\n";

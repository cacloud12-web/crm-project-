<?php

/**
 * Convert employee-activity-audit.json to Excel (.xls SpreadsheetML) — no Python required.
 *
 * Usage:
 *   /opt/alt/php83/usr/bin/php scripts/employee-activity-audit-to-excel.php
 *   /opt/alt/php83/usr/bin/php scripts/employee-activity-audit-to-excel.php path/to.json path/to.xls
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$jsonPath = $argv[1] ?? $root.'/storage/app/audits/employee-activity-audit.json';

if (! is_file($jsonPath)) {
    fwrite(STDERR, "Missing JSON: {$jsonPath}\n");
    exit(1);
}

$data = json_decode((string) file_get_contents($jsonPath), true, 512, JSON_THROW_ON_ERROR);
$period = $data['period'] ?? 'all_time';
if (is_array($period)) {
    $outName = sprintf(
        'CRM_Full_Report_Last_%s_Days_%s_to_%s.xls',
        $period['days'] ?? '',
        $period['from'] ?? '',
        $period['to'] ?? ''
    );
} else {
    $outName = 'CRM_Full_Report_All_Time.xls';
}
$outPath = $argv[2] ?? $root.'/storage/app/audits/'.$outName;

$xml = buildWorkbook($data);
$outDir = dirname($outPath);
if (! is_dir($outDir)) {
    mkdir($outDir, 0755, true);
}
file_put_contents($outPath, $xml);

echo "Excel saved: {$outPath}\n";

function xml(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function cell(string $value, string $type = 'String'): string
{
    if ($type === 'Number' && is_numeric($value)) {
        return '<Cell><Data ss:Type="Number">'.xml((string) $value).'</Data></Cell>';
    }

    return '<Cell><Data ss:Type="String">'.xml((string) $value).'</Data></Cell>';
}

function row(array $values): string
{
    $cells = '';
    foreach ($values as $value) {
        $type = is_int($value) || is_float($value) ? 'Number' : 'String';
        $cells .= cell((string) $value, $type);
    }

    return '<Row>'.$cells.'</Row>';
}

function table(array $headers, array $rows): string
{
    $out = '<Table>';
    $out .= row($headers);
    foreach ($rows as $r) {
        $out .= row($r);
    }
    $out .= '</Table>';

    return $out;
}

function sheet(string $name, string $tableXml): string
{
    return '<Worksheet ss:Name="'.xml($name).'">'.$tableXml.'</Worksheet>';
}

function buildWorkbook(array $data): string
{
    $period = $data['period'] ?? 'all_time';
    $periodTxt = is_array($period)
        ? ($period['from'].' to '.$period['to'])
        : 'ALL TIME';

    $summaryHeaders = [
        'Employee', 'Leads Assigned (now)', 'Leads Assigned (period)', 'Demo Target (period)',
        'Demo Achieved (period)', 'Achievement %', 'Today Target', 'Today Achieved',
        'Demos Scheduled', 'Demos Completed', 'Demos Open', 'Follow-ups Total',
        'Demo Scheduled FU (open)', 'Demo Completed FU', 'Calls Total', 'Purchases',
        'Purchased Demo Results', 'Integrity Issues',
    ];
    $summaryRows = [];
    foreach ($data['employees'] as $emp) {
        $s = $emp['summary'];
        $summaryRows[] = [
            $emp['employee_name'],
            $s['leads_assigned_active'] ?? 0,
            $s['leads_assigned_in_period'] ?? 0,
            $s['demo_target_period'] ?? 0,
            $s['demo_achieved_period'] ?? 0,
            $s['demo_achievement_pct'] ?? 0,
            $s['demo_target_today'] ?? 0,
            $s['demo_achieved_today'] ?? 0,
            $s['demos_scheduled_created'] ?? 0,
            $s['demos_completed'] ?? 0,
            $s['demos_still_open'] ?? 0,
            $s['followups_total'] ?? 0,
            $s['followups_open_demo_scheduled'] ?? 0,
            $s['followups_demo_completed'] ?? 0,
            $s['calls_total'] ?? 0,
            $s['purchases_total'] ?? 0,
            $s['purchased_demo_results'] ?? 0,
            $s['integrity_issue_count'] ?? 0,
        ];
    }
    $gt = $data['grand_totals'] ?? [];
    $summaryRows[] = [
        'GRAND TOTAL',
        $gt['leads_assigned_active'] ?? 0,
        $gt['leads_assigned_in_period'] ?? 0,
        $gt['demo_target_period'] ?? 0,
        $gt['demo_achieved_period'] ?? 0,
        '',
        '',
        '',
        $gt['demos_scheduled_created'] ?? 0,
        $gt['demos_completed'] ?? 0,
        '',
        $gt['followups_total'] ?? 0,
        '',
        '',
        $gt['calls_total'] ?? 0,
        $gt['purchases_total'] ?? 0,
        $gt['purchased_demo_results'] ?? 0,
        $data['integrity_issue_count'] ?? 0,
    ];

    $sheets = [];
    $sheets[] = sheet('Summary', table($summaryHeaders, $summaryRows));

    if (! empty($data['integrity_issues'])) {
        $issueRows = [];
        foreach ($data['integrity_issues'] as $issue) {
            $issueRows[] = [
                $issue['employee'] ?? '',
                $issue['type'] ?? '',
                $issue['id'] ?? '',
                $issue['ca_id'] ?? '',
                $issue['firm_name'] ?? ($issue['followup_type'] ?? ''),
                implode(', ', $issue['issues'] ?? []),
            ];
        }
        $sheets[] = sheet('Integrity Issues', table(
            ['Employee', 'Type', 'Record ID', 'CA ID', 'Firm', 'Issues'],
            $issueRows
        ));
    }

    $usedNames = ['Summary', 'Integrity Issues'];
    foreach ($data['employees'] as $emp) {
        $name = uniqueSheetName($emp['employee_name'], $usedNames);
        $rows = [];
        foreach (['demos', 'followups', 'calls', 'purchases'] as $section) {
            $items = $emp[$section] ?? [];
            if ($items === []) {
                continue;
            }
            $rows[] = [strtoupper($section), '', '', '', '', ''];
            $cols = array_keys($items[0]);
            $rows[] = $cols;
            foreach ($items as $item) {
                $line = [];
                foreach ($cols as $col) {
                    $val = $item[$col] ?? '';
                    if ($col === 'integrity_flags' && is_array($val)) {
                        $val = $val === [] ? '' : 'FLAG: '.implode(', ', $val);
                    } elseif (is_array($val)) {
                        $val = json_encode($val);
                    }
                    $line[] = $val;
                }
                $rows[] = $line;
            }
            $rows[] = ['', '', '', '', '', ''];
        }
        if ($rows === []) {
            $rows[] = ['No activity in selected period'];
        }
        $maxCols = max(array_map('count', $rows));
        $headers = range(1, $maxCols);
        $sheets[] = sheet($name, table($headers, $rows));
    }

    $header = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
    $header .= '<?mso-application progid="Excel.Sheet"?>'."\n";
    $header .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">'."\n";
    $header .= '<DocumentProperties xmlns="urn:schemas-microsoft-com:office:office">';
    $header .= '<Title>CRM Full Report</Title>';
    $header .= '<Subject>Period: '.xml($periodTxt).'</Subject>';
    $header .= '</DocumentProperties>'."\n";

    return $header.implode("\n", $sheets)."\n</Workbook>\n";
}

function uniqueSheetName(string $name, array &$used): string
{
    $base = preg_replace('/[\\\\\\/*?:\\[\\]]/', '', $name) ?? 'Employee';
    $base = mb_substr(trim($base), 0, 28) ?: 'Employee';
    $candidate = $base;
    $i = 1;
    while (in_array($candidate, $used, true)) {
        $suffix = '_'.$i;
        $candidate = mb_substr($base, 0, 31 - strlen($suffix)).$suffix;
        $i++;
    }
    $used[] = $candidate;

    return $candidate;
}

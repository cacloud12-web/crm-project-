<?php

/**
 * READ-ONLY: export production OCR-linked missing-city masters to CSV.
 * SELECT only. No writes.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$env = file($root.'/.env', FILE_IGNORE_NEW_LINES) ?: [];
$host = $db = $user = $pass = null;
foreach ($env as $line) {
    $line = trim($line);
    if ($line === '' || ! str_starts_with($line, '#')) {
        continue;
    }
    $line = ltrim(substr($line, 1));
    if (str_starts_with($line, 'DB_HOST=')) {
        $host = trim(substr($line, 8), " \t\"'");
    } elseif (str_starts_with($line, 'DB_DATABASE=')) {
        $db = trim(substr($line, 12), " \t\"'");
    } elseif (str_starts_with($line, 'DB_USERNAME=')) {
        $user = trim(substr($line, 12), " \t\"'");
    } elseif (str_starts_with($line, 'DB_PASSWORD=')) {
        $pass = trim(substr($line, 12), " \t\"'");
    }
}

if (! $host || ! $db || ! $user || $pass === null) {
    fwrite(STDERR, "Missing Hostinger DB_* comments in .env\n");
    exit(1);
}

$outPath = $root.'/storage/app/audits/prod-ocr-linked-missing-masters.csv';
@mkdir(dirname($outPath), 0755, true);

echo "Connecting (read-only export)...\n";
$t0 = microtime(true);
$pdo = new PDO(
    "mysql:host={$host};port=3306;dbname={$db};charset=utf8mb4",
    $user,
    $pass,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 60,
        PDO::MYSQL_ATTR_INIT_COMMAND => 'SET SESSION net_read_timeout=600, net_write_timeout=600, wait_timeout=600',
    ]
);
echo 'connected '.round(microtime(true) - $t0, 2)."s\n";

$sql = <<<'SQL'
SELECT ca_id, firm_name, city_id, ocr_city_text, source_ocr_row_id, source_ocr_document_id
FROM ca_masters
WHERE deleted_at IS NULL
  AND (city_id IS NULL OR city_id = 0)
  AND source_ocr_row_id IS NOT NULL
ORDER BY ca_id
SQL;

$t0 = microtime(true);
$stmt = $pdo->query($sql);
$fh = fopen($outPath, 'wb');
if ($fh === false) {
    fwrite(STDERR, "Cannot open {$outPath}\n");
    exit(1);
}
fputcsv($fh, ['ca_id', 'firm_name', 'city_id', 'ocr_city_text', 'source_ocr_row_id', 'source_ocr_document_id']);
$n = 0;
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($fh, [
        $row['ca_id'],
        $row['firm_name'],
        $row['city_id'],
        $row['ocr_city_text'],
        $row['source_ocr_row_id'],
        $row['source_ocr_document_id'],
    ]);
    $n++;
    if ($n % 1000 === 0) {
        echo "exported {$n}...\n";
    }
}
fclose($fh);
echo "DONE rows={$n} file={$outPath} elapsed=".round(microtime(true) - $t0, 2)."s\n";

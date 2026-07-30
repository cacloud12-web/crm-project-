<?php

/**
 * READ-ONLY: export production cities master (small) for mapping checks.
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

$pdo = new PDO(
    "mysql:host={$host};port=3306;dbname={$db};charset=utf8mb4",
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 60]
);

$out = $root.'/storage/app/audits/prod-cities.csv';
$fh = fopen($out, 'wb');
fputcsv($fh, ['id', 'city_name', 'state_id']);
$cols = $pdo->query('SHOW COLUMNS FROM cities')->fetchAll(PDO::FETCH_COLUMN);
$nameCol = in_array('city_name', $cols, true) ? 'city_name' : (in_array('name', $cols, true) ? 'name' : null);
$stateCol = in_array('state_id', $cols, true) ? 'state_id' : 'NULL';
if ($nameCol === null) {
    fwrite(STDERR, 'cities name column unknown: '.implode(',', $cols)."\n");
    exit(1);
}
$sql = "SELECT id, {$nameCol} AS city_name, {$stateCol} AS state_id FROM cities";
$n = 0;
foreach ($pdo->query($sql) as $row) {
    fputcsv($fh, [$row['id'], $row['city_name'], $row['state_id']]);
    $n++;
}
fclose($fh);
echo "cities={$n} file={$out}\n";

<?php
/**
 * Schema Drift Scanner
 *
 * Compares Postgres (production source of truth for DATA) against
 * MySQL (freshly migrated via Laravel — source of truth for MIGRATIONS).
 *
 * Reports columns that exist in PG but NOT in MySQL — these are drift
 * columns added to prod DB outside of Laravel's migration system.
 */

$pgUrl = getenv('DATABASE_URL') ?: '';
if (!$pgUrl) { fwrite(STDERR, "DATABASE_URL not set\n"); exit(1); }
$pg = parse_url(preg_replace('/^postgres:\/\//', 'postgresql://', $pgUrl));
$pgConn = new PDO(
    sprintf('pgsql:host=%s;port=%s;dbname=%s', $pg['host'], $pg['port'] ?? 5432, ltrim($pg['path'], '/')),
    $pg['user'], urldecode($pg['pass'] ?? ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$myConn = new PDO(
    'mysql:host=127.0.0.1;port=9000;dbname=taxnest_staging;charset=utf8mb4',
    'root', '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// Get all PG tables
$pgTables = $pgConn->query(
    "SELECT table_name FROM information_schema.tables WHERE table_schema='public' AND table_type='BASE TABLE' ORDER BY table_name"
)->fetchAll(PDO::FETCH_COLUMN);

$myTables = $myConn->query(
    "SELECT table_name FROM information_schema.tables WHERE table_schema='taxnest_staging' AND table_type='BASE TABLE' ORDER BY table_name"
)->fetchAll(PDO::FETCH_COLUMN);

$missingTablesInMy = array_diff($pgTables, $myTables);
$extraTablesInMy   = array_diff($myTables, $pgTables);

echo "=== TABLES ONLY IN POSTGRES (missing from migrations) ===\n";
foreach ($missingTablesInMy as $t) echo "  - $t\n";
if (!$missingTablesInMy) echo "  (none)\n";

echo "\n=== TABLES ONLY IN MYSQL (dropped in prod?) ===\n";
foreach ($extraTablesInMy as $t) echo "  - $t\n";
if (!$extraTablesInMy) echo "  (none)\n";

echo "\n=== COLUMN DRIFT (columns in PG missing from MySQL) ===\n";
printf("%-35s %-30s %-20s %-10s %s\n", 'TABLE', 'COLUMN', 'PG_TYPE', 'NULLABLE', 'DEFAULT');
echo str_repeat('-', 120) . "\n";

$driftList = [];
$sharedTables = array_intersect($pgTables, $myTables);

foreach ($sharedTables as $table) {
    // PG columns
    $pgColsStmt = $pgConn->prepare(
        "SELECT column_name, data_type, udt_name, is_nullable, column_default, character_maximum_length,
                numeric_precision, numeric_scale
         FROM information_schema.columns
         WHERE table_schema='public' AND table_name = ?
         ORDER BY ordinal_position"
    );
    $pgColsStmt->execute([$table]);
    $pgCols = [];
    foreach ($pgColsStmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $pgCols[$c['column_name']] = $c;
    }

    // MySQL columns
    $myColsStmt = $myConn->prepare(
        "SELECT column_name FROM information_schema.columns
         WHERE table_schema='taxnest_staging' AND table_name = ?"
    );
    $myColsStmt->execute([$table]);
    $myCols = $myColsStmt->fetchAll(PDO::FETCH_COLUMN);
    $myColsLower = array_map('strtolower', $myCols);

    foreach ($pgCols as $colName => $meta) {
        if (!in_array(strtolower($colName), $myColsLower, true)) {
            $driftList[] = [
                'table' => $table,
                'column' => $colName,
                'pg_type' => $meta['data_type'],
                'udt' => $meta['udt_name'],
                'nullable' => $meta['is_nullable'],
                'default' => $meta['column_default'],
                'char_len' => $meta['character_maximum_length'],
                'num_prec' => $meta['numeric_precision'],
                'num_scale' => $meta['numeric_scale'],
            ];
            printf("%-35s %-30s %-20s %-10s %s\n",
                $table, $colName, $meta['data_type'], $meta['is_nullable'],
                substr((string)$meta['column_default'], 0, 40)
            );
        }
    }
}

echo "\n=== SUMMARY ===\n";
echo "Total drift columns: " . count($driftList) . "\n";

// Write JSON report
file_put_contents(
    __DIR__ . '/../.local/drift_report.json',
    json_encode($driftList, JSON_PRETTY_PRINT)
);
echo "Report written: .local/drift_report.json\n";

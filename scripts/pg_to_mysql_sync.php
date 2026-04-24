<?php
/**
 * Postgres → MySQL data sync script.
 *
 * Reads data from current $DATABASE_URL (Postgres) and writes to MySQL staging.
 * Designed to run in a single PHP process — uses raw PDO for speed and
 * sidesteps Laravel's config caching issues with dual connections.
 *
 * Usage:
 *   php scripts/pg_to_mysql_sync.php
 *
 * Output:
 *   Row count comparison per table and overall match/mismatch summary.
 */

// ---- Postgres source ----
$pgUrl = getenv('DATABASE_URL') ?: '';
if (!$pgUrl) {
    fwrite(STDERR, "ERROR: DATABASE_URL env not set\n");
    exit(1);
}
$pg = parse_url(preg_replace('/^postgres:\/\//', 'postgresql://', $pgUrl));
$pgDsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $pg['host'], $pg['port'] ?? 5432, ltrim($pg['path'], '/'));
$pgConn = new PDO($pgDsn, $pg['user'], urldecode($pg['pass'] ?? ''), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_TIMEOUT => 30,
]);
$pgConn->exec("SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY");
$pgConn->exec("SET default_transaction_read_only = on");
fwrite(STDERR, "[SAFETY] Postgres session forced READ ONLY.\n");
try {
    $pgConn->exec("CREATE TEMP TABLE __ro_check (x int)");
    fwrite(STDERR, "[SAFETY] !!! READ-ONLY ENFORCEMENT FAILED — aborting !!!\n");
    exit(1);
} catch (PDOException $e) {
    fwrite(STDERR, "[SAFETY] Verified: PG writes blocked (" . substr($e->getMessage(), 0, 60) . "...)\n");
}

// ---- MySQL target ----
$myDsn = 'mysql:host=127.0.0.1;port=9000;dbname=taxnest_staging;charset=utf8mb4';
$myConn = new PDO($myDsn, 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$myConn->exec("SET FOREIGN_KEY_CHECKS=0");
$myConn->exec("SET UNIQUE_CHECKS=0");
$myConn->exec("SET autocommit=0");

// ---- Discover tables (from MySQL — schema is the truth) ----
$tables = $myConn->query(
    "SELECT table_name FROM information_schema.tables WHERE table_schema='taxnest_staging' AND table_type='BASE TABLE' ORDER BY table_name"
)->fetchAll(PDO::FETCH_COLUMN);

// Skip Laravel framework tables and ephemeral state — not real business data
$skipTables = ['migrations', 'cache', 'cache_locks', 'failed_jobs', 'jobs', 'job_batches', 'sessions', 'password_reset_tokens'];

$results = [];
$totalCopied = 0;
$mismatches = [];

echo str_repeat('=', 80) . "\n";
echo "Postgres → MySQL Data Sync\n";
echo str_repeat('=', 80) . "\n";
printf("%-40s %12s %12s %10s\n", 'TABLE', 'PG_ROWS', 'MY_ROWS', 'STATUS');
echo str_repeat('-', 80) . "\n";

foreach ($tables as $table) {
    if (in_array($table, $skipTables, true)) {
        continue;
    }

    try {
        // Source row count
        $pgCount = (int) $pgConn->query("SELECT COUNT(*) FROM \"{$table}\"")->fetchColumn();
    } catch (Throwable $e) {
        printf("%-40s %12s %12s %10s\n", $table, 'ERR', '-', 'NO_PG');
        continue;
    }

    if ($pgCount === 0) {
        // No data to copy — just verify target is also empty
        $myCount = (int) $myConn->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        printf("%-40s %12d %12d %10s\n", $table, 0, $myCount, 'EMPTY');
        continue;
    }

    // Discover columns from PG
    $colsStmt = $pgConn->prepare(
        "SELECT column_name, data_type FROM information_schema.columns
         WHERE table_schema='public' AND table_name = ? ORDER BY ordinal_position"
    );
    $colsStmt->execute([$table]);
    $cols = $colsStmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($cols)) {
        printf("%-40s %12s %12s %10s\n", $table, 'ERR', '-', 'NO_COLS');
        continue;
    }
    $colNames = array_column($cols, 'column_name');
    $colTypes = array_combine($colNames, array_column($cols, 'data_type'));

    if (getenv('DRY_RUN') === '1') {
        $myCount = (int) $myConn->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        $status = ($pgCount === $myCount) ? 'OK_DRY' : 'DRY_DIFF';
        printf("%-40s %12d %12d %10s\n", $table, $pgCount, $myCount, $status);
        if ($pgCount !== $myCount) {
            $mismatches[] = "$table: PG=$pgCount MY=$myCount";
        }
        continue;
    }

    // Truncate target table for clean copy
    try {
        $myConn->exec("TRUNCATE TABLE `{$table}`");
    } catch (Throwable $e) {
        // Ignore — some tables can't be truncated due to FKs (already disabled though)
        $myConn->exec("DELETE FROM `{$table}`");
    }

    // Stream rows in batches of 500
    $copied = 0;
    $pgQuery = $pgConn->prepare("SELECT * FROM \"{$table}\" ORDER BY 1");
    $pgQuery->execute();
    $colList = '`' . implode('`,`', $colNames) . '`';
    $placeholders = '(' . rtrim(str_repeat('?,', count($colNames)), ',') . ')';

    $batch = [];
    $batchSize = 200;
    while ($row = $pgQuery->fetch(PDO::FETCH_ASSOC)) {
        // Normalize values: PG booleans → 0/1, JSON strings stay strings, NULL stays NULL
        $values = [];
        foreach ($colNames as $col) {
            $v = $row[$col] ?? null;
            $type = $colTypes[$col] ?? 'text';
            if ($v === null) {
                $values[] = null;
            } elseif ($type === 'boolean') {
                // PG returns 't'/'f' or true/false; coerce to 1/0
                $values[] = ($v === 't' || $v === true || $v === '1' || $v === 1) ? 1 : 0;
            } elseif (in_array($type, ['json', 'jsonb'], true)) {
                $values[] = is_string($v) ? $v : json_encode($v);
            } elseif ($type === 'bytea') {
                // Hex-decode PG bytea (\x...) — rare in our schema
                $values[] = is_string($v) && substr($v, 0, 2) === '\\x' ? hex2bin(substr($v, 2)) : $v;
            } else {
                $values[] = $v;
            }
        }
        $batch[] = $values;

        if (count($batch) >= $batchSize) {
            $copied += insertBatch($myConn, $table, $colList, $placeholders, $batch);
            $batch = [];
        }
    }
    if (!empty($batch)) {
        $copied += insertBatch($myConn, $table, $colList, $placeholders, $batch);
    }
    $myConn->commit();
    $myConn->beginTransaction();

    $myCount = (int) $myConn->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    $status = ($pgCount === $myCount) ? 'OK' : 'MISMATCH';
    if ($status === 'MISMATCH') {
        $mismatches[] = "$table: PG=$pgCount MY=$myCount";
    }
    $totalCopied += $copied;
    printf("%-40s %12d %12d %10s\n", $table, $pgCount, $myCount, $status);
}

$myConn->commit();

echo str_repeat('=', 80) . "\n";
echo "Total rows copied: {$totalCopied}\n";
if (empty($mismatches)) {
    echo "RESULT: ALL TABLES MATCH ✓\n";
    exit(0);
} else {
    echo "RESULT: " . count($mismatches) . " MISMATCH(ES):\n";
    foreach ($mismatches as $m) {
        echo "  - $m\n";
    }
    exit(2);
}

function insertBatch(PDO $conn, string $table, string $colList, string $placeholders, array $batch): int
{
    $sql = "INSERT INTO `{$table}` ({$colList}) VALUES " . implode(',', array_fill(0, count($batch), $placeholders));
    $flatten = [];
    foreach ($batch as $row) {
        foreach ($row as $v) {
            $flatten[] = $v;
        }
    }
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($flatten);
        return count($batch);
    } catch (Throwable $e) {
        // Fallback: row-by-row to identify the bad row
        $ok = 0;
        foreach ($batch as $row) {
            try {
                $rowSql = "INSERT INTO `{$table}` ({$colList}) VALUES {$placeholders}";
                $rowStmt = $conn->prepare($rowSql);
                $rowStmt->execute($row);
                $ok++;
            } catch (Throwable $ee) {
                fwrite(STDERR, "  ! row insert failed in {$table}: " . substr($ee->getMessage(), 0, 200) . "\n");
            }
        }
        return $ok;
    }
}

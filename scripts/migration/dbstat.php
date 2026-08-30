<?php
/**
 * Per-table fingerprint of a Laravel app's MySQL/MariaDB database.
 * Runs on EITHER server. Read-only — issues nothing but SELECT and CHECKSUM.
 *
 *   php dbstat.php <app-root> [--fast]
 *
 * Prints one TAB-separated line per table, sorted by name:
 *   <table>  <rows>  <checksum>
 *
 * CHECKSUM TABLE reads every row, so it catches silent content corruption that
 * a row count would miss. --fast skips it (row counts only) for a quick look.
 *
 * The password is read from .env and never printed.
 */

$root = $argv[1] ?? null;
if (!$root || !is_dir($root)) {
    fwrite(STDERR, "usage: dbstat.php <app-root> [--fast]\n");
    exit(2);
}
$fast = in_array('--fast', $argv, true);

$envPath = rtrim($root, '/') . '/.env';
if (!is_readable($envPath)) {
    fwrite(STDERR, "dbstat: cannot read $envPath\n");
    exit(2);
}

// Live .env values are quoted; parse_ini_file keeps the quotes and produces a
// mangled username, so parse by hand and trim.
$cfg = [];
foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') {
        continue;
    }
    if (!preg_match('/^([A-Z0-9_]+)\s*=\s*(.*)$/', $line, $m)) {
        continue;
    }
    $cfg[$m[1]] = trim($m[2], " \t\"'");
}

$host = $cfg['DB_HOST'] ?? '127.0.0.1';
$port = (int) ($cfg['DB_PORT'] ?? 3306);
$name = $cfg['DB_DATABASE'] ?? '';
$user = $cfg['DB_USERNAME'] ?? '';
$pass = $cfg['DB_PASSWORD'] ?? '';

if ($name === '' || $user === '') {
    fwrite(STDERR, "dbstat: DB_DATABASE / DB_USERNAME missing from $envPath\n");
    exit(2);
}

mysqli_report(MYSQLI_REPORT_OFF);
$db = @new mysqli($host, $user, $pass, $name, $port);
if ($db->connect_errno) {
    // Deliberately does not echo the DSN — it would leak the username.
    fwrite(STDERR, "dbstat: connection failed ({$db->connect_errno})\n");
    exit(3);
}
$db->set_charset('utf8mb4');

$tables = [];
$res = $db->query(
    "SELECT table_name FROM information_schema.tables
      WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'
      ORDER BY table_name"
);
while ($row = $res->fetch_row()) {
    $tables[] = $row[0];
}

// Every table is checksummed, including the churning ones (sessions, cache,
// jobs). Skipping them here would mean a row swapped for a different row of
// the same count passes verification unnoticed. Deciding which differences are
// tolerable belongs to 05-verify.sh, which knows whether the site is still
// live or already down for cutover.
echo "# tables\t" . count($tables) . "\n";
foreach ($tables as $t) {
    $q = $db->query('SELECT COUNT(*) FROM `' . $t . '`');
    $rows = $q ? (int) $q->fetch_row()[0] : -1;

    $sum = '-';
    if (!$fast) {
        $q = $db->query('CHECKSUM TABLE `' . $t . '`');
        if ($q && ($r = $q->fetch_row())) {
            $sum = $r[1] === null ? '-' : (string) $r[1];
        }
    }
    printf("%s\t%d\t%s\n", $t, $rows, $sum);
}

$db->close();

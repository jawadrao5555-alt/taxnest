<?php
/**
 * Write a MySQL client defaults file from a Laravel .env.
 *
 *   php mycnf.php <app-root> <target-path>
 *
 * Exists so the database password never travels through a shell command line
 * (visible in `ps` to every other user on a shared host) and never lands in a
 * log. The target is created 0600 before anything is written to it, and this
 * script prints nothing on success.
 */

$root   = $argv[1] ?? null;
$target = $argv[2] ?? null;

if (!$root || !$target) {
    fwrite(STDERR, "usage: mycnf.php <app-root> <target-path>\n");
    exit(2);
}

$envPath = rtrim($root, '/') . '/.env';
if (!is_readable($envPath)) {
    fwrite(STDERR, "mycnf: cannot read $envPath\n");
    exit(2);
}

// Live .env values are quoted; parse_ini_file would keep the quotes and hand
// MySQL a mangled username, which surfaces as "access denied".
$cfg = [];
foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') {
        continue;
    }
    if (preg_match('/^([A-Z0-9_]+)\s*=\s*(.*)$/', $line, $m)) {
        $cfg[$m[1]] = trim($m[2], " \t\"'");
    }
}

foreach (['DB_USERNAME', 'DB_PASSWORD', 'DB_DATABASE'] as $need) {
    if (!isset($cfg[$need]) || $cfg[$need] === '') {
        fwrite(STDERR, "mycnf: $need missing or empty in $envPath\n");
        exit(2);
    }
}

// my.cnf treats a leading/trailing quote as part of the value unless the whole
// value is quoted, and backslashes are escapes. Double-quote and escape.
$q = static fn (string $v): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';

$ini = "[client]\n"
     . 'host=' . ($cfg['DB_HOST'] ?? '127.0.0.1') . "\n"
     . 'port=' . ($cfg['DB_PORT'] ?? '3306') . "\n"
     . 'user=' . $q($cfg['DB_USERNAME']) . "\n"
     . 'password=' . $q($cfg['DB_PASSWORD']) . "\n"
     . "default-character-set=utf8mb4\n";

// Create empty and locked down first, so the secret is never briefly readable.
$fh = @fopen($target, 'w');
if ($fh === false) {
    fwrite(STDERR, "mycnf: cannot write $target\n");
    exit(2);
}
@chmod($target, 0600);
fwrite($fh, $ini);
fclose($fh);

exit(0);

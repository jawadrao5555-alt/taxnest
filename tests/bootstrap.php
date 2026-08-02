<?php

/*
 * PHPUnit test bootstrap.
 *
 * The workspace shell exports app env vars (SESSION_DRIVER=file,
 * CACHE_STORE=file, ...). PHPUnit's <env force="true"> overrides
 * $_ENV and putenv() but NOT $_SERVER — and Laravel's env reader
 * prefers $_SERVER, so shell-exported values would silently beat
 * phpunit.xml and leak state (rate limiter, sessions) between runs.
 *
 * To keep the scrub list from drifting out of sync with phpunit.xml,
 * we parse phpunit.xml here and automatically scrub EVERY <env> key
 * declared in it from $_SERVER (force="true" or not) — so the
 * phpunit.xml values are the ones the app actually sees. Adding a new
 * state-bearing driver var only requires touching phpunit.xml.
 *
 * A few aliases/related keys are scrubbed too even though they are not
 * declared in phpunit.xml (e.g. CACHE_DRIVER is the legacy alias of
 * CACHE_STORE, DATABASE_URL would beat DB_CONNECTION/DB_DATABASE).
 * tests/TestCase.php asserts the effective config as a second line
 * of defense.
 */

$phpunitXml = __DIR__ . '/../phpunit.xml';

$keys = [
    // Aliases / companions of phpunit.xml-managed keys that Laravel also
    // reads and that a shell export could leak through:
    'CACHE_DRIVER',   // legacy alias of CACHE_STORE
    'DATABASE_URL',   // would override DB_CONNECTION/DB_DATABASE
];

$xml = @simplexml_load_file($phpunitXml);
if ($xml === false || !isset($xml->php)) {
    fwrite(STDERR, "tests/bootstrap.php: could not parse phpunit.xml <php><env> block — env isolation cannot be guaranteed.\n");
    exit(1);
}

foreach ($xml->php->env as $env) {
    $name = (string) $env['name'];
    if ($name !== '') {
        $keys[] = $name;
    }
}

if (!in_array('APP_ENV', $keys, true) || !in_array('CACHE_STORE', $keys, true)) {
    fwrite(STDERR, "tests/bootstrap.php: phpunit.xml is missing expected <env> entries (APP_ENV/CACHE_STORE) — refusing to run with uncertain env isolation.\n");
    exit(1);
}

/*
 * NOTE: PHPUnit applies <php><env> BEFORE loading this bootstrap, so we
 * must only scrub $_SERVER here (the one channel force="true" does not
 * touch). Never unset $_ENV or putenv() here — that would wipe the values
 * PHPUnit just set. Keys that must beat shell exports need force="true"
 * in phpunit.xml.
 */
foreach ($keys as $key) {
    unset($_SERVER[$key]);
}

require __DIR__ . '/../vendor/autoload.php';

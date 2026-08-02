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
 * Scrub the isolation-critical keys from $_SERVER here so the
 * phpunit.xml <php><env> values are the ones the app actually sees.
 * tests/TestCase.php asserts the effective config as a second line
 * of defense.
 */
foreach ([
    'APP_ENV',
    'CACHE_STORE',
    'CACHE_DRIVER',
    'DB_CONNECTION',
    'DB_DATABASE',
    'DATABASE_URL',
    'SESSION_DRIVER',
    'QUEUE_CONNECTION',
    'MAIL_MAILER',
] as $key) {
    unset($_SERVER[$key]);
}

require __DIR__ . '/../vendor/autoload.php';

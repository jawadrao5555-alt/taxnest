<?php
use Illuminate\Support\Str;

$dbUrl = null;
foreach (['DATABASE_URL'] as $envKey) {
    $val = getenv($envKey) ?: ($_ENV[$envKey] ?? ($_SERVER[$envKey] ?? null));
    if ($val && preg_match('/^postgres(ql)?:\/\//', $val)) {
        $dbUrl = $val;
        break;
    }
}

if (empty($dbUrl) && file_exists('/tmp/prod_database_url')) {
    $raw = trim(file_get_contents('/tmp/prod_database_url'));
    if (!empty($raw) && preg_match('/^postgres(ql)?:\/\//', $raw)) {
        $dbUrl = $raw;
    }
}

if (empty($dbUrl) && file_exists('/tmp/replitdb')) {
    $raw = trim(file_get_contents('/tmp/replitdb'));
    if (!empty($raw) && preg_match('/^postgres(ql)?:\/\//', $raw)) {
        $dbUrl = $raw;
    }
}

if (!empty($dbUrl)) {
    $normalized = preg_replace('/^postgres:\/\//', 'postgresql://', $dbUrl);
    $parsedUrl = parse_url($normalized) ?: [];
    $dbHost = $parsedUrl['host'] ?? '127.0.0.1';
    $dbPort = $parsedUrl['port'] ?? '5432';
    $dbName = isset($parsedUrl['path']) ? ltrim($parsedUrl['path'], '/') : 'laravel';
    $dbUser = $parsedUrl['user'] ?? 'root';
    $dbPass = isset($parsedUrl['pass']) ? urldecode($parsedUrl['pass']) : '';
    $sslMode = 'prefer';
    if (isset($parsedUrl['query'])) {
        parse_str($parsedUrl['query'], $qp);
        $sslMode = $qp['sslmode'] ?? 'prefer';
    }
} else {
    $dbHost = env('DB_HOST', '127.0.0.1');
    $dbPort = env('DB_PORT', '5432');
    $dbName = env('DB_DATABASE', 'laravel');
    $dbUser = env('DB_USERNAME', 'root');
    $dbPass = env('DB_PASSWORD', '');
    $sslMode = 'prefer';
}

return [
    'default' => 'pgsql',
    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
        ],
        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
        ],
        'pgsql' => [
            'driver' => 'pgsql',
            'host' => $dbHost,
            'port' => $dbPort,
            'database' => $dbName,
            'username' => $dbUser,
            'password' => $dbPass,
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => $sslMode,
            'connect_timeout' => 10,
            'options' => [
                PDO::ATTR_TIMEOUT => 10,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_PERSISTENT => true,
            ],
        ],
    ],
    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],
    'redis' => [
        'client' => env('REDIS_CLIENT', 'phpredis'),
        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug(env('APP_NAME', 'laravel'), '_').'_database_'),
        ],
        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],
    ],
];

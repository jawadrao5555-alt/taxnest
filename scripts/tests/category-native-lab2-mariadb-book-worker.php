<?php

/** Concurrent work-order creator for category MariaDB lab (CLI worker). */

use App\Models\Company;
use App\Services\PosServiceWorkOrderService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$host = getenv('DB_HOST') ?: '127.0.0.1';
if (! in_array($host, ['127.0.0.1', 'localhost'], true)) {
    fwrite(STDERR, "non-loopback host\n");
    exit(2);
}
$dbName = getenv('DB_DATABASE') ?: '';
if ($dbName !== 'taxnest_category_lab') {
    fwrite(STDERR, "wrong database\n");
    exit(2);
}

config([
    'database.default' => 'mysql',
    'database.connections.mysql.host' => $host,
    'database.connections.mysql.port' => getenv('DB_PORT') ?: '3306',
    'database.connections.mysql.database' => $dbName,
    'database.connections.mysql.username' => getenv('DB_USERNAME') ?: 'taxnest_dev',
    'database.connections.mysql.password' => getenv('DB_PASSWORD') ?: 'taxnest_local_dev_only',
    'database.connections.mysql.unix_socket' => '',
]);
DB::purge('mysql');
DB::reconnect('mysql');

$companyId = (int) ($argv[1] ?? 0);
$company = Company::findOrFail($companyId);
app(PosServiceWorkOrderService::class)->create($company, [
    'customer_name' => 'Race',
    'title' => 'Race job',
    'quantity' => 1,
    'unit_price' => 1,
    'details' => ['staff' => 'R', 'station' => '2'],
], null, null);

echo "ok\n";
exit(0);

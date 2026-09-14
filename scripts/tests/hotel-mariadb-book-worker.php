<?php

/**
 * Single booking worker for hotel MariaDB concurrency check.
 * Args: companyId userId roomId checkIn checkOut idempotencyKey outJsonPath
 */

use App\Exceptions\HotelStayException;
use App\Services\HotelStayService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

if ($argc < 8) {
    fwrite(STDERR, "usage: companyId userId roomId checkIn checkOut idem out\n");
    exit(2);
}

[, $companyId, $userId, $roomId, $checkIn, $checkOut, $idem, $out] = $argv;

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$socket = getenv('DB_SOCKET') ?: '';
config([
    'database.default' => 'mysql',
    'database.connections.mysql.host' => getenv('DB_HOST') ?: '127.0.0.1',
    'database.connections.mysql.port' => getenv('DB_PORT') ?: '3307',
    'database.connections.mysql.database' => getenv('DB_DATABASE') ?: 'taxnest_dev',
    'database.connections.mysql.username' => getenv('DB_USERNAME') ?: 'taxnest_dev',
    'database.connections.mysql.password' => getenv('DB_PASSWORD') ?: 'taxnest_local_dev_only',
    'database.connections.mysql.unix_socket' => $socket,
]);
DB::purge('mysql');
DB::reconnect('mysql');

try {
    $stay = app(HotelStayService::class)->book((int) $companyId, (int) $userId, [
        'room_id' => (int) $roomId,
        'check_in_date' => $checkIn,
        'check_out_date' => $checkOut,
        'guest_name' => 'Concurrent Guest',
        'idempotency_key' => $idem,
    ]);
    file_put_contents($out, json_encode(['ok' => true, 'stay_id' => $stay->id]));
    exit(0);
} catch (HotelStayException $e) {
    file_put_contents($out, json_encode(['ok' => false, 'error' => $e->getMessage()]));
    exit(3);
} catch (Throwable $e) {
    file_put_contents($out, json_encode(['ok' => false, 'error' => $e->getMessage()]));
    exit(4);
}

#!/usr/bin/env php
<?php

/**
 * Disposable seed for settings-baseline-retain-harness.sh.
 * Builds a tiny sqlite DB with companies/users only — no production data.
 */

use App\Services\PosSettingsSnapshot;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$dbPath = $argv[1] ?? null;
if (! $dbPath || ! str_starts_with($dbPath, '/')) {
    fwrite(STDERR, "usage: settings-baseline-harness-seed.php /abs/path/lab.sqlite\n");
    exit(2);
}

config([
    'database.default' => 'sqlite',
    'database.connections.sqlite.database' => $dbPath,
    'database.connections.sqlite.prefix' => '',
]);
DB::purge('sqlite');
DB::reconnect('sqlite');

@unlink($dbPath);
touch($dbPath);

Schema::dropIfExists('users');
Schema::dropIfExists('companies');
Schema::create('companies', function (Blueprint $t) {
    $t->id();
    $t->string('name');
    $t->timestamps();
});
Schema::create('users', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('company_id')->nullable();
    $t->unsignedBigInteger('branch_id')->nullable();
    $t->string('role')->nullable();
    $t->string('pos_role')->nullable();
    $t->text('permissions')->nullable();
    $t->text('pos_custom_access')->nullable();
    $t->boolean('is_active')->default(true);
    $t->boolean('is_pos_cashier')->nullable();
    $t->boolean('pos_can_reprint')->nullable();
    $t->unsignedBigInteger('pos_till_id')->nullable();
    $t->string('status')->nullable();
    $t->timestamps();
});

DB::table('companies')->insert([
    ['id' => 1, 'name' => 'Lab A', 'created_at' => now(), 'updated_at' => now()],
    ['id' => 2, 'name' => 'Lab B', 'created_at' => now(), 'updated_at' => now()],
]);
DB::table('users')->insert([
    [
        'id' => 1, 'company_id' => 1, 'role' => 'user', 'pos_role' => 'pos_cashier',
        'pos_custom_access' => '["orders"]', 'is_active' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ],
    [
        'id' => 2, 'company_id' => 1, 'role' => 'user', 'pos_role' => 'pos_cashier',
        'pos_custom_access' => '["orders","service_jobs"]', 'is_active' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ],
    [
        'id' => 3, 'company_id' => 2, 'role' => 'user', 'pos_role' => 'pos_manager',
        'pos_custom_access' => '["reports"]', 'is_active' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ],
]);

$snap = new PosSettingsSnapshot();
$baseline = $snap->capture();
$path = $argv[2] ?? null;
if ($path) {
    file_put_contents($path, json_encode($baseline, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

echo "HARNESS_SEED_OK users=3\n";

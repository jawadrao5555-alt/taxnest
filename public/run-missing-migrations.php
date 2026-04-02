<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

header('Content-Type: application/json');
$results = [];

try {
    if (!Schema::hasColumn('pos_transactions', 'customer_id')) {
        DB::statement("ALTER TABLE pos_transactions ADD COLUMN customer_id BIGINT NULL");
        $results[] = 'Added customer_id to pos_transactions';
    } else {
        $results[] = 'customer_id already exists in pos_transactions';
    }

    if (!Schema::hasColumn('companies', 'pos_theme')) {
        DB::statement("ALTER TABLE companies ADD COLUMN pos_theme VARCHAR(30) DEFAULT 'default'");
        $results[] = 'Added pos_theme to companies';
    } else {
        $results[] = 'pos_theme already exists in companies';
    }

    if (!Schema::hasColumn('companies', 'pos_dashboard_style')) {
        DB::statement("ALTER TABLE companies ADD COLUMN pos_dashboard_style VARCHAR(30) DEFAULT 'default'");
        $results[] = 'Added pos_dashboard_style to companies';
    } else {
        $results[] = 'pos_dashboard_style already exists in companies';
    }

    $tables = ['pos_transactions', 'restaurant_orders', 'restaurant_order_items', 'companies'];
    $columns = [];
    foreach ($tables as $t) {
        $cols = DB::select("SELECT column_name FROM information_schema.columns WHERE table_name = ? ORDER BY ordinal_position", [$t]);
        $columns[$t] = array_map(fn($c) => $c->column_name, $cols);
    }

    echo json_encode(['success' => true, 'results' => $results, 'columns' => $columns], JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

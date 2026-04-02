<?php
header('Content-Type: application/json');
$db = parse_url(getenv('DATABASE_URL') ?: 'postgres://user:pass@localhost:5432/db');
if (!$db) die(json_encode(['error' => 'No DATABASE_URL']));
$dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $db['host'], $db['port'] ?? 5432, ltrim($db['path'], '/'));
try {
    $pdo = new PDO($dsn, $db['user'], $db['pass']);
    $tables = ['pos_transactions', 'pos_payments', 'restaurant_orders', 'restaurant_order_items'];
    $result = [];
    foreach ($tables as $t) {
        $stmt = $pdo->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = '$t' ORDER BY ordinal_position");
        $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result[$t] = array_column($cols, 'column_name');
    }
    echo json_encode($result, JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}

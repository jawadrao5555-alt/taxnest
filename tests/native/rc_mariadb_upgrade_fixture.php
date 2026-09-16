<?php
declare(strict_types=1);

/*
 * Representative, fictional data-bearing upgrade fixture for the isolated RC
 * MariaDB lab. It intentionally inserts no credentials, customer data, or
 * regulator payloads. The script is run once before, then once after, the
 * additive DI migrations so a migration cannot silently discard operating
 * records that predate it.
 */

$mode = $argv[1] ?? '';
$database = getenv('DB_DATABASE') ?: '';
$socket = getenv('DB_SOCKET') ?: '';
$evidenceDir = getenv('RC_MARIADB_EVIDENCE_DIR') ?: '';
if (!in_array($mode, ['seed', 'verify'], true)
    || !preg_match('/^taxnest_rc_[a-z0-9_]+_upgrade$/', $database)
    || !str_starts_with($socket, '/tmp/taxnest-rc-mariadb-')
    || $evidenceDir === '') {
    fwrite(STDERR, "FAIL: refusing unsafe upgrade fixture target\n");
    exit(2);
}

$pdo = new PDO('mysql:unix_socket='.$socket.';dbname='.$database, 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$now = '2026-09-16 11:00:00';

function failUpgrade(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function hasColumn(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');
    $stmt->execute([$table, $column]);
    return (bool) $stmt->fetchColumn();
}

/** @param array<string, scalar|null> $values */
function insertKnown(PDO $pdo, string $table, array $values): int
{
    $usable = [];
    foreach ($values as $column => $value) {
        if (hasColumn($pdo, $table, $column)) {
            $usable[$column] = $value;
        }
    }
    if ($usable === []) {
        failUpgrade("no usable columns for {$table}");
    }
    $quoted = array_map(fn (string $column): string => '`'.str_replace('`', '``', $column).'`', array_keys($usable));
    $marks = implode(',', array_fill(0, count($usable), '?'));
    $stmt = $pdo->prepare('INSERT INTO `'.$table.'` ('.implode(',', $quoted).") VALUES ({$marks})");
    $stmt->execute(array_values($usable));
    return (int) $pdo->lastInsertId();
}

/** @return array<string, mixed> */
function snapshot(PDO $pdo): array
{
    $rows = [];
    $queries = [
        'setting' => "SELECT `key`,`value` FROM system_settings WHERE `key`='rc_upgrade_marker'",
        'company' => "SELECT id,name,ntn,invoice_number_prefix,next_invoice_number FROM companies WHERE ntn='RC-UPGRADE-001'",
        'branch' => "SELECT b.company_id,b.name,b.is_head_office FROM branches b JOIN companies c ON c.id=b.company_id WHERE c.ntn='RC-UPGRADE-001'",
        'invoice' => "SELECT i.company_id,i.branch_id,i.invoice_number,i.internal_invoice_number,i.fbr_invoice_number,i.fbr_invoice_id,i.status,i.total_amount FROM invoices i JOIN companies c ON c.id=i.company_id WHERE c.ntn='RC-UPGRADE-001'",
        'stock' => "SELECT s.company_id,s.product_id,s.branch_id,s.quantity,s.avg_purchase_price FROM inventory_stocks s JOIN companies c ON c.id=s.company_id WHERE c.ntn='RC-UPGRADE-001'",
        'movement' => "SELECT m.company_id,m.product_id,m.branch_id,m.type,m.quantity,m.balance_after,m.reference_number FROM inventory_movements m JOIN companies c ON c.id=m.company_id WHERE c.ntn='RC-UPGRADE-001'",
        'ledger' => "SELECT l.company_id,l.invoice_id,l.customer_name,l.debit,l.credit,l.balance_after,l.type FROM customer_ledgers l JOIN companies c ON c.id=l.company_id WHERE c.ntn='RC-UPGRADE-001'",
        'pos_product' => "SELECT p.company_id,p.branch_id,p.name,p.stock_quantity FROM pos_products p JOIN companies c ON c.id=p.company_id WHERE c.ntn='RC-UPGRADE-001'",
    ];
    foreach ($queries as $name => $sql) {
        $rows[$name] = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
    return $rows;
}

function persistSnapshot(string $path, array $snapshot): string
{
    $json = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    file_put_contents($path, $json);
    return hash('sha256', $json);
}

if ($mode === 'seed') {
    $companyId = insertKnown($pdo, 'companies', [
        'name' => 'RC Upgrade Fictional Co',
        'ntn' => 'RC-UPGRADE-001',
        'email' => 'rc-upgrade@example.test',
        'product_type' => 'di',
        'company_status' => 'active',
        'invoice_number_prefix' => 'RC26',
        'next_invoice_number' => 42,
        'fbr_environment' => 'sandbox',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $branchId = insertKnown($pdo, 'branches', [
        'company_id' => $companyId,
        'name' => 'RC Upgrade Branch',
        'address' => 'Fictional Road',
        'city' => 'Lahore',
        'province' => 'Punjab',
        'is_head_office' => 1,
        'is_active' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $productId = insertKnown($pdo, 'products', [
        'company_id' => $companyId,
        'name' => 'RC Upgrade Stock Item',
        'hs_code' => '0101.2100',
        'default_tax_rate' => 18,
        'uom' => 'PCS',
        'default_price' => 100,
        'is_active' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $invoiceId = insertKnown($pdo, 'invoices', [
        'company_id' => $companyId,
        'branch_id' => $branchId,
        'invoice_number' => 'RC26-000041',
        'internal_invoice_number' => 'RC26-000041',
        'fbr_invoice_number' => 'RC-FISCAL-000041',
        'fbr_invoice_id' => 'RC-FISCAL-000041',
        'fbr_status' => 'sandbox',
        'status' => 'locked',
        'buyer_name' => 'Fictional Ledger Buyer',
        'buyer_ntn' => 'RC-BUYER-001',
        'buyer_registration_type' => 'Registered',
        'document_type' => 'Sale Invoice',
        'invoice_date' => '2026-09-15',
        'total_amount' => 118,
        'total_value_excluding_st' => 100,
        'total_sales_tax' => 18,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    insertKnown($pdo, 'inventory_stocks', [
        'company_id' => $companyId,
        'product_id' => $productId,
        'branch_id' => $branchId,
        'quantity' => 17,
        'min_stock_level' => 3,
        'avg_purchase_price' => 55,
        'last_purchase_price' => 60,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    insertKnown($pdo, 'inventory_movements', [
        'company_id' => $companyId,
        'product_id' => $productId,
        'branch_id' => $branchId,
        'type' => 'purchase',
        'quantity' => 17,
        'unit_price' => 55,
        'total_price' => 935,
        'balance_after' => 17,
        'reference_type' => 'rc_upgrade',
        'reference_id' => $invoiceId,
        'reference_number' => 'RC-UPGRADE-MOVE-001',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    insertKnown($pdo, 'customer_ledgers', [
        'company_id' => $companyId,
        'invoice_id' => $invoiceId,
        'customer_name' => 'Fictional Ledger Buyer',
        'customer_ntn' => 'RC-BUYER-001',
        'debit' => 118,
        'credit' => 0,
        'balance_after' => 118,
        'type' => 'invoice',
        'notes' => 'RC data-bearing upgrade fixture',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    insertKnown($pdo, 'pos_products', [
        'company_id' => $companyId,
        'branch_id' => $branchId,
        'name' => 'RC POS Stock Item',
        'price' => 100,
        'tax_rate' => 18,
        'stock_quantity' => 17,
        'is_active' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    insertKnown($pdo, 'system_settings', [
        'key' => 'rc_upgrade_marker',
        'value' => 'preserve-settings-fiscal-branch-stock-numbering-ledger',
        'description' => 'Fictional disposable RC upgrade marker',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $hash = persistSnapshot($evidenceDir.'/upgrade-before.json', snapshot($pdo));
    printf("PASS: seeded 8 representative data-bearing records; before_sha256=%s\n", $hash);
    exit(0);
}

$before = $evidenceDir.'/upgrade-before.json';
if (!is_file($before)) {
    failUpgrade('upgrade-before.json is missing');
}
$expected = json_decode((string) file_get_contents($before), true, flags: JSON_THROW_ON_ERROR);
$actual = snapshot($pdo);
if ($expected !== $actual) {
    persistSnapshot($evidenceDir.'/upgrade-after-mismatch.json', $actual);
    failUpgrade('data-bearing upgrade changed the representative snapshot');
}
$hash = persistSnapshot($evidenceDir.'/upgrade-after.json', $actual);
printf("PASS: preserved settings=1 fiscal_refs=1 branches=1 stock=2 numbering=1 ledger=1; after_sha256=%s\n", $hash);
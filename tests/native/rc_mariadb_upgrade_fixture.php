<?php
declare(strict_types=1);

/*
 * Fictional, data-bearing upgrade fixture for the isolated RC MariaDB lab.
 * It starts before the DI/package migration family, then proves two tenants'
 * settings, fiscal references, branch ownership, stock, serials, numbering
 * and ledger history survive. Expected one-way package backfill and additive
 * fiscal metadata behavior are checked separately, never mistaken for loss.
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
const RC_TENANTS = ['RC-UPGRADE-A', 'RC-UPGRADE-B'];
const RC_NOW = '2026-09-14 11:00:00';

function failUpgrade(string $message): never { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
function qi(string $name): string { return '`'.str_replace('`', '``', $name).'`'; }

function columns(PDO $pdo, string $table): array
{
    $q = $pdo->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? ORDER BY ordinal_position');
    $q->execute([$table]);
    return $q->fetchAll(PDO::FETCH_COLUMN);
}

/** @param array<string, scalar|null> $values */
function insertKnown(PDO $pdo, string $table, array $values): int
{
    $available = array_flip(columns($pdo, $table));
    $values = array_filter($values, fn ($v, $key) => isset($available[$key]), ARRAY_FILTER_USE_BOTH);
    if ($values === []) failUpgrade("no usable columns for {$table}");
    $stmt = $pdo->prepare('INSERT INTO '.qi($table).' ('.implode(',', array_map('qi', array_keys($values))).') VALUES ('.implode(',', array_fill(0, count($values), '?')).')');
    $stmt->execute(array_values($values));
    return (int) $pdo->lastInsertId();
}

/** @return array{columns: array<int,string>, rows: array<int,array<string,mixed>>} */
function snapshotTable(PDO $pdo, string $table, string $where, array $bindings, ?array $wantedColumns = null): array
{
    $available = columns($pdo, $table);
    $wantedColumns ??= array_values(array_filter($available, fn (string $column): bool => !preg_match('/token|secret|password|api[_-]?key/i', $column)));
    $wantedColumns = array_values(array_filter($wantedColumns, fn (string $column): bool => in_array($column, $available, true)));
    if ($wantedColumns === []) failUpgrade("no non-secret snapshot columns for {$table}");
    $statement = $pdo->prepare('SELECT '.implode(',', array_map('qi', $wantedColumns)).' FROM '.qi($table).' WHERE '.$where.' ORDER BY id');
    $statement->execute($bindings);
    return ['columns' => $wantedColumns, 'rows' => $statement->fetchAll(PDO::FETCH_ASSOC)];
}

/** @return array<string, array{columns: array<int,string>, rows: array<int,array<string,mixed>>}> */
function snapshot(PDO $pdo, ?array $wanted = null): array
{
    $whereCompany = 'company_id IN (SELECT id FROM companies WHERE ntn IN (?,?))';
    $plans = [
        'companies' => ['ntn IN (?,?)', RC_TENANTS],
        'branches' => [$whereCompany, RC_TENANTS],
        'products' => [$whereCompany, RC_TENANTS],
        'pos_products' => [$whereCompany, RC_TENANTS],
        'invoices' => [$whereCompany, RC_TENANTS],
        'invoice_items' => ['invoice_id IN (SELECT id FROM invoices WHERE '.$whereCompany.')', RC_TENANTS],
        'inventory_stocks' => [$whereCompany, RC_TENANTS],
        'inventory_movements' => [$whereCompany, RC_TENANTS],
        'customer_ledgers' => [$whereCompany, RC_TENANTS],
        'system_settings' => ["`key` LIKE 'rc_upgrade_%'", []],
        'subscriptions' => [$whereCompany, RC_TENANTS],
    ];
    $out = [];
    foreach ($plans as $table => [$where, $bindings]) {
        $out[$table] = snapshotTable($pdo, $table, $where, $bindings, $wanted[$table]['columns'] ?? null);
    }
    return $out;
}

function persist(string $path, array $value): string
{
    $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    file_put_contents($path, $json);
    return hash('sha256', $json);
}

if ($mode === 'seed') {
    $companyIds = [];
    $branchIds = [];
    $productIds = [];
    $invoiceIds = [];
    foreach (RC_TENANTS as $offset => $ntn) {
        $letter = $offset === 0 ? 'A' : 'B';
        $environment = $letter === 'A' ? 'sandbox' : 'production';
        $companyIds[$letter] = insertKnown($pdo, 'companies', [
            'name' => "RC Upgrade Fictional {$letter}",
            'ntn' => $ntn,
            'email' => "rc-upgrade-{$letter}@example.test",
            'product_type' => 'di',
            'company_status' => 'active',
            'inventory_enabled' => 1,
            'invoice_number_prefix' => "RC{$letter}26",
            'next_invoice_number' => $letter === 'A' ? 42 : 77,
            'fbr_environment' => $environment,
            'fbr_reporting_enabled' => 1,
            'standard_tax_rate' => 18,
            'created_at' => RC_NOW, 'updated_at' => RC_NOW,
        ]);
        $branchIds[$letter] = insertKnown($pdo, 'branches', [
            'company_id' => $companyIds[$letter],
            'name' => "RC {$letter} Branch",
            'address' => "Fictional {$letter} Road",
            'city' => $letter === 'A' ? 'Lahore' : 'Multan',
            'province' => 'Punjab',
            'is_head_office' => 1, 'is_active' => 1,
            'created_at' => RC_NOW, 'updated_at' => RC_NOW,
        ]);
        $productIds[$letter] = insertKnown($pdo, 'products', [
            'company_id' => $companyIds[$letter],
            'name' => "RC {$letter} Stock Item", 'hs_code' => '0101.2100',
            'default_tax_rate' => 18, 'uom' => 'PCS', 'default_price' => 100, 'is_active' => 1,
            'created_at' => RC_NOW, 'updated_at' => RC_NOW,
        ]);
        $invoiceIds[$letter] = insertKnown($pdo, 'invoices', [
            'company_id' => $companyIds[$letter], 'branch_id' => $branchIds[$letter],
            'invoice_number' => "RC{$letter}26-000041", 'internal_invoice_number' => "RC{$letter}26-000041",
            'fbr_invoice_number' => "RC-FISCAL-{$letter}-000041", 'fbr_invoice_id' => "RC-FISCAL-{$letter}-000041",
            'fbr_status' => $environment, 'status' => 'locked',
            'buyer_name' => "Fictional Ledger Buyer {$letter}", 'buyer_ntn' => "RC-BUYER-{$letter}",
            'buyer_registration_type' => 'Registered', 'document_type' => 'Sale Invoice', 'invoice_date' => '2026-09-14',
            'total_amount' => 118, 'total_value_excluding_st' => 100, 'total_sales_tax' => 18,
            'created_at' => RC_NOW, 'updated_at' => RC_NOW,
        ]);
        insertKnown($pdo, 'invoice_items', [
            'invoice_id' => $invoiceIds[$letter], 'hs_code' => '0101.2100', 'description' => "RC {$letter} serialized item",
            'quantity' => 1, 'price' => 100, 'tax' => 18, 'tax_rate' => 18, 'schedule_type' => 'standard',
            'serial_no' => "RC-SERIAL-{$letter}-001", 'created_at' => RC_NOW, 'updated_at' => RC_NOW,
        ]);
        insertKnown($pdo, 'inventory_stocks', [
            'company_id' => $companyIds[$letter], 'product_id' => $productIds[$letter], 'branch_id' => $branchIds[$letter],
            'quantity' => $letter === 'A' ? 17 : 29, 'min_stock_level' => 3, 'avg_purchase_price' => 55, 'last_purchase_price' => 60,
            'created_at' => RC_NOW, 'updated_at' => RC_NOW,
        ]);
        insertKnown($pdo, 'inventory_movements', [
            'company_id' => $companyIds[$letter], 'product_id' => $productIds[$letter], 'branch_id' => $branchIds[$letter],
            'type' => 'purchase', 'quantity' => $letter === 'A' ? 17 : 29, 'unit_price' => 55,
            'total_price' => $letter === 'A' ? 935 : 1595, 'balance_after' => $letter === 'A' ? 17 : 29,
            'reference_type' => 'rc_upgrade', 'reference_id' => $invoiceIds[$letter], 'reference_number' => "RC-UPGRADE-MOVE-{$letter}",
            'created_at' => RC_NOW, 'updated_at' => RC_NOW,
        ]);
        insertKnown($pdo, 'customer_ledgers', [
            'company_id' => $companyIds[$letter], 'invoice_id' => $invoiceIds[$letter],
            'customer_name' => "Fictional Ledger Buyer {$letter}", 'customer_ntn' => "RC-BUYER-{$letter}",
            'debit' => 118, 'credit' => 0, 'balance_after' => 118, 'type' => 'invoice',
            'notes' => 'RC fictional data-bearing upgrade fixture', 'created_at' => RC_NOW, 'updated_at' => RC_NOW,
        ]);
        insertKnown($pdo, 'pos_products', [
            'company_id' => $companyIds[$letter], 'branch_id' => $branchIds[$letter], 'name' => "RC POS {$letter} Stock",
            'price' => 100, 'tax_rate' => 18, 'stock_quantity' => $letter === 'A' ? 17 : 29, 'is_active' => 1,
            'created_at' => RC_NOW, 'updated_at' => RC_NOW,
        ]);
        insertKnown($pdo, 'system_settings', [
            'key' => "rc_upgrade_{$letter}_settings", 'value' => json_encode(['tenant' => $letter, 'environment' => $environment, 'numbering' => $letter === 'A' ? 42 : 77], JSON_THROW_ON_ERROR),
            'description' => "Fictional RC {$letter} tenant settings", 'created_at' => RC_NOW, 'updated_at' => RC_NOW,
        ]);
    }
    $legacyPlanId = insertKnown($pdo, 'pricing_plans', [
        'name' => 'Business', 'product_type' => 'di', 'invoice_limit' => 100, 'price' => 999,
        'is_trial' => 0, 'created_at' => RC_NOW, 'updated_at' => RC_NOW,
    ]);
    insertKnown($pdo, 'subscriptions', [
        'company_id' => $companyIds['A'], 'pricing_plan_id' => $legacyPlanId,
        'start_date' => '2026-09-01', 'end_date' => '2026-12-01', 'active' => 1,
        'created_at' => RC_NOW, 'updated_at' => RC_NOW,
    ]);
    $hash = persist($evidenceDir.'/upgrade-before.json', snapshot($pdo));
    printf("PASS: seeded tenants=2 branches=2 stocks=2 serials=2 fiscal_refs=2 ledgers=2 tenant_settings=2; before_sha256=%s\n", $hash);
    exit(0);
}

$beforePath = $evidenceDir.'/upgrade-before.json';
if (!is_file($beforePath)) failUpgrade('upgrade-before.json is missing');
$before = json_decode((string) file_get_contents($beforePath), true, 512, JSON_THROW_ON_ERROR);
$after = snapshot($pdo, $before);
// The subscription plan is an intentional one-way package migration, not a
// preservation invariant; compare its company/dates/active fields separately.
$beforeSubscriptions = $before['subscriptions'];
$afterSubscriptions = $after['subscriptions'];
foreach (['beforeSubscriptions', 'afterSubscriptions'] as $variable) {
    $subscriptions = $$variable;
    $subscriptions['columns'] = array_values(array_filter($subscriptions['columns'], fn (string $column): bool => $column !== 'pricing_plan_id'));
    $subscriptions['columns'] = array_values(array_filter($subscriptions['columns'], fn (string $column): bool => $column !== 'updated_at'));
    foreach ($subscriptions['rows'] as &$row) {
        unset($row['pricing_plan_id'], $row['updated_at']);
    }
    unset($row);
    $$variable = $subscriptions;
}
$beforeComparable = $before;
$afterComparable = $after;
$beforeComparable['subscriptions'] = $beforeSubscriptions;
$afterComparable['subscriptions'] = $afterSubscriptions;
if ($beforeComparable !== $afterComparable) {
    persist($evidenceDir.'/upgrade-after-mismatch.json', $after);
    failUpgrade('data-bearing upgrade changed a preservation invariant');
}
$legacy = $pdo->query("SELECT p.name,s.active,s.start_date,s.end_date FROM subscriptions s JOIN companies c ON c.id=s.company_id JOIN pricing_plans p ON p.id=s.pricing_plan_id WHERE c.ntn='RC-UPGRADE-A'")->fetch(PDO::FETCH_ASSOC);
if (($legacy['name'] ?? null) !== 'Kaarobar' || (int) ($legacy['active'] ?? 0) !== 1 || ($legacy['start_date'] ?? null) !== '2026-09-01' || ($legacy['end_date'] ?? null) !== '2026-12-01') {
    failUpgrade('expected Business-to-Kaarobar package backfill did not preserve subscription term');
}
$fiscal = $pdo->query("SELECT COUNT(*) FROM invoices WHERE company_id IN (SELECT id FROM companies WHERE ntn IN ('RC-UPGRADE-A','RC-UPGRADE-B')) AND fiscal_submission_state IS NULL AND fiscal_submission_environment IS NULL AND fiscal_submission_provenance IS NULL")->fetchColumn();
if ((int) $fiscal !== 2) failUpgrade('historic fiscal rows were incorrectly inferred or overwritten by additive metadata migration');
$hash = persist($evidenceDir.'/upgrade-after.json', $after);
printf("PASS: preserved tenants=2 settings=2 fiscal_refs=2 environments=2 branches=2 stocks=2 serials=2 numbering=2 ledgers=2; expected_backfill=Business->Kaarobar; metadata_uninferred=2; after_sha256=%s\n", $hash);
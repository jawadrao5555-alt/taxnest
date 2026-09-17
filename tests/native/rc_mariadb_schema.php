<?php
declare(strict_types=1);

/* Read-only schema ledger for the disposable RC MariaDB only. */
$database = getenv('DB_DATABASE') ?: '';
$socket = getenv('DB_SOCKET') ?: '';
if (!preg_match('/^taxnest_rc_/', $database) || !str_starts_with($socket, '/tmp/taxnest-rc-mariadb-')) {
    fwrite(STDERR, "FAIL: refusing non-disposable MariaDB schema target\n"); exit(2);
}
$pdo = new PDO('mysql:unix_socket='.$socket.';dbname='.$database, getenv('DB_USERNAME') ?: 'root', getenv('DB_PASSWORD') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
function failSchema(string $message): never { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
function columns(PDO $pdo, string $database, string $table): array {
    $q = $pdo->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema=? AND table_name=?');
    $q->execute([$database, $table]); return $q->fetchAll(PDO::FETCH_COLUMN);
}
function indexCheck(PDO $pdo, string $database, string $table, string $index, array $want, bool $unique): void {
    $q = $pdo->prepare('SELECT column_name,non_unique FROM information_schema.statistics WHERE table_schema=? AND table_name=? AND index_name=? ORDER BY seq_in_index');
    $q->execute([$database, $table, $index]); $rows=$q->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows || array_column($rows, 'column_name') !== $want || (((int)$rows[0]['non_unique'] === 0) !== $unique)) failSchema("index mismatch $table.$index");
}
function quoteIdentifier(string $name): string {
    return '`'.str_replace('`', '``', $name).'`';
}
function orphanCheck(PDO $pdo, string $database): int {
    $sql = 'SELECT k.constraint_name,k.table_name,k.column_name,k.referenced_table_name,k.referenced_column_name,k.ordinal_position
        FROM information_schema.key_column_usage k
        WHERE k.table_schema=? AND k.referenced_table_schema=? AND k.referenced_table_name IS NOT NULL
        ORDER BY k.constraint_name,k.ordinal_position';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$database, $database]);
    $constraints = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = $row['table_name'].'|'.$row['constraint_name'];
        $constraints[$key][] = $row;
    }
    foreach ($constraints as $columns) {
        $child = quoteIdentifier($columns[0]['table_name']);
        $parent = quoteIdentifier($columns[0]['referenced_table_name']);
        $on = [];
        $present = [];
        foreach ($columns as $column) {
            $on[] = 'c.'.quoteIdentifier($column['column_name']).'=p.'.quoteIdentifier($column['referenced_column_name']);
            $present[] = 'c.'.quoteIdentifier($column['column_name']).' IS NOT NULL';
        }
        $parentKey = quoteIdentifier($columns[0]['referenced_column_name']);
        $orphans = (int) $pdo->query(
            'SELECT COUNT(*) FROM '.$child.' c LEFT JOIN '.$parent.' p ON '.implode(' AND ', $on)
            .' WHERE '.implode(' AND ', $present).' AND p.'.$parentKey.' IS NULL'
        )->fetchColumn();
        if ($orphans !== 0) {
            failSchema('orphan rows for '.$columns[0]['table_name'].'.'.$columns[0]['constraint_name']);
        }
    }
    return count($constraints);
}
$required = [
    'companies'=>['id'], 'branches'=>['id','company_id'],
    'invoices'=>['id','company_id','invoice_number','fiscal_submission_state'],
    'pos_transactions'=>['id','company_id','branch_id','invoice_number'],
    'pos_products'=>['id','company_id','branch_id','stock_quantity'],
    'inventory_stocks'=>['id','company_id','product_id','branch_id','quantity'],
    'inventory_movements'=>['id','company_id','product_id','branch_id','balance_after'],
    'pos_day_close_reports'=>['id','company_id','branch_id','report_date'],
    'fbr_day_close_reports'=>['id','company_id','report_date'],
    'invoice_import_batches'=>['id','company_id','status'],
    'invoice_bulk_submissions'=>['id','company_id','state','total','done','success','failed','skipped','pending'],
    'job_batches'=>['id'], 'jobs'=>['id'], 'pos_agent_devices'=>['id','company_id','agent_version','last_seen_at'],
];
foreach ($required as $table=>$want) {
    $have=columns($pdo,$database,$table);
    foreach ($want as $column) if (!in_array($column,$have,true)) failSchema("missing $table.$column");
}
indexCheck($pdo,$database,'invoices','invoices_company_invoice_unique',['company_id','invoice_number'],true);
indexCheck($pdo,$database,'pos_transactions','pos_transactions_company_invoice_unique',['company_id','invoice_number'],true);
indexCheck($pdo,$database,'pos_day_close_reports','pos_dcr_company_branch_date_unique',['company_id','branch_id','report_date'],true);
indexCheck($pdo,$database,'fbr_day_close_reports','fbr_day_close_reports_company_id_report_date_unique',['company_id','report_date'],true);
indexCheck($pdo,$database,'inventory_stocks','inventory_stocks_product_id_foreign',['product_id'],false);
indexCheck($pdo,$database,'invoice_import_batches','invoice_import_batches_status_index',['status'],false);
indexCheck($pdo,$database,'job_batches','PRIMARY',['id'],true);
$foreignKeyCount=orphanCheck($pdo,$database);
$files=glob(__DIR__.'/../../database/migrations/*.php') ?: [];
$ledger=$pdo->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
if (count($files) !== count($ledger) || array_diff(array_map(fn($p)=>basename($p,'.php'),$files),$ledger)) failSchema('migration ledger does not equal filesystem migrations');
$manifest=json_decode((string)file_get_contents(__DIR__.'/../../database/schema-manifest.json'),true);
$manifestTables=is_array($manifest['tables'] ?? null) ? count($manifest['tables']) : 0;
$tableCount=(int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn();
printf("PASS: manifest tables=%d, migrated tables=%d, migration ledger=%d, integrity indexes=7, foreign-key orphan checks=%d\n",$manifestTables,$tableCount,count($ledger),$foreignKeyCount);
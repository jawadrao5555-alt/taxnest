<?php

// Fictional native concurrency lab only. Never reads a shop/company credential.
if (getenv('RC_SAFE_RUN') !== '1' || !in_array($argv[1] ?? '', ['seed', 'issue', 'verify'], true)) exit(2);
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$name = (string) config('database.connections.mysql.database');
if (!preg_match('/^taxnest_rc_[a-z0-9_]+_numbering$/D', $name)
    || !in_array(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost'], true)) exit(2);
config(['database.default' => 'mysql']);
\Illuminate\Support\Facades\DB::purge('mysql');
$db = \Illuminate\Support\Facades\DB::connection();
if ($db->getDatabaseName() !== $name) exit(2);
$schema = $db->getSchemaBuilder();
if ($argv[1] === 'seed') {
    foreach (['fbr_pos_invoice_sequences', 'fbr_pos_transactions', 'companies'] as $table) $schema->dropIfExists($table);
    $schema->create('companies', fn ($t) => $t->id());
    $schema->create('fbr_pos_transactions', function ($t) { $t->id(); $t->unsignedBigInteger('company_id'); $t->string('invoice_number'); });
    $db->table('companies')->insert([['id' => 91], ['id' => 92]]);
    (require __DIR__.'/../../database/migrations/2026_10_12_100001_fbr_pos_durable_invoice_sequences.php')->up();
    echo "seeded fictional lab\n";
} elseif ($argv[1] === 'issue') {
    $number = $db->transaction(function () use ($db) {
        $number = \App\Services\FbrPosInvoiceSeries::issue(91, 'FPOS-2026-');
        $db->table('fbr_pos_transactions')->insert(['company_id' => 91, 'invoice_number' => $number]);
        return $number;
    }, 5);
    echo $number."\n";
} else {
    $numbers = $db->table('fbr_pos_transactions')->orderBy('invoice_number')->pluck('invoice_number')->all();
    $expected = array_map(fn ($n) => 'FPOS-2026-'.str_pad((string) $n, 5, '0', STR_PAD_LEFT), range(1, 12));
    if ($numbers !== $expected) throw new RuntimeException('Concurrent invoice serials are not unique and sequential.');
    if (\App\Services\FbrPosInvoiceSeries::issue(92, 'FPOS-2026-') !== 'FPOS-2026-00001') throw new RuntimeException('Tenant isolation failed.');
    $db->table('fbr_pos_transactions')->where('company_id', 91)->delete();
    if (\App\Services\FbrPosInvoiceSeries::issue(91, 'FPOS-2026-') !== 'FPOS-2026-00013') throw new RuntimeException('Deleted serial was reused.');
    echo "PASS: 12 concurrent first allocations, second tenant, deleted high-water mark\n";
}

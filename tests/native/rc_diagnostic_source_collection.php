<?php

declare(strict_types=1);

$database = getenv('DB_DATABASE') ?: '';
$socket = getenv('DB_SOCKET') ?: '';
if (getenv('APP_ENV') !== 'rc-mariadb' || ! getenv('LD_PRELOAD')
    || ! preg_match('/^taxnest_rc_[a-z0-9_]+_full$/D', $database)
    || ! preg_match('#^/tmp/taxnest-rc-mariadb-[a-z0-9_]+/run/mariadb.sock$#D', $socket)) {
    fwrite(STDERR, "FAIL unsafe diagnostic observation target\n");
    exit(2);
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Company;
use App\Services\Reporting\DiagnosticSourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

if (($argv[1] ?? '') === 'worker') {
    [$companyId, $day, $run, $barrier, $index] = array_slice($argv, 2);
    if (! ctype_digit($companyId) || ! ctype_digit($index) || ! str_starts_with($barrier, dirname($socket).'/diag-observation-')) {
        throw new RuntimeException('Unsafe observation worker identity.');
    }
    file_put_contents($barrier.'.ready-'.$index, 'ready');
    $until = microtime(true) + 20;
    while (! is_file($barrier)) {
        if (microtime(true) > $until) {
            throw new RuntimeException('Observation barrier timeout.');
        } usleep(10000);
    }
    if (app(DiagnosticSourceCollection::class)->capture((int) $companyId, 'pra.fiscal', $day, $run) !== 'OBSERVED') {
        throw new RuntimeException('Native source was not observed.');
    }
    exit(0);
}
$suffix = bin2hex(random_bytes(8));
$company = Company::create(['name' => 'Synthetic diagnostic source', 'ntn' => $suffix, 'product_type' => 'pos']);
$other = Company::create(['name' => 'Other synthetic diagnostic source', 'ntn' => 'x'.$suffix, 'product_type' => 'pos']);
$day = now(config('reporting_connector.timezone', 'Asia/Karachi'))->subDay()->toDateString();
$stamp = $day.' 12:00:00';
DB::table('pra_logs')->insert([
    ['company_id' => $company->id, 'created_at' => $stamp, 'updated_at' => $stamp, 'request_payload' => '{"synthetic":"PRIVATE"}'],
    ['company_id' => $other->id, 'created_at' => $stamp, 'updated_at' => $stamp, 'request_payload' => '{"synthetic":"FOREIGN"}'],
]);
$before = DB::table('pra_logs')->whereIn('company_id', [$company->id, $other->id])->orderBy('id')->get()->toJson();
$run = (string) Str::uuid();
$barrier = dirname($socket).'/diag-observation-'.$suffix;
$processes = [];
try {
    foreach ([1, 2] as $index) {
        $p = new Process([PHP_BINARY, '-c', php_ini_loaded_file(), __FILE__, 'worker', (string) $company->id, $day, $run, $barrier, (string) $index]);
        $p->setTimeout(40);
        $p->start();
        $processes[] = $p;
    }
    $until = microtime(true) + 20;
    while (count(glob($barrier.'.ready-*')) !== 2) {
        if (microtime(true) > $until) {
            throw new RuntimeException('Observation workers did not reach barrier.');
        }usleep(10000);
    }
    file_put_contents($barrier, 'go');
    foreach ($processes as $p) {
        $p->wait();
        if (! $p->isSuccessful()) {
            throw new RuntimeException('Native observation worker failed.');
        }
    }
    $observation = DB::table('diagnostic_source_observations')->where('company_id', $company->id)->sole();
    $report = app(DiagnosticSourceCollection::class)->latest($company->id, 'pra.fiscal', $day, $day);
    if ((int) $observation->retained_rows !== 1 || $report['complete_collection_proven'] !== false
        || DB::table('diagnostic_source_observations')->where('company_id', $other->id)->count() !== 0
        || DB::table('pra_logs')->whereIn('company_id', [$company->id, $other->id])->orderBy('id')->get()->toJson() !== $before
        || str_contains(json_encode($report), 'PRIVATE')) {
        throw new RuntimeException('Native observation scope, idempotency or source preservation failed.');
    }
    echo "PASS: native concurrent source observation is idempotent, scoped and preserves source rows\n";
    $bill = DB::table('pos_transactions')->insertGetId(['company_id' => $company->id, 'invoice_number' => 'RC-DIAG-'.$suffix,
        'invoice_mode' => 'local', 'pra_status' => 'local', 'status' => 'completed', 'transaction_type' => 'sale', 'subtotal' => 100,
        'total_amount' => 100, 'payment_method' => 'cash', 'created_at' => $stamp, 'updated_at' => $stamp]);
    $immutableBill = (array) DB::table('pos_transactions')->find($bill);
    $ims = [];
    foreach ([$company->id, $other->id] as $tenant) {
        $ims[] = DB::table('pos_ims_submissions')->insertGetId([
            'company_id' => $tenant, 'source_transaction_id' => $bill, 'environment' => 'sandbox', 'connection_mode' => 'cloud',
            'snapshot' => '{}', 'payload' => '{"private":"PRIVATE"}', 'state' => 'draft', 'created_by' => 0, 'created_at' => $stamp, 'updated_at' => $stamp]);
    }
    $prints = [];
    foreach (['pos', 'fbrpos'] as $product) {
        $prints[] = DB::table('pos_print_evidence')->insertGetId([
            'company_id' => $company->id, 'transaction_id' => $bill, 'product_type' => $product, 'event' => 'result_received',
            'context' => '{"private":"PRIVATE"}', 'received_at' => $stamp]);
    }
    $billReport = app(\App\Services\Reporting\DiagnosticBillEvidence::class)->read(['company_name' => $company->name, 'bill_id' => $bill], [$company->id]);
    $collection = app(DiagnosticSourceCollection::class);
    $collection->capture($company->id, 'pos.print_receipts', $day, (string) Str::uuid());
    $printReport = $collection->latest($company->id, 'pos.print_receipts', $day, $day);
    $sourceReport = app(\App\Services\Reporting\DiagnosticSourceEvidence::class)->read([
        'company_name' => $company->name, 'source' => 'pos.print_receipts', 'date_from' => $day], [$company->id]);
    if ($billReport['sources']['federal_ims_attempts']['rows'] !== [['id' => (int) $ims[0]]]
        || $billReport['sources']['print_events']['rows'] !== [['id' => (int) $prints[0]]]
        || $printReport['retained_rows'] !== 1 || $sourceReport['observed']['retained_rows_in_window'] !== 1
        || str_contains(json_encode([$billReport, $sourceReport]), 'PRIVATE')
        || $immutableBill !== (array) DB::table('pos_transactions')->find($bill)) {
        throw new RuntimeException('Native IMS/print attribution or source preservation failed');
    }
    echo "PASS: native IMS links and print source read/collection retain exact tenant/product attribution and unchanged bill\n";
    $notifications = [];
    foreach ([$company->id, $other->id, null] as $tenant) {
        $notifications[] = DB::table('notifications')->insertGetId(['company_id' => $tenant,
            'type' => 'synthetic_diagnostic', 'title' => 'PRIVATE synthetic title', 'message' => 'PRIVATE synthetic message',
            'read' => false, 'created_at' => $stamp, 'updated_at' => $stamp]);
    }
    $notificationBefore = DB::table('notifications')->whereIn('id', $notifications)->orderBy('id')->get()->toJson();
    $collection->capture($company->id, 'pos.notifications', $day, (string) Str::uuid());
    $shared = app(\App\Services\Reporting\DiagnosticSourceEvidence::class)->read([
        'company_name' => $company->name, 'source' => 'pos.notifications', 'date_from' => $day], [$company->id]);
    $exhausted = app(\App\Services\Reporting\DiagnosticSourceEvidence::class)->read([
        'company_name' => $company->name, 'source' => 'pos.notifications', 'date_from' => $day, 'after_id' => max($notifications)], [$company->id]);
    if (array_column($shared['rows'], 'id') !== [(int) $notifications[0]] || $shared['collection_observation']['retained_rows'] !== 1
        || $exhausted['status'] !== 'PARTIAL' || $exhausted['rows'] !== [] || $exhausted['observed']['retained_rows_in_window'] !== 1
        || str_contains(json_encode([$shared, $exhausted]), 'PRIVATE')
        || $notificationBefore !== DB::table('notifications')->whereIn('id', $notifications)->orderBy('id')->get()->toJson()) {
        throw new RuntimeException('Native shared notification source scope or exhausted cursor proof failed');
    }
    echo "PASS: native shared source excludes global and foreign rows; exhausted cursor retains window evidence and original rows\n";

    $health = Company::create(['name' => 'Synthetic health diagnostic '.$suffix, 'ntn' => 'h'.$suffix, 'product_type' => 'erps']);
    $otherHealth = Company::create(['name' => 'Other health diagnostic '.$suffix, 'ntn' => 'y'.$suffix, 'product_type' => 'erps']);
    $healthBill = DB::table('health_bills')->insertGetId([
        'company_id' => $health->id, 'health_patient_id' => 900001, 'bill_no' => 'HB-'.$suffix,
        'bill_date' => $day, 'created_at' => $stamp, 'updated_at' => $stamp,
    ]);
    $healthSources = [
        'clinical_visits' => ['health_visits', 'visit', [
            'health_patient_id' => 900001, 'health_doctor_id' => 800001, 'visit_no' => 'HV-'.$suffix, 'visit_date' => $day,
        ]],
        'pharmacy_sales' => ['health_pharmacy_sales', 'pharmacy_sale', ['sale_number' => 'HS-'.$suffix]],
        'admission_charges' => ['health_admission_charges', 'admission_charge', [
            'health_admission_id' => 700001, 'charge_date' => $day, 'description' => 'PRIVATE admission source',
        ]],
        'operations' => ['health_operations', 'operation', [
            'health_patient_id' => 900001, 'operation_no' => 'HO-'.$suffix, 'title' => 'PRIVATE operation source',
        ]],
    ];
    $healthSourceIds = [];
    foreach ($healthSources as $key => [$table, $sourceType, $required]) {
        $healthSourceIds[$key] = DB::table($table)->insertGetId($required + [
            'company_id' => $health->id, 'created_at' => $stamp, 'updated_at' => $stamp,
        ]);
        $foreignId = DB::table($table)->insertGetId($required + [
            'company_id' => $otherHealth->id, 'created_at' => $stamp, 'updated_at' => $stamp,
        ]);
        DB::table('health_charges')->insert([
            ['company_id' => $health->id, 'health_patient_id' => 900001, 'charge_no' => 'HC-'.substr(hash('sha256', $sourceType.$suffix), 0, 20),
                'charge_date' => $day, 'description' => 'PRIVATE charge', 'source_type' => $sourceType,
                'source_id' => $healthSourceIds[$key], 'health_bill_id' => $healthBill, 'created_at' => $stamp, 'updated_at' => $stamp],
            ['company_id' => $otherHealth->id, 'health_patient_id' => 900002, 'charge_no' => 'HF-'.substr(hash('sha256', $sourceType.$suffix), 0, 20),
                'charge_date' => $day, 'description' => 'PRIVATE foreign charge', 'source_type' => $sourceType,
                'source_id' => $foreignId, 'health_bill_id' => $healthBill, 'created_at' => $stamp, 'updated_at' => $stamp],
        ]);
    }
    $healthBefore = collect(array_keys($healthSources))->mapWithKeys(function ($key) use ($healthSources) {
        $table = $healthSources[$key][0];

        return [$table => DB::table($table)->orderBy('id')->get()->toJson()];
    })->put('health_charges', DB::table('health_charges')->whereIn('company_id', [$health->id, $otherHealth->id])->orderBy('id')->get()->toJson());
    $healthReport = app(\App\Services\Reporting\DiagnosticBillEvidence::class)->read([
        'company_name' => $health->name, 'bill_id' => $healthBill,
    ], [$health->id]);
    foreach ($healthSourceIds as $key => $id) {
        if ($healthReport['sources'][$key]['rows'] !== [['id' => (int) $id]]) {
            throw new RuntimeException('Native health source attribution failed for '.$key);
        }
    }
    $healthAfter = collect(array_keys($healthSources))->mapWithKeys(function ($key) use ($healthSources) {
        $table = $healthSources[$key][0];

        return [$table => DB::table($table)->orderBy('id')->get()->toJson()];
    })->put('health_charges', DB::table('health_charges')->whereIn('company_id', [$health->id, $otherHealth->id])->orderBy('id')->get()->toJson());
    if ($healthReport['version'] !== 3 || str_contains(json_encode($healthReport), 'PRIVATE') || $healthBefore->all() !== $healthAfter->all()) {
        throw new RuntimeException('Native health source report leaked or changed retained rows');
    }
    echo "PASS: native healthcare bill follows exact tenant-scoped clinical and pharmacy source IDs without changing rows\n";

} finally {
    foreach ($processes as $p) {
        if ($p->isRunning()) {
            $p->stop(1);
        }
    }
    if (is_file($barrier)) {
        unlink($barrier);
    }
    foreach (glob($barrier.'.ready-*') as $ready) {
        unlink($ready);
    }
}

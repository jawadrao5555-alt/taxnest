<?php
declare(strict_types=1);

/*
 * Native (InnoDB/MariaDB) DI fiscal proof. This is deliberately an application
 * harness rather than a PHPUnit sqlite substitute: independent PHP processes
 * race the real claim and durable-result tables. No HTTP client is invoked and
 * the clean launcher empties every fiscal endpoint/token environment variable.
 */

use App\Jobs\BulkSubmitInvoiceJob;
use App\Jobs\SeedBulkSubmitBatchJob;
use App\Models\InvoiceBulkSubmission;
use App\Services\DiFiscalSubmissionState;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = getenv('DB_DATABASE') ?: '';
$socket = getenv('DB_SOCKET') ?: '';
if (!preg_match('/^taxnest_rc_[a-z0-9_]+_di$/', $database)
    || !str_starts_with($socket, '/tmp/taxnest-rc-mariadb-')
    || !in_array((string) getenv('APP_ENV'), ['rc-mariadb', 'testing'], true)) {
    fwrite(STDERR, "FAIL: refusing unsafe DI MariaDB target\n");
    exit(2);
}

function failDi(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function assertDi(bool $condition, string $message): void
{
    if (!$condition) {
        failDi($message);
    }
    echo "PASS: {$message}\n";
}

function makeDiCompany(string $suffix): int
{
    return (int) DB::table('companies')->insertGetId([
        'name' => "RC DI {$suffix}",
        'ntn' => "RC-DI-{$suffix}",
        'email' => "rc-di-{$suffix}@example.test",
        'product_type' => 'di',
        'company_status' => 'active',
        'is_internal_account' => true,
        'fbr_environment' => 'sandbox',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function makeDiInvoice(int $companyId, string $suffix): int
{
    return (int) DB::table('invoices')->insertGetId([
        'company_id' => $companyId,
        'invoice_number' => "RC-DI-{$suffix}",
        'internal_invoice_number' => "RC-DI-{$suffix}",
        'status' => 'draft',
        'buyer_name' => 'Fictional DI Buyer',
        'buyer_ntn' => "RC-BUYER-{$suffix}",
        'buyer_registration_type' => 'Registered',
        'document_type' => 'Sale Invoice',
        'invoice_date' => '2026-09-16',
        'total_amount' => 118,
        'total_value_excluding_st' => 100,
        'total_sales_tax' => 18,
        'is_fbr_processing' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @return array{0: int, 1: array<int, string>}
 */
function runWorkers(string $worker, array $arguments, int $count): array
{
    $env = [];
    foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
        if (is_string($key) && (is_string($value) || is_int($value) || is_float($value))) {
            $env[$key] = (string) $value;
        }
    }
    $env['PATH'] = getenv('PATH') ?: '/usr/bin:/bin';
    $command = array_merge([PHP_BINARY, $worker], array_map('strval', $arguments));
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $processes = [];
    for ($i = 0; $i < $count; $i++) {
        $pipes = [];
        $process = proc_open($command, $descriptors, $pipes, base_path(), $env);
        if (!is_resource($process)) {
            failDi('could not start independent DI worker');
        }
        $processes[] = [$process, $pipes];
    }

    $output = [];
    $successes = 0;
    foreach ($processes as [$process, $pipes]) {
        $stdout = trim((string) stream_get_contents($pipes[1]));
        $stderr = trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0) {
            failDi("independent DI worker exit={$code}; stderr={$stderr}");
        }
        $successes++;
        $output[] = $stdout;
    }
    return [$successes, $output];
}

assertDi(
    (string) getenv('FBR_API_URL') === ''
    && (string) getenv('FBR_PRODUCTION_URL') === ''
    && (string) getenv('PRA_API_URL') === ''
    && (string) getenv('PRA_PRODUCTION_URL') === '',
    'clean process has no fiscal endpoint configured'
);

$companyId = makeDiCompany('CLAIM');
$claimInvoiceId = makeDiInvoice($companyId, 'CLAIM');
[$claimWorkers, $claimOutput] = runWorkers(
    __DIR__.'/../../tests/native/rc_di_fiscal_claim_worker.php',
    [$claimInvoiceId],
    10
);
assertDi($claimWorkers === 10, '10 independent fiscal claim workers completed');
assertDi(count(array_filter($claimOutput, fn (string $line): bool => $line === 'claimed')) === 1, 'canonical fiscal claim has exactly one winner');
$claimed = DB::table('invoices')->where('id', $claimInvoiceId)->first();
assertDi(
    $claimed->is_fbr_processing == 1
    && $claimed->fiscal_submission_state === DiFiscalSubmissionState::SUBMITTING
    && $claimed->fiscal_submission_environment === 'sandbox',
    'winner persisted canonical submitting lease'
);

$ambiguousInvoiceId = makeDiInvoice($companyId, 'AMBIGUOUS');
$ambiguous = DiFiscalSubmissionState::reserve($ambiguousInvoiceId, 'native_ambiguous', 'sandbox');
assertDi($ambiguous !== null, 'ambiguous-recovery fixture reserved canonical claim');
DiFiscalSubmissionState::verificationRequired($ambiguous, 'sandbox', 'native_ambiguous_response');
$ambiguous->save();
assertDi(
    DiFiscalSubmissionState::reserve($ambiguousInvoiceId, 'unsafe_replay', 'sandbox') === null,
    'ambiguous response remains sealed against replay'
);
$sealed = DB::table('invoices')->where('id', $ambiguousInvoiceId)->first();
assertDi(
    $sealed->status === 'pending_verification'
    && $sealed->fiscal_submission_state === DiFiscalSubmissionState::VERIFICATION_REQUIRED
    && $sealed->fiscal_submission_provenance === 'native_ambiguous_response',
    'ambiguous recovery persisted verification evidence'
);

$resultInvoiceId = makeDiInvoice($companyId, 'RESULT');
$resultBatch = InvoiceBulkSubmission::create([
    'company_id' => $companyId,
    'state' => 'running',
    'total' => 1,
    'dispatched' => 1,
    'started_at' => now(),
    'last_progress_at' => now(),
]);
[$resultWorkers] = runWorkers(
    __DIR__.'/../../tests/native/rc_di_bulk_result_worker.php',
    [$resultBatch->id, $resultInvoiceId],
    10
);
assertDi($resultWorkers === 10, '10 independent bulk-result workers completed');
$resultBatch->refresh();
assertDi(
    (int) $resultBatch->done === 1
    && (int) $resultBatch->success === 1
    && $resultBatch->state === 'completed'
    && DB::table('invoice_bulk_submission_results')->where('batch_id', $resultBatch->id)->where('invoice_id', $resultInvoiceId)->count() === 1,
    'bulk-result race and duplicate callback settle exactly once'
);

$recoveryBatch = InvoiceBulkSubmission::create([
    'company_id' => $companyId,
    'state' => 'running',
    'total' => 1,
    'dispatched' => 1,
    'done' => 1,
    'success' => 1,
    'started_at' => now(),
    'last_progress_at' => now(),
]);
$recoveryInvoiceId = makeDiInvoice($companyId, 'RECOVERY');
DB::table('invoice_bulk_submission_results')->insert([
    'batch_id' => $recoveryBatch->id,
    'invoice_id' => $recoveryInvoiceId,
    'outcome' => 'success',
    'message' => 'committed before simulated callback loss',
    'created_at' => now(),
    'updated_at' => now(),
]);
BulkSubmitInvoiceJob::recordResult($recoveryBatch->id, $recoveryInvoiceId, 'success', 'redelivery after callback loss');
assertDi($recoveryBatch->fresh()->state === 'completed', 'duplicate callback recovers settlement after result-before-settlement loss');

$outboxCompanyId = makeDiCompany('OUTBOX');
$outboxOne = makeDiInvoice($outboxCompanyId, 'OUTBOX-ONE');
$outboxTwo = makeDiInvoice($outboxCompanyId, 'OUTBOX-TWO');
$outboxBatch = InvoiceBulkSubmission::create([
    'company_id' => $outboxCompanyId,
    'state' => 'queued',
    'target_status' => 'draft',
    'max_invoice_id' => $outboxTwo,
    'started_at' => now(),
    'last_progress_at' => now(),
]);
$seed = new SeedBulkSubmitBatchJob($outboxBatch->id);
$claimNextChunk = new ReflectionMethod($seed, 'claimNextChunk');
$claimNextChunk->setAccessible(true);
$claim = $claimNextChunk->invoke($seed);
assertDi(is_array($claim) && count($claim[1]) === 2, 'outbox cursor claim selected frozen invoice range');
assertDi(
    DB::table('invoice_bulk_submission_outbox')->where('batch_id', $outboxBatch->id)->count() === 2,
    'outbox rows committed with cursor advancement'
);
Queue::fake();
$dispatchOutbox = new ReflectionMethod($seed, 'dispatchOutbox');
$dispatchOutbox->setAccessible(true);
$dispatchOutbox->invoke($seed);
$dispatchOutbox->invoke($seed);
Queue::assertPushed(BulkSubmitInvoiceJob::class, 2);
assertDi(
    DB::table('invoice_bulk_submission_outbox')->where('batch_id', $outboxBatch->id)->whereNotNull('dispatched_at')->count() === 2,
    'outbox redelivery dispatches each committed row once'
);

printf(
    "PASS: DI native proof claims=10 result_workers=10 result_rows=1 outbox_rows=2 endpoint_calls=0\n"
);
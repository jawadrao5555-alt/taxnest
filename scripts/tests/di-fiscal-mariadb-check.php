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
function runWorkers(string $worker, array $arguments, int $count, bool $synchronize = false): array
{
    return runWorkerSpecs(array_fill(0, $count, [$worker, $arguments]), $synchronize);
}

/**
 * @param array<int, array{0:string,1:array<int,int>}> $specs
 * @return array{0: int, 1: array<int, string>}
 */
function runWorkerSpecs(array $specs, bool $synchronize = false): array
{
    $guard = (string) getenv('LD_PRELOAD');
    if (!is_file($guard)) {
        failDi('independent DI workers require the loopback-only egress guard');
    }
    $env = [];
    foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
        if (is_string($key) && (is_string($value) || is_int($value) || is_float($value))) {
            $env[$key] = (string) $value;
        }
    }
    $env['PATH'] = getenv('PATH') ?: '/usr/bin:/bin';
    $env['LD_PRELOAD'] = $guard;
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $processes = [];
    $barrier = $synchronize ? tempnam(sys_get_temp_dir(), 'taxnest-rc-di-barrier-') : null;
    if ($barrier !== false && $barrier !== null) {
        unlink($barrier);
    }
    $readyDirectory = $synchronize ? sys_get_temp_dir().'/taxnest-rc-di-ready-'.bin2hex(random_bytes(8)) : null;
    if ($readyDirectory !== null && !mkdir($readyDirectory, 0700)) {
        failDi('could not create DI worker ready directory');
    }
    foreach ($specs as $position => [$worker, $arguments]) {
        $pipes = [];
        $command = array_merge([PHP_BINARY, $worker], array_map('strval', $arguments));
        if ($barrier !== null) {
            $command[] = $barrier;
            $command[] = $readyDirectory.'/'.$position;
        }
        $process = proc_open($command, $descriptors, $pipes, base_path(), $env);
        if (!is_resource($process)) {
            failDi('could not start independent DI worker');
        }
        $processes[] = [$process, $pipes];
    }
    if ($barrier !== null) {
        $deadline = microtime(true) + 20;
        do {
            $ready = glob($readyDirectory.'/*') ?: [];
            if (count($ready) === count($specs)) {
                break;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);
        if (count($ready) !== count($specs)) {
            failDi('not every DI worker reached the synchronization barrier');
        }
        touch($barrier);
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
    if ($barrier !== null) {
        unlink($barrier);
        array_map('unlink', glob($readyDirectory.'/*') ?: []);
        rmdir($readyDirectory);
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
assertDi(is_file((string) getenv('LD_PRELOAD')), 'clean process and independent workers use the loopback-only egress guard');

$companyId = makeDiCompany('CLAIM');
$claimInvoiceId = makeDiInvoice($companyId, 'CLAIM');
[$claimWorkers, $claimOutput] = runWorkers(
    __DIR__.'/../../tests/native/rc_di_fiscal_claim_worker.php',
    [$claimInvoiceId],
    10,
    true
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

$duplicateInvoiceId = makeDiInvoice($companyId, 'DUPLICATE');
$duplicateBatch = InvoiceBulkSubmission::create([
    'company_id' => $companyId,
    'state' => 'running',
    'total' => 1,
    'dispatched' => 1,
    'started_at' => now(),
    'last_progress_at' => now(),
]);
[$resultWorkers] = runWorkers(
    __DIR__.'/../../tests/native/rc_di_bulk_result_worker.php',
    [$duplicateBatch->id, $duplicateInvoiceId],
    10,
    true
);
assertDi($resultWorkers === 10, '10 independent bulk-result workers completed');
$duplicateBatch->refresh();
assertDi(
    (int) $duplicateBatch->done === 1
    && (int) $duplicateBatch->success === 1
    && $duplicateBatch->state === 'completed'
    && DB::table('invoice_bulk_submission_results')->where('batch_id', $duplicateBatch->id)->where('invoice_id', $duplicateInvoiceId)->count() === 1,
    'duplicate callbacks write one durable invoice result'
);

$terminalOne = makeDiInvoice($companyId, 'TERMINAL-ONE');
$terminalTwo = makeDiInvoice($companyId, 'TERMINAL-TWO');
$terminalBatch = InvoiceBulkSubmission::create([
    'company_id' => $companyId,
    'state' => 'running',
    'total' => 2,
    'dispatched' => 2,
    'started_at' => now(),
    'last_progress_at' => now(),
]);
[$terminalWorkers] = runWorkerSpecs([
    [__DIR__.'/../../tests/native/rc_di_bulk_result_worker.php', [$terminalBatch->id, $terminalOne]],
    [__DIR__.'/../../tests/native/rc_di_bulk_result_worker.php', [$terminalBatch->id, $terminalTwo]],
], true);
$terminalBatch->refresh();
assertDi(
    $terminalWorkers === 2
    && (int) $terminalBatch->done === 2
    && (int) $terminalBatch->success === 2
    && $terminalBatch->state === 'completed'
    && DB::table('invoice_bulk_submission_results')->where('batch_id', $terminalBatch->id)->count() === 2,
    'barrier-synchronized distinct terminal results settle total=2 exactly once'
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
$bulkJobCount = fn (): int => (int) DB::table('jobs')->where('queue', BulkSubmitInvoiceJob::QUEUE)->where('payload', 'like', '%BulkSubmitInvoiceJob%')->count();
$jobsBefore = $bulkJobCount();
[$outboxWorkers] = runWorkers(
    __DIR__.'/../../tests/native/rc_di_outbox_dispatch_worker.php',
    [$outboxBatch->id, 'dispatch'],
    2,
    true
);
assertDi(
    $outboxWorkers === 2
    && DB::table('invoice_bulk_submission_outbox')->where('batch_id', $outboxBatch->id)->whereNotNull('dispatched_at')->count() === 2
    && $bulkJobCount() === $jobsBefore + 2,
    'concurrent outbox dispatch enqueues each committed row once'
);

$crashCompanyId = makeDiCompany('OUTBOX-CRASH');
$crashInvoice = makeDiInvoice($crashCompanyId, 'OUTBOX-CRASH');
$crashBatch = InvoiceBulkSubmission::create([
    'company_id' => $crashCompanyId,
    'state' => 'queued',
    'target_status' => 'draft',
    'max_invoice_id' => $crashInvoice,
    'started_at' => now(),
    'last_progress_at' => now(),
]);
$crashSeed = new SeedBulkSubmitBatchJob($crashBatch->id);
$claimNextChunk = new ReflectionMethod($crashSeed, 'claimNextChunk');
$claimNextChunk->setAccessible(true);
$claimNextChunk->invoke($crashSeed);
$jobsBeforeCrash = $bulkJobCount();
[$crashWorkers] = runWorkers(
    __DIR__.'/../../tests/native/rc_di_outbox_dispatch_worker.php',
    [$crashBatch->id, 'crash_after_enqueue'],
    1,
    true
);
$crashRow = DB::table('invoice_bulk_submission_outbox')->where('batch_id', $crashBatch->id)->first();
assertDi(
    $crashWorkers === 1
    && $crashRow->dispatched_at === null
    && $crashRow->dispatch_claim_token !== null
    && $bulkJobCount() === $jobsBeforeCrash + 1,
    'post-enqueue pre-mark crash leaves one leased row for at-least-once recovery'
);
$liveBefore = $bulkJobCount();
[$liveWorkers] = runWorkers(
    __DIR__.'/../../tests/native/rc_di_outbox_dispatch_worker.php',
    [$crashBatch->id, 'dispatch'],
    2,
    true
);
assertDi(
    $liveWorkers === 2
    && $crashRow->dispatched_at === null
    && $bulkJobCount() === $liveBefore,
    'live outbox lease is not stolen by immediate retry'
);
DB::table('invoice_bulk_submission_outbox')->where('id', $crashRow->id)->update([
    'dispatch_claimed_at' => now()->subSeconds(SeedBulkSubmitBatchJob::OUTBOX_CLAIM_SECONDS + 1),
]);
$jobsBeforeRecovery = $bulkJobCount();
[$recoveryWorkers] = runWorkers(
    __DIR__.'/../../tests/native/rc_di_outbox_dispatch_worker.php',
    [$crashBatch->id, 'dispatch'],
    2,
    true
);
assertDi(
    $recoveryWorkers === 2
    && DB::table('invoice_bulk_submission_outbox')->where('id', $crashRow->id)->whereNotNull('dispatched_at')->exists()
    && $bulkJobCount() === $jobsBeforeRecovery + 1,
    'expired outbox claim replays one at-least-once hand-off'
);
[$downstreamWorkers] = runWorkers(
    __DIR__.'/../../tests/native/rc_di_bulk_result_worker.php',
    [$crashBatch->id, $crashInvoice],
    2,
    true
);
$crashBatch->refresh();
assertDi(
    $downstreamWorkers === 2
    && (int) $crashBatch->done === 1
    && DB::table('invoice_bulk_submission_results')->where('batch_id', $crashBatch->id)->where('invoice_id', $crashInvoice)->count() === 1,
    'at-least-once outbox replay reaches one downstream fiscal result'
);

printf(
    "PASS: DI native proof claims=10 duplicate_workers=10 terminal_workers=2 terminal_results=2 outbox_workers=4 endpoint_calls=0\n"
);
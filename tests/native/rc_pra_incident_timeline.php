<?php
declare(strict_types=1);

$db = getenv('DB_DATABASE') ?: '';
$socket = getenv('DB_SOCKET') ?: '';
if (!preg_match('/^taxnest_rc_[a-z0-9_]+_full$/', $db)
    || !str_starts_with($socket, '/tmp/taxnest-rc-mariadb-')
    || getenv('APP_ENV') !== 'rc-mariadb' || !getenv('LD_PRELOAD')) {
    fwrite(STDERR, "FAIL unsafe diagnostic timeline target\n"); exit(2);
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
\Illuminate\Support\Carbon::setTestNow(now()->startOfSecond());

use App\Models\PosPrintJob;
use App\Services\PrintJobEvidence;
use App\Services\Reporting\PraIncidentTimeline;
use Illuminate\Support\Facades\DB;

DB::beginTransaction();
try {
    $suffix = bin2hex(random_bytes(6));
    $company = DB::table('companies')->insertGetId(['name'=>'RC diagnostic '.$suffix,
        'email'=>$suffix.'@diagnostic.invalid', 'ntn'=>$suffix, 'product_type'=>'pos', 'created_at'=>now(), 'updated_at'=>now()]);
    $bill = DB::table('pos_transactions')->insertGetId(['company_id'=>$company,
        'invoice_number'=>'RC-DIAG-'.$suffix, 'invoice_mode'=>'local', 'pra_status'=>'local', 'status'=>'completed',
        'transaction_type'=>'sale', 'subtotal'=>100, 'total_amount'=>100, 'payment_method'=>'cash', 'created_at'=>now(), 'updated_at'=>now()]);
    $before = (array) DB::table('pos_transactions')->find($bill);
    $job = PosPrintJob::create(['company_id'=>$company, 'transaction_id'=>$bill, 'type'=>'kot', 'status'=>'pending', 'target_printer'=>'Fictional diagnostic printer']);
    PrintJobEvidence::record($company, 'claimed', $job->id, 1);
    PrintJobEvidence::record($company, 'result_received', $job->id, 1, ['outcome'=>'spool_accepted', 'claim_token'=>'SECRET']);
    DB::table('pos_print_jobs')->where('id', $job->id)->delete();
    $report = app(PraIncidentTimeline::class)->read(['company_name'=>'RC diagnostic '.$suffix,
        'date_from'=>now(config('reporting_connector.timezone'))->toDateString(), 'bill_id'=>$bill], [$company]);
    if (count($report['events']) !== 3 || $report['events'][2]['details']['outcome'] !== 'spool_accepted'
        || $report['coverage']['complete'] !== false || $report['physical_print'] !== 'unknown'
        || str_contains(json_encode($report), 'SECRET') || $before !== (array) DB::table('pos_transactions')->find($bill)) {
        throw new RuntimeException('Native diagnostic correlation, redaction or immutable financial evidence failed');
    }
    foreach ([7, 30, 31] as $days) DB::table('pos_print_evidence')->insert([
        'company_id'=>$company, 'event'=>'claimed', 'context'=>'{}', 'received_at'=>now()->subDays($days)]);
    PrintJobEvidence::prune(1);
    if (DB::table('pos_print_evidence')->where('company_id', $company)->where('received_at', now()->subDays(7))->count() !== 1
        || DB::table('pos_print_evidence')->where('company_id', $company)->where('received_at', now()->subDays(30))->count() !== 1) {
        throw new RuntimeException('Native retention removed seven-day or exact thirty-day evidence');
    }
    echo "PASS: native PRA diagnostic creation/claim/result survives job purge; redaction, immutable bill and seven/thirty-day retention boundary\n";
} finally {
    DB::rollBack();
}

<?php
/**
 * Stand-in for the Desktop Agent while a video is recorded (DEV ONLY).
 *
 * The demo shop runs FBR reporting in fiscal_device + agent mode, so every
 * final bill queues as fbr_status='pending' for an Agent that does not exist
 * in this workspace. Without this loop the sale screen keeps "Reporting to
 * FBR" and the header shows a pulsing red "Failed 1" pill. This marks the
 * demo company's pending bills as submitted (fake FBR number, code 100) every
 * couple of seconds — exactly what the real Agent writes on success.
 *
 *   VIDEO_PIPELINE_ALLOW=1 php fake-agent-loop.php
 */
// Hard-bound to the pharmacy demo shop: the CLI takes NO identity argument,
// so nothing can point this loop at another company.
const DEMO_EMAIL = 'pharmacydemo@nestpos.pk';

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->instance('request', Illuminate\Http\Request::create('/', 'GET'));
$app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    \App\Support\DevStagingGuard::assertLocalStaging('fake-agent-loop');
} catch (\RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
$company = DB::table('companies')
    ->where('email', DEMO_EMAIL)
    ->where('product_type', 'fbrpos')
    ->where('is_internal_account', true)
    ->first();
if (!$company) { fwrite(STDERR, "fake-agent-loop: no internal FBR demo company for " . DEMO_EMAIL . "\n"); exit(1); }

fwrite(STDOUT, "fake agent watching company #{$company->id}\n");
while (true) {
    $pending = DB::table('fbr_pos_transactions')
        ->where('company_id', $company->id)
        ->where('fbr_status', 'pending')
        ->whereNull('fbr_invoice_number')
        ->get(['id']);
    foreach ($pending as $t) {
        DB::table('fbr_pos_transactions')->where('id', $t->id)->where('fbr_status', 'pending')->update([
            'fbr_invoice_number' => '7000' . str_pad((string) $t->id, 9, '0', STR_PAD_LEFT),
            'fbr_status' => 'submitted',
            'fbr_response_code' => '100',
            'fbr_error_message' => null,
            'updated_at' => now(),
        ]);
        fwrite(STDOUT, "  submitted #{$t->id}\n");
    }
    sleep(2);
}

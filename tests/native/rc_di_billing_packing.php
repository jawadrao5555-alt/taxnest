<?php

declare(strict_types=1);

$database = getenv('DB_DATABASE') ?: '';
$socket = getenv('DB_SOCKET') ?: '';
if (getenv('APP_ENV') !== 'rc-mariadb' || ! getenv('LD_PRELOAD')
    || ! preg_match('/^taxnest_rc_[a-z0-9_]+_full$/D', $database)
    || ! preg_match('#^/tmp/taxnest-rc-mariadb-[a-z0-9_]+/run/mariadb.sock$#D', $socket)) {
    fwrite(STDERR, "FAIL unsafe DI billing/packing target\n");
    exit(2);
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Company;
use App\Models\Invoice;
use App\Models\PricingPlan;
use App\Models\Product;
use App\Services\DiSellingPacking;
use App\Services\FbrService;
use App\Services\SubscriptionAssignmentService;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

Http::preventStrayRequests();
function requestInvoice(string $key, array $payload): int
{
    $request = Request::create('/api/di/v1/invoices', 'POST', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.$key, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
    ], json_encode($payload, JSON_THROW_ON_ERROR));
    $response = app(Kernel::class)->handle($request);
    if (! in_array($response->getStatusCode(), [200, 201, 422], true)) {
        throw new RuntimeException('Unexpected DI API status '.$response->getStatusCode().': '.$response->getContent());
    }

    return $response->getStatusCode();
}
if (($argv[1] ?? '') === 'worker') {
    [$key, $encoded, $barrier, $index] = array_slice($argv, 2);
    if (! str_starts_with($barrier, dirname($socket).'/di-packing-') || ! ctype_digit($index)) {
        throw new RuntimeException('Unsafe DI return barrier.');
    }
    $payload = json_decode(base64_decode($encoded, true), true, 512, JSON_THROW_ON_ERROR);
    $payload['client_reference'] .= '-worker-'.$index;
    file_put_contents($barrier.'.ready-'.$index, 'ready');
    $deadline = microtime(true) + 20;
    while (! is_file($barrier)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('DI return barrier timed out.');
        }
        usleep(10000);
    }
    echo requestInvoice($key, $payload)."\n";
    exit(0);
}

$suffix = bin2hex(random_bytes(8));
$company = Company::withoutGlobalScopes()->create(['name' => 'Synthetic native DI packing', 'ntn' => 'DP'.$suffix,
    'product_type' => 'di', 'status' => 'approved', 'company_status' => 'active', 'province' => 'Punjab']);
$key = 'dik_'.$company->id.'_'.bin2hex(random_bytes(16));
$company->forceFill(['di_api_key_hash' => hash('sha256', $key)])->save();
$plan = PricingPlan::create(['name' => 'Synthetic native DI cycles '.$suffix, 'product_type' => 'di', 'price' => 1000,
    'price_monthly' => 1000, 'price_quarterly' => 2970, 'price_semi_annual' => 5820, 'price_yearly' => 11280,
    'invoice_limit' => -1, 'is_trial' => false]);
$sub = SubscriptionAssignmentService::assign($company->id, $plan->id, 'quarterly');
if ($sub->billing_cycle !== 'quarterly' || (float) $sub->final_price !== 2970.0
    || $sub->end_date->toDateString() !== now()->addMonthsNoOverflow(3)->toDateString()) {
    throw new RuntimeException('Native DI cycle does not match its configured price/term.');
}
$packings = DiSellingPacking::definitions([['label' => '25 KG bag', 'factor' => '25']], 'KG');
$product = Product::withoutGlobalScopes()->create(['company_id' => $company->id, 'name' => 'Synthetic native packing product',
    'hs_code' => '33049900', 'uom' => 'KG', 'default_price' => 100, 'is_active' => true, 'selling_packings' => $packings]);
$payload = ['client_reference' => 'native-packing-'.$suffix, 'mode' => 'draft', 'buyer_name' => 'Synthetic native buyer',
    'buyer_address' => 'Synthetic Lahore', 'document_type' => 'Sale Invoice', 'destination_province' => 'Punjab',
    'items' => [['product_id' => $product->id, 'selling_packing_id' => $packings[0]['id'], 'hs_code' => '33049900',
        'description' => 'Synthetic native bags', 'default_uom' => 'KG', 'quantity' => '2', 'price' => '2500',
        'tax' => 900, 'tax_rate' => 18, 'schedule_type' => 'standard']]];
if (requestInvoice($key, $payload) !== 201) {
    throw new RuntimeException('Native packing invoice not created.');
}
$invoice = Invoice::withoutGlobalScopes()->where('company_id', $company->id)->where('client_reference', $payload['client_reference'])->sole();
$item = $invoice->items()->sole();
if ((float) $item->quantity !== 50.0 || (float) $item->price !== 100.0 || (float) $invoice->total_amount !== 5900.0) {
    throw new RuntimeException('Native persisted packing values mismatch.');
}
$fiscal = app(FbrService::class)->buildPayload($invoice->load('items', 'company'), true);
if ($fiscal['items'][0]['quantity'] !== 50.0 || (float) $fiscal['items'][0]['totalValues'] !== 5900.0) {
    throw new RuntimeException('Native packing fiscal snapshot mismatch.');
}
$fractional = $payload;
$fractional['client_reference'] .= '-fractional';
$fractional['items'][0]['quantity'] = '0.3333';
if (requestInvoice($key, $fractional) !== 201) {
    throw new RuntimeException('Native fractional packing not created.');
}
$fractionalInvoice = Invoice::withoutGlobalScopes()->where('company_id', $company->id)->where('client_reference', $fractional['client_reference'])->sole();
if (number_format($fractionalInvoice->items()->sole()->quantity, 4, '.', '') !== '8.3325') {
    throw new RuntimeException('Native DECIMAL column truncated the converted 4dp quantity.');
}
$invoice->update(['status' => 'locked']);
$product->update(['selling_packings' => DiSellingPacking::definitions([['label' => '50 KG bag', 'factor' => '50']], 'KG')]);
if (requestInvoice($key, $payload) !== 200 || $item->fresh()->selling_snapshot['factor'] !== '25') {
    throw new RuntimeException('Native packing retry changed original evidence.');
}
$payload['document_type'] = 'Credit Note';
$payload['reference_invoice_number'] = $invoice->invoice_number;
$payload['client_reference'] = 'native-return-'.$suffix;
$payload['items'][0]['source_invoice_item_id'] = $item->id;
$barrier = dirname($socket).'/di-packing-'.$suffix;
$workers = [];
for ($index = 0; $index < 4; $index++) {
    $process = new Process([PHP_BINARY, __FILE__, 'worker', $key, base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), $barrier, (string) $index]);
    $process->setTimeout(45)->start();
    $workers[] = $process;
}
$deadline = microtime(true) + 25;
while (count(glob($barrier.'.ready-*')) !== 4) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Native packing workers did not reach the barrier.');
    }
    usleep(10000);
}
file_put_contents($barrier, 'go');
$statuses = [];
foreach ($workers as $worker) {
    $worker->wait();
    if (! $worker->isSuccessful()) {
        throw new RuntimeException($worker->getOutput().$worker->getErrorOutput());
    }
    $statuses[] = trim($worker->getOutput());
}
if (count(array_filter($statuses, fn ($status) => $status === '201')) !== 1
    || count(array_filter($statuses, fn ($status) => $status === '422')) !== 3) {
    throw new RuntimeException('Native return quantity lock did not produce one winner: '.json_encode($statuses));
}
$return = Invoice::withoutGlobalScopes()->where('company_id', $company->id)->where('document_type', 'Credit Note')->sole();
if ((float) $return->items()->sole()->quantity !== 50.0) {
    throw new RuntimeException('Native return did not preserve the original 25 KG packing.');
}
foreach (glob($barrier.'*') as $path) {
    unlink($path);
}
Http::assertNothingSent();
echo "PASS: native configured DI quarterly term, exact 4dp packing persistence/payload and stable retry; four simultaneous original-packing returns create one full credit and refuse three excess claims; no fiscal endpoint calls\n";

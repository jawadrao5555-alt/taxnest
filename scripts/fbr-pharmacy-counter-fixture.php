<?php
/**
 * Dev-only fixture for scripts/fbr-pharmacy-counter-check.mjs (FBR Pharmacy
 * Mode: out-of-stock alternatives + missed-sale capture at the counter).
 *
 * The browser check needs, on the dev pharmacy demo shop (the video-demo
 * company seeded by FbrPharmacyVideoDemoSeeder):
 *   - pharmacy mode + inventory ON (recorded and restored),
 *   - ONE zero-stock product that shares its salt + strength with an in-stock
 *     product (so the alternatives panel has something to offer).
 *
 *   setup     put the shop in that state, print JSON {company_id, product_id,
 *             barcode, name, alternative, login}
 *   teardown  delete the fixture product + every missed-sale row the check
 *             wrote, restore the recorded flags.
 *
 * Originals are recorded in storage/app/fbr-pharmacy-counter-fixture.json so a
 * crashed run is repaired by a plain `teardown`.
 *
 * Usage (PG env-strip prefix as for every artisan call in this container):
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *       -u PGPASSWORD -u PGDATABASE php scripts/fbr-pharmacy-counter-fixture.php setup
 *
 * Exit codes: 0 = ok (JSON on stdout), 2 = could not run.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Services\PosFeatureService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

const STATE_FILE = 'fbr-pharmacy-counter-fixture.json';
const FIXTURE_BARCODE = '1581000000015';
const FIXTURE_NAME = 'Febrol 500mg (QA fixture)';
const FIXTURE_TERM = 'QA counter check';

function bail(string $msg): never
{
    fwrite(STDERR, "ERROR: {$msg}\n");
    exit(2);
}

$cmd = $argv[1] ?? 'status';
$login = getenv('POS_CHECK_LOGIN') ?: \Database\Seeders\FbrPharmacyVideoDemoSeeder::LOGIN_EMAIL;

$user = User::where('email', $login)->first();
if (!$user) {
    bail("no dev user {$login} — seed the pharmacy demo shop first: php artisan db:seed --class=FbrPharmacyVideoDemoSeeder");
}
$company = Company::find($user->company_id);
if (!$company) bail("user {$login} has no company");
if (($company->product_type ?? '') !== 'fbrpos') bail("company {$company->id} is not an FBR POS shop");

$readState = function () {
    return Storage::exists(STATE_FILE) ? (json_decode(Storage::get(STATE_FILE), true) ?: null) : null;
};

if ($cmd === 'setup') {
    $existing = $readState();
    if (!$existing) {
        Storage::put(STATE_FILE, json_encode([
            'company_id' => $company->id,
            'feature_flags' => $company->feature_flags,
            'pharmacy_mode' => $company->pharmacy_mode ?? null,
            'inventory_enabled' => $company->inventory_enabled ?? null,
        ]));
    }
    $flags = is_array($company->feature_flags) ? $company->feature_flags : [];
    $flags['pharmacy'] = true;
    $flags['batch_expiry'] = true;
    $flags['inventory'] = true;
    $flags = PosFeatureService::normalize($flags);
    $company->update(['feature_flags' => $flags] + PosFeatureService::masterSwitches($flags));
    PosFeatureService::flushGateCaches();

    // An in-stock same-salt product is what the panel offers; the seeded
    // Panadol 500mg is the template so tax/uom columns are shop-consistent.
    $alt = Product::where('company_id', $company->id)->where('generic_name', 'Paracetamol')->where('strength', '500mg')
        ->where('barcode', '!=', FIXTURE_BARCODE)->orderBy('id')->first();
    if (!$alt) bail("no in-stock Paracetamol 500mg product on company {$company->id} — reseed FbrPharmacyVideoDemoSeeder");

    $p = Product::where('company_id', $company->id)->where('barcode', FIXTURE_BARCODE)->first();
    if (!$p) {
        $p = $alt->replicate();
        $p->name = FIXTURE_NAME;
        $p->barcode = FIXTURE_BARCODE;
        $p->sku = 'QA-PH-1581';
        $p->save();
    }
    // Zero stock = no inventory_stocks row at all (the probe answers 0).
    if (Schema::hasTable('inventory_stocks')) {
        DB::table('inventory_stocks')->where('company_id', $company->id)->where('product_id', $p->id)->delete();
    }
    echo json_encode([
        'company_id' => $company->id, 'product_id' => $p->id, 'barcode' => FIXTURE_BARCODE,
        'name' => $p->name, 'alternative' => $alt->name, 'alternative_barcode' => $alt->barcode,
        'login' => $login, 'term' => FIXTURE_TERM,
    ]), "\n";
    exit(0);
}

if ($cmd === 'teardown') {
    $deleted = Product::where('company_id', $company->id)->where('barcode', FIXTURE_BARCODE)->get();
    foreach ($deleted as $d) {
        if (Schema::hasTable('inventory_stocks')) {
            DB::table('inventory_stocks')->where('company_id', $company->id)->where('product_id', $d->id)->delete();
        }
        $d->forceDelete();
    }
    $missed = 0;
    if (Schema::hasTable('pharmacy_missed_sales')) {
        $missed = DB::table('pharmacy_missed_sales')->where('company_id', $company->id)
            ->where(function ($q) { $q->where('term', 'like', FIXTURE_TERM . '%')->orWhere('term', 'like', 'Febrol%'); })
            ->delete();
    }
    $state = $readState();
    if ($state && (int) ($state['company_id'] ?? 0) === (int) $company->id) {
        $flags = is_array($state['feature_flags'] ?? null) ? $state['feature_flags'] : [];
        $flags = PosFeatureService::normalize($flags);
        $company->update(['feature_flags' => $flags] + PosFeatureService::masterSwitches($flags));
        PosFeatureService::flushGateCaches();
        Storage::delete(STATE_FILE);
    }
    echo json_encode(['products_deleted' => $deleted->count(), 'missed_deleted' => $missed, 'restored' => (bool) $state]), "\n";
    exit(0);
}

// status
$p = Product::where('company_id', $company->id)->where('barcode', FIXTURE_BARCODE)->first();
$missed = Schema::hasTable('pharmacy_missed_sales')
    ? DB::table('pharmacy_missed_sales')->where('company_id', $company->id)->orderByDesc('id')->limit(5)
        ->get(['id', 'term', 'reason', 'product_id', 'quantity'])->all()
    : [];
echo json_encode(['company_id' => $company->id, 'fixture_product' => $p?->id, 'recent_missed' => $missed]), "\n";

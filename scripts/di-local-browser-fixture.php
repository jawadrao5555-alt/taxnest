<?php
// Only synthetic, disposable SQLite fixtures behind the loopback egress guard.
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$database = (string) config('database.connections.sqlite.database');
if (!app()->environment('testing') || config('database.default') !== 'sqlite'
    || !str_starts_with($database, '/tmp/taxnest-di-ui-') || getenv('RC_SAFE_RUN') !== '1') {
    fwrite(STDERR, "DI UI fixture refused non-disposable environment.\n"); exit(2);
}
$mode = $argv[1] ?? '';
$out = $argv[2] ?? '';
if ($mode === 'verify') {
    $invoice = App\Models\Invoice::withoutGlobalScopes()->with(['items', 'company'])->sole();
    $expected = ($argv[3] ?? '') === 'financial' ? 98.0 : 11800.0;
    if ($invoice->status !== 'draft' || abs((float) $invoice->total_amount - $expected) > 0.005 || $invoice->fbr_invoice_number) exit(1);
    if (($argv[3] ?? '') === 'withholding' && abs((float) $invoice->items->first()->st_withheld_amount - 500.25) > 0.005) exit(1);
    echo "DI browser draft persisted with expected amount and no fiscal submission.\n"; exit(0);
}
if ($mode !== 'seed') exit(2);
$company = App\Models\Company::create(['name'=>'Synthetic DI Browser Seller','ntn'=>'1234567', 'product_type'=>'di', 'status'=>'approved','company_status'=>'active','province'=>'Punjab','city'=>'Lahore','address'=>'Synthetic test address']);
$password = bin2hex(random_bytes(16));
$user = App\Models\User::create(['name'=>'Synthetic DI Owner','email'=>'di-ui@rc-browser.invalid','password'=>Illuminate\Support\Facades\Hash::make($password),'role'=>'company_admin','is_active'=>true,'company_id'=>$company->id]);
$user->forceFill(['email_verified_at'=>now()])->save();
$plan = App\Models\PricingPlan::create(['name'=>'Synthetic DI Plan','product_type'=>'di','price'=>5000,'is_trial'=>false,'invoice_limit'=>-1]);
App\Models\Subscription::create(['company_id'=>$company->id,'pricing_plan_id'=>$plan->id,'active'=>true,'start_date'=>now()->subMonth()->toDateString(),'end_date'=>now()->addYear()->toDateString(),'override_type'=>'none']);
App\Models\GlobalHsMaster::updateOrCreate(['hs_code'=>'33049900'],['description'=>'Synthetic taxable test line','pct_code'=>'33049900','schedule_type'=>'standard','tax_rate'=>18,'default_uom'=>'Numbers, pieces, units','st_withheld_applicable'=>true,'mapping_status'=>'approved']);
App\Models\HsMasterGlobal::updateOrCreate(['hs_code'=>'33049900'],['description'=>'Synthetic taxable test line','schedule_type'=>'standard','default_tax_rate'=>18,'default_uom'=>'Numbers, pieces, units','st_withheld_applicable'=>true,'is_active'=>true]);
file_put_contents($out, json_encode(['login'=>$user->email,'password'=>$password]));
chmod($out, 0600);
echo "Synthetic DI browser fixture seeded.\n";

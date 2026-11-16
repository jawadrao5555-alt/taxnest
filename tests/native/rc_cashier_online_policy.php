<?php
declare(strict_types=1);
$db = getenv('DB_DATABASE') ?: '';
$socket = getenv('DB_SOCKET') ?: '';
if (!preg_match('/^taxnest_rc_[a-z0-9_]+_full$/D', $db)
    || !preg_match('#^/tmp/taxnest-rc-mariadb-[a-z0-9_]+/run/mariadb.sock$#D', $socket)
    || getenv('APP_ENV') !== 'rc-mariadb' || !getenv('LD_PRELOAD')) {
    fwrite(STDERR, "FAIL unsafe cashier policy target\n"); exit(2);
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{Company, User};
use App\Services\PosOnlinePaymentPolicy;
use App\Http\Controllers\PosOnlinePaymentPolicyController;
use Illuminate\Support\Facades\{DB, Http, Schema};
use Illuminate\Http\Request;
Http::preventStrayRequests();
function policyProof(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function policyStatus(callable $fn): int {
    try { $fn(); return 200; }
    catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) { return $e->getStatusCode(); }
    catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) { return 404; }
}
DB::beginTransaction();
try {
    policyProof(Schema::hasTable('pos_online_payment_policies'), 'Migrated policy table missing');
    $company = Company::create(['name'=>'Synthetic cashier policy','ntn'=>bin2hex(random_bytes(6)), 'product_type'=>'pos','status'=>'approved','company_status'=>'active','pra_reporting_enabled'=>false]);
    $foreign = Company::create(['name'=>'Synthetic foreign cashier policy','ntn'=>bin2hex(random_bytes(6)), 'product_type'=>'pos','status'=>'approved','company_status'=>'active','pra_reporting_enabled'=>false]);
    $make = fn ($companyId, $role, $posRole) => User::create(['name'=>'Synthetic policy actor','email'=>bin2hex(random_bytes(10)).'@rc-policy.invalid','password'=>bcrypt('SyntheticOnly123!'),'company_id'=>$companyId,'role'=>$role,'pos_role'=>$posRole,'is_active'=>true]);
    $owner=$make($company->id,'company_admin','pos_admin'); $cashier=$make($company->id,'user','pos_cashier');
    $admin=$make($company->id,'user','pos_admin'); $other=$make($foreign->id,'user','pos_cashier');
    app()->instance('currentCompanyId',$company->id);
    $request=Request::create('/pos/team/online-payment-policy/'.$cashier->id,'PUT',['requires_approval'=>true]);
    $request->setLaravelSession(app('session')->driver()); app()->instance('request',$request);
    $controller=app(PosOnlinePaymentPolicyController::class);
    policyProof(!PosOnlinePaymentPolicy::requiresApproval($cashier,$company->id),'Default must be OFF');
    policyProof(policyStatus(fn()=>PosOnlinePaymentPolicy::assertAllowed($cashier,$company->id,'qr_payment'))===200,'Default OFF changed QR');
    auth('pos')->setUser($admin);
    policyProof(policyStatus(fn()=>$controller->update($request,$cashier->id))===403,'Standalone POS admin configured policy');
    auth('pos')->setUser($owner); $controller->update($request,$cashier->id);
    policyProof(policyStatus(fn()=>$controller->update($request,$other->id))===404,'Cross-company policy changed');
    // The retained actor object represents an existing web session or queued actor snapshot.
    policyProof(PosOnlinePaymentPolicy::requiresApproval($cashier,$company->id),'Existing session missed current policy');
    foreach(['qr_payment','online'] as $method) policyProof(policyStatus(fn()=>PosOnlinePaymentPolicy::assertAllowed($cashier,$company->id,$method))===403,'Online method bypass');
    policyProof(policyStatus(fn()=>PosOnlinePaymentPolicy::assertAllowed($cashier,$company->id,'cash',true))===403,'Forged confirmation bypass');
    foreach(['cash','card'] as $method) policyProof(policyStatus(fn()=>PosOnlinePaymentPolicy::assertAllowed($cashier,$company->id,$method))===200,'Cash/card changed');
    policyProof(policyStatus(fn()=>PosOnlinePaymentPolicy::assertAllowed($owner,$company->id,'qr_payment',true))===200,'Own-session owner approval blocked');
    policyProof(policyStatus(fn()=>PosOnlinePaymentPolicy::assertAllowed($other,$company->id,'qr_payment'))===403,'Foreign actor accepted');
    DB::table('users')->where('id',$owner->id)->update(['is_active'=>false]);
    policyProof(policyStatus(fn()=>PosOnlinePaymentPolicy::assertAllowed($owner,$company->id,'qr_payment'))===403,'Inactive cached owner accepted');
    policyProof(DB::table('pos_online_payment_policies')->where('company_id',$company->id)->count()===1,'Policy identity duplicated');
    echo "PASS: native cashier policy schema default OFF owner-only settings current-session enforcement and tenant isolation\n";
} finally { DB::rollBack(); }

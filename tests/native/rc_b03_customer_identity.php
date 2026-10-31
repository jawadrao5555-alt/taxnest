<?php
declare(strict_types=1);

// Actual controller race in a freshly migrated, guarded RC database only.
$db=getenv('DB_DATABASE') ?: '';
$socket=getenv('DB_SOCKET') ?: '';
if (!preg_match('/^taxnest_rc_[a-z0-9_]+_full$/D',$db)
    || !str_starts_with($socket,'/tmp/taxnest-rc-mariadb-')
    || getenv('APP_ENV')!=='rc-mariadb' || !getenv('LD_PRELOAD')) {
    fwrite(STDERR,"FAIL unsafe B03 native target\n");exit(2);
}
require dirname(__DIR__,2).'/vendor/autoload.php';
$app=require dirname(__DIR__,2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\{Company,PosCustomer};
use App\Services\PosCustomerPhoneIdentity;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

if (($argv[1] ?? '')==='worker') {
    $id=(int)($argv[2] ?? 0);$phone=$argv[3] ?? '';$barrier=$argv[4] ?? '';
    if (!str_starts_with($barrier,dirname($socket).'/b03-start-')) throw new RuntimeException('Invalid native barrier');
    Company::whereKey($id)->where('name','Synthetic B03 native identity')->firstOrFail();
    // Deliberately process-local cache: database locks must prevent duplicates.
    config(['cache.default'=>'array']);
    app()->instance('currentCompanyId',$id);
    $request=\Illuminate\Http\Request::create('/pos/restaurant/api/customer-store','POST',['name'=>'Synthetic native contact','phone'=>$phone]);
    $controller=app(\App\Http\Controllers\RestaurantPosController::class);
    $request->validate(['phone'=>'required|string']);
    PosCustomerPhoneIdentity::canonical($phone);
    file_put_contents($barrier.'.ready-'.hash('sha256',$phone),'ready');
    $until=microtime(true)+15;
    while (!is_file($barrier)) { if(microtime(true)>$until)throw new RuntimeException('Native barrier timeout');usleep(500); }
    $response=$controller->customerStore($request);
    $data=$response->getData(true);
    if ($response->status()!==200 || empty($data['customer']['id']))throw new RuntimeException('Native customer create failed');
    echo json_encode(['id'=>(int)$data['customer']['id']])."\n";exit(0);
}

$companies=[];$processes=[];$barrier=dirname($socket).'/b03-start-'.bin2hex(random_bytes(8));
try {
    foreach (['Synthetic B03 native identity','Synthetic B03 native foreign'] as $name) {
        $company=Company::create(['name'=>$name,'email'=>bin2hex(random_bytes(12)).'@rc-b03.invalid','ntn'=>bin2hex(random_bytes(7)),'product_type'=>'pos','status'=>'approved','company_status'=>'active']);
        $companies[]=$company->id;
    }
    $foreign=PosCustomer::create(['company_id'=>$companies[1],'name'=>'Synthetic foreign unchanged','phone'=>'+92 300 1234567','is_active'=>true]);
    $foreignBefore=$foreign->getAttributes();
    $phones=['0300-1234567','+92 300 1234567','00923001234567','3001234567','0300 123 4567','+9203001234567'];
    foreach ($phones as $phone) {
        $process=new Process([PHP_BINARY,'-c',php_ini_loaded_file(),__FILE__,'worker',(string)$companies[0],$phone,$barrier]);
        $process->setTimeout(30);$process->start();$processes[]=$process;
    }
    $readyDeadline=microtime(true)+15;
    while(count(glob($barrier.'.ready-*'))!==count($processes)) { if(microtime(true)>$readyDeadline)throw new RuntimeException('Native workers did not become ready');usleep(10000); }
    file_put_contents($barrier,'go');
    $ids=[];
    foreach ($processes as $process) {
        $process->wait();
        if (!$process->isSuccessful())throw new RuntimeException('B03 native worker failed: '.$process->getErrorOutput());
        $row=json_decode(trim($process->getOutput()),true,512,JSON_THROW_ON_ERROR);
        $ids[]=$row['id'];
    }
    if(count(array_unique($ids))!==1 || PosCustomer::where('company_id',$companies[0])->count()!==1)throw new RuntimeException('Concurrent phone formats split one customer identity');
    foreach ($phones as $phone) {
        $query=PosCustomer::where('company_id',$companies[0]);
        PosCustomerPhoneIdentity::constrain($query,'phone',$phone,false);
        if($query->count()!==1)throw new RuntimeException('Native normalized lookup lost a compatible format');
    }
    $query=PosCustomer::where('company_id',$companies[0]);
    PosCustomerPhoneIdentity::constrain($query,'phone','+44 3001234567',false);
    if($query->exists() || $foreign->fresh()->getAttributes()!==$foreignBefore)throw new RuntimeException('Foreign phone or tenant identity mixed');
    echo "PASS: six simultaneous actual customer-store controller workers share one canonical identity; native SQL phone variants, foreign country and second tenant preserved\n";
} finally {
    foreach ($processes as $process) if($process->isRunning())$process->stop(1);
    if(is_file($barrier))unlink($barrier);
    foreach(glob($barrier.'.ready-*') as $ready)unlink($ready);
    if($companies){DB::table('pos_customers')->whereIn('company_id',$companies)->delete();DB::table('companies')->whereIn('id',$companies)->delete();}
}

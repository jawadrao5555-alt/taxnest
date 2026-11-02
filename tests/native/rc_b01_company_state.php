<?php
declare(strict_types=1);

$db=getenv('DB_DATABASE') ?: '';
$socket=getenv('DB_SOCKET') ?: '';
if (!preg_match('/^taxnest_rc_[a-z0-9_]+_full$/D',$db)
    || !str_starts_with($socket,'/tmp/taxnest-rc-mariadb-')
    || getenv('APP_ENV')!=='rc-mariadb' || !getenv('LD_PRELOAD')) {
    fwrite(STDERR,"FAIL unsafe B01 native target\n");exit(2);
}
require dirname(__DIR__,2).'/vendor/autoload.php';
$app=require dirname(__DIR__,2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\{AdminAuditLog,AdminUser,Company,Subscription};
use App\Services\CompanyAccountStateService;
use Symfony\Component\Process\Process;

if (($argv[1] ?? '')==='worker') {
    [$companyId,$adminId,$token,$barrier,$index]=array_slice($argv,2);
    if (!str_starts_with($barrier,dirname($socket).'/b01-start-') || !ctype_digit($index)) throw new RuntimeException('Invalid B01 barrier');
    Company::whereKey($companyId)->where('name','Synthetic B01 native account')->firstOrFail();
    auth('admin')->setUser(AdminUser::findOrFail($adminId));
    $request=\Illuminate\Http\Request::create('/admin/companies/'.$companyId.'/suspend','POST',[
        'state_token'=>$token,'reason'=>'Concurrent B01 review','restoration_condition'=>'Manual owner review',
    ]);
    $request->headers->set('Accept','application/json');
    $controller=app(\App\Http\Controllers\SaasAdmin\AdminCompanyController::class);
    file_put_contents($barrier.'.ready-'.$index,'ready');
    $until=microtime(true)+20;
    while(!is_file($barrier)){if(microtime(true)>$until)throw new RuntimeException('B01 barrier timeout');usleep(500);}
    $response=$controller->suspend($request,(int)$companyId);
    if($response->status()!==200)throw new RuntimeException('B01 worker transition failed');
    echo json_encode($response->getData(true),JSON_THROW_ON_ERROR)."\n";exit(0);
}

$companies=[];$admin=null;$sub=null;$processes=[];$barrier=dirname($socket).'/b01-start-'.bin2hex(random_bytes(8));
try {
    $admin=AdminUser::create(['name'=>'Synthetic B01 native reviewer','email'=>bin2hex(random_bytes(10)).'@rc-b01.invalid','password'=>bcrypt('SyntheticOnly123!'),'role'=>'super_admin']);
    foreach(['Synthetic B01 native account','Synthetic B01 native other account'] as $name){
        $company=Company::create(['name'=>$name,'email'=>bin2hex(random_bytes(10)).'@rc-b01.invalid','ntn'=>bin2hex(random_bytes(6)),'product_type'=>'pos','status'=>'approved','company_status'=>'active']);
        $companies[]=$company->id;
    }
    $sub=Subscription::create(['company_id'=>$companies[0],'active'=>true,'start_date'=>'2025-01-01','end_date'=>'2025-12-31','final_price'=>12499,'override_type'=>'temporary','override_until'=>now()->addDays(3)]);
    $term=$sub->fresh()->getAttributes();$foreign=Company::findOrFail($companies[1])->getAttributes();
    $token=CompanyAccountStateService::token(Company::findOrFail($companies[0]));
    for($i=0;$i<6;$i++){
        $p=new Process([PHP_BINARY,'-c',php_ini_loaded_file(),__FILE__,'worker',(string)$companies[0],(string)$admin->id,$token,$barrier,(string)$i]);
        $p->setTimeout(40);$p->start();$processes[]=$p;
    }
    $until=microtime(true)+20;
    while(count(glob($barrier.'.ready-*'))!==6){if(microtime(true)>$until)throw new RuntimeException('B01 workers not ready');usleep(10000);}
    file_put_contents($barrier,'go');$changed=0;
    foreach($processes as $p){$p->wait();if(!$p->isSuccessful())throw new RuntimeException('B01 worker process failed');$data=json_decode(trim($p->getOutput()),true,512,JSON_THROW_ON_ERROR);$changed+=(int)($data['changed'] ?? false);}
    $events=AdminAuditLog::where('target_type','Company')->where('target_id',$companies[0])->get();
    if($changed!==1||$events->count()!==1||$events[0]->metadata['reason']!=='Concurrent B01 review')throw new RuntimeException('B01 concurrent state/audit duplicated');
    $company=Company::findOrFail($companies[0]);
    if($company->status!=='suspended'||$company->company_status!=='suspended'||!$company->suspended_at)throw new RuntimeException('B01 dual company state disagrees');
    $stale=CompanyAccountStateService::change($company->id,true,$admin->id,['state_token'=>$token,'reason'=>'Stale activation']);
    if(!$stale['conflict']||$stale['changed'])throw new RuntimeException('B01 stale state form was accepted');
    CompanyAccountStateService::change($company->id,true,$admin->id,['state_token'=>CompanyAccountStateService::token($company->fresh()),'reason'=>'Manual review completed']);
    if($sub->fresh()->getAttributes()!==$term||Company::findOrFail($companies[1])->getAttributes()!==$foreign)throw new RuntimeException('B01 changed subscription or second company');
    if(AdminAuditLog::where('target_type','Company')->where('target_id',$companies[0])->count()!==2)throw new RuntimeException('B01 expected two recorded transitions');
    echo "PASS: six concurrent B01 status requests create one transition and audit; stale activation refused; subscription and second company unchanged\n";
} finally {
    foreach($processes as $p)if($p->isRunning())$p->stop(1);
    if(is_file($barrier))unlink($barrier);
    foreach(glob($barrier.'.ready-*') as $ready)unlink($ready);
    if($companies){AdminAuditLog::where('target_type','Company')->whereIn('target_id',$companies)->delete();Subscription::whereIn('company_id',$companies)->delete();Company::withTrashed()->whereIn('id',$companies)->forceDelete();}
    if($admin)$admin->delete();
}

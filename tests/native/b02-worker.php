<?php
// Never bootstrap a real application environment or run without the inherited loopback guard.
$root=dirname(__DIR__,2);
if(getenv('TAXNEST_REQUIRE_B02_NATIVE')!=='1'||file_exists($root.'/.env')||!str_contains(getenv('LD_PRELOAD')?:'','guard'))exit(2);
require $root.'/vendor/autoload.php';
$app=require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Tests\Support\B02NativeDatabase::configure();
$command=json_decode($argv[1],true,512,JSON_THROW_ON_ERROR);
if(!preg_match('#^/tmp/taxnest-b02-race-[a-f0-9]+/[0-9]+\.ready$#',$argv[2]))exit(2);
$app->instance('currentCompanyId',(int)$command['company']);
file_put_contents($argv[2],'ready');
try {
    if($command['kind']==='cash') {
        $user=App\Models\User::findOrFail($command['actor']);auth('pos')->setUser($user);
        $receipt=App\Services\PosShopCashSettlement::receive($command['company'],$user,[$command['bill']],125.5,$command['key']);
        echo json_encode(['result'=>'received','id'=>$receipt->id]);
    } elseif($command['kind']==='stock') {
        $service=new App\Services\PosStockInService(new App\Services\PosStockInExcelService());
        $service->post(App\Models\PosStockInBatch::findOrFail($command['batch']),App\Models\Company::findOrFail($command['company']),1);
        echo json_encode(['result'=>'posted']);
    } else exit(2);
} catch(Illuminate\Validation\ValidationException $e) {echo json_encode(['result'=>'rejected','errors'=>array_keys($e->errors())]);}

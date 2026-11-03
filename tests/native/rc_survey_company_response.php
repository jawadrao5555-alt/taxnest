<?php

declare(strict_types=1);

$db = getenv('DB_DATABASE') ?: '';
$socket = getenv('DB_SOCKET') ?: '';
if (!preg_match('/^taxnest_rc_[a-z0-9_]+_full$/D', $db)
    || !str_starts_with($socket, '/tmp/taxnest-rc-mariadb-')
    || getenv('APP_ENV') !== 'rc-mariadb' || !getenv('LD_PRELOAD')) {
    fwrite(STDERR, "FAIL unsafe survey native target\n"); exit(2);
}
require dirname(__DIR__,2).'/vendor/autoload.php';
$app=require dirname(__DIR__,2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\{Company,User,Survey,SurveyResponse};
use Illuminate\Http\Request;
use Symfony\Component\Process\Process;

if (($argv[1] ?? '')==='worker') {
    [$surveyId,$userId,$barrier,$index]=array_slice($argv,2);
    if(!str_starts_with($barrier,dirname($socket).'/survey-start-'))throw new RuntimeException('Unsafe survey barrier');
    auth('pos')->setUser(User::findOrFail($userId));
    $request=Request::create('/pos/survey/'.$surveyId.'/respond','POST',['answers'=>['rating'=>'4','text'=>'Worker '.$index]]);
    $route=new \Illuminate\Routing\Route(['POST'],'/pos/survey/{id}/respond',fn()=>null);
    $route->bind($request);$request->setRouteResolver(fn()=>$route);$app->instance('request',$request);
    file_put_contents($barrier.'.ready-'.$index,'ready');$until=microtime(true)+20;
    while(!is_file($barrier)){if(microtime(true)>$until)throw new RuntimeException('Survey barrier timeout');usleep(500);}
    $response=app(\App\Http\Controllers\PosSurveyController::class)->respond($request,$surveyId);
    if($response->status()!==200)throw new RuntimeException('Survey submission failed');
    echo json_encode($response->getData(true),JSON_THROW_ON_ERROR);exit(0);
}

$company=null;$survey=null;$users=[];$workers=[];$barrier=dirname($socket).'/survey-start-'.bin2hex(random_bytes(8));
try {
    $company=Company::create(['name'=>'Synthetic survey race','email'=>bin2hex(random_bytes(8)).'@survey.invalid','ntn'=>bin2hex(random_bytes(6)),'product_type'=>'pos','status'=>'approved','company_status'=>'approved']);
    for($i=0;$i<6;$i++)$users[]=User::create(['name'=>'Synthetic survey user','email'=>bin2hex(random_bytes(8)).'@survey.invalid','password'=>bcrypt('SyntheticOnly123!'),'company_id'=>$company->id,'role'=>'company_admin','pos_role'=>'pos_admin','is_active'=>true]);
    $survey=Survey::create(['title'=>'Synthetic company response race','audience'=>'pos_all','is_published'=>true,'starts_at'=>now()->subMinute(),'expires_at'=>now()->addDay(),'response_policy'=>'company','targeting'=>['companies'=>[$company->id]],
        'questions'=>[['key'=>'rating','text'=>'Rating','options'=>[['key'=>'4','label'=>'4'],['key'=>'5','label'=>'5']]],['key'=>'text','text'=>'Suggestion','type'=>'text','options'=>[]]]]);
    for($i=0;$i<6;$i++){
        $p=new Process([PHP_BINARY,'-c',php_ini_loaded_file(),__FILE__,'worker',(string)$survey->id,(string)$users[$i]->id,$barrier,(string)$i]);
        $p->setTimeout(40);$p->start();$workers[]=$p;
    }
    $until=microtime(true)+20;
    while(count(glob($barrier.'.ready-*'))!==6){if(microtime(true)>$until)throw new RuntimeException('Survey workers not ready');usleep(10000);}
    file_put_contents($barrier,'go');$wins=0;
    foreach($workers as $p){$p->wait();if(!$p->isSuccessful())throw new RuntimeException('Survey worker process failed: '.$p->getErrorOutput());$j=json_decode($p->getOutput(),true,512,JSON_THROW_ON_ERROR);$wins+=(int)!($j['already']??false);}
    if($wins!==1 || SurveyResponse::where('survey_id',$survey->id)->whereNotNull('answered_at')->count()!==1)throw new RuntimeException('Company survey duplicated an answer');
    $answer=SurveyResponse::where('survey_id',$survey->id)->firstOrFail();
    if($answer->answers['rating']!=='4'||!str_starts_with($answer->answers['text'],'Worker '))throw new RuntimeException('Survey answer changed');
    echo "PASS: six concurrent actual survey controller submissions record one company answer without overwriting it\n";
} finally {
    foreach($workers as $p)if($p->isRunning())$p->stop(1);
    if(is_file($barrier))unlink($barrier);foreach(glob($barrier.'.ready-*') as $f)unlink($f);
    if($survey){SurveyResponse::where('survey_id',$survey->id)->delete();$survey->delete();}
    foreach($users as $user)$user->delete();if($company)$company->forceDelete();
}

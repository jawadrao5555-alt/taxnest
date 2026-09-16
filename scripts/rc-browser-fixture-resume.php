<?php
declare(strict_types=1);
// Rebuild only synthetic browser credentials and fixture JSON; retain all
// existing disposable MariaDB data and never migrate or reset the schema.
use App\Models\AdminUser; use App\Models\Company; use App\Models\User;
use Illuminate\Contracts\Console\Kernel; use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Hash;
require dirname(__DIR__).'/vendor/autoload.php'; $app=require dirname(__DIR__).'/bootstrap/app.php'; $app->make(Kernel::class)->bootstrap();
$fail=static function(string $m):never{fwrite(STDERR,"RC BROWSER RESUME REFUSED: {$m}\n");exit(2);};
$out=(string)getenv('RC_BROWSER_FIXTURE_OUT'); $socket=(string)getenv('DB_SOCKET');
if((string)DB::connection()->getDriverName()!=='mysql'||(string)DB::connection()->getDatabaseName()!=='taxnest_rc_browser'||!preg_match('#^/tmp/taxnest-rc-mariadb-browser-[0-9]+/run/mariadb\.sock$#',$socket)||!preg_match('#^/tmp/taxnest-rc-browser-[0-9]+/fixture\.json$#',$out))$fail('connection or fixture target outside exact isolated allowlist.');
$companyEmails=['hotel-company@rc-browser.invalid','service-company@rc-browser.invalid','fiscal-company@rc-browser.invalid','health-company@rc-browser.invalid'];
$companies=Company::whereIn('email',$companyEmails)->pluck('id','email'); if($companies->count()!==count($companyEmails))$fail('synthetic companies absent; setup required.');
$emails=['hotel-owner@rc-browser.invalid','hotel-manager@rc-browser.invalid','hotel-housekeeping@rc-browser.invalid','hotel-outlet@rc-browser.invalid','hotel-denied@rc-browser.invalid','service-work-orders@rc-browser.invalid','service-manager@rc-browser.invalid','service-denied@rc-browser.invalid','fiscal@rc-browser.invalid','health@rc-browser.invalid'];
$users=User::whereIn('email',$emails)->get()->keyBy('email'); $admin=AdminUser::where('email','hotel-admin-manage-as@rc-browser.invalid')->first();
if($users->count()!==count($emails)||!$admin)$fail('synthetic browser identities absent; setup required.');
$password='RcBrowser!'.bin2hex(random_bytes(18)); foreach($users as $u)$u->forceFill(['password'=>Hash::make($password),'is_active'=>true])->save(); $admin->forceFill(['password'=>Hash::make($password)])->save();
$u=static fn(string $email)=>$users[$email]->email; $hotel=(int)$companies['hotel-company@rc-browser.invalid'];
$fixture=['generated_at'=>now()->toIso8601String(),'synthetic'=>true,'readOnlyJourneys'=>[
['name'=>'hotel-owner','login'=>$u('hotel-owner@rc-browser.invalid'),'password'=>$password,'loginPath'=>'/pos/login','paths'=>['/pos/hotel'],'markers'=>['Front Desk']],
['name'=>'hotel-manager','login'=>$u('hotel-manager@rc-browser.invalid'),'password'=>$password,'loginPath'=>'/pos/login','paths'=>['/pos/hotel/rooms'],'markers'=>['Rooms']],
['name'=>'hotel-housekeeping','login'=>$u('hotel-housekeeping@rc-browser.invalid'),'password'=>$password,'loginPath'=>'/pos/login','paths'=>['/pos/hotel/housekeeping'],'markers'=>['Housekeeping']],
['name'=>'hotel-outlet','login'=>$u('hotel-outlet@rc-browser.invalid'),'password'=>$password,'loginPath'=>'/pos/login','paths'=>['/pos/hotel/restaurant'],'markers'=>['Current Order']],
['name'=>'hotel-admin-manage-as','login'=>$admin->email,'password'=>$password,'loginPath'=>'/admin/login','submitPath'=>"/admin/companies/$hotel",'submitSelector'=>'form[action$="/impersonate"]:has(input[name="mode"][value="full"])','paths'=>['/pos/hotel'],'markers'=>['Front Desk']],
['name'=>'service-work-orders-manager','login'=>$u('service-manager@rc-browser.invalid'),'password'=>$password,'loginPath'=>'/pos/login','paths'=>['/pos/work-orders','/pos/work-orders/report.csv'],'markers'=>['Event Plan Board','Event Plan Number']],
['name'=>'health','login'=>$u('health@rc-browser.invalid'),'password'=>$password,'loginPath'=>'/health/login','paths'=>['/health/dashboard'],'markers'=>['Synthetic Browser Health Clinic']],
['name'=>'fiscal','login'=>$u('fiscal@rc-browser.invalid'),'password'=>$password,'loginPath'=>'/fbr-pos/login','paths'=>['/fbr-pos/create'],'markers'=>['FBR']],
['name'=>'hotel-denied','login'=>$u('hotel-denied@rc-browser.invalid'),'password'=>$password,'loginPath'=>'/pos/login','path'=>'/pos/hotel','denied'=>true],
['name'=>'service-work-orders-denied','login'=>$u('service-denied@rc-browser.invalid'),'password'=>$password,'loginPath'=>'/pos/login','paths'=>['/pos/work-orders','/pos/work-orders/report.csv'],'denied'=>true],
],'transactionalJourneys'=>[['name'=>'service-work-orders','login'=>$u('service-work-orders@rc-browser.invalid'),'password'=>$password,'loginPath'=>'/pos/login','paths'=>['/pos/work-orders'],'markers'=>['Event Plan Board'],'serviceWorkflow'=>['createPath'=>'/pos/work-orders/create','customerName'=>'Synthetic Workflow Customer','title'=>'Synthetic Browser Event','scheduledAt'=>'2030-01-01T10:00','quantity'=>1,'unitPrice'=>500,'details'=>['event'=>'Synthetic event','venue'=>'Synthetic venue','guest_count'=>'10'],'transitions'=>['brief_confirmed','planned','in_progress','event_complete','closed'],'invoiceMarker'=>'Fiscal sale']]]];
file_put_contents($out,json_encode($fixture,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL,LOCK_EX)!==false||$fail('fixture write failed');chmod($out,0600);fwrite(STDOUT,"RC browser fixture credentials rotated and regenerated.\n");
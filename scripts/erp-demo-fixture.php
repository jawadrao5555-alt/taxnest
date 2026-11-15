<?php
declare(strict_types=1);
// Dedicated empty-database bootstrap. Never run against the standalone POS fixture.
$state = $argv[1] ?? '';
$socket = $argv[2] ?? '';
$fail = static function (string $message): never { fwrite(STDERR, "ERP DEMO REFUSED: $message\n"); exit(2); };
if (getenv('RC_SAFE_RUN') !== '1' || !getenv('LD_PRELOAD')
    || !preg_match('#^/tmp/taxnest-erp-demo-[0-9]+-[A-Za-z0-9]+/safe-runtime/browser-state$#', $state)
    || realpath($state) !== $state || is_link($state)
    || !preg_match('#^/tmp/taxnest-rc-mariadb-erp-demo-[0-9]+-[A-Za-z0-9]+/run/mariadb\.sock$#', $socket)
    || filetype($socket) !== 'socket' || is_link($socket)) $fail('guarded state and dedicated native socket required');
foreach (['APP_ENV'=>'local','DB_CONNECTION'=>'mysql','DB_HOST'=>'127.0.0.1','DB_DATABASE'=>'taxnest_erp_demo',
    'DB_USERNAME'=>'root','DB_PASSWORD'=>'','DB_SOCKET'=>$socket,'MAIL_MAILER'=>'array','QUEUE_CONNECTION'=>'sync',
    'SESSION_DRIVER'=>'file','ERP_DEMO_ALLOW_LOCAL'=>'1'] as $key=>$value) { putenv("$key=$value"); $_ENV[$key]=$value; $_SERVER[$key]=$value; }
preg_match('#^/tmp/taxnest-erp-demo-([0-9]+-[A-Za-z0-9]+)/#', $state, $identity);
if ($socket !== '/tmp/taxnest-rc-mariadb-erp-demo-'.$identity[1].'/run/mariadb.sock' || realpath($socket) !== $socket) $fail('paired canonical fixture identity required');
$password = 'ErpDemo!'.bin2hex(random_bytes(24));
putenv('ERP_DEMO_PASSWORD='.$password);
require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$connection=Illuminate\Support\Facades\DB::connection();
if ($connection->getDatabaseName() !== 'taxnest_erp_demo' || $connection->getConfig('unix_socket') !== $socket
    || !str_contains((string)$connection->selectOne('select version() as v')->v, 'MariaDB')) $fail('exact disposable MariaDB required');
if ($connection->select('SHOW TABLES')) $fail('database must be completely empty before migrations; no reset supported');
Illuminate\Support\Facades\Artisan::call('migrate', ['--force'=>true]);
// Legacy migrations insert sample tenants. Only this proven fresh schema may
// clear those generated rows; a nonempty input schema was refused above.
Illuminate\Support\Facades\Schema::disableForeignKeyConstraints();
foreach (['health_patients','health_doctors','users','companies'] as $table) Illuminate\Support\Facades\DB::table($table)->delete();
Illuminate\Support\Facades\Schema::enableForeignKeyConstraints();
(new Database\Seeders\ErpDemoHospitalSeeder)->run();
$roles=['owner'=>App\Services\HealthAccessService::ROLE_OWNER]+Database\Seeders\ErpDemoHospitalSeeder::DEMO_ROLES;
$company=App\Models\Company::sole();
foreach ($roles as $alias=>$role) {
    $user=App\Models\User::where('email','erp.'.$alias.'@example.test')->sole();
    if ($user->company_id !== $company->id || $user->health_role !== $role) $fail('role fixture mismatch');
}
if ($company->fbr_reporting_enabled || $company->fbr_universal_enabled || $company->fbr_pos_enabled) $fail('fiscal flags must remain off');
$fixture=['version'=>1,'fixture_kind'=>'hospital-erp-demo','socket'=>$socket,'database'=>'taxnest_erp_demo','synthetic'=>true,
    'pixel_proof_nonce'=>bin2hex(random_bytes(32)), 'password'=>$password,'roles'=>array_keys($roles),
    'readOnlyJourneys'=>array_keys($roles),'transactionalJourneys'=>[]];
$path=$state.'/fixture.json';
if (file_exists($path) || is_link($path)) $fail('existing fixture target refused');
$tmp=$path.'.tmp';$h=fopen($tmp,'x');if (!$h) $fail('private fixture create failed');chmod($tmp,0600);
fwrite($h,json_encode($fixture,JSON_THROW_ON_ERROR));fclose($h);rename($tmp,$path);
echo 'PASS: native empty MariaDB migrations, ten demo logins, linked clinician and fiscal OFF; no clinical transaction created'.PHP_EOL;

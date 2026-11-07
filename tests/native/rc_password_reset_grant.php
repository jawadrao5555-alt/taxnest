<?php
declare(strict_types=1);

$database = getenv('DB_DATABASE') ?: '';
$socket = getenv('DB_SOCKET') ?: '';
if (getenv('APP_ENV') !== 'rc-mariadb' || !getenv('LD_PRELOAD')
    || !preg_match('/^taxnest_rc_[a-z0-9_]+_full$/D', $database)
    || !preg_match('#^/tmp/taxnest-rc-mariadb-[a-z0-9_]+/run/mariadb.sock$#D', $socket)) {
    fwrite(STDERR, "FAIL unsafe password reset grant target\n");
    exit(2);
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\{Company, User};
use App\Http\Controllers\Auth\NewPasswordController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Hash};
use Symfony\Component\Process\Process;

if (($argv[1] ?? '') === 'worker') {
    [$recordId, $token, $email, $barrier, $index] = array_slice($argv, 2);
    if (!str_starts_with($barrier, dirname($socket).'/reset-grant-') || !ctype_digit($index)) {
        throw new RuntimeException('Unsafe reset grant barrier.');
    }
    $session = app('session')->driver();
    $session->put(['password_reset_record' => (int) $recordId, 'password_reset_token' => $token,
        'password_reset_email' => $email, 'password_reset_product' => 'di']);
    $request = Request::create('/reset-password', 'POST', ['email' => $email, 'token' => $token,
        'password' => 'SyntheticReset'.$index.'!', 'password_confirmation' => 'SyntheticReset'.$index.'!']);
    $request->setLaravelSession($session);
    app()->instance('request', $request);
    file_put_contents($barrier.'.ready-'.$index, 'ready');
    $deadline = microtime(true) + 20;
    while (!is_file($barrier)) {
        if (microtime(true) > $deadline) throw new RuntimeException('Reset grant barrier timed out.');
        usleep(10000);
    }
    $response = app(NewPasswordController::class)->store($request);
    echo $response->getTargetUrl() === route('login') ? "RESET_WON\n" : "RESET_REFUSED\n";
    exit(0);
}

$suffix = bin2hex(random_bytes(8));
$company = Company::create(['name' => 'Synthetic reset grant', 'ntn' => $suffix, 'product_type' => 'di']);
$other = Company::create(['name' => 'Synthetic reset other', 'ntn' => 'x'.$suffix, 'product_type' => 'di']);
$user = User::create(['name' => 'Synthetic reset owner', 'company_id' => $company->id, 'email' => $suffix.'@reset.invalid',
    'password' => bcrypt('SyntheticOriginal123!'), 'role' => 'company_admin']);
$foreign = User::create(['name' => 'Synthetic reset other owner', 'company_id' => $other->id, 'email' => 'other-'.$suffix.'@reset.invalid',
    'password' => bcrypt('SyntheticOther123!'), 'role' => 'company_admin']);
$foreignBefore = $foreign->fresh()->getAttributes();
$token = bin2hex(random_bytes(32));
$recordId = DB::table('password_reset_otps')->insertGetId(['email' => $user->email, 'otp' => '123456',
    'token' => bin2hex(random_bytes(32)), 'product_type' => 'di', 'used' => true,
    'reset_grant_hash' => hash('sha256', $token), 'expires_at' => now()->addMinutes(15), 'created_at' => now(), 'updated_at' => now()]);
$barrier = dirname($socket).'/reset-grant-'.$suffix;
$processes = [];
try {
    foreach ([1, 2] as $index) {
        $process = new Process([PHP_BINARY, '-c', php_ini_loaded_file(), __FILE__, 'worker',
            (string) $recordId, $token, $user->email, $barrier, (string) $index]);
        $process->setTimeout(40);
        $process->start();
        $processes[] = $process;
    }
    $deadline = microtime(true) + 20;
    while (count(glob($barrier.'.ready-*')) !== 2) {
        if (microtime(true) > $deadline) throw new RuntimeException('Reset workers did not reach barrier.');
        usleep(10000);
    }
    file_put_contents($barrier, 'go');
    $wins = 0;
    foreach ($processes as $process) {
        $process->wait();
        if (!$process->isSuccessful()) throw new RuntimeException('Reset grant worker failed.');
        $wins += substr_count($process->getOutput(), 'RESET_WON');
    }
    $record = DB::table('password_reset_otps')->find($recordId);
    $password = $user->fresh()->password;
    if ($wins !== 1 || !$record->reset_completed_at || $record->reset_grant_hash !== null
        || (!Hash::check('SyntheticReset1!', $password) && !Hash::check('SyntheticReset2!', $password))
        || $foreign->fresh()->getAttributes() !== $foreignBefore) {
        throw new RuntimeException('Reset grant proof failed: wins='.$wins
            .'; completed='.(int) (bool) $record->reset_completed_at
            .'; hash_cleared='.(int) ($record->reset_grant_hash === null)
            .'; password_matches='.(int) (Hash::check('SyntheticReset1!', $password) || Hash::check('SyntheticReset2!', $password))
            .'; other_account_unchanged='.(int) ($foreign->fresh()->getAttributes() === $foreignBefore));
    }
    echo "PASS: native password reset grant permits one concurrent completion and preserves other account\n";
} finally {
    foreach ($processes as $process) if ($process->isRunning()) $process->stop(1);
    if (is_file($barrier)) unlink($barrier);
    foreach (glob($barrier.'.ready-*') as $ready) unlink($ready);
}

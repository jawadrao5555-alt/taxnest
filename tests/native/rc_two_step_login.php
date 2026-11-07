<?php

declare(strict_types=1);

$database = getenv('DB_DATABASE') ?: '';
$socket = getenv('DB_SOCKET') ?: '';
if (getenv('APP_ENV') !== 'rc-mariadb' || ! getenv('LD_PRELOAD')
    || ! preg_match('/^taxnest_rc_[a-z0-9_]+_full$/D', $database)
    || ! preg_match('#^/tmp/taxnest-rc-mariadb-[a-z0-9_]+/run/mariadb.sock$#D', $socket)) {
    fwrite(STDERR, "FAIL unsafe two-step target\n");
    exit(2);
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Company;
use App\Models\User;
use App\Services\TwoStepLogin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

if (($argv[1] ?? '') === 'worker') {
    [$id,$code,$time,$barrier,$index] = array_slice($argv, 2);
    if (! str_starts_with($barrier, dirname($socket).'/two-step-') || ! ctype_digit($index)) {
        throw new RuntimeException('Unsafe barrier');
    }
    \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::createFromTimestampUTC((int) $time));
    $session = app('session')->driver();
    $request = Request::create('/security/two-step/di/verify', 'POST', ['code' => $code]);
    $request->setLaravelSession($session);
    app()->instance('request', $request);
    auth('web')->login(User::findOrFail($id));
    file_put_contents($barrier.'.ready-'.$index, 'ready');
    $deadline = microtime(true) + 20;
    while (! is_file($barrier)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Barrier timeout');
        }usleep(10000);
    }
    app(\App\Http\Controllers\Auth\TwoStepController::class)->verify($request, 'di');
    echo $session->get('two_step_verified.web') ? "FACTOR_WON\n" : "FACTOR_REFUSED\n";
    exit(0);
}
$suffix = bin2hex(random_bytes(8));
$company = Company::create(['name' => 'Synthetic two-step', 'ntn' => $suffix, 'product_type' => 'di']);
$other = Company::create(['name' => 'Other synthetic two-step', 'ntn' => 'x'.$suffix, 'product_type' => 'di']);
$user = User::create(['name' => 'Synthetic factor owner', 'company_id' => $company->id, 'email' => $suffix.'@factor.invalid',
    'password' => bcrypt('SyntheticFactor123!'), 'role' => 'company_admin', 'is_active' => true]);
$foreign = User::create(['name' => 'Other factor owner', 'company_id' => $other->id, 'email' => 'other-'.$suffix.'@factor.invalid',
    'password' => bcrypt('SyntheticOther123!'), 'role' => 'company_admin', 'is_active' => true]);
$key = TwoStepLogin::secret();
$recovery = bin2hex(random_bytes(16));
foreach ([$user, $foreign] as $actor) {
    DB::table('two_step_credentials')->insert(TwoStepLogin::identity($actor) + [
        'secret' => Crypt::encryptString($key), 'recovery_hashes' => json_encode([hash('sha256', $recovery)]),
        'created_at' => now(), 'updated_at' => now()]);
}
$before = TwoStepLogin::credential($foreign);
$foreignBefore = $foreign->fresh()->getAttributes();
$time = now()->timestamp;
foreach ([TwoStepLogin::code($key, intdiv($time, 30)), $recovery] as $round => $code) {
    $barrier = dirname($socket).'/two-step-'.$suffix.'-'.$round;
    $processes = [];
    try {
        foreach ([1, 2] as $index) {
            $process = new Process([PHP_BINARY, '-c', php_ini_loaded_file(), __FILE__, 'worker', (string) $user->id,
                $code, (string) $time, $barrier, (string) $index]);
            $process->setTimeout(40);
            $process->start();
            $processes[] = $process;
        }
        $deadline = microtime(true) + 20;
        while (count(glob($barrier.'.ready-*')) !== 2) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Workers not ready');
            }usleep(10000);
        }
        file_put_contents($barrier, 'go');
        $wins = 0;
        foreach ($processes as $process) {
            $process->wait();
            if (! $process->isSuccessful()) {
                throw new RuntimeException('Two-step worker failed: '.$process->getErrorOutput());
            }$wins += substr_count($process->getOutput(), 'FACTOR_WON');
        }
        if ($wins !== 1 || $before != TwoStepLogin::credential($foreign) || $foreignBefore !== $foreign->fresh()->getAttributes()) {
            throw new RuntimeException('Factor single-use / tenant proof failed');
        }
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
        if (is_file($barrier)) {
            unlink($barrier);
        }
        foreach (glob($barrier.'.ready-*') as $ready) {
            unlink($ready);
        }
    }
}
if (count(json_decode(TwoStepLogin::credential($user)->recovery_hashes, true)) !== 0) {
    throw new RuntimeException('Recovery code retained');
}
echo "PASS: native concurrent two-step controller permits one TOTP and one recovery use; other account unchanged\n";

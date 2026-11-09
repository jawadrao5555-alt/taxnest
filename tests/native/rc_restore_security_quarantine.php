<?php

declare(strict_types=1);

$database = getenv('DB_DATABASE') ?: '';
$socket = getenv('DB_SOCKET') ?: '';
if (getenv('APP_ENV') !== 'rc-mariadb' || ! getenv('LD_PRELOAD')
    || ! preg_match('/^taxnest_rc_[a-z0-9_]+_full$/D', $database)
    || ! preg_match('#^/tmp/taxnest-rc-mariadb-[a-z0-9_]+/run/mariadb.sock$#D', $socket)) {
    fwrite(STDERR, "FAIL unsafe restore security quarantine target\n");
    exit(2);
}

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\AdminUser;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

$suffix = bin2hex(random_bytes(8));
$company = Company::create([
    'name' => 'Synthetic restored company', 'ntn' => 'R'.$suffix, 'product_type' => 'di',
]);
$company->forceFill(['agent_api_key' => 'tnk_restore_'.$suffix, 'agent_enabled' => true])->saveQuietly();
$owner = User::create([
    'name' => 'Synthetic restored owner', 'company_id' => $company->id,
    'email' => $suffix.'@restore.invalid', 'password' => Hash::make('SyntheticRestore123!'),
    'role' => 'company_admin',
]);
$owner->forceFill(['session_generation' => '11111111-1111-4111-8111-111111111111'])->saveQuietly();
$admin = AdminUser::create([
    'name' => 'Synthetic restored admin', 'email' => 'admin-'.$suffix.'@restore.invalid',
    'password' => Hash::make('SyntheticAdmin123!'), 'role' => 'super_admin',
]);
$companyBefore = (array) DB::table('companies')->where('id', $company->id)->first();

DB::table('sessions')->insert([
    'id' => Str::random(40), 'user_id' => $owner->id, 'ip_address' => '127.0.0.1',
    'user_agent' => 'synthetic restored browser', 'payload' => 'synthetic', 'last_activity' => now()->timestamp,
]);
DB::table('password_reset_tokens')->insert([
    'email' => $owner->email, 'token' => 'synthetic', 'created_at' => now(),
]);
DB::table('password_reset_otps')->insert([
    'email' => $owner->email, 'product_type' => 'di', 'otp' => '123456',
    'token' => str_repeat('a', 64), 'expires_at' => now()->addHour(), 'used' => false,
    'reset_grant_hash' => str_repeat('b', 64), 'created_at' => now(), 'updated_at' => now(),
]);
$clientId = 'restore-'.$suffix;
$grantId = (string) Str::uuid();
DB::table('reporting_oauth_clients')->insert([
    'id' => $clientId, 'name' => 'Synthetic restored reporting client',
    'redirect_uris' => json_encode(['https://restore.invalid/callback']), 'created_at' => now(),
]);
DB::table('reporting_oauth_codes')->insert([
    'hash' => hash('sha256', 'code-'.$suffix), 'client_id' => $clientId, 'actor_id' => $admin->id,
    'redirect_uri' => 'https://restore.invalid/callback', 'challenge' => str_repeat('c', 43),
    'resource' => 'https://restore.invalid/api/reporting/mcp', 'scope' => 'reporting:read',
    'company_ids' => json_encode([$company->id]), 'expires_at' => now()->addHour(), 'created_at' => now(),
]);
DB::table('reporting_oauth_grants')->insert([
    'id' => $grantId, 'client_id' => $clientId, 'actor_id' => $admin->id,
    'resource' => 'https://restore.invalid/api/reporting/mcp', 'scope' => 'reporting:read',
    'company_ids' => json_encode([$company->id]), 'expires_at' => now()->addDay(), 'created_at' => now(),
]);
DB::table('reporting_oauth_access_tokens')->insert([
    'hash' => hash('sha256', 'access-'.$suffix), 'grant_id' => $grantId,
    'expires_at' => now()->addHour(), 'created_at' => now(),
]);
DB::table('reporting_oauth_refresh_tokens')->insert([
    'hash' => hash('sha256', 'refresh-'.$suffix), 'grant_id' => $grantId,
    'expires_at' => now()->addDay(), 'created_at' => now(),
]);
DB::table('consultant_client_links')->insert([
    'consultant_user_id' => $owner->id, 'company_id' => $company->id, 'status' => 'active',
    'initiated_by' => 'client', 'approved_by_user_id' => $owner->id, 'approved_at' => now(),
    'created_at' => now(), 'updated_at' => now(),
]);

DB::table('admin_users')->where('id', $admin->id)->update(['remember_token' => str_repeat('r', 60)]);
$restoreId = 'native-restore-'.$suffix;
if (Artisan::call('security:restore-quarantine', [
    '--restore-id' => $restoreId,
    '--confirm' => 'Quarantine restored access',
]) !== 0) {
    throw new RuntimeException('Native restore security quarantine command failed: '.Artisan::output());
}
$generation = (string) $owner->fresh()->session_generation;
$evidence = DB::table('restore_security_quarantines')->where('restore_reference_hash', hash('sha256', $restoreId))->first();
if (! Str::isUuid($generation)
    || $generation === '11111111-1111-4111-8111-111111111111'
    || DB::table('sessions')->exists()
    || DB::table('password_reset_tokens')->exists()
    || ! (bool) DB::table('password_reset_otps')->value('used')
    || DB::table('password_reset_otps')->value('reset_grant_hash') !== null
    || DB::table('reporting_oauth_grants')->value('revoked_at') === null
    || DB::table('reporting_oauth_access_tokens')->exists()
    || DB::table('reporting_oauth_refresh_tokens')->exists()
    || DB::table('consultant_client_links')->value('status') !== 'revoked'
    || ! $evidence || $evidence->status !== 'completed'
    || $companyBefore !== (array) DB::table('companies')->where('id', $company->id)->first()) {
    throw new RuntimeException('Native restore quarantine revocation or Agent/business preservation failed.');
}

if (Artisan::call('security:restore-quarantine', [
    '--restore-id' => $restoreId,
    '--confirm' => 'Quarantine restored access',
]) !== 0 || $generation !== (string) $owner->fresh()->session_generation
    || DB::table('restore_security_quarantines')->where('restore_reference_hash', hash('sha256', $restoreId))->count() !== 1) {
    throw new RuntimeException('Native restore quarantine was not idempotent.');
}

$request = \Illuminate\Http\Request::create('/admin/restore-fixture', 'GET');
$request->setLaravelSession(app('session')->driver());
app()->instance('request', $request);
auth('admin')->setUser($admin);
$boundary = app(\App\Http\Middleware\RestoreSessionBoundary::class);
$refused = $boundary->handle($request, fn () => response('UNEXPECTED'));
if ($refused->getStatusCode() !== 302 || auth('admin')->check()
    || DB::table('admin_users')->where('id', $admin->id)->value('remember_token') !== null) {
    throw new RuntimeException('Native stale external-session or remember-cookie boundary failed.');
}
auth('admin')->login($admin->fresh());
$accepted = $boundary->handle($request, fn () => response('FRESH'));
if ($accepted->getStatusCode() !== 200 || $accepted->getContent() !== 'FRESH') {
    throw new RuntimeException('Native fresh-login restore boundary failed.');
}
echo "PASS: native restore epoch refuses stale administrator sessions and remember cookies; fresh login succeeds\n";

echo "PASS: native post-restore quarantine revokes browser, reset, reporting and consultant access; company rows and Agent pairing remain exact\n";

<?php

/**
 * Seed a fictional SaaS admin + ensure the disposable Hotel QA company exists
 * (Rooms ON, restaurant_mode ON) for Manage-as-Company Chromium journeys.
 *
 * Fail-closed: VIDEO_PIPELINE_ALLOW=1 + DevStagingGuard (taxnest_dev|staging @ loopback).
 * Writes credentials to untracked .local/hotel-admin-qa-creds.env
 *
 * Usage:
 *   VIDEO_PIPELINE_ALLOW=1 php scripts/cloud-local-hotel-admin-qa-seed.php
 */

use App\Models\AdminUser;
use App\Models\Company;
use App\Models\User;
use App\Services\PosFeatureService;
use App\Support\DevStagingGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if ((string) env('VIDEO_PIPELINE_ALLOW', '') !== '1') {
    fwrite(STDERR, "Refused: set VIDEO_PIPELINE_ALLOW=1\n");
    exit(2);
}
DevStagingGuard::assertLocalStaging('cloud-local-hotel-admin-qa-seed');

// Ensure the Hotel QA company + staff exist (idempotent).
$code = 0;
passthru('VIDEO_PIPELINE_ALLOW=1 php ' . escapeshellarg(__DIR__ . '/cloud-local-hotel-qa-seed.php'), $code);
if ($code !== 0) {
    fwrite(STDERR, "hotel QA seed failed ($code)\n");
    exit($code ?: 1);
}

$adminEmail = 'hotel-admin-qa@nestpos.local';
$adminPass = getenv('HOTEL_ADMIN_QA_PASS') ?: ('HotelAdminQa' . bin2hex(random_bytes(4)) . '!');

if (!Schema::hasTable('admin_users')) {
    fwrite(STDERR, "admin_users table missing — migrate first\n");
    exit(1);
}

$admin = AdminUser::where('email', $adminEmail)->first();
if (!$admin) {
    $admin = AdminUser::create([
        'name' => 'Hotel Admin QA',
        'email' => $adminEmail,
        'password' => Hash::make($adminPass),
        'role' => 'super_admin',
    ]);
} else {
    $admin->forceFill([
        'password' => Hash::make($adminPass),
        'role' => 'super_admin',
        'name' => 'Hotel Admin QA',
    ])->save();
}

$hotel = Company::where('email', 'hotelqa@nestpos.pk')->first();
if (!$hotel) {
    fwrite(STDERR, "Hotel QA company missing after seed\n");
    exit(1);
}

// Prove restaurant_mode ON + rooms ON for Manage-as acceptance.
PosFeatureService::flushGateCaches();
$flags = PosFeatureService::forCompany($hotel);
if (empty($flags->rooms) || !(bool) $hotel->restaurant_mode) {
    $merge = PosFeatureService::defaultsForCategory('hotel');
    $merge['kitchen'] = true;
    $merge['kot'] = true;
    $merge['tables'] = true;
    $merge['rooms'] = true;
    $hotel->forceFill([
        'feature_flags' => $merge,
        'restaurant_mode' => true,
        'business_category' => 'hotel',
        'company_status' => 'active',
        'status' => 'approved',
    ])->save();
}

$owner = User::where('email', 'hotelqa@nestpos.pk')->where('company_id', $hotel->id)->first();
if (!$owner) {
    fwrite(STDERR, "Hotel QA owner missing\n");
    exit(1);
}

$dir = __DIR__ . '/../.local';
if (!is_dir($dir)) {
    mkdir($dir, 0700, true);
}
$path = $dir . '/hotel-admin-qa-creds.env';
$hotelCreds = is_file($dir . '/hotel-qa-creds.env') ? file_get_contents($dir . '/hotel-qa-creds.env') : '';
$hotelPass = '';
if (preg_match('/^HOTEL_QA_PASS=(.*)$/m', $hotelCreds, $m)) {
    $hotelPass = trim($m[1]);
}

file_put_contents($path, <<<EOF
# Local Hotel Manage-as QA credentials — NEVER commit.
HOTEL_ADMIN_QA_LOGIN={$adminEmail}
HOTEL_ADMIN_QA_PASS={$adminPass}
HOTEL_QA_COMPANY_ID={$hotel->id}
HOTEL_QA_LOGIN=hotelqa@nestpos.pk
HOTEL_QA_PASS={$hotelPass}
EOF);

echo "OK admin_id={$admin->id} email={$adminEmail} company_id={$hotel->id} restaurant_mode=" . ((int) $hotel->fresh()->restaurant_mode) . "\n";
echo "Creds: {$path}\n";
exit(0);

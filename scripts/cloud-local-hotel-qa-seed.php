<?php

/**
 * Seed a disposable Hotel / Guest House QA company on local MariaDB only.
 *
 * Fail-closed: VIDEO_PIPELINE_ALLOW=1 + DevStagingGuard (taxnest_dev|staging @ loopback).
 * Writes credentials to untracked .local/hotel-qa-creds.env
 *
 * Usage:
 *   VIDEO_PIPELINE_ALLOW=1 php scripts/cloud-local-hotel-qa-seed.php
 */

use App\Models\Company;
use App\Models\User;
use App\Services\HotelStayService;
use App\Services\PosFeatureService;
use App\Support\DevStagingGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if ((string) env('VIDEO_PIPELINE_ALLOW', '') !== '1') {
    fwrite(STDERR, "Refused: set VIDEO_PIPELINE_ALLOW=1\n");
    exit(2);
}
DevStagingGuard::assertLocalStaging('cloud-local-hotel-qa-seed');

if (!Schema::hasTable('hotel_rooms')) {
    $code = $app->make(Kernel::class)->call('migrate', ['--force' => true]);
    if ($code !== 0) {
        fwrite(STDERR, "migrate failed ($code)\n");
        exit(1);
    }
}

PosFeatureService::flushGateCaches();
PosFeatureService::assumeExtrasColumn(true);

$login = 'hotelqa@nestpos.pk';
$pass = getenv('HOTEL_QA_PASS') ?: ('HotelQa' . bin2hex(random_bytes(4)) . '!');
$hkLogin = 'hotelqa-hk@nestpos.pk';
$deniedLogin = 'hotelqa-denied@nestpos.pk';

$company = Company::where('email', $login)->first();
$flags = PosFeatureService::defaultsForCategory('hotel');
// Disposable QA hotel keeps restaurant_mode ON so Outlet separation can be smoked
// without rewriting any real customer company. Seed is fail-closed + fictional only.
$flags['kitchen'] = true;
$flags['kot'] = true;
$flags['tables'] = true;
if (!$company) {
    $company = Company::create([
        'name' => 'Hotel QA Guest House',
        'ntn' => (string) random_int(100000000, 999999999),
        'email' => $login,
        'phone' => '03001112233',
        'status' => 'approved',
        'company_status' => 'active',
        'product_type' => 'pos',
        'pos_integration_mode' => 'pra',
        'business_category' => 'hotel',
        'feature_flags' => $flags,
        'restaurant_mode' => true,
        'is_internal_account' => true,
        'pos_setup_completed' => true,
        'onboarding_completed' => true,
        'pos_tax_rate_cash' => 0,
        'pos_tax_rate_card' => 0,
        'pra_reporting_enabled' => false,
    ]);
} else {
    $company->forceFill([
        'business_category' => 'hotel',
        'feature_flags' => $flags,
        'restaurant_mode' => true,
        'is_internal_account' => true,
        'pos_setup_completed' => true,
        'pos_tax_rate_cash' => 0,
        'pos_tax_rate_card' => 0,
        'pra_reporting_enabled' => false,
    ])->save();
}

$mkUser = function (string $email, string $role, string $posRole, ?array $custom) use ($company, $pass) {
    $user = User::where('email', $email)->first();
    if (!$user) {
        $user = User::create([
            'name' => 'Hotel QA ' . $posRole,
            'email' => $email,
            'password' => Hash::make($pass),
            'company_id' => $company->id,
            'role' => $role,
            'pos_role' => $posRole,
            'is_active' => true,
        ]);
    } else {
        $user->forceFill([
            'password' => Hash::make($pass),
            'company_id' => $company->id,
            'role' => $role,
            'pos_role' => $posRole,
            'is_active' => true,
        ])->save();
    }
    if ($custom !== null) {
        $user->forceFill(['pos_custom_access' => json_encode($custom)])->save();
    } else {
        $user->forceFill(['pos_custom_access' => null])->save();
    }

    return $user->fresh();
};

$owner = $mkUser($login, 'company_admin', 'pos_admin', null);
$hk = $mkUser($hkLogin, 'staff', 'pos_cashier', ['dashboard', 'hotel_housekeeping']);
$denied = $mkUser($deniedLogin, 'staff', 'pos_cashier', ['dashboard']);
$restoLogin = 'hotelqa-resto@nestpos.pk';
$resto = $mkUser($restoLogin, 'staff', 'pos_cashier', ['dashboard', 'orders']);

// Pre-mark What's New so interactive smoke is not blocked by elaan overlays.
if (Schema::hasTable('app_updates') && Schema::hasTable('app_update_seens')) {
    $updateIds = DB::table('app_updates')->where('is_published', 1)->pluck('id');
    foreach ([$owner, $hk, $denied, $resto] as $u) {
        foreach ($updateIds as $uid) {
            $exists = DB::table('app_update_seens')
                ->where('user_id', $u->id)
                ->where('app_update_id', $uid)
                ->exists();
            if (!$exists) {
                DB::table('app_update_seens')->insert([
                    'app_update_id' => $uid,
                    'user_id' => $u->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}

$stays = app(HotelStayService::class);
$existing = \App\Models\HotelRoom::where('company_id', $company->id)->count();
if ($existing < 3) {
    foreach ([
        ['101', 'Deluxe', 4500],
        ['102', 'Standard', 3000],
        ['103', 'Standard', 2800],
    ] as [$num, $type, $rate]) {
        if (!\App\Models\HotelRoom::where('company_id', $company->id)->where('room_number', $num)->exists()) {
            $room = $stays->createRoom((int) $company->id, [
                'room_number' => $num,
                'room_type' => $type,
                'capacity' => 2,
                'rate_amount' => $rate,
                'rate_unit' => 'NGT',
            ]);
            if ($num === '102') {
                $stays->setHousekeeping($room, 'dirty');
            }
        }
    }
}

if (Schema::hasTable('pos_products')
    && !\App\Models\PosProduct::where('company_id', $company->id)->where('name', 'QA Minibar Water')->exists()) {
    \App\Models\PosProduct::create([
        'company_id' => $company->id,
        'name' => 'QA Minibar Water',
        'price' => 120,
        'uom' => 'NOS',
        'is_active' => true,
        'show_on_sale' => true,
    ]);
}

$dir = __DIR__ . '/../.local';
if (!is_dir($dir)) {
    mkdir($dir, 0700, true);
}
$creds = $dir . '/hotel-qa-creds.env';
file_put_contents($creds, <<<EOF
# Local Hotel QA credentials — NEVER commit.
HOTEL_QA_LOGIN={$login}
HOTEL_QA_PASS={$pass}
HOTEL_QA_HK_LOGIN={$hkLogin}
HOTEL_QA_DENIED_LOGIN={$deniedLogin}
HOTEL_QA_RESTO_LOGIN={$restoLogin}
CLOUD_LOCAL_QA_LOGIN={$login}
CLOUD_LOCAL_QA_PASSWORD={$pass}
VIDEO_DEMO_LOGIN={$login}
VIDEO_DEMO_PASS={$pass}
EOF);

echo "OK hotel QA company_id={$company->id} owner={$login} hk={$hkLogin} denied={$deniedLogin} resto={$restoLogin} restaurant_mode=1\n";
echo "Creds: {$creds}\n";
exit(0);

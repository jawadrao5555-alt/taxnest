<?php
/**
 * One-time bootstrap: create "Restaurant Features" What's New announcement (Aug 2026)
 * Run on live:  /usr/local/bin/ea-php84 /home/taxnestc/public_html/scripts/announce_restaurant_features_aug2026.php
 */

define('LARAVEL_START', microtime(true));

require '/home/taxnestc/public_html/vendor/autoload.php';

$app = require_once '/home/taxnestc/public_html/bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();

use App\Models\AppUpdate;

// Guard: skip if already created (idempotent)
$exists = AppUpdate::where('title', 'like', '%Restaurant Features%')
    ->where('audience', 'pos')
    ->whereDate('created_at', '>=', '2026-08-01')
    ->exists();

if ($exists) {
    echo "SKIP: announcement already exists.\n";
    exit(0);
}

$update = AppUpdate::create([
    'title'        => 'Restaurant Features: 5 Naye Improvements!',
    'points'       => [
        'Provisional Bills mein ab search karein — customer ka naam, phone number ya bill number likhein aur bill foran mil jaye ga.',
        'Rush order ab KOT par bara sa "URGENT" saaf kaali chhapai mein aata hai — kitchen ko foran pata chale ga.',
        'Kitchen/bill notes ab multi-line — Enter dabayen tou nayi line banti hai, aur KOT par notes number-war (1, 2, 3...) chhapte hain.',
        'Make Final par ab "Receipt print na karein" ka option — jab receipt ki zaroorat na ho tou print skip karein.',
        'Delivery orders mein ab "Payment First, Then KOT" — pehle payment lein, phir KOT kitchen jaye.',
    ],
    'image_path'   => null,
    'audience'     => 'pos',
    'is_published' => true,
    'created_by'   => null,
]);

echo "OK: AppUpdate created with id=" . $update->id . "\n";
exit(0);

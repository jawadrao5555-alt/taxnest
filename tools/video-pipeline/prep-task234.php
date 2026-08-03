<?php
// Task 234 demo-data prep (dev only): team members + hazri sessions + PRA demo
// settings for the Al-Noor video demo shop. Idempotent. Run with the usual
// PG env-strip prefix: env -u DATABASE_URL ... php tools/video-pipeline/prep-task234.php
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Crypt;

$cid = DB::table('companies')->where('email', 'videodemo@nestpos.pk')->value('id');
if (!$cid) { fwrite(STDERR, "demo company missing — run VideoDemoShopSeeder first\n"); exit(1); }

// PRA demo settings (masked/demo values only — NEVER real credentials).
DB::table('companies')->where('id', $cid)->update([
    'pra_environment' => 'sandbox',
    'pra_pos_id' => '100519',            // demo-looking POS id
    'pra_connection_mode' => 'cloud',
]);

// Pre-existing team members (video creates a NEW cashier live on-screen).
$members = [
    ['Farhan Ali', 'farhan@alnoor-demo.pk', 'pos_manager', 'Demo@1234'],
    ['Ahmed Raza', 'ahmed@alnoor-demo.pk', 'pos_cashier', 'Demo@1234'],
];
$ids = [];
foreach ($members as [$name, $email, $role, $pw]) {
    $data = [
        'name' => $name, 'company_id' => $cid, 'role' => 'user', 'pos_role' => $role,
        'is_active' => 1, 'password' => Hash::make($pw), 'updated_at' => now(),
    ];
    if (Schema::hasColumn('users', 'pos_team_password_enc')) {
        $data['pos_team_password_enc'] = Crypt::encryptString($pw);
    }
    $u = DB::table('users')->where('email', $email)->first();
    if ($u) { DB::table('users')->where('id', $u->id)->update($data); $ids[$name] = $u->id; }
    else { $ids[$name] = DB::table('users')->insertGetId($data + ['email' => $email, 'created_at' => now()]); }
}
$adminId = DB::table('users')->where('email', 'videodemo@nestpos.pk')->value('id');

// Hazri sessions for TODAY (so the report has real rows).
DB::table('pos_user_sessions')->where('company_id', $cid)->delete();
// Business day = 6AM→6AM window; anchor sessions to NOW (clamped inside the
// window) so this works whatever wall-clock time the recording happens.
// IMPORTANT: re-run this script right before recording.
$bd = \App\Services\PosBusinessDay::current($cid);
$start = \Carbon\Carbon::parse($bd, config('app.timezone'))->setTime(6, 0);
$base = now()->subHours(4);
if ($base->lt($start)) { $base = $start->copy()->addMinutes(15); }
$rows = [
    [$adminId,           $base->copy(),                  null,                             now()],
    [$ids['Farhan Ali'], $base->copy()->addMinutes(12),  null,                             now()->subMinutes(3)],
    [$ids['Ahmed Raza'], $base->copy()->addMinutes(45),  $base->copy()->addHours(2),       $base->copy()->addHours(2)],
    [$ids['Ahmed Raza'], $base->copy()->addHours(3),     null,                             now()->subMinutes(2)],
];
foreach ($rows as [$uid, $in, $out, $seen]) {
    DB::table('pos_user_sessions')->insert([
        'company_id' => $cid, 'user_id' => $uid, 'login_at' => $in,
        'logout_at' => $out, 'last_activity_at' => $seen, 'ip' => '10.0.0.5',
        'created_at' => $in, 'updated_at' => now(),
    ]);
}
echo "prep done: company=$cid admin=$adminId team=" . json_encode($ids) . "\n";

<?php

/**
 * Fictional, disposable Category Lab tenant. Refuses every non-local database.
 * Credentials are test constants and never overlap a customer/live account.
 */

use App\Models\Company;
use App\Models\PosService;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PosFeatureService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$connection = DB::connection();
$driver = $connection->getDriverName();
$database = (string) $connection->getDatabaseName();
$host = (string) config('database.connections.'.config('database.default').'.host', '');
$safeSqlite = $driver === 'sqlite' && str_contains($database, '.local/category-lab.sqlite');
$safeMysql = in_array($driver, ['mysql', 'mariadb'], true)
    && in_array($host, ['127.0.0.1', 'localhost'], true)
    && in_array($database, ['taxnest_dev', 'taxnest_staging', 'taxnest_lab', 'taxnest_category_lab'], true);
if (! $safeSqlite && ! $safeMysql) {
    fwrite(STDERR, "CATEGORY QA SEED REFUSED: database is not the disposable local lab.\n");
    exit(2);
}

$company = Company::withTrashed()->where('email', 'owner@category-lab.invalid')->first();
if (! $company) {
    $company = Company::create([
        'name' => 'Fictional Aurora Salon Lab',
        'owner_name' => 'Test Owner',
        'ntn' => '0000000-0',
        'email' => 'owner@category-lab.invalid',
        'product_type' => 'pos',
        'business_category' => 'salon',
        'pos_type' => 'salon',
        'company_status' => 'active',
        'status' => 'active',
        'pos_setup_completed' => true,
        'pra_reporting_enabled' => false,
        'feature_flags' => PosFeatureService::defaultsForCategory('salon'),
    ]);
}
if ($company->trashed()) {
    $company->restore();
}

$user = User::updateOrCreate(
    ['email' => 'owner@category-lab.invalid', 'product_type' => 'pos'],
    [
        'name' => 'Category Lab Owner', 'password' => bcrypt('CategoryLab!2026'),
        'company_id' => $company->id, 'role' => 'company_admin', 'pos_role' => 'pos_admin',
        'is_active' => true,
    ]
);

// Lab smoke must create work orders. Without an active paid/trial-open
// subscription the POS locks into view-only and Create Appointment never lands.
if (Schema::hasTable('subscriptions') && Schema::hasTable('pricing_plans')) {
    $planId = (int) (DB::table('pricing_plans')
        ->where('product_type', 'pos')
        ->where('name', 'Unlimited')
        ->value('id')
        ?: DB::table('pricing_plans')->where('product_type', 'pos')->where('is_trial', false)->value('id'));
    if ($planId > 0) {
        Subscription::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $company->id],
            [
                'pricing_plan_id' => $planId,
                'billing_cycle' => 'yearly',
                'discount_percent' => 0,
                'final_price' => 0,
                'start_date' => now()->toDateString(),
                'end_date' => now()->addYear()->toDateString(),
                'trial_ends_at' => null,
                'active' => true,
                'override_type' => 'none',
                'override_until' => null,
            ]
        );
    }
}

foreach ([['Haircut', 1200], ['Facial', 2500], ['Manicure', 1800]] as [$name, $price]) {
    PosService::updateOrCreate(['company_id' => $company->id, 'name' => $name], [
        'price' => $price, 'tax_rate' => 16, 'is_active' => true, 'is_tax_exempt' => false,
    ]);
}

// Pre-mark What's New so interactive smoke is not blocked by elaan overlays.
if (Schema::hasTable('app_updates') && Schema::hasTable('app_update_seens')) {
    $updateIds = DB::table('app_updates')->where('is_published', 1)->pluck('id');
    foreach ($updateIds as $uid) {
        $exists = DB::table('app_update_seens')
            ->where('user_id', $user->id)
            ->where('app_update_id', $uid)
            ->exists();
        if (! $exists) {
            DB::table('app_update_seens')->insert([
                'app_update_id' => $uid,
                'user_id' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}

$dir = dirname(__DIR__).'/.local';
if (! is_dir($dir)) {
    mkdir($dir, 0700, true);
}
file_put_contents($dir.'/category-qa-creds.env', <<<'EOF'
# Fictional local category-lab credentials — NEVER commit.
CATEGORY_QA_LOGIN=owner@category-lab.invalid
CATEGORY_QA_PASS=CategoryLab!2026
EOF);

echo "CATEGORY QA SEED OK company={$company->id} user={$user->id} login=owner@category-lab.invalid\n";

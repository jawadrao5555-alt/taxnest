<?php

namespace Tests\Feature;

use App\Models\PricingPlan;
use App\Services\PosPlanComparisonService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PosThreePackagePricingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();

        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type');
            $table->decimal('price', 12, 2);
            $table->decimal('price_monthly', 12, 2)->nullable();
            $table->boolean('is_trial')->default(false);
            $table->integer('user_limit')->default(2);
            $table->integer('branch_limit')->default(1);
            $table->boolean('rider_tracking_enabled')->default(false);
            $table->boolean('caller_id_enabled')->default(false);
            $table->boolean('whatsapp_enabled')->default(false);
            $table->timestamps();
        });
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('pricing_plan_id');
            $table->decimal('final_price', 12, 2);
            $table->date('end_date');
        });
        Schema::create('payment_proofs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pricing_plan_id');
            $table->string('status');
            $table->decimal('amount', 12, 2);
        });
    }

    public function test_new_annual_rates_and_unlimited_tracking_preserve_existing_contracts(): void
    {
        $starter = $this->plan('Starter', 'pos', 17999, 2, 1);
        $business = $this->plan('Business', 'pos', 27999, 7, 1);
        $unlimited = $this->plan('Unlimited', 'pos', 34999, 12, 2, true);
        $legacy = $this->plan('Pro', 'pos', 29999, 20, 3);
        $fbr = $this->plan('Business', 'fbrpos', 27999, 5, 2);
        $trial = $this->plan('Trial', 'pos', 0, 2, 1, false, true);

        DB::table('subscriptions')->insert([
            'company_id' => 41, 'pricing_plan_id' => $business,
            'final_price' => 27999, 'end_date' => '2027-06-01',
        ]);
        DB::table('payment_proofs')->insert([
            'pricing_plan_id' => $unlimited, 'status' => 'verified', 'amount' => 34999,
        ]);

        $this->migrate();
        $this->migrate(); // a deploy retry must not touch contracts or drift

        foreach ([$starter => [19999, 2, 1, 0], $business => [29999, 7, 1, 0], $unlimited => [37999, 12, 2, 1]] as $id => [$price, $seats, $branches, $tracking]) {
            $plan = DB::table('pricing_plans')->find($id);
            $this->assertSame($price, (int) $plan->price);
            $this->assertSame($seats, (int) $plan->user_limit);
            $this->assertSame($branches, (int) $plan->branch_limit);
            $this->assertSame($tracking, (int) $plan->rider_tracking_enabled);
            $this->assertSame(1649, (int) $plan->price_monthly, 'retired billing-cycle columns are historical');
        }
        $this->assertSame(29999, (int) DB::table('pricing_plans')->find($legacy)->price);
        $this->assertSame(27999, (int) DB::table('pricing_plans')->find($fbr)->price);
        $this->assertSame(0, (int) DB::table('pricing_plans')->find($trial)->price);
        $this->assertSame(27999, (int) DB::table('subscriptions')->first()->final_price);
        $this->assertSame('2027-06-01', DB::table('subscriptions')->first()->end_date);
        $this->assertSame(34999, (int) DB::table('payment_proofs')->first()->amount);
    }

    public function test_comparison_exposes_tracking_as_business_addon_and_unlimited_included(): void
    {
        $plans = collect([
            $this->planModel('Starter', false),
            $this->planModel('Business', false),
            $this->planModel('Unlimited', true),
        ]);
        $features = collect(PosPlanComparisonService::sections($plans))->firstWhere('key', 'features')['rows'];
        $tracking = collect($features)->firstWhere('key', 'rider_tracking');

        $this->assertSame([false, false, true], $tracking['values']);
        $this->assertSame([false, true, true], $tracking['addon_values']);
        $this->assertSame([], PosPlanComparisonService::auditNames());
    }

    private function plan(string $name, string $type, int $price, int $seats, int $branches, bool $caller = false, bool $trial = false): int
    {
        return DB::table('pricing_plans')->insertGetId([
            'name' => $name, 'product_type' => $type, 'price' => $price,
            'price_monthly' => 1649, 'is_trial' => $trial,
            'user_limit' => $seats, 'branch_limit' => $branches,
            'caller_id_enabled' => $caller, 'rider_tracking_enabled' => false,
            'whatsapp_enabled' => $name !== 'Starter',
        ]);
    }

    private function planModel(string $name, bool $tracking): PricingPlan
    {
        $plan = new PricingPlan();
        $plan->forceFill([
            'name' => $name, 'product_type' => 'pos', 'is_trial' => false,
            'rider_tracking_enabled' => $tracking,
        ]);

        return $plan;
    }

    private function migrate(): void
    {
        $migration = require database_path('migrations/2026_09_29_160000_pos_three_package_prices_and_tracking.php');
        $migration->up();
    }
}

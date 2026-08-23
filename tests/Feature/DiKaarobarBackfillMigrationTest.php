<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Owner rule (Sep 2026): no Digital Invoice shop may be left on a package that
 * is not sold any more — they all belong on Kaarobar. What the backfill must
 * NOT do matters just as much: a free Trial stays a Trial, a shop already on a
 * current package is not rewritten, and admin arrangements survive.
 */
class DiKaarobarBackfillMigrationTest extends TestCase
{
    private int $asaan;
    private int $kaarobar;
    private int $unlimited;
    private int $premium;
    private int $trial;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();

        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->default('di');
            $table->boolean('is_trial')->default(false);
            $table->boolean('is_public')->default(true);
        });
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->nullable();
            $table->integer('invoice_limit_override')->nullable();
            $table->timestamps();
        });
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('pricing_plan_id')->nullable();
            $table->boolean('active')->default(true);
            $table->date('end_date')->nullable();
            $table->timestamps();
        });

        $this->asaan     = $this->plan('Asaan');
        $this->kaarobar  = $this->plan('Kaarobar');
        $this->unlimited = $this->plan('Unlimited');
        $this->premium   = $this->plan('Premium', ['is_public' => false]);
        $this->trial     = $this->plan('Trial', ['is_public' => false, 'is_trial' => true]);
    }

    public function test_a_shop_on_a_retired_package_lands_on_kaarobar(): void
    {
        $sub = $this->shopOn($this->premium);

        $this->runMigration();

        $this->assertSame($this->kaarobar, (int) DB::table('subscriptions')->where('id', $sub)->value('pricing_plan_id'));
    }

    public function test_an_active_subscription_with_no_package_at_all_lands_on_kaarobar(): void
    {
        $sub = $this->shopOn(null);

        $this->runMigration();

        $this->assertSame($this->kaarobar, (int) DB::table('subscriptions')->where('id', $sub)->value('pricing_plan_id'));
    }

    public function test_a_company_with_no_product_type_still_counts_as_digital_invoice(): void
    {
        $sub = $this->shopOn($this->premium, ['product_type' => null]);

        $this->runMigration();

        $this->assertSame($this->kaarobar, (int) DB::table('subscriptions')->where('id', $sub)->value('pricing_plan_id'));
    }

    public function test_a_free_trial_is_never_converted_into_a_paid_package(): void
    {
        $sub = $this->shopOn($this->trial);

        $this->runMigration();

        $this->assertSame($this->trial, (int) DB::table('subscriptions')->where('id', $sub)->value('pricing_plan_id'));
    }

    public function test_shops_already_on_a_current_package_are_left_where_they_are(): void
    {
        $onAsaan     = $this->shopOn($this->asaan);
        $onKaarobar  = $this->shopOn($this->kaarobar);
        $onUnlimited = $this->shopOn($this->unlimited);

        $this->runMigration();

        $this->assertSame($this->asaan, (int) DB::table('subscriptions')->where('id', $onAsaan)->value('pricing_plan_id'));
        $this->assertSame($this->kaarobar, (int) DB::table('subscriptions')->where('id', $onKaarobar)->value('pricing_plan_id'));
        $this->assertSame($this->unlimited, (int) DB::table('subscriptions')->where('id', $onUnlimited)->value('pricing_plan_id'));
    }

    public function test_an_old_inactive_subscription_row_is_left_as_history(): void
    {
        $sub = $this->shopOn($this->premium, [], ['active' => false]);

        $this->runMigration();

        $this->assertSame($this->premium, (int) DB::table('subscriptions')->where('id', $sub)->value('pricing_plan_id'));
    }

    public function test_a_pos_shop_is_not_dragged_onto_a_digital_invoice_package(): void
    {
        $posPlan = $this->plan('POS Business', ['product_type' => 'pos', 'is_public' => false]);
        $sub = $this->shopOn($posPlan, ['product_type' => 'pos']);

        $this->runMigration();

        $this->assertSame($posPlan, (int) DB::table('subscriptions')->where('id', $sub)->value('pricing_plan_id'));
    }

    public function test_an_admin_limit_arrangement_survives_the_move(): void
    {
        $sub = $this->shopOn($this->premium, ['invoice_limit_override' => 200000]);
        $companyId = (int) DB::table('subscriptions')->where('id', $sub)->value('company_id');

        $this->runMigration();

        $this->assertSame($this->kaarobar, (int) DB::table('subscriptions')->where('id', $sub)->value('pricing_plan_id'));
        $this->assertSame(200000, (int) DB::table('companies')->where('id', $companyId)->value('invoice_limit_override'));
    }

    public function test_the_shop_keeps_its_own_expiry_date(): void
    {
        $sub = $this->shopOn($this->premium, [], ['end_date' => '2026-09-19']);

        $this->runMigration();

        $row = DB::table('subscriptions')->where('id', $sub)->first();
        $this->assertSame($this->kaarobar, (int) $row->pricing_plan_id);
        $this->assertStringStartsWith('2026-09-19', (string) $row->end_date);
    }

    public function test_running_it_twice_changes_nothing_the_second_time(): void
    {
        $sub = $this->shopOn($this->premium);

        $this->runMigration();
        $first = DB::table('subscriptions')->where('id', $sub)->value('updated_at');

        $this->runMigration();

        $this->assertSame($this->kaarobar, (int) DB::table('subscriptions')->where('id', $sub)->value('pricing_plan_id'));
        $this->assertSame($first, DB::table('subscriptions')->where('id', $sub)->value('updated_at'));
    }

    public function test_it_does_nothing_on_a_database_that_has_no_kaarobar_yet(): void
    {
        DB::table('pricing_plans')->where('id', $this->kaarobar)->delete();
        $sub = $this->shopOn($this->premium);

        $this->runMigration();

        $this->assertSame($this->premium, (int) DB::table('subscriptions')->where('id', $sub)->value('pricing_plan_id'));
    }

    private function plan(string $name, array $attrs = []): int
    {
        return (int) DB::table('pricing_plans')->insertGetId(array_merge([
            'name' => $name,
            'product_type' => 'di',
            'is_trial' => false,
            'is_public' => true,
        ], $attrs));
    }

    private function shopOn(?int $planId, array $companyAttrs = [], array $subAttrs = []): int
    {
        $companyId = DB::table('companies')->insertGetId(array_merge([
            'name' => 'Shop ' . uniqid(),
            'product_type' => 'di',
            'created_at' => now(),
            'updated_at' => now(),
        ], $companyAttrs));

        return (int) DB::table('subscriptions')->insertGetId(array_merge([
            'company_id' => $companyId,
            'pricing_plan_id' => $planId,
            'active' => true,
            'end_date' => now()->addMonth()->toDateString(),
            'created_at' => now()->subMonths(2),
            'updated_at' => now()->subMonths(2),
        ], $subAttrs));
    }

    private function runMigration(): void
    {
        $migration = require database_path('migrations/2026_09_16_100000_move_remaining_di_shops_to_kaarobar.php');
        $migration->up();
    }
}

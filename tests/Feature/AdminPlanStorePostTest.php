<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\AdminUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * SECURITY LOCK — AdminPlanController@store / @update POST paths.
 *
 * These tests lock two security-relevant behaviours of the plan create/edit
 * forms so a future refactor can never silently regress them:
 *
 * 1. FIELD-LIST PROTECTION — the controller writes an explicit field list,
 *    never the whole request. `is_trial` IS in PricingPlan::$fillable, so a
 *    naive `create($request->all())` would let a crafted POST flip it; the
 *    explicit list must keep injected `is_trial` (and other stray fields)
 *    ignored on both store and update.
 *
 * 2. PRICE_MONTHLY MIRRORING — di/fbrpos plans store MONTHLY prices, so
 *    price_monthly must mirror price; pos plans store ANNUAL prices, so
 *    price_monthly must be NULL (and be reset to NULL when a plan is
 *    switched to pos on update).
 */
class AdminPlanStorePostTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role')->default('super_admin');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('admin_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->string('action');
            $table->string('target_type')->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->text('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
        });

        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->default('di');
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('price_monthly', 12, 2)->nullable();
            $table->decimal('compare_at', 12, 2)->nullable();
            $table->integer('invoice_limit')->default(0);
            $table->integer('user_limit')->nullable();
            $table->integer('max_terminals')->nullable();
            $table->integer('max_users')->nullable();
            $table->integer('max_products')->nullable();
            $table->boolean('inventory_enabled')->default(false);
            $table->boolean('reports_enabled')->default(false);
            $table->boolean('is_trial')->default(false);
            $table->text('features')->nullable();
            $table->timestamps();
        });

        DB::table('admin_users')->insert([
            'name' => 'Post Admin',
            'email' => 'post-admin@taxnest.test',
            'password' => Hash::make('Post@12345'),
            'role' => 'super_admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function actingAsAdmin(): self
    {
        return $this->actingAs(AdminUser::first(), 'admin');
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Test Plan',
            'price' => 2500,
            'invoice_limit' => 100,
            'product_type' => 'di',
        ], $overrides);
    }

    private function seedPlan(array $overrides = []): int
    {
        return DB::table('pricing_plans')->insertGetId(array_merge([
            'name' => 'Existing Plan',
            'product_type' => 'di',
            'price' => 1000,
            'price_monthly' => 1000,
            'invoice_limit' => 50,
            'is_trial' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    // ── store ────────────────────────────────────────────────────────────

    /** Injected is_trial (and other stray fillable fields) must be ignored on create. */
    public function test_store_ignores_injected_is_trial(): void
    {
        $response = $this->actingAsAdmin()->from('/admin/plans')->post('/admin/plans', $this->validPayload([
            'is_trial' => 1,
            'compare_at' => 999999, // another fillable column not on the form
        ]));

        $response->assertRedirect('/admin/plans');
        $response->assertSessionHas('success');

        $plan = DB::table('pricing_plans')->where('name', 'Test Plan')->first();
        $this->assertNotNull($plan);
        $this->assertEquals(0, (int) $plan->is_trial, 'is_trial must not be injectable via POST');
        $this->assertNull($plan->compare_at, 'compare_at must not be injectable via POST');
    }

    /** di plans store monthly prices — price_monthly mirrors price. */
    public function test_store_mirrors_price_monthly_for_di(): void
    {
        $this->actingAsAdmin()->post('/admin/plans', $this->validPayload([
            'name' => 'DI Plan', 'product_type' => 'di', 'price' => 1500,
        ]))->assertSessionHasNoErrors();

        $plan = DB::table('pricing_plans')->where('name', 'DI Plan')->first();
        $this->assertEquals(1500.0, (float) $plan->price_monthly);
    }

    /** fbrpos plans store monthly prices — price_monthly mirrors price. */
    public function test_store_mirrors_price_monthly_for_fbrpos(): void
    {
        $this->actingAsAdmin()->post('/admin/plans', $this->validPayload([
            'name' => 'FBR Plan', 'product_type' => 'fbrpos', 'price' => 3200,
        ]))->assertSessionHasNoErrors();

        $plan = DB::table('pricing_plans')->where('name', 'FBR Plan')->first();
        $this->assertEquals(3200.0, (float) $plan->price_monthly);
    }

    /** pos plans store ANNUAL prices — price_monthly must stay NULL. */
    public function test_store_leaves_price_monthly_null_for_pos(): void
    {
        $this->actingAsAdmin()->post('/admin/plans', $this->validPayload([
            'name' => 'POS Plan', 'product_type' => 'pos', 'price' => 12000,
        ]))->assertSessionHasNoErrors();

        $plan = DB::table('pricing_plans')->where('name', 'POS Plan')->first();
        $this->assertNull($plan->price_monthly);
    }

    /** Invalid product_type must be rejected, not saved. */
    public function test_store_rejects_invalid_product_type(): void
    {
        $this->actingAsAdmin()->from('/admin/plans')->post('/admin/plans', $this->validPayload([
            'product_type' => 'hacked',
        ]))->assertSessionHasErrors('product_type');

        $this->assertSame(0, DB::table('pricing_plans')->count());
    }

    // ── update ───────────────────────────────────────────────────────────

    /** Injected is_trial must be ignored on update — including trying to CLEAR a trial flag. */
    public function test_update_ignores_injected_is_trial(): void
    {
        $id = $this->seedPlan(['is_trial' => true]);

        $this->actingAsAdmin()->put("/admin/plans/{$id}", $this->validPayload([
            'name' => 'Renamed Plan',
            'is_trial' => 0, // attacker tries to strip trial flag
        ]))->assertSessionHasNoErrors();

        $plan = DB::table('pricing_plans')->find($id);
        $this->assertSame('Renamed Plan', $plan->name);
        $this->assertEquals(1, (int) $plan->is_trial, 'is_trial must not be clearable via POST');
    }

    /** Update mirrors price_monthly for di/fbrpos. */
    public function test_update_mirrors_price_monthly_for_di(): void
    {
        $id = $this->seedPlan(['price' => 1000, 'price_monthly' => 1000]);

        $this->actingAsAdmin()->put("/admin/plans/{$id}", $this->validPayload([
            'product_type' => 'di', 'price' => 4500,
        ]))->assertSessionHasNoErrors();

        $plan = DB::table('pricing_plans')->find($id);
        $this->assertEquals(4500.0, (float) $plan->price_monthly);
    }

    /** Switching a plan to pos on update must RESET price_monthly to NULL. */
    public function test_update_resets_price_monthly_when_switched_to_pos(): void
    {
        $id = $this->seedPlan(['product_type' => 'di', 'price_monthly' => 1000]);

        $this->actingAsAdmin()->put("/admin/plans/{$id}", $this->validPayload([
            'product_type' => 'pos', 'price' => 15000,
        ]))->assertSessionHasNoErrors();

        $plan = DB::table('pricing_plans')->find($id);
        $this->assertNull($plan->price_monthly);
    }

    /** Guests can never hit the write paths. */
    public function test_guests_cannot_post_plans(): void
    {
        $id = $this->seedPlan();

        $this->post('/admin/plans', $this->validPayload())->assertRedirect('/admin/login');
        $this->put("/admin/plans/{$id}", $this->validPayload())->assertRedirect('/admin/login');

        $this->assertSame(1, DB::table('pricing_plans')->count());
        $this->assertSame('Existing Plan', DB::table('pricing_plans')->find($id)->name);
    }
}

<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ADMIN PLAN EDITOR — LADDER GUARD (Task 1455).
 *
 * The plan editor used to write price / limits / feature switches straight to
 * pricing_plans with no ladder check. An admin could cap products on a costlier
 * package, switch Reports off on a higher plan, or reprice so the ladder
 * reorders — and the only visible effect was the public card quietly dropping
 * its "Everything in <previous>, plus:" line. The deploy gate caught it weeks
 * later, if at all.
 *
 * Locked here:
 *   1. A save that would tighten a limit going UP the ladder is refused.
 *   2. A save that would switch a feature OFF on a costlier package is refused.
 *   3. A new half-configured package dropped below an existing one is refused.
 *   4. "Save anyway" still gets through — and the override is recorded.
 *   5. A harmless edit is never blocked.
 *   6. A ladder that is ALREADY broken does not block an unrelated save
 *      (the guard reports only what the save would ADD) but IS shown as a
 *      banner on the plans page, so an old break is not invisible either.
 */
class AdminPlanLadderGuardTest extends TestCase
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
            $table->unsignedBigInteger('admin_user_id')->nullable();
            $table->string('action');
            $table->string('target_type')->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('entity_type')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->text('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
        });

        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->default('pos');
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('price_monthly', 12, 2)->nullable();
            $table->decimal('price_quarterly', 12, 2)->nullable();
            $table->decimal('compare_at', 12, 2)->nullable();
            $table->integer('invoice_limit')->default(0);
            $table->integer('user_limit')->nullable();
            $table->integer('branch_limit')->nullable();
            $table->integer('max_terminals')->nullable();
            $table->integer('max_users')->nullable();
            $table->integer('max_products')->nullable();
            $table->boolean('is_trial')->default(false);
            $table->text('features')->nullable();
            foreach ([
                'inventory_enabled', 'reports_enabled', 'restaurant_enabled', 'deals_enabled',
                'analytics_enabled', 'excel_enabled', 'offline_enabled', 'riders_enabled',
                'qr_menu_enabled', 'whatsapp_enabled', 'hazri_enabled', 'rider_tracking_enabled',
                'custom_access_enabled', 'caller_id_enabled', 'khata_enabled', 'loyalty_enabled',
                'kot_enabled',
            ] as $column) {
                $table->boolean($column)->default(false);
            }
            $table->timestamps();
        });

        DB::table('admin_users')->insert([
            'name' => 'Ladder Admin',
            'email' => 'ladder-admin@taxnest.test',
            'password' => Hash::make('Ladder@12345'),
            'role' => 'super_admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->seedPosLadder();
    }

    /** The approved POS ladder, in the database, auditing clean. */
    private function seedPosLadder(): void
    {
        $rows = [
            // 22 Aug 2026: the six optional features (riders, qr_menu,
            // whatsapp, hazri, rider_tracking, caller_id) are paid add-ons —
            // no plan row carries them, so the ladder differs by limits and
            // by custom_access (Business+) only.
            ['Starter',   6000,  2000,  2,  1, 1, []],
            ['Business',  12000, 5000,  5,  1, 3, ['restaurant', 'deals', 'analytics', 'reports', 'excel', 'offline', 'custom_access']],
            ['Pro',       24000, 10000, 10, 2, -1, ['restaurant', 'deals', 'analytics', 'reports', 'excel', 'offline', 'custom_access']],
            ['Pro Max',   36000, -1,    20, 3, -1, ['restaurant', 'deals', 'analytics', 'reports', 'excel', 'offline', 'custom_access']],
            ['Unlimited', 60000, -1,    -1, 5, -1, ['restaurant', 'deals', 'analytics', 'reports', 'excel', 'offline', 'custom_access']],
        ];

        foreach ($rows as [$name, $price, $bills, $team, $branches, $counters, $on]) {
            DB::table('pricing_plans')->insert(array_merge([
                'name' => $name,
                'product_type' => 'pos',
                'price' => $price,
                'invoice_limit' => $bills,
                'user_limit' => $team,
                'branch_limit' => $branches,
                'max_terminals' => $counters,
                'max_products' => -1,
                // Included in every package.
                'khata_enabled' => true,
                'loyalty_enabled' => true,
                'inventory_enabled' => true,
                'kot_enabled' => true,
                'is_trial' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ], array_combine(
                array_map(fn ($key) => \App\Services\PosPlanComparisonService::FEATURE_ROWS[$key]['column'], $on),
                array_fill(0, count($on), true)
            )));
        }
    }

    private function actingAsAdmin(): self
    {
        return $this->actingAs(AdminUser::first(), 'admin');
    }

    private function planId(string $name): int
    {
        return (int) DB::table('pricing_plans')->where('name', $name)->value('id');
    }

    /** The edit form's payload for a plan exactly as it stands (so only the override differs). */
    private function payloadFor(string $name, array $overrides = []): array
    {
        $plan = DB::table('pricing_plans')->where('name', $name)->first();

        return array_merge([
            'name' => $plan->name,
            'product_type' => $plan->product_type,
            'price' => (int) $plan->price,
            'invoice_limit' => (int) $plan->invoice_limit,
            'max_terminals' => $plan->max_terminals,
            'max_users' => $plan->max_users,
            'max_products' => $plan->max_products,
            'inventory_enabled' => $plan->inventory_enabled ? 1 : 0,
            'reports_enabled' => $plan->reports_enabled ? 1 : 0,
        ], $overrides);
    }

    // ── 1-3: the three ways a ladder breaks ──────────────────────────────

    public function test_tightening_a_limit_on_a_costlier_package_is_refused(): void
    {
        // Pro sits above Business (3 counters) but would allow only 2.
        $response = $this->actingAsAdmin()->from('/admin/plans')
            ->put('/admin/plans/' . $this->planId('Pro'), $this->payloadFor('Pro', ['max_terminals' => 2]));

        $response->assertRedirect('/admin/plans');
        $response->assertSessionHasErrors('ladder');
        $this->assertSame(-1, (int) DB::table('pricing_plans')->where('name', 'Pro')->value('max_terminals'),
            'the refused edit must not have been written');
    }

    public function test_switching_a_feature_off_on_a_costlier_package_is_refused(): void
    {
        // Reports is ON for Business; unticking it on Pro breaks the ladder.
        $payload = $this->payloadFor('Pro');
        unset($payload['reports_enabled']); // an unticked checkbox is simply absent

        $response = $this->actingAsAdmin()->from('/admin/plans')
            ->put('/admin/plans/' . $this->planId('Pro'), $payload);

        $response->assertSessionHasErrors('ladder');
        $this->assertSame(1, (int) DB::table('pricing_plans')->where('name', 'Pro')->value('reports_enabled'));
    }

    public function test_a_half_configured_new_package_below_an_existing_one_is_refused(): void
    {
        // No khata / loyalty / inventory, and a product cap — every one of
        // those contradicts "included in every package".
        $response = $this->actingAsAdmin()->from('/admin/plans')->post('/admin/plans', [
            'name' => 'Mini',
            'product_type' => 'pos',
            'price' => 3000,
            'invoice_limit' => 500,
            'max_terminals' => 1,
            'max_products' => 100,
        ]);

        $response->assertSessionHasErrors('ladder');
        $this->assertNull(DB::table('pricing_plans')->where('name', 'Mini')->first(),
            'the refused package must not have been created');
    }

    // ── 4: the deliberate way past it ────────────────────────────────────

    public function test_save_anyway_gets_through_and_is_recorded(): void
    {
        $response = $this->actingAsAdmin()->from('/admin/plans')->put(
            '/admin/plans/' . $this->planId('Pro'),
            $this->payloadFor('Pro', ['max_terminals' => 2, 'ladder_override' => 1])
        );

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success');
        $this->assertSame(2, (int) DB::table('pricing_plans')->where('name', 'Pro')->value('max_terminals'));

        $log = DB::table('admin_audit_logs')->orderByDesc('id')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('ladder_override', (string) $log->metadata,
            'an override must leave a trail of what it overrode');
    }

    // ── 5-6: the guard must not get in the way ───────────────────────────

    public function test_a_harmless_edit_still_saves(): void
    {
        $response = $this->actingAsAdmin()->from('/admin/plans')->put(
            '/admin/plans/' . $this->planId('Pro'),
            $this->payloadFor('Pro', ['price' => 26000])
        );

        $response->assertSessionHasNoErrors();
        $this->assertSame(26000, (int) DB::table('pricing_plans')->where('name', 'Pro')->value('price'));
    }

    public function test_a_pre_existing_break_does_not_block_an_unrelated_save(): void
    {
        // Somebody already switched khata off on Pro Max (the ladder is broken
        // before this request even starts).
        DB::table('pricing_plans')->where('name', 'Pro Max')->update(['khata_enabled' => false]);

        $response = $this->actingAsAdmin()->from('/admin/plans')->put(
            '/admin/plans/' . $this->planId('Starter'),
            $this->payloadFor('Starter', ['price' => 6500])
        );

        $response->assertSessionHasNoErrors();
        $this->assertSame(6500, (int) DB::table('pricing_plans')->where('name', 'Starter')->value('price'));
    }

    public function test_the_plans_page_warns_while_a_ladder_is_broken(): void
    {
        $clean = $this->actingAsAdmin()->get('/admin/plans');
        $clean->assertStatus(200);
        $clean->assertDontSee('Package ladder needs attention');

        DB::table('pricing_plans')->where('name', 'Pro Max')->update(['khata_enabled' => false]);

        $broken = $this->actingAsAdmin()->get('/admin/plans');
        $broken->assertStatus(200);
        $broken->assertSee('Package ladder needs attention');
        $broken->assertSee('khata_enabled');
    }
}

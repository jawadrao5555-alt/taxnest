<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Company;
use App\Models\User;
use App\Http\Controllers\PosRiderController;
use App\Http\Controllers\PosController;
use App\Services\PosFeatureService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * Task #437 — Delivery Board modal (sale screen -> iframe /pos/deliveries)
 * regression guard for Task 431's feature.
 *
 * Locked in this suite:
 *  1. EMBEDDED CONTRACT — /pos/deliveries must keep shipping the frame-detect
 *     script (adds .tn-embedded when window.self !== window.top), the CSS
 *     rules hiding the app top-nav + .tn-embed-hide elements in embedded
 *     mode, and the history-back button carrying the tn-embed-hide class.
 *     If any of these go missing, the sale-screen modal iframe shows the
 *     FULL top-nav / a stranding back button inside the overlay.
 *  2. SALE-SCREEN GATING (source structure) — universal.blade.php's
 *     $showDeliveriesBoardBtn verdict must keep all three legs (delivery
 *     feature flag + riders plan gate + Custom Access tick), and every
 *     tnOpenDeliveryBoard entry point (2 buttons + modal/script block) must
 *     stay wrapped in @if($showDeliveriesBoardBtn).
 *  3. GATING BEHAVIOR — the exact primitives the blade verdict calls,
 *     evaluated against a real sqlite schema: feature-ON + riders-plan
 *     company passes, feature-OFF company fails the feature leg, plan-locked
 *     (non-internal, riders_enabled=false) company fails planAllows.
 *  4. FINGERPRINT — the button is BAKED into the (offline-cached) sale
 *     screen, so a riders plan-gate flip must change posBootFingerprint's
 *     'set' hash (mirrors Task 117's offline_enabled rule).
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create
 * (schema copied from PosDeliveriesStreamScopeTest + fingerprint tables from
 * PosBootFingerprintStabilityTest).
 */
class PosDeliveryBoardModalGuardTest extends TestCase
{
    private function buildSchema(): void
    {
        User::flushScopeColumnCache();
        PosFeatureService::flushGateCaches();
        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('ntn')->nullable();
            $table->string('product_type')->nullable();
            $table->string('status')->default('approved');
            $table->string('company_status')->default('approved');
            $table->boolean('restaurant_mode')->default(false);
            $table->boolean('pos_setup_completed')->default(true);
            $table->string('confidential_pin')->nullable();
            $table->string('default_language')->nullable();
            $table->text('invoice_display_prefs')->nullable();
            $table->text('feature_flags')->nullable();
            $table->boolean('is_internal_account')->default(false);
            $table->boolean('pra_reporting_enabled')->default(false);
            $table->boolean('agent_enabled')->default(false);
            $table->string('pra_connection_mode')->nullable();
            $table->string('pra_environment')->nullable();
            $table->string('pos_theme')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable()->unique();
            $table->string('password')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('role')->nullable();
            $table->string('pos_role')->nullable();
            $table->string('pos_billing_scope', 10)->nullable();
            $table->text('pos_custom_access')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('dark_mode')->default(false);
            $table->string('language')->nullable();
            $table->unsignedBigInteger('default_branch_id')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->boolean('is_head_office')->default(false);
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('branch_user', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamps();
        });

        Schema::create('pos_user_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->timestamp('login_at')->nullable();
            $table->timestamp('logout_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('pricing_plan_id')->nullable();
            $table->string('status')->default('active');
            $table->boolean('is_active')->default(true);
            $table->boolean('active')->default(true);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->string('override_type')->default('none');
            $table->timestamp('override_until')->nullable();
            $table->timestamp('override_granted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->nullable();
            $table->string('product_type')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->text('features')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_trial')->default(false);
            $table->boolean('riders_enabled')->default(true);
            $table->boolean('restaurant_enabled')->default(false);
            $table->timestamps();
        });

        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('invoice_number')->nullable();
            $table->string('business_date')->nullable();
            $table->string('status')->nullable();
            $table->string('invoice_mode')->nullable();
            $table->string('pra_status')->nullable();
            $table->string('pra_invoice_number')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->string('order_type')->nullable();
            $table->string('payment_method')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->unsignedBigInteger('rider_id')->nullable();
            $table->string('delivery_status')->nullable();
            $table->unsignedBigInteger('rider_settlement_id')->nullable();
            $table->timestamp('rider_settled_at')->nullable();
            $table->timestamp('rider_assigned_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_rider_settlements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('rider_id');
            $table->unsignedBigInteger('settled_by')->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->integer('bill_count')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_riders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();
        });

        // Fingerprint's catalog revision aggregates over these.
        foreach (['pos_products', 'pos_services', 'pos_deals'] as $t) {
            Schema::create($t, function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->timestamps();
            });
        }

        Schema::create('pos_tax_rules', function (Blueprint $table) {
            $table->id();
            $table->string('payment_method');
            $table->decimal('tax_rate', 8, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * @param bool $deliveryFeature company feature_flags.delivery
     * @param bool $ridersPlan      pricing_plans.riders_enabled
     * @param bool $internal        is_internal_account (bypasses plan gates)
     */
    private function makeCompany(bool $deliveryFeature = true, bool $ridersPlan = true, bool $internal = false): int
    {
        $flags = ['tables' => true, 'kitchen' => true, 'kot' => true, 'customer_profile' => true];
        if ($deliveryFeature) {
            $flags['delivery'] = true;
        }

        $companyId = (int) DB::table('companies')->insertGetId([
            'name'                => 'Delivery Board Guard Co ' . rand(1000, 9999),
            'product_type'        => 'pos',
            'status'              => 'approved',
            'company_status'      => 'approved',
            'is_internal_account' => $internal,
            'feature_flags'       => json_encode($flags),
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        $planId = (int) DB::table('pricing_plans')->insertGetId([
            'name'           => $ridersPlan ? 'Pro' : 'Starter',
            'product_type'   => 'pos',
            'price'          => 0,
            'riders_enabled' => $ridersPlan,
            'is_active'      => true,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        DB::table('subscriptions')->insert([
            'company_id'      => $companyId,
            'pricing_plan_id' => $planId,
            'status'          => 'active',
            'is_active'       => true,
            'active'          => true,
            'start_date'      => now()->subMonth()->toDateString(),
            'end_date'        => now()->addMonth()->toDateString(),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        return $companyId;
    }

    private function makeUser(int $companyId, string $posRole = 'pos_admin', ?array $customAccess = null): User
    {
        $id = DB::table('users')->insertGetId([
            'name'              => ucfirst(str_replace('pos_', '', $posRole)),
            'email'             => $posRole . '_' . $companyId . '_' . rand(10000, 99999) . '@dbguard.test',
            'password'          => Hash::make('Secret@12'),
            'company_id'        => $companyId,
            'role'              => 'user',
            'pos_role'          => $posRole,
            'pos_custom_access' => $customAccess === null ? null : json_encode($customAccess),
            'is_active'         => true,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
        return User::find($id);
    }

    /**
     * The EXACT verdict universal.blade.php computes for the Delivery Board
     * button (structure pinned separately by the source-structure test).
     */
    private function boardVerdict(User $user): bool
    {
        $company = Company::findOrFail($user->company_id);
        $features = PosFeatureService::forCompany($company);

        return !empty($features->delivery)
            && PosFeatureService::planAllows($company, 'riders_enabled')
            && (($user->posCustomAllows('deliveries')) ?? true);
    }

    // ── 1. Embedded contract on /pos/deliveries ─────────────────────────────

    public function test_deliveries_page_ships_embedded_script_and_hiding_rules(): void
    {
        $this->buildSchema();
        $cid = $this->makeCompany(deliveryFeature: true, ridersPlan: true, internal: true);
        $admin = $this->makeUser($cid, 'pos_admin');

        $res = $this->actingAs($admin, 'pos')->get('/pos/deliveries');
        $res->assertOk();

        $html = $res->getContent();

        // Frame-detect script: adds .tn-embedded when running inside an iframe.
        $this->assertStringContainsString('window.self !== window.top', $html,
            'Deliveries page lost the iframe frame-detect script (Task 431 embedded mode).');
        $this->assertStringContainsString("classList.add('tn-embedded')", $html,
            'Frame-detect script no longer adds the tn-embedded class.');

        // Hiding rules: top-nav + tn-embed-hide elements disappear in embedded mode.
        $this->assertStringContainsString('.tn-embedded .topnav-bar', $html,
            'Embedded CSS rule hiding the app top-nav is gone — the modal iframe would show the FULL nav.');
        $this->assertStringContainsString('.tn-embedded .tn-embed-hide', $html,
            'Embedded CSS rule hiding .tn-embed-hide elements is gone.');

        // The history-back button must carry tn-embed-hide (back inside the
        // iframe would strand the modal on a previous page).
        $this->assertMatchesRegularExpression(
            '/class="[^"]*tn-embed-hide[^"]*"[^>]*title="' . preg_quote(__('pos.ti_go_back'), '/') . '"/su',
            $html,
            'Back button lost its tn-embed-hide class — it would render inside the sale-screen modal iframe.'
        );
    }

    // ── 2. Sale-screen source structure ─────────────────────────────────────

    public function test_sale_screen_board_verdict_keeps_all_three_gating_legs(): void
    {
        $blade = file_get_contents(resource_path('views/pos/universal.blade.php'));

        $pos = strpos($blade, '$showDeliveriesBoardBtn =');
        $this->assertNotFalse($pos, 'universal.blade.php lost the $showDeliveriesBoardBtn verdict block.');

        $verdict = substr($blade, $pos, 400);
        $this->assertStringContainsString('$features->delivery', $verdict,
            'Verdict lost the delivery feature-flag leg.');
        $this->assertStringContainsString("planAllows(\$company, 'riders_enabled')", $verdict,
            'Verdict lost the riders plan-gate leg.');
        $this->assertStringContainsString("posCustomAllows('deliveries')", $verdict,
            'Verdict lost the Custom Access leg.');
    }

    public function test_every_board_entry_point_is_wrapped_in_the_verdict_gate(): void
    {
        $blade = file_get_contents(resource_path('views/pos/universal.blade.php'));

        // Entry points: desktop header button, mobile button, and the modal +
        // tnOpenDeliveryBoard/tnCloseDeliveryBoard script block. Each region
        // opens with @if($showDeliveriesBoardBtn) — an ungated button would
        // show the board to feature-OFF / plan-locked / access-blocked staff.
        $gateCount = substr_count($blade, '@if($showDeliveriesBoardBtn)');
        $this->assertGreaterThanOrEqual(3, $gateCount,
            'Expected all 3 Delivery Board regions (2 buttons + modal/script) to be gated by @if($showDeliveriesBoardBtn).');

        // Every onclick entry point must exist only if the gate exists at all
        // (function definition also lives inside a gated region).
        $onclickCount = substr_count($blade, 'onclick="tnOpenDeliveryBoard()"');
        $this->assertSame(2, $onclickCount,
            'Expected exactly 2 tnOpenDeliveryBoard buttons (desktop + mobile). New buttons must be added INSIDE an @if($showDeliveriesBoardBtn) region and this count updated.');

        // The function definition block itself is gated: the @if immediately
        // preceding the modal wrapper must be the verdict gate.
        $modalPos = strpos($blade, 'id="tn-delivery-board"');
        $this->assertNotFalse($modalPos, 'Delivery Board modal wrapper missing.');
        $before = substr($blade, max(0, $modalPos - 1500), 1500);
        $this->assertStringContainsString('@if($showDeliveriesBoardBtn)', $before,
            'Delivery Board modal/script block is no longer wrapped in @if($showDeliveriesBoardBtn).');
    }

    // ── 3. Gating behavior (real sqlite verdicts) ───────────────────────────

    public function test_admin_of_delivery_feature_and_riders_plan_company_gets_the_button(): void
    {
        $this->buildSchema();
        $cid = $this->makeCompany(deliveryFeature: true, ridersPlan: true);
        $this->assertTrue($this->boardVerdict($this->makeUser($cid, 'pos_admin')));
    }

    public function test_feature_off_company_never_gets_the_button(): void
    {
        $this->buildSchema();
        $cid = $this->makeCompany(deliveryFeature: false, ridersPlan: true);
        $this->assertFalse($this->boardVerdict($this->makeUser($cid, 'pos_admin')));
    }

    public function test_plan_locked_company_never_gets_the_button(): void
    {
        $this->buildSchema();
        $cid = $this->makeCompany(deliveryFeature: true, ridersPlan: false, internal: false);
        $this->assertFalse(
            PosFeatureService::planAllows(Company::findOrFail($cid), 'riders_enabled'),
            'planAllows must lock riders_enabled for a non-internal company on a riders-disabled plan.'
        );
        $this->assertFalse($this->boardVerdict($this->makeUser($cid, 'pos_admin')));
    }

    public function test_custom_access_blocked_cashier_never_gets_the_button(): void
    {
        $this->buildSchema();
        $cid = $this->makeCompany(deliveryFeature: true, ridersPlan: true);
        // An ACTIVE Delivery Manager account must exist, otherwise the owner
        // rule (3 Aug 2026) opens the deliveries fallback for cashiers even
        // when their custom set leaves 'deliveries' unticked.
        $this->makeUser($cid, 'pos_delivery');
        // Cashier with a custom set that EXCLUDES deliveries.
        $cashier = $this->makeUser($cid, 'pos_cashier', ['reports']);
        $this->assertFalse($this->boardVerdict($cashier));

        // And the fallback itself stays locked in: without any delivery
        // manager the same cashier WOULD get the board.
        $cid2 = $this->makeCompany(deliveryFeature: true, ridersPlan: true);
        $cashier2 = $this->makeUser($cid2, 'pos_cashier', ['reports']);
        $this->assertTrue($this->boardVerdict($cashier2));
    }

    public function test_feature_off_company_with_open_rider_cash_can_reach_board_gate(): void
    {
        $this->buildSchema();
        $cid = $this->makeCompany(deliveryFeature: false, ridersPlan: false);
        DB::table('pos_riders')->insert([
            'company_id' => $cid,
            'name' => 'Open Cash Rider',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('pos_transactions')->insert([
            'company_id' => $cid,
            'business_date' => now()->toDateString(),
            'status' => 'completed',
            'order_type' => 'delivery',
            'payment_method' => 'cash',
            'total_amount' => 500,
            'rider_id' => 1,
            'delivery_status' => 'delivered',
            'rider_settlement_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->app->instance('currentCompanyId', $cid);
        $this->actingAs($this->makeUser($cid, 'pos_admin'), 'pos');
        $method = new \ReflectionMethod(PosRiderController::class, 'deliveryGate');
        $method->setAccessible(true);

        $this->assertNull($method->invoke(app(PosRiderController::class)),
            'An open rider-cash bill must keep the settlement board reachable after Delivery is switched off.');
    }

    public function test_feature_off_company_without_open_rider_cash_keeps_gate(): void
    {
        $this->buildSchema();
        $cid = $this->makeCompany(deliveryFeature: false, ridersPlan: true);
        $this->app->instance('currentCompanyId', $cid);
        $this->actingAs($this->makeUser($cid, 'pos_admin'), 'pos');
        $method = new \ReflectionMethod(PosRiderController::class, 'deliveryGate');
        $method->setAccessible(true);

        $response = $method->invoke(app(PosRiderController::class));

        $this->assertInstanceOf(\Illuminate\Http\RedirectResponse::class, $response);
        $this->assertSame(route('pos.features'), $response->getTargetUrl());
    }

    // ── 4. Boot fingerprint refreshes the baked button ──────────────────────

    public function test_riders_plan_flip_changes_boot_fingerprint(): void
    {
        $this->buildSchema();
        $cid = $this->makeCompany(deliveryFeature: true, ridersPlan: true);
        $user = $this->makeUser($cid, 'pos_admin');

        $fingerprint = function () use ($cid, $user): array {
            PosFeatureService::flushGateCaches();
            $m = new \ReflectionMethod(PosController::class, 'posBootFingerprint');
            $m->setAccessible(true);
            return $m->invoke(app(PosController::class), Company::findOrFail($cid), $user->fresh());
        };

        $before = $fingerprint();

        // Downgrade: riders gate flips OFF → the offline-cached sale screen
        // (button baked in) must look stale and refresh.
        DB::table('pricing_plans')->update(['riders_enabled' => false]);

        $after = $fingerprint();
        $this->assertNotSame($before['set'], $after['set'],
            'posBootFingerprint no longer reacts to a riders_enabled plan flip — a downgraded shop would keep the cached Delivery Board button.');
    }
}

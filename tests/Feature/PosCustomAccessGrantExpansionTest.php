<?php

namespace Tests\Feature;

use App\Http\Middleware\PosAdminOnly;
use App\Http\Middleware\PosAuth;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * POS Team Custom Access — GRANT-EXPANSION side (Task #124).
 *
 * Task #120 proved the SAFETY invariants (sale screen never locked, owner
 * never restricted, corrupt JSON never crashes). This suite proves the
 * opposite direction actually works:
 *  (a) a cashier whose set ticks 'customize' can OPEN PosAdminOnly settings
 *      pages (grants EXPAND reach beyond the plain-cashier default),
 *  (b) a manager whose set UNTICKS 'customize' is blocked from those same
 *      pages (grants RESTRICT a manager's default full access),
 *  (c) an UNMAPPED path inside the PosAdminOnly group is ALSO blocked for
 *      any member with a custom set (deny-by-default — new admin endpoints
 *      are born closed until mapped in PosAccessService::PATH_MAP).
 *
 * Run with:
 *   APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: \
 *     php artisan test --filter=PosCustomAccessGrantExpansionTest
 */
class PosCustomAccessGrantExpansionTest extends TestCase
{
    /**
     * Same lightweight sqlite schema as PosCustomAccessInvariantsTest
     * (buildHttpSchema pattern) + pos_services for the /pos/services page.
     */
    private function buildHttpSchema(): int
    {
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
            $table->text('feature_flags')->nullable();
            $table->string('confidential_pin')->nullable();
            $table->string('default_language')->nullable();
            $table->text('invoice_display_prefs')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('role')->nullable();
            $table->string('pos_role')->nullable();
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

        Schema::create('pos_services', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('tax_rate', 8, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_tax_exempt')->default(false);
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
            $table->timestamps();
        });

        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->nullable();
            $table->string('product_type')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->text('features')->nullable();
            $table->boolean('restaurant_enabled')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $companyId = DB::table('companies')->insertGetId([
            'name' => 'Grant Expansion Co',
            'product_type' => 'pos',
            'status' => 'approved',
            'company_status' => 'approved',
            'feature_flags' => json_encode(['recipes' => true, 'inventory' => true]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Custom Access is plan-gated (Unlimited-only). This lightweight
        // pricing_plans schema has no custom_access_enabled column, so the
        // gate fails open — but an ACTIVE subscription must exist.
        $planId = DB::table('pricing_plans')->insertGetId([
            'name' => 'Test Plan',
            'product_type' => 'pos',
            'price' => 0,
            'restaurant_enabled' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('subscriptions')->insert([
            'company_id' => $companyId,
            'pricing_plan_id' => $planId,
            'status' => 'active',
            'is_active' => true,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $companyId;
    }

    private function makeMember(int $companyId, string $posRole, ?array $customSet, string $email): User
    {
        $id = DB::table('users')->insertGetId([
            'name' => ucfirst(str_replace('pos_', '', $posRole)) . ' Member',
            'email' => $email,
            'password' => Hash::make('Member@12345'),
            'company_id' => $companyId,
            'role' => 'user',
            'pos_role' => $posRole,
            'pos_custom_access' => $customSet === null ? null : json_encode($customSet),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::find($id);
    }

    // ════════════════════════════════════════════════════════════════════
    // (a) EXPANSION: cashier + ['customize'] opens PosAdminOnly pages
    // ════════════════════════════════════════════════════════════════════

    public function test_cashier_with_customize_tick_opens_admin_settings_pages(): void
    {
        $companyId = $this->buildHttpSchema();
        $cashier = $this->makeMember($companyId, 'pos_cashier', ['customize'], 'cashier@grant.test');

        // /pos/services is inside the PosAdminOnly group and maps to 'customize'.
        // A plain cashier (no set) is bounced by PosAdminOnly — the ticked
        // grant must EXPAND past both PosAuth and PosAdminOnly to a real 200.
        $resp = $this->actingAs($cashier, 'pos')->get('/pos/services');
        $resp->assertStatus(200);

        // /pos/receipt-settings additionally guards with posCashierBlocked() —
        // that in-controller guard must ALSO honor the ticked grant.
        $resp2 = $this->actingAs($cashier, 'pos')->get('/pos/receipt-settings');
        $resp2->assertStatus(200);
    }

    public function test_plain_cashier_without_set_is_still_bounced_from_admin_pages(): void
    {
        // Control: the 200 above must come from the GRANT, not a hole.
        $companyId = $this->buildHttpSchema();
        $cashier = $this->makeMember($companyId, 'pos_cashier', null, 'plain@grant.test');

        $resp = $this->actingAs($cashier, 'pos')->get('/pos/services');
        $resp->assertRedirect(route('pos.dashboard', absolute: false));
        $resp->assertSessionHas('error');
    }

    // ════════════════════════════════════════════════════════════════════
    // (b) RESTRICTION: manager with 'customize' UNTICKED is blocked
    // ════════════════════════════════════════════════════════════════════

    public function test_manager_with_customize_unticked_is_blocked_from_settings(): void
    {
        $companyId = $this->buildHttpSchema();
        // Set includes 'dashboard' so the block lands on pos.dashboard
        // (without it, the fallback is the always-open sale screen).
        $manager = $this->makeMember($companyId, 'pos_manager', ['reports', 'dashboard'], 'manager@grant.test');

        foreach (['/pos/services', '/pos/receipt-settings'] as $path) {
            $resp = $this->actingAs($manager, 'pos')->get($path);
            $resp->assertRedirect('/pos/dashboard');
            $resp->assertSessionHas('error');
        }
    }

    public function test_manager_blocked_without_dashboard_lands_on_sale_screen_never_loops(): void
    {
        $companyId = $this->buildHttpSchema();
        $manager = $this->makeMember($companyId, 'pos_manager', ['reports'], 'manager2@grant.test');

        $resp = $this->actingAs($manager, 'pos')->get('/pos/receipt-settings');
        $resp->assertRedirect('/pos/invoice/create');
        $resp->assertSessionHas('error');
    }

    public function test_manager_without_custom_set_keeps_full_default_access(): void
    {
        // Control: restriction comes ONLY from the stored set.
        $companyId = $this->buildHttpSchema();
        $manager = $this->makeMember($companyId, 'pos_manager', null, 'manager3@grant.test');

        $resp = $this->actingAs($manager, 'pos')->get('/pos/services');
        $resp->assertStatus(200);
    }

    // ════════════════════════════════════════════════════════════════════
    // (d) RECIPE EXCEL ROUTES: direct links honor the inventory grant
    // ════════════════════════════════════════════════════════════════════

    public function test_cashier_without_inventory_access_cannot_reach_recipe_page_or_excel_routes(): void
    {
        $companyId = $this->buildHttpSchema();
        // A saved set is important here: it proves the route gate is enforcing
        // an explicit unticked inventory feature, rather than only relying on
        // the normal cashier role defaults or hidden navigation.
        $cashier = $this->makeMember(
            $companyId,
            'pos_cashier',
            ['dashboard', 'reports'],
            'recipe-cashier@grant.test'
        );

        foreach ([
            ['get', '/pos/restaurant/recipes'],
            ['get', '/pos/restaurant/recipes/template'],
            ['post', '/pos/restaurant/recipes/import'],
        ] as [$method, $path]) {
            $response = $this->actingAs($cashier, 'pos')->{$method}($path);
            $response->assertRedirect('/pos/dashboard');
            $response->assertSessionHas('error');
        }
    }

    public function test_manager_can_reach_recipe_template_and_import_routes(): void
    {
        $companyId = $this->buildHttpSchema();
        $manager = $this->makeMember($companyId, 'pos_manager', null, 'recipe-manager@grant.test');

        $this->actingAs($manager, 'pos')
            ->get('/pos/restaurant/recipes/template')
            ->assertOk()
            ->assertHeader(
                'Content-Type',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            );

        // No file is intentional: validation is the controller-level proof
        // that the request passed PosAuth, PosAdminOnly, and feature:recipes.
        $this->from('/pos/restaurant/recipes')
            ->actingAs($manager, 'pos')
            ->post('/pos/restaurant/recipes/import')
            ->assertSessionHasErrors('excel_file');
    }

    // ════════════════════════════════════════════════════════════════════
    // (c) DENY-BY-DEFAULT: unmapped path inside the PosAdminOnly group
    // ════════════════════════════════════════════════════════════════════

    /** Register a hypothetical FUTURE admin endpoint that nobody mapped yet. */
    private function registerUnmappedAdminRoute(): void
    {
        Route::middleware(['web', PosAuth::class, PosAdminOnly::class])
            ->get('/pos/brand-new-admin-tool', fn () => response('future admin tool'));

        // Precondition of the whole test: the path really is unmapped.
        $this->assertNull(
            \App\Services\PosAccessService::featureForPath('pos/brand-new-admin-tool'),
            'Test route must not be mapped in PATH_MAP for the deny-by-default check'
        );
    }

    public function test_unmapped_admin_path_is_blocked_for_manager_with_custom_set(): void
    {
        $companyId = $this->buildHttpSchema();
        $this->registerUnmappedAdminRoute();

        // Even a set that ticks EVERYTHING must not open an unmapped admin
        // endpoint — new endpoints stay closed until mapped in PATH_MAP.
        $manager = $this->makeMember(
            $companyId,
            'pos_manager',
            \App\Services\PosAccessService::FEATURES,
            'manager4@grant.test'
        );

        $resp = $this->actingAs($manager, 'pos')->get('/pos/brand-new-admin-tool');
        $resp->assertRedirect(route('pos.dashboard', absolute: false));
        $resp->assertSessionHas('error');
    }

    public function test_unmapped_admin_path_is_blocked_for_cashier_with_custom_set(): void
    {
        $companyId = $this->buildHttpSchema();
        $this->registerUnmappedAdminRoute();

        $cashier = $this->makeMember($companyId, 'pos_cashier', ['customize'], 'cashier2@grant.test');

        $resp = $this->actingAs($cashier, 'pos')->get('/pos/brand-new-admin-tool');
        $resp->assertRedirect(route('pos.dashboard', absolute: false));
        $resp->assertSessionHas('error');
    }

    public function test_unmapped_admin_path_stays_open_for_members_without_a_set(): void
    {
        // Control: deny-by-default applies ONLY to custom-set members; a
        // plain manager (admin-equivalent) still reaches the new endpoint.
        $companyId = $this->buildHttpSchema();
        $this->registerUnmappedAdminRoute();

        $manager = $this->makeMember($companyId, 'pos_manager', null, 'manager5@grant.test');
        $this->actingAs($manager, 'pos')->get('/pos/brand-new-admin-tool')->assertStatus(200);
    }
}

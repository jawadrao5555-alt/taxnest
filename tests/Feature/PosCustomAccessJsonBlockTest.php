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
 * POS Team Custom Access — JSON/background-request branch (Task #133).
 *
 * Both PosAuth and PosAdminOnly have a JSON branch: when a BLOCKED request
 * expects JSON (sale-screen fetch/AJAX), they must abort(403) instead of
 * issuing a 302 redirect — an HTML redirect silently breaks front-end fetch
 * handlers. This suite pins that contract:
 *  (a) blocked MAPPED path (PosAuth branch) with Accept: application/json → 403,
 *  (b) blocked mapped path inside the PosAdminOnly group with JSON → 403,
 *  (c) blocked UNMAPPED PosAdminOnly path (deny-by-default) with JSON → 403,
 *  (d) an ALLOWED JSON request (feature ticked) still succeeds (no 403/302).
 *
 * Run with:
 *   APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: \
 *     php artisan test --filter=PosCustomAccessJsonBlockTest
 */
class PosCustomAccessJsonBlockTest extends TestCase
{
    /** Same lightweight sqlite schema as PosCustomAccessGrantExpansionTest. */
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
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $companyId = DB::table('companies')->insertGetId([
            'name' => 'Json Block Co',
            'product_type' => 'pos',
            'status' => 'approved',
            'company_status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $planId = DB::table('pricing_plans')->insertGetId([
            'name' => 'Test Plan',
            'product_type' => 'pos',
            'price' => 0,
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

    /** Register a hypothetical FUTURE admin endpoint that nobody mapped yet. */
    private function registerUnmappedAdminRoute(): void
    {
        Route::middleware(['web', PosAuth::class, PosAdminOnly::class])
            ->get('/pos/brand-new-admin-tool', fn () => response('future admin tool'));

        $this->assertNull(
            \App\Services\PosAccessService::featureForPath('pos/brand-new-admin-tool'),
            'Test route must not be mapped in PATH_MAP for the deny-by-default check'
        );
    }

    // ════════════════════════════════════════════════════════════════════
    // (a) PosAuth JSON branch: blocked MAPPED path → 403, never 302
    // ════════════════════════════════════════════════════════════════════

    public function test_blocked_mapped_path_returns_403_for_json_request(): void
    {
        $companyId = $this->buildHttpSchema();
        // /pos/customers maps to 'customers' and sits OUTSIDE the PosAdminOnly
        // group — the block here comes from PosAuth's own JSON branch.
        $manager = $this->makeMember($companyId, 'pos_manager', ['reports', 'dashboard'], 'manager-json@block.test');

        $this->assertSame(
            'customers',
            \App\Services\PosAccessService::featureForPath('pos/customers'),
            'Precondition: /pos/customers must be a MAPPED path'
        );

        $resp = $this->actingAs($manager, 'pos')->getJson('/pos/customers');
        $resp->assertStatus(403);
    }

    // ════════════════════════════════════════════════════════════════════
    // (b) PosAdminOnly JSON branch: blocked mapped admin path → 403
    // ════════════════════════════════════════════════════════════════════

    public function test_blocked_mapped_admin_path_returns_403_for_json_request(): void
    {
        $companyId = $this->buildHttpSchema();
        // 'customize' NOT ticked → /pos/services (mapped to 'customize',
        // inside the PosAdminOnly group) is blocked for this manager.
        $manager = $this->makeMember($companyId, 'pos_manager', ['reports', 'dashboard'], 'manager-json2@block.test');

        $resp = $this->actingAs($manager, 'pos')->getJson('/pos/services');
        $resp->assertStatus(403);
    }

    public function test_blocked_admin_path_returns_403_for_cashier_json_request(): void
    {
        $companyId = $this->buildHttpSchema();
        // Cashier whose set does NOT tick 'customize' — blocked from settings.
        $cashier = $this->makeMember($companyId, 'pos_cashier', ['orders'], 'cashier-json@block.test');

        $resp = $this->actingAs($cashier, 'pos')->getJson('/pos/services');
        $resp->assertStatus(403);
    }

    // ════════════════════════════════════════════════════════════════════
    // (c) Deny-by-default: UNMAPPED PosAdminOnly path with JSON → 403
    // ════════════════════════════════════════════════════════════════════

    public function test_unmapped_admin_path_returns_403_for_json_request(): void
    {
        $companyId = $this->buildHttpSchema();
        $this->registerUnmappedAdminRoute();

        // Even an all-ticked set must not open an unmapped admin endpoint —
        // and via JSON the refusal must be a 403, never an HTML redirect.
        $manager = $this->makeMember(
            $companyId,
            'pos_manager',
            \App\Services\PosAccessService::FEATURES,
            'manager-json3@block.test'
        );

        $resp = $this->actingAs($manager, 'pos')->getJson('/pos/brand-new-admin-tool');
        $resp->assertStatus(403);

        $cashier = $this->makeMember($companyId, 'pos_cashier', ['customize'], 'cashier-json2@block.test');
        $this->actingAs($cashier, 'pos')->getJson('/pos/brand-new-admin-tool')->assertStatus(403);
    }

    // ════════════════════════════════════════════════════════════════════
    // (d) ALLOWED JSON request (feature ticked) still succeeds
    // ════════════════════════════════════════════════════════════════════

    public function test_allowed_json_request_with_ticked_feature_succeeds(): void
    {
        $companyId = $this->buildHttpSchema();
        $cashier = $this->makeMember($companyId, 'pos_cashier', ['customize'], 'cashier-json3@block.test');

        // 'customize' ticked → /pos/services opens even through PosAdminOnly.
        $resp = $this->actingAs($cashier, 'pos')->get('/pos/services', ['Accept' => 'application/json']);
        $resp->assertStatus(200);
    }
}

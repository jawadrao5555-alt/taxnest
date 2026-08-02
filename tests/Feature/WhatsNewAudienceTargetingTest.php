<?php

namespace Tests\Feature;

use App\Models\AppUpdate;
use App\Models\AppUpdateSeen;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * "What's New" announcements — audience targeting invariants (Task #155).
 *
 * Audience column supports three values: 'pos' (PRA POS panel only),
 * 'fbr_pos' (FBR POS panel only) and 'all' (both panels). These must hold:
 *
 *   1. PRA POS layout (pos-app) shows ONLY ['pos','all'] rows — an
 *      'fbr_pos' announcement must NEVER leak onto the PRA panel.
 *   2. FBR POS layout (fbr-pos-app) shows ONLY ['fbr_pos','all'] rows —
 *      a 'pos' announcement must NEVER leak onto the FBR panel.
 *   3. markSeen with the pos guard marks ONLY published ['pos','all'] rows
 *      seen; with the fbrpos guard ONLY published ['fbr_pos','all'] rows.
 *      Unpublished rows are never marked.
 *   4. Cashiers (and other non-admin roles) never get the popup/bell —
 *      isPosAdmin() gate (admin/manager/company_admin only).
 *
 * Layout coverage renders a REAL page through each layout over HTTP
 * (/pos/my-profile and /fbr-pos/my-profile — light, always-open pages)
 * with unique marker titles asserted present/absent in the response body.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create in
 * setUp (see Phase3LoginIsolationTest / PosCustomAccessInvariantsTest).
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' php vendor/bin/phpunit tests/Feature/WhatsNewAudienceTargetingTest.php
 */
class WhatsNewAudienceTargetingTest extends TestCase
{
    // Unique markers — safe to assertSee/assertDontSee in full-page HTML.
    private const T_POS = 'WNMARK-PRA-ONLY-73kd1';
    private const T_FBR = 'WNMARK-FBR-ONLY-73kd2';
    private const T_ALL = 'WNMARK-EVERYONE-73kd3';
    private const T_HIDDEN = 'WNMARK-UNPUBLISHED-73kd4';

    private int $posCompanyId;
    private int $fbrCompanyId;
    private int $posAdminId;
    private int $fbrAdminId;
    private int $cashierId;

    protected function setUp(): void
    {
        parent::setUp();

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
            $table->boolean('fbr_pos_enabled')->default(false);
            $table->boolean('fbr_reporting_enabled')->default(false);
            $table->boolean('inventory_enabled')->default(false);
            $table->string('pos_theme')->nullable();
            $table->string('pos_dashboard_style')->nullable();
            $table->string('confidential_pin')->nullable();
            $table->string('default_language')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->string('username')->nullable();
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

        // Branch context (fbr layout + BranchContextService)
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

        // Plan/subscription rows the layouts may consult.
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

        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        // The tables under test.
        Schema::create('app_updates', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('points');
            $table->string('image_path')->nullable();
            $table->string('audience')->default('pos');
            $table->boolean('is_published')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('app_update_seens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('app_update_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamps();
            $table->unique(['app_update_id', 'user_id']);
        });

        $this->seedFixtures();
    }

    private function seedFixtures(): void
    {
        $now = now();

        $this->posCompanyId = DB::table('companies')->insertGetId([
            'name' => 'PRA Shop', 'product_type' => 'pos',
            'status' => 'approved', 'company_status' => 'approved',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->fbrCompanyId = DB::table('companies')->insertGetId([
            'name' => 'FBR Shop', 'product_type' => 'fbrpos', 'fbr_pos_enabled' => true,
            'status' => 'approved', 'company_status' => 'approved',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $this->posAdminId = DB::table('users')->insertGetId([
            'name' => 'PRA Admin', 'email' => 'praadmin@wn.test',
            'password' => Hash::make('Secret@12345'), 'company_id' => $this->posCompanyId,
            'role' => 'company_admin', 'pos_role' => 'pos_admin',
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->cashierId = DB::table('users')->insertGetId([
            'name' => 'PRA Cashier', 'email' => 'pracashier@wn.test',
            'password' => Hash::make('Secret@12345'), 'company_id' => $this->posCompanyId,
            'role' => 'user', 'pos_role' => 'pos_cashier',
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->fbrAdminId = DB::table('users')->insertGetId([
            'name' => 'FBR Admin', 'email' => 'fbradmin@wn.test',
            'password' => Hash::make('Secret@12345'), 'company_id' => $this->fbrCompanyId,
            'role' => 'company_admin', 'pos_role' => 'pos_admin',
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);

        foreach ([
            [self::T_POS, 'pos', true],
            [self::T_FBR, 'fbr_pos', true],
            [self::T_ALL, 'all', true],
            [self::T_HIDDEN, 'all', false], // unpublished — invisible everywhere
        ] as [$title, $audience, $published]) {
            AppUpdate::create([
                'title' => $title,
                'points' => ['Point one'],
                'audience' => $audience,
                'is_published' => $published,
            ]);
        }
    }

    private function updateId(string $title): int
    {
        return (int) AppUpdate::where('title', $title)->value('id');
    }

    // ════════════════════════════════════════════════════════════════════
    // 1+2. Layout audience filtering (real HTTP render through each layout)
    // ════════════════════════════════════════════════════════════════════

    public function test_pra_layout_shows_pos_and_all_but_never_fbr_audience(): void
    {
        $resp = $this->actingAs(User::find($this->posAdminId), 'pos')->get('/pos/my-profile');

        $resp->assertStatus(200);
        $resp->assertSee(self::T_POS);
        $resp->assertSee(self::T_ALL);
        $resp->assertDontSee(self::T_FBR);      // FBR-only must never leak to PRA
        $resp->assertDontSee(self::T_HIDDEN);   // unpublished never shows
    }

    public function test_fbr_layout_shows_fbr_and_all_but_never_pos_audience(): void
    {
        $resp = $this->actingAs(User::find($this->fbrAdminId), 'fbrpos')->get('/fbr-pos/my-profile');

        $resp->assertStatus(200);
        $resp->assertSee(self::T_FBR);
        $resp->assertSee(self::T_ALL);
        $resp->assertDontSee(self::T_POS);      // PRA-only must never leak to FBR
        $resp->assertDontSee(self::T_HIDDEN);
    }

    // ════════════════════════════════════════════════════════════════════
    // 3. markSeen guard→audience mapping
    // ════════════════════════════════════════════════════════════════════

    public function test_pos_guard_mark_seen_marks_only_published_pos_and_all_rows(): void
    {
        $resp = $this->actingAs(User::find($this->posAdminId), 'pos')
            ->post('/pos/whats-new/seen');

        $resp->assertStatus(200)->assertJson(['ok' => true]);

        $seen = AppUpdateSeen::where('user_id', $this->posAdminId)->pluck('app_update_id')->all();
        $this->assertEqualsCanonicalizing(
            [$this->updateId(self::T_POS), $this->updateId(self::T_ALL)],
            $seen,
            'pos guard must mark exactly the published [pos, all] rows seen'
        );
        $this->assertNotContains($this->updateId(self::T_FBR), $seen, 'fbr_pos row must NOT be marked by pos guard');
        $this->assertNotContains($this->updateId(self::T_HIDDEN), $seen, 'unpublished row must NOT be marked');
    }

    public function test_fbrpos_guard_mark_seen_marks_only_published_fbr_and_all_rows(): void
    {
        $resp = $this->actingAs(User::find($this->fbrAdminId), 'fbrpos')
            ->post('/fbr-pos/whats-new/seen');

        $resp->assertStatus(200)->assertJson(['ok' => true]);

        $seen = AppUpdateSeen::where('user_id', $this->fbrAdminId)->pluck('app_update_id')->all();
        $this->assertEqualsCanonicalizing(
            [$this->updateId(self::T_FBR), $this->updateId(self::T_ALL)],
            $seen,
            'fbrpos guard must mark exactly the published [fbr_pos, all] rows seen'
        );
        $this->assertNotContains($this->updateId(self::T_POS), $seen, 'pos row must NOT be marked by fbrpos guard');
    }

    public function test_mark_seen_is_idempotent_on_second_call(): void
    {
        $user = User::find($this->posAdminId);
        $this->actingAs($user, 'pos')->post('/pos/whats-new/seen')->assertStatus(200);
        $this->actingAs($user, 'pos')->post('/pos/whats-new/seen')->assertStatus(200);

        $this->assertSame(
            2,
            AppUpdateSeen::where('user_id', $this->posAdminId)->count(),
            'Double markSeen must not duplicate seen rows'
        );
    }

    // ════════════════════════════════════════════════════════════════════
    // 4. Role gate — cashier never gets popup/bell (isPosAdmin only)
    // ════════════════════════════════════════════════════════════════════

    public function test_cashier_never_sees_whats_new_popup_or_bell(): void
    {
        $resp = $this->actingAs(User::find($this->cashierId), 'pos')->get('/pos/my-profile');

        $resp->assertStatus(200);
        $resp->assertDontSee(self::T_POS);
        $resp->assertDontSee(self::T_ALL);
        $resp->assertDontSee(self::T_FBR);
    }
}

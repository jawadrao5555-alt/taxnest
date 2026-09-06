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
    // Task 1585: category-targeted elaans.
    private const T_RESTAURANT = 'WNMARK-RESTAURANT-ONLY-73kd5';
    private const T_PHARMACY = 'WNMARK-PHARMACY-ONLY-73kd6';

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
            $table->string('business_category')->nullable(); // Task 1585
            $table->string('pos_type')->nullable();
            $table->text('feature_flags')->nullable();
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
            $table->string('type', 20)->nullable(); // Task 1286: feature|improvement (null = legacy)
            $table->text('target_categories')->nullable(); // Task 1585: null/[] = all shops
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
            // Task 1585: an explicit category that matches NO targeted fixture
            // row, so the audience tests keep measuring audience alone.
            'name' => 'PRA Shop', 'product_type' => 'pos', 'business_category' => 'salon',
            'status' => 'approved', 'company_status' => 'approved',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->fbrCompanyId = DB::table('companies')->insertGetId([
            'name' => 'FBR Shop', 'product_type' => 'fbrpos', 'fbr_pos_enabled' => true,
            'business_category' => 'grocery',
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

        // Task 1585: category-targeted rows. 'all' audience on purpose — the
        // category is what narrows them, not the panel.
        AppUpdate::create([
            'title' => self::T_RESTAURANT, 'points' => ['Point one'],
            'audience' => 'all', 'is_published' => true,
            'target_categories' => ['restaurant'],
        ]);
        AppUpdate::create([
            'title' => self::T_PHARMACY, 'points' => ['Point one'],
            'audience' => 'all', 'is_published' => true,
            'target_categories' => ['pharmacy'],
        ]);
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

    public function test_specific_update_dismiss_marks_only_that_update_seen(): void
    {
        $posId = $this->updateId(self::T_POS);
        $allId = $this->updateId(self::T_ALL);

        $this->actingAs(User::find($this->posAdminId), 'pos')
            ->postJson('/pos/whats-new/seen', ['update_id' => $posId])
            ->assertStatus(200)
            ->assertJson(['ok' => true]);

        $seen = AppUpdateSeen::where('user_id', $this->posAdminId)->pluck('app_update_id')->all();
        $this->assertSame([$posId], $seen);
        $this->assertNotContains($allId, $seen, 'A different unread update must remain unread.');
    }

    public function test_specific_update_dismiss_cannot_mark_another_panels_update_seen(): void
    {
        $fbrId = $this->updateId(self::T_FBR);

        $this->actingAs(User::find($this->posAdminId), 'pos')
            ->postJson('/pos/whats-new/seen', ['update_id' => $fbrId])
            ->assertStatus(200)
            ->assertJson(['ok' => true]);

        $this->assertDatabaseMissing('app_update_seens', [
            'user_id' => $this->posAdminId,
            'app_update_id' => $fbrId,
        ]);
    }

    public function test_bell_history_renders_reopenable_large_detail_modals(): void
    {
        $posId = $this->updateId(self::T_POS);

        $response = $this->actingAs(User::find($this->posAdminId), 'pos')->get('/pos/my-profile');

        $response->assertStatus(200);
        $response->assertSee('open-whats-new-detail', false);
        $response->assertSee('data-whats-new-detail-id="' . $posId . '"', false);
        $response->assertSee('body: JSON.stringify({ update_id:', false);
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

    // ════════════════════════════════════════════════════════════════════
    // 5. Pending companies — approval middleware blocks the seen POST, so
    //    the popup/bell must be skipped entirely (dismiss-loop guard).
    // ════════════════════════════════════════════════════════════════════

    public function test_pending_company_never_gets_popup_on_pra_layout(): void
    {
        DB::table('companies')->where('id', $this->posCompanyId)
            ->update(['status' => 'pending', 'company_status' => 'pending']);

        $resp = $this->actingAs(User::find($this->posAdminId), 'pos')->get('/pos/my-profile');

        $resp->assertStatus(200);
        $resp->assertDontSee(self::T_POS);
        $resp->assertDontSee(self::T_ALL);
        $resp->assertDontSee(self::T_HIDDEN);
    }

    public function test_pending_company_never_gets_popup_on_fbr_layout(): void
    {
        DB::table('companies')->where('id', $this->fbrCompanyId)
            ->update(['status' => 'pending', 'company_status' => 'pending']);

        $resp = $this->actingAs(User::find($this->fbrAdminId), 'fbrpos')->get('/fbr-pos/my-profile');

        $resp->assertStatus(200);
        $resp->assertDontSee(self::T_FBR);
        $resp->assertDontSee(self::T_ALL);
        $resp->assertDontSee(self::T_HIDDEN);
    }

    // ════════════════════════════════════════════════════════════════════
    // 6. Read-only impersonation (View as Company) — ReadOnlyImpersonation
    //    blocks ALL POSTs incl. /whats-new/seen → skip popup/bell entirely.
    // ════════════════════════════════════════════════════════════════════

    public function test_readonly_impersonation_skips_popup_on_pra_layout(): void
    {
        // A real impersonation always has the admin's own login alive in the same
        // session — without it the flag is orphaned and gets cleared on sight.
        auth('admin')->setUser((new \App\Models\AdminUser())->forceFill(['id' => 1]));

        $resp = $this->actingAs(User::find($this->posAdminId), 'pos')
            ->withSession(['impersonation' => ['readonly' => true, 'admin_id' => 1, 'company_id' => $this->posCompanyId]])
            ->get('/pos/my-profile');

        $resp->assertStatus(200);
        $resp->assertDontSee(self::T_POS);
        $resp->assertDontSee(self::T_ALL);
    }

    public function test_readonly_impersonation_skips_popup_on_fbr_layout(): void
    {
        auth('admin')->setUser((new \App\Models\AdminUser())->forceFill(['id' => 1]));

        $resp = $this->actingAs(User::find($this->fbrAdminId), 'fbrpos')
            ->withSession(['impersonation' => ['readonly' => true, 'admin_id' => 1, 'company_id' => $this->fbrCompanyId]])
            ->get('/fbr-pos/my-profile');

        $resp->assertStatus(200);
        $resp->assertDontSee(self::T_FBR);
        $resp->assertDontSee(self::T_ALL);
    }

    public function test_full_access_impersonation_still_shows_popup(): void
    {
        // readonly=false (full "Manage as Company") — POSTs are allowed, so
        // the popup must still show; only READ-ONLY mode is skipped.
        $resp = $this->actingAs(User::find($this->posAdminId), 'pos')
            ->withSession(['impersonation' => ['readonly' => false, 'admin_id' => 1, 'company_id' => $this->posCompanyId]])
            ->get('/pos/my-profile');

        $resp->assertStatus(200);
        $resp->assertSee(self::T_POS);
        $resp->assertSee(self::T_ALL);
    }

    // ════════════════════════════════════════════════════════════════════
    // 7. 7-day live window (Task 1286) — published updates auto-disappear
    //    from the POS side 7 days after publish (read-time filter, no cron).
    //    Rows are never deleted; only visibility + markSeen are cut off.
    // ════════════════════════════════════════════════════════════════════

    private function backdate(string $title, int $days): void
    {
        DB::table('app_updates')->where('id', $this->updateId($title))
            ->update(['created_at' => now()->subDays($days)]);
    }

    public function test_eight_day_old_published_update_vanishes_from_pra_layout(): void
    {
        $this->backdate(self::T_POS, 8);

        $resp = $this->actingAs(User::find($this->posAdminId), 'pos')->get('/pos/my-profile');

        $resp->assertStatus(200);
        $resp->assertDontSee(self::T_POS);  // expired — gone from popup AND bell
        $resp->assertSee(self::T_ALL);      // fresh row still visible
    }

    public function test_eight_day_old_published_update_vanishes_from_fbr_layout(): void
    {
        $this->backdate(self::T_FBR, 8);

        $resp = $this->actingAs(User::find($this->fbrAdminId), 'fbrpos')->get('/fbr-pos/my-profile');

        $resp->assertStatus(200);
        $resp->assertDontSee(self::T_FBR);
        $resp->assertSee(self::T_ALL);
    }

    public function test_six_day_old_update_still_shows_on_pra_layout(): void
    {
        $this->backdate(self::T_POS, 6);

        $resp = $this->actingAs(User::find($this->posAdminId), 'pos')->get('/pos/my-profile');

        $resp->assertStatus(200);
        $resp->assertSee(self::T_POS); // inside the window — still live
    }

    public function test_mark_seen_skips_eight_day_old_updates(): void
    {
        $this->backdate(self::T_POS, 8);

        $this->actingAs(User::find($this->posAdminId), 'pos')
            ->post('/pos/whats-new/seen')->assertStatus(200);

        $seen = AppUpdateSeen::where('user_id', $this->posAdminId)->pluck('app_update_id')->all();
        $this->assertEqualsCanonicalizing(
            [$this->updateId(self::T_ALL)],
            $seen,
            'markSeen must only mark rows inside the 7-day live window'
        );
        $this->assertNotContains($this->updateId(self::T_POS), $seen, 'expired row must NOT be marked seen');
    }

    // ════════════════════════════════════════════════════════════════════
    // 8. Update type (Task 1286) — legacy rows without a type must default
    //    to 'improvement' (accessor) and render without badge errors.
    // ════════════════════════════════════════════════════════════════════

    public function test_legacy_row_without_type_defaults_to_improvement_and_renders(): void
    {
        $legacyTitle = 'WNTYPE-LEGACY-73kd9';
        $id = DB::table('app_updates')->insertGetId([
            // type intentionally omitted (NULL) — pre-Task-1286 legacy row
            'title' => $legacyTitle, 'points' => json_encode(['Point one']),
            'audience' => 'pos', 'is_published' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame('improvement', AppUpdate::find($id)->type, 'null type must normalize to improvement');

        $resp = $this->actingAs(User::find($this->posAdminId), 'pos')->get('/pos/my-profile');
        $resp->assertStatus(200);
        $resp->assertSee($legacyTitle); // badge rendering must not 500 on legacy rows
    }

    // ════════════════════════════════════════════════════════════════════
    // Task 1585: business-category targeting (popup, bell and mark-seen must
    // all agree, and a shop with no stored category resolves the same way the
    // POS itself resolves it).
    // ════════════════════════════════════════════════════════════════════

    private function setCategory(int $companyId, ?string $category): void
    {
        DB::table('companies')->where('id', $companyId)->update(['business_category' => $category]);
    }

    public function test_pra_restaurant_sees_restaurant_and_universal_elaans(): void
    {
        $this->setCategory($this->posCompanyId, 'restaurant');

        $resp = $this->actingAs(User::find($this->posAdminId), 'pos')->get('/pos/my-profile');

        $resp->assertStatus(200);
        $resp->assertSee(self::T_RESTAURANT);
        $resp->assertSee(self::T_ALL);
        $resp->assertDontSee(self::T_PHARMACY);
    }

    public function test_pra_salon_sees_only_universal_elaans(): void
    {
        $this->setCategory($this->posCompanyId, 'salon');

        $resp = $this->actingAs(User::find($this->posAdminId), 'pos')->get('/pos/my-profile');

        $resp->assertStatus(200);
        $resp->assertSee(self::T_ALL);
        $resp->assertDontSee(self::T_RESTAURANT);
        $resp->assertDontSee(self::T_PHARMACY);
    }

    public function test_fbr_pharmacy_sees_pharmacy_but_not_restaurant_elaan(): void
    {
        $this->setCategory($this->fbrCompanyId, 'pharmacy');

        $resp = $this->actingAs(User::find($this->fbrAdminId), 'fbrpos')->get('/fbr-pos/my-profile');

        $resp->assertStatus(200);
        $resp->assertSee(self::T_PHARMACY);
        $resp->assertSee(self::T_ALL);
        $resp->assertDontSee(self::T_RESTAURANT);
    }

    public function test_fbr_grocery_never_sees_a_pra_only_targeted_elaan(): void
    {
        $this->setCategory($this->fbrCompanyId, 'grocery');
        AppUpdate::create([
            'title' => 'WNMARK-PRA-RESTAURANT-73kd7', 'points' => ['Point one'],
            'audience' => 'pos', 'is_published' => true, 'target_categories' => ['restaurant'],
        ]);

        $resp = $this->actingAs(User::find($this->fbrAdminId), 'fbrpos')->get('/fbr-pos/my-profile');

        $resp->assertStatus(200);
        $resp->assertDontSee('WNMARK-PRA-RESTAURANT-73kd7');
        $resp->assertDontSee(self::T_PHARMACY);
    }

    public function test_mark_seen_respects_category_targeting(): void
    {
        $this->setCategory($this->posCompanyId, 'salon');

        $this->actingAs(User::find($this->posAdminId), 'pos')
            ->post('/pos/whats-new/seen')->assertStatus(200);

        $seen = AppUpdateSeen::where('user_id', $this->posAdminId)->pluck('app_update_id')->all();
        $this->assertContains($this->updateId(self::T_ALL), $seen);
        $this->assertNotContains($this->updateId(self::T_RESTAURANT), $seen,
            'a salon must not have a restaurant-targeted elaan marked seen — it never saw it');
        $this->assertNotContains($this->updateId(self::T_PHARMACY), $seen);
    }

    public function test_company_without_stored_category_resolves_like_the_pos_does(): void
    {
        // No business_category, no pos_type: PosFeatureService::resolveCategory
        // falls back to 'restaurant', so this shop must see the restaurant row.
        $this->setCategory($this->posCompanyId, null);

        $resp = $this->actingAs(User::find($this->posAdminId), 'pos')->get('/pos/my-profile');

        $resp->assertStatus(200);
        $resp->assertSee(self::T_RESTAURANT);
        $resp->assertSee(self::T_ALL);
    }

    public function test_unknown_category_keys_are_dropped_and_empty_list_means_all_shops(): void
    {
        $upd = AppUpdate::create([
            'title' => 'WNMARK-NORMALIZE-73kd8', 'points' => ['Point one'],
            'audience' => 'pos', 'is_published' => true,
            'target_categories' => ['restaurant', 'not_a_real_category', ''],
        ]);
        $this->assertSame(['restaurant'], $upd->fresh()->target_categories);

        $upd->update(['target_categories' => []]);
        $this->assertNull($upd->fresh()->target_categories, 'an empty list must store as NULL = all shops');

        $this->setCategory($this->posCompanyId, 'salon');
        $this->actingAs(User::find($this->posAdminId), 'pos')->get('/pos/my-profile')
            ->assertSee('WNMARK-NORMALIZE-73kd8');
    }

    public function test_feature_type_round_trips_and_unknown_normalizes(): void
    {
        $upd = AppUpdate::create([
            'title' => 'WNTYPE-FEATURE-73kda', 'points' => ['Point one'],
            'audience' => 'pos', 'is_published' => true, 'type' => 'feature',
        ]);
        $this->assertSame('feature', $upd->fresh()->type);

        DB::table('app_updates')->where('id', $upd->id)->update(['type' => 'garbage']);
        $this->assertSame('improvement', $upd->fresh()->type, 'unknown type values must normalize to improvement');
    }
}

<?php

namespace Tests\Feature;

use App\Models\AppUpdate;
use App\Models\AppUpdateSeen;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\ControlsSharedServiceNotice;
use Tests\TestCase;

/**
 * Featured "bara elaan" What's New popup (Task 722).
 *
 * An app_update flagged is_featured renders the popup in celebratory HERO
 * style (marker: data-wn-featured="1" — language-independent) on both the
 * PRA POS (pos-app) and FBR POS (fbr-pos-app) layouts. Invariants:
 *
 *   1. Unseen featured update → hero popup marker present (both panels).
 *   2. Only non-featured unseen updates → NORMAL popup (no hero marker).
 *   3. Featured update already seen → no popup at all (dismiss sticks).
 *   4. Cashier never sees the hero popup (same isPosAdmin gate).
 *   5. While a shared domain/Agent announcement window is live, NO What's New
 *      popup opens on either panel — the announcement is the only interruption.
 *
 * Because of (5) these tests must own the clock: they travel outside the
 * announcement window for the cases that expect a popup and inside it for the
 * suppression cases. Both instants come from App\Support\SharedServiceNotice,
 * so scheduling the next announcement can never turn this file red.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create in
 * setUp (see WhatsNewAudienceTargetingTest).
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' php vendor/bin/phpunit tests/Feature/FeaturedWhatsNewTest.php
 */
class FeaturedWhatsNewTest extends TestCase
{
    use ControlsSharedServiceNotice;

    private const HERO_MARKER = 'data-wn-featured="1"';
    /** Present in BOTH popup styles, and only when a popup is actually due. */
    private const WN_POPUP_MARKER = 'wnOpen: true';
    private const T_FEATURED_POS = 'WNFEAT-PRA-HERO-91xa1';
    private const T_FEATURED_FBR = 'WNFEAT-FBR-HERO-91xa2';
    private const T_PLAIN = 'WNFEAT-PLAIN-91xa3';

    private int $posCompanyId;
    private int $fbrCompanyId;
    private int $posAdminId;
    private int $fbrAdminId;
    private int $cashierId;

    protected function setUp(): void
    {
        parent::setUp();

        // Default for this file: no shared announcement is live, so the What's
        // New popup behaves the way every case below describes.
        $this->travelOutsideAnnouncementWindow();

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

        Schema::create('app_updates', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('points');
            $table->string('image_path')->nullable();
            $table->string('audience')->default('pos');
            $table->string('type', 20)->nullable(); // Task 1286: feature|improvement (null = legacy)
            $table->boolean('is_published')->default(true);
            $table->boolean('is_featured')->default(false);
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

    protected function tearDown(): void
    {
        // Static override + frozen clock would otherwise leak into later files.
        $this->releaseAnnouncementWindow();

        parent::tearDown();
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
            'name' => 'PRA Admin', 'email' => 'praadmin@wnf.test',
            'password' => Hash::make('Secret@12345'), 'company_id' => $this->posCompanyId,
            'role' => 'company_admin', 'pos_role' => 'pos_admin',
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->cashierId = DB::table('users')->insertGetId([
            'name' => 'PRA Cashier', 'email' => 'pracashier@wnf.test',
            'password' => Hash::make('Secret@12345'), 'company_id' => $this->posCompanyId,
            'role' => 'user', 'pos_role' => 'pos_cashier',
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->fbrAdminId = DB::table('users')->insertGetId([
            'name' => 'FBR Admin', 'email' => 'fbradmin@wnf.test',
            'password' => Hash::make('Secret@12345'), 'company_id' => $this->fbrCompanyId,
            'role' => 'company_admin', 'pos_role' => 'pos_admin',
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function makeUpdate(string $title, string $audience, bool $featured): AppUpdate
    {
        return AppUpdate::create([
            'title' => $title,
            'points' => ['Point one', 'Point two'],
            'audience' => $audience,
            'is_published' => true,
            'is_featured' => $featured,
        ]);
    }

    // ════════════════════════════════════════════════════════════════════
    // 1. Unseen featured update → HERO popup on both panels
    // ════════════════════════════════════════════════════════════════════

    public function test_featured_update_renders_hero_popup_on_pra_layout(): void
    {
        $this->makeUpdate(self::T_FEATURED_POS, 'pos', true);

        $resp = $this->actingAs(User::find($this->posAdminId), 'pos')->get('/pos/my-profile');

        $resp->assertStatus(200);
        $resp->assertSee(self::T_FEATURED_POS);
        $resp->assertSee(self::HERO_MARKER, false);
    }

    public function test_featured_update_renders_hero_popup_on_fbr_layout(): void
    {
        $this->makeUpdate(self::T_FEATURED_FBR, 'fbr_pos', true);

        $resp = $this->actingAs(User::find($this->fbrAdminId), 'fbrpos')->get('/fbr-pos/my-profile');

        $resp->assertStatus(200);
        $resp->assertSee(self::T_FEATURED_FBR);
        $resp->assertSee(self::HERO_MARKER, false);
    }

    // ════════════════════════════════════════════════════════════════════
    // 2. Non-featured updates keep the NORMAL popup (no hero marker)
    // ════════════════════════════════════════════════════════════════════

    public function test_plain_update_keeps_normal_popup_without_hero_marker(): void
    {
        $this->makeUpdate(self::T_PLAIN, 'pos', false);

        $resp = $this->actingAs(User::find($this->posAdminId), 'pos')->get('/pos/my-profile');

        $resp->assertStatus(200);
        $resp->assertSee(self::T_PLAIN);
        $resp->assertDontSee(self::HERO_MARKER, false);
    }

    // ════════════════════════════════════════════════════════════════════
    // 3. Seen featured update → no popup (second dismiss sticks)
    // ════════════════════════════════════════════════════════════════════

    public function test_seen_featured_update_shows_no_popup(): void
    {
        $upd = $this->makeUpdate(self::T_FEATURED_POS, 'pos', true);
        AppUpdateSeen::create(['app_update_id' => $upd->id, 'user_id' => $this->posAdminId]);

        $resp = $this->actingAs(User::find($this->posAdminId), 'pos')->get('/pos/my-profile');

        $resp->assertStatus(200);
        // Popup gone (hero marker absent). The title may still appear in the
        // BELL dropdown — that's by design (bell lists seen updates too).
        $resp->assertDontSee(self::HERO_MARKER, false);
    }

    // ════════════════════════════════════════════════════════════════════
    // 4. Cashier never sees the hero popup (isPosAdmin gate unchanged)
    // ════════════════════════════════════════════════════════════════════

    public function test_cashier_never_sees_featured_hero_popup(): void
    {
        $this->makeUpdate(self::T_FEATURED_POS, 'pos', true);

        $resp = $this->actingAs(User::find($this->cashierId), 'pos')->get('/pos/my-profile');

        $resp->assertStatus(200);
        $resp->assertDontSee(self::HERO_MARKER, false);
        $resp->assertDontSee(self::T_FEATURED_POS);
    }

    // ════════════════════════════════════════════════════════════════════
    // 5. Shared announcement window → every What's New popup stays closed
    //    (deliberate: the announcement must be the only interruption)
    // ════════════════════════════════════════════════════════════════════

    public function test_announcement_window_suppresses_hero_popup_on_pra_layout(): void
    {
        $this->travelInsideAnnouncementWindow();
        $this->makeUpdate(self::T_FEATURED_POS, 'pos', true);

        $resp = $this->actingAs(User::find($this->posAdminId), 'pos')->get('/pos/my-profile');

        $resp->assertStatus(200);
        $resp->assertDontSee(self::HERO_MARKER, false);
    }

    public function test_announcement_window_suppresses_hero_popup_on_fbr_layout(): void
    {
        $this->travelInsideAnnouncementWindow();
        $this->makeUpdate(self::T_FEATURED_FBR, 'fbr_pos', true);

        $resp = $this->actingAs(User::find($this->fbrAdminId), 'fbrpos')->get('/fbr-pos/my-profile');

        $resp->assertStatus(200);
        $resp->assertDontSee(self::HERO_MARKER, false);
    }

    public function test_announcement_window_suppresses_plain_popup_on_both_panels(): void
    {
        $this->travelInsideAnnouncementWindow();
        $this->makeUpdate(self::T_PLAIN, 'all', false);

        $pra = $this->actingAs(User::find($this->posAdminId), 'pos')->get('/pos/my-profile');
        $pra->assertStatus(200);
        $pra->assertDontSee(self::WN_POPUP_MARKER, false);

        $fbr = $this->actingAs(User::find($this->fbrAdminId), 'fbrpos')->get('/fbr-pos/my-profile');
        $fbr->assertStatus(200);
        $fbr->assertDontSee(self::WN_POPUP_MARKER, false);
    }

    /**
     * The suppression is a pause, not a dismissal: the same unseen update
     * opens its popup again as soon as the announcement window has passed.
     */
    public function test_popup_returns_once_the_announcement_window_has_passed(): void
    {
        $this->travelInsideAnnouncementWindow();
        $this->makeUpdate(self::T_FEATURED_POS, 'pos', true);

        $during = $this->actingAs(User::find($this->posAdminId), 'pos')->get('/pos/my-profile');
        $during->assertDontSee(self::HERO_MARKER, false);

        $this->travelOutsideAnnouncementWindow();

        $after = $this->actingAs(User::find($this->posAdminId), 'pos')->get('/pos/my-profile');
        $after->assertStatus(200);
        $after->assertSee(self::HERO_MARKER, false);
    }
}

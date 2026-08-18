<?php

namespace Tests\Feature;

use App\Models\FeatureSuggestion;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * PRA provisional-billing elaan popup gating (Task 1202 / guarded by Task 1206).
 *
 * Invariants:
 *   1. Popup marker (data-pra-elaan-popup) renders ONLY for PRA POS admins/managers
 *      with no pending What's New / survey popups and an unseen elaan.
 *   2. Never renders for: cashier, confined roles (waiter), pending company,
 *      or after users.pra_elaan_seen_at is set.
 *   3. FBR POS companies do not use this layout (pos-app.blade.php is PRA only);
 *      /fbr-pos/ routes carry no elaan block — this is an architectural boundary,
 *      not a per-request gate.
 *   4. POST /pos/pra-elaan/respond → creates exactly one feature_suggestions row
 *      (source='pra_elaan') per user even on repeat posts, and stamps seen_at.
 *   5. POST /pos/pra-elaan/dismiss → stamps seen_at without creating a row.
 *   6. Bad choice → 422; cashier POST → 403.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create in setUp
 * (identical convention to PosSurveyTest / WhatsNewAudienceTargetingTest).
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' php vendor/bin/phpunit tests/Feature/PraElaanPopupTest.php
 */
class PraElaanPopupTest extends TestCase
{
    /** Unique marker so grep-assertions never match other content. */
    private const MARKER = 'data-pra-elaan-popup';

    private int $companyId;
    private int $pendingCompanyId;
    private int $adminId;
    private int $managerId;
    private int $cashierId;
    private int $waiterId;
    private int $pendingAdminId;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        // ── companies ────────────────────────────────────────────────────────
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

        // ── users (includes pra_elaan_seen_at for this feature) ─────────────
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
            $table->string('pos_personal_style')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('dark_mode')->default(false);
            $table->string('language')->nullable();
            $table->unsignedBigInteger('default_branch_id')->nullable();
            $table->timestamp('pra_elaan_seen_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        // ── support tables required by the layout / auth guards ─────────────
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

        // ── feature_suggestions: the elaan respond route writes here ─────────
        Schema::create('feature_suggestions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('product')->nullable();
            $table->string('title', 300);
            $table->text('details')->nullable();
            $table->string('status')->default('pending');
            $table->text('admin_note')->nullable();
            $table->string('source')->nullable();   // 'pra_elaan' for elaan rows
            $table->timestamps();
        });

        // NOTE: we intentionally do NOT create app_updates or surveys tables.
        // The layout's What's New and Survey blocks are protected by
        // Schema::hasTable() guards and will leave $whatsNewPopup/$surveyPopup
        // null — so the elaan popup can render without interference.

        $this->seedFixtures();
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function seedFixtures(): void
    {
        $now = now();

        $this->companyId = DB::table('companies')->insertGetId([
            'name' => 'PRA Shop', 'product_type' => 'pos',
            'status' => 'approved', 'company_status' => 'approved',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $this->pendingCompanyId = DB::table('companies')->insertGetId([
            'name' => 'Pending Shop', 'product_type' => 'pos',
            'status' => 'pending', 'company_status' => 'pending',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $mk = fn (string $email, int $companyId, string $role, string $posRole) =>
            DB::table('users')->insertGetId([
                'name'       => ucfirst(explode('@', $email)[0]),
                'email'      => $email,
                'password'   => Hash::make('Secret@12345'),
                'company_id' => $companyId,
                'role'       => $role,
                'pos_role'   => $posRole,
                'is_active'  => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        $this->adminId         = $mk('pe-admin@pe.test',   $this->companyId,        'company_admin', 'pos_admin');
        $this->managerId       = $mk('pe-mgr@pe.test',     $this->companyId,        'user',          'pos_manager');
        $this->cashierId       = $mk('pe-cash@pe.test',    $this->companyId,        'user',          'pos_cashier');
        $this->waiterId        = $mk('pe-waiter@pe.test',  $this->companyId,        'user',          'pos_waiter');
        $this->pendingAdminId  = $mk('pe-pend@pe.test',    $this->pendingCompanyId, 'company_admin', 'pos_admin');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1. Rendering + role gating
    // ─────────────────────────────────────────────────────────────────────────

    public function test_popup_renders_for_pra_admin(): void
    {
        $resp = $this->actingAs(User::find($this->adminId), 'pos')
            ->get('/pos/my-profile');
        $resp->assertStatus(200);
        $resp->assertSee(self::MARKER, false);
    }

    public function test_popup_renders_for_pos_manager(): void
    {
        $resp = $this->actingAs(User::find($this->managerId), 'pos')
            ->get('/pos/my-profile');
        $resp->assertStatus(200);
        $resp->assertSee(self::MARKER, false);
    }

    public function test_popup_never_renders_for_cashier(): void
    {
        $resp = $this->actingAs(User::find($this->cashierId), 'pos')
            ->get('/pos/my-profile');
        $resp->assertStatus(200);
        $resp->assertDontSee(self::MARKER, false);
    }

    public function test_popup_never_renders_for_confined_waiter(): void
    {
        // Waiters are redirected to their confined home by PosAuth middleware
        // before the layout even renders — the marker must not appear in any
        // response body (redirect or rendered page).
        $resp = $this->actingAs(User::find($this->waiterId), 'pos')
            ->get('/pos/my-profile');
        $this->assertStringNotContainsString(self::MARKER, $resp->getContent());
    }

    public function test_popup_never_renders_for_pending_company(): void
    {
        $resp = $this->actingAs(User::find($this->pendingAdminId), 'pos')
            ->get('/pos/my-profile');
        // Pending companies may be redirected by approval middleware or render
        // a restricted page — either way the elaan popup must not appear.
        $this->assertStringNotContainsString(self::MARKER, $resp->getContent());
    }

    public function test_popup_never_renders_after_seen_at_stamped(): void
    {
        // Simulate the user having already dismissed or responded.
        DB::table('users')
            ->where('id', $this->adminId)
            ->update(['pra_elaan_seen_at' => now()]);

        $resp = $this->actingAs(User::find($this->adminId), 'pos')
            ->get('/pos/my-profile');
        $resp->assertStatus(200);
        $resp->assertDontSee(self::MARKER, false);
    }

    public function test_popup_never_renders_when_master_switch_is_off(): void
    {
        \App\Models\SystemSetting::set('pos_pra_elaan_enabled', '0', 'test');

        $resp = $this->actingAs(User::find($this->adminId), 'pos')
            ->get('/pos/my-profile');
        $resp->assertStatus(200);
        $resp->assertDontSee(self::MARKER, false);
    }

    public function test_popup_never_renders_for_readonly_impersonation(): void
    {
        $resp = $this->actingAs(User::find($this->adminId), 'pos')
            ->withSession(['impersonation' => ['readonly' => true, 'admin_id' => 1]])
            ->get('/pos/my-profile');
        $resp->assertStatus(200);
        $resp->assertDontSee(self::MARKER, false);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. Cashier cannot POST (route-level gating)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_cashier_respond_post_is_403(): void
    {
        $resp = $this->actingAs(User::find($this->cashierId), 'pos')
            ->postJson('/pos/pra-elaan/respond', ['choice' => 'band']);
        $resp->assertStatus(403);
        $this->assertSame(0, FeatureSuggestion::count());
    }

    public function test_cashier_dismiss_post_is_403(): void
    {
        $resp = $this->actingAs(User::find($this->cashierId), 'pos')
            ->postJson('/pos/pra-elaan/dismiss');
        $resp->assertStatus(403);
    }

    public function test_waiter_respond_post_is_blocked(): void
    {
        // Waiters are redirected by PosAuth middleware before the controller
        // runs — accept any non-2xx outcome (302 redirect or 403) as long as
        // no feature_suggestion row is created.
        $resp = $this->actingAs(User::find($this->waiterId), 'pos')
            ->postJson('/pos/pra-elaan/respond', ['choice' => 'band']);
        $this->assertNotSame(200, $resp->getStatusCode());
        $this->assertSame(0, FeatureSuggestion::count());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. respond: one row per user, seen_at stamped
    // ─────────────────────────────────────────────────────────────────────────

    public function test_respond_creates_one_row_and_stamps_seen(): void
    {
        $resp = $this->actingAs(User::find($this->adminId), 'pos')
            ->postJson('/pos/pra-elaan/respond', ['choice' => 'band']);
        $resp->assertStatus(200)->assertJson(['ok' => true]);

        // Exactly one feature_suggestion row with source='pra_elaan'.
        $this->assertSame(1, FeatureSuggestion::where('source', FeatureSuggestion::PRA_ELAAN_SOURCE)->count());

        $row = FeatureSuggestion::where('source', FeatureSuggestion::PRA_ELAAN_SOURCE)->first();
        $this->assertSame((int) $this->companyId, (int) $row->company_id);
        $this->assertSame((int) $this->adminId, (int) $row->user_id);
        $this->assertSame('pos', $row->product);
        $this->assertSame(FeatureSuggestion::PRA_ELAAN_CHOICES['band'], $row->title);

        // pra_elaan_seen_at must be stamped on the user row.
        $user = User::find($this->adminId);
        $this->assertNotNull($user->pra_elaan_seen_at);
    }

    public function test_respond_with_mashwara_stores_details(): void
    {
        $resp = $this->actingAs(User::find($this->adminId), 'pos')
            ->postJson('/pos/pra-elaan/respond', [
                'choice'   => 'aur',
                'mashwara' => 'Mera mashwara yeh hai.',
            ]);
        $resp->assertStatus(200)->assertJson(['ok' => true]);

        $row = FeatureSuggestion::where('source', FeatureSuggestion::PRA_ELAAN_SOURCE)->first();
        $this->assertSame('Mera mashwara yeh hai.', $row->details);
    }

    public function test_second_respond_never_creates_a_duplicate_row(): void
    {
        // First submission.
        $this->actingAs(User::find($this->adminId), 'pos')
            ->postJson('/pos/pra-elaan/respond', ['choice' => 'band'])
            ->assertStatus(200);

        // Second submission with a different choice — must NOT duplicate the row.
        $again = $this->actingAs(User::find($this->adminId), 'pos')
            ->postJson('/pos/pra-elaan/respond', ['choice' => 'jari']);
        $again->assertStatus(200)->assertJson(['ok' => true]);

        // Still exactly one row; the original choice is kept.
        $rows = FeatureSuggestion::where('source', FeatureSuggestion::PRA_ELAAN_SOURCE)->get();
        $this->assertCount(1, $rows);
        $this->assertSame(FeatureSuggestion::PRA_ELAAN_CHOICES['band'], $rows->first()->title);
    }

    public function test_popup_gone_after_responding(): void
    {
        $this->actingAs(User::find($this->adminId), 'pos')
            ->postJson('/pos/pra-elaan/respond', ['choice' => 'jari'])
            ->assertStatus(200);

        $page = $this->actingAs(User::find($this->adminId), 'pos')
            ->get('/pos/my-profile');
        $page->assertStatus(200);
        $page->assertDontSee(self::MARKER, false);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4. dismiss: stamps seen_at WITHOUT creating a feature_suggestion row
    // ─────────────────────────────────────────────────────────────────────────

    public function test_dismiss_stamps_seen_at_without_creating_a_row(): void
    {
        $resp = $this->actingAs(User::find($this->adminId), 'pos')
            ->postJson('/pos/pra-elaan/dismiss');
        $resp->assertStatus(200)->assertJson(['ok' => true]);

        // No feature_suggestion rows — dismiss is "Baad mein", not an answer.
        $this->assertSame(0, FeatureSuggestion::count());

        // But the seen_at timestamp is set so the popup never reappears.
        $user = User::find($this->adminId);
        $this->assertNotNull($user->pra_elaan_seen_at);
    }

    public function test_popup_gone_after_dismiss(): void
    {
        $this->actingAs(User::find($this->adminId), 'pos')
            ->postJson('/pos/pra-elaan/dismiss')
            ->assertStatus(200);

        $page = $this->actingAs(User::find($this->adminId), 'pos')
            ->get('/pos/my-profile');
        $page->assertStatus(200);
        $page->assertDontSee(self::MARKER, false);
    }

    public function test_manager_dismiss_stamps_seen_at(): void
    {
        $resp = $this->actingAs(User::find($this->managerId), 'pos')
            ->postJson('/pos/pra-elaan/dismiss');
        $resp->assertStatus(200)->assertJson(['ok' => true]);

        $this->assertNotNull(User::find($this->managerId)->pra_elaan_seen_at);
        $this->assertSame(0, FeatureSuggestion::count());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 5. Bad-input validation
    // ─────────────────────────────────────────────────────────────────────────

    public function test_invalid_choice_returns_422(): void
    {
        $resp = $this->actingAs(User::find($this->adminId), 'pos')
            ->postJson('/pos/pra-elaan/respond', ['choice' => 'not-a-valid-choice']);
        $resp->assertStatus(422);
        $this->assertSame(0, FeatureSuggestion::count());
    }

    public function test_missing_choice_returns_422(): void
    {
        $resp = $this->actingAs(User::find($this->adminId), 'pos')
            ->postJson('/pos/pra-elaan/respond', []);
        $resp->assertStatus(422);
        $this->assertSame(0, FeatureSuggestion::count());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 6. What's New suppression — no two full-screen overlays at once (Task 1207)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Helper: create the app_updates + app_update_seens tables so the layout's
     * Schema::hasTable('app_updates') guard passes.  Safe to call twice — each
     * test runs in a fresh in-memory SQLite DB (setUp drops all tables first).
     */
    private function createAppUpdatesTables(): void
    {
        Schema::create('app_updates', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->json('points')->nullable();
            $table->string('image_path')->nullable();
            $table->string('audience')->default('all');
            $table->boolean('is_published')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('app_update_seens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('app_update_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamps();
        });
    }

    public function test_elaan_hidden_when_whats_new_popup_is_pending(): void
    {
        $this->createAppUpdatesTables();

        // Ensure the What's New master switch is on (default, but be explicit).
        \App\Models\SystemSetting::set('pos_whats_new_enabled', '1', 'test');

        // Insert an unseen published AppUpdate aimed at the POS audience.
        $updateId = DB::table('app_updates')->insertGetId([
            'title'        => 'New POS feature',
            'points'       => json_encode(['Check it out']),
            'audience'     => 'pos',
            'is_published' => true,
            'is_featured'  => false,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        // Admin has NOT seen the update → $whatsNewPopup is set in the layout.
        // The elaan condition $praElaanShow && !$whatsNewPopup must evaluate to
        // false, so data-pra-elaan-popup must NOT appear.
        $resp = $this->actingAs(User::find($this->adminId), 'pos')
            ->get('/pos/my-profile');

        $resp->assertStatus(200);
        $resp->assertDontSee(self::MARKER, false);
    }

    public function test_elaan_appears_once_whats_new_is_marked_seen(): void
    {
        $this->createAppUpdatesTables();

        \App\Models\SystemSetting::set('pos_whats_new_enabled', '1', 'test');

        $updateId = DB::table('app_updates')->insertGetId([
            'title'        => 'New POS feature',
            'points'       => json_encode(['Check it out']),
            'audience'     => 'pos',
            'is_published' => true,
            'is_featured'  => false,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        // Sanity: elaan is hidden while the update is unseen.
        $hidden = $this->actingAs(User::find($this->adminId), 'pos')
            ->get('/pos/my-profile');
        $hidden->assertStatus(200);
        $hidden->assertDontSee(self::MARKER, false);

        // Mark the update as seen for this admin → $whatsNewPopup becomes null.
        DB::table('app_update_seens')->insert([
            'app_update_id' => $updateId,
            'user_id'       => $this->adminId,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        // Now the elaan popup should be free to render.
        $visible = $this->actingAs(User::find($this->adminId), 'pos')
            ->get('/pos/my-profile');
        $visible->assertStatus(200);
        $visible->assertSee(self::MARKER, false);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 7. Survey suppression — elaan must not compete with an active survey
    //    (blade gate line 1511: $praElaanShow && !$whatsNewPopup &&
    //     !($surveyPopup && !$surveyDismissedSession))
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Create the surveys + survey_responses tables so the layout's
     * Schema::hasTable('surveys') guard passes.  Safe to call once per test —
     * each test gets a fresh in-memory SQLite DB (setUp drops all tables first).
     */
    private function createSurveyTables(): void
    {
        Schema::create('surveys', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('intro')->nullable();
            $table->json('questions')->nullable();
            $table->boolean('allow_comment')->default(false);
            $table->string('audience')->default('pos_all');
            $table->boolean('is_published')->default(true);
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('survey_responses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('survey_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->json('answers')->nullable();
            $table->text('comment')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Active survey present, admin has no SurveyResponse → elaan must be hidden.
     *
     * Blade condition: $surveyPopup && !$surveyDismissedSession is TRUE
     *   ⇒ the outer AND is FALSE ⇒ data-pra-elaan-popup must NOT appear.
     */
    public function test_elaan_hidden_when_active_survey_is_showing(): void
    {
        $this->createSurveyTables();

        // Ensure the surveys master switch is on (default, but be explicit).
        \App\Models\SystemSetting::set('pos_surveys_enabled', '1', 'test');

        // Insert an active, published survey targeting all POS companies.
        $surveyId = DB::table('surveys')->insertGetId([
            'title'        => 'Caller ID Survey',
            'audience'     => 'pos_all',
            'is_published' => true,
            'closed_at'    => null,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        // No SurveyResponse row for the admin — survey is unanswered.
        // No session dismissal key either.

        $resp = $this->actingAs(User::find($this->adminId), 'pos')
            ->get('/pos/my-profile');

        $resp->assertStatus(200);
        // The survey popup is active and not session-dismissed → elaan suppressed.
        $resp->assertDontSee(self::MARKER, false);
    }

    /**
     * Same active survey, but the admin chose "Baad mein" (session dismiss) →
     * $surveyDismissedSession is TRUE so the outer condition becomes FALSE →
     * elaan is free to render.
     */
    public function test_elaan_appears_when_survey_is_dismissed_for_session(): void
    {
        $this->createSurveyTables();

        \App\Models\SystemSetting::set('pos_surveys_enabled', '1', 'test');

        $surveyId = DB::table('surveys')->insertGetId([
            'title'        => 'Caller ID Survey',
            'audience'     => 'pos_all',
            'is_published' => true,
            'closed_at'    => null,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        // Simulate "Baad mein": the layout reads session('pos_survey_dismissed_<id>').
        $resp = $this->actingAs(User::find($this->adminId), 'pos')
            ->withSession(['pos_survey_dismissed_' . $surveyId => true])
            ->get('/pos/my-profile');

        $resp->assertStatus(200);
        // Survey is dismissed for this session → elaan is no longer suppressed.
        $resp->assertSee(self::MARKER, false);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 8. Two users in same company each get their own row
    // ─────────────────────────────────────────────────────────────────────────

    public function test_admin_and_manager_each_get_their_own_row(): void
    {
        $this->actingAs(User::find($this->adminId), 'pos')
            ->postJson('/pos/pra-elaan/respond', ['choice' => 'band'])
            ->assertStatus(200);

        $this->actingAs(User::find($this->managerId), 'pos')
            ->postJson('/pos/pra-elaan/respond', ['choice' => 'jari'])
            ->assertStatus(200);

        $rows = FeatureSuggestion::where('source', FeatureSuggestion::PRA_ELAAN_SOURCE)->get();
        $this->assertCount(2, $rows);
        $this->assertSame(
            FeatureSuggestion::PRA_ELAAN_CHOICES['band'],
            $rows->firstWhere('user_id', $this->adminId)->title
        );
        $this->assertSame(
            FeatureSuggestion::PRA_ELAAN_CHOICES['jari'],
            $rows->firstWhere('user_id', $this->managerId)->title
        );
    }
}

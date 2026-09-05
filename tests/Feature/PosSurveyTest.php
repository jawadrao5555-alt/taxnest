<?php

namespace Tests\Feature;

use App\Models\Survey;
use App\Models\SurveyResponse;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * POS survey popup (Task 1022 — Caller ID elaan / advice collection).
 *
 * Invariants:
 *   1. Popup renders ONLY for POS admins/managers — cashiers never see it
 *      and their POSTs are 403.
 *   2. Pending companies and read-only impersonation never get the popup
 *      (no dismiss loop possible).
 *   3. One response per user — a second submit never overwrites the first.
 *   4. Answers are validated against the survey's OWN options (bad option /
 *      missing question = 422).
 *   5. Closing the survey hides the popup and rejects new responses, but
 *      keeps existing results.
 *   6. Audience 'pos_restaurant' shows only to restaurant-mode companies.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create in
 * setUp (see WhatsNewAudienceTargetingTest).
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' php vendor/bin/phpunit tests/Feature/PosSurveyTest.php
 */
class PosSurveyTest extends TestCase
{
    private const TITLE = 'SVMARK-CALLERID-91xa1';

    private int $companyId;
    private int $restCompanyId;
    private int $pendingCompanyId;
    private int $adminId;
    private int $restAdminId;
    private int $pendingAdminId;
    private int $cashierId;
    private int $surveyId;

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

        // The tables under test.
        Schema::create('surveys', function (Blueprint $table) {
            $table->id();
            $table->string('title', 150);
            $table->text('intro')->nullable();
            $table->text('questions');
            $table->boolean('allow_comment')->default(true);
            $table->string('audience', 30)->default('pos_all');
            $table->boolean('is_published')->default(false);
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('survey_responses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('survey_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('company_id');
            $table->text('answers')->nullable();
            $table->text('comment')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();
            $table->unique(['survey_id', 'user_id']);
        });

        $this->seedFixtures();
    }

    private function seedFixtures(): void
    {
        $now = now();

        $this->companyId = DB::table('companies')->insertGetId([
            'name' => 'PRA Shop', 'product_type' => 'pos',
            'status' => 'approved', 'company_status' => 'approved',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->restCompanyId = DB::table('companies')->insertGetId([
            'name' => 'PRA Restaurant', 'product_type' => 'pos', 'restaurant_mode' => true,
            'status' => 'approved', 'company_status' => 'approved',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->pendingCompanyId = DB::table('companies')->insertGetId([
            'name' => 'Pending Shop', 'product_type' => 'pos',
            'status' => 'pending', 'company_status' => 'pending',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $mk = fn (string $email, int $companyId, string $role, string $posRole) => DB::table('users')->insertGetId([
            'name' => ucfirst(explode('@', $email)[0]), 'email' => $email,
            'password' => Hash::make('Secret@12345'), 'company_id' => $companyId,
            'role' => $role, 'pos_role' => $posRole,
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->adminId = $mk('svadmin@sv.test', $this->companyId, 'company_admin', 'pos_admin');
        $this->restAdminId = $mk('svrest@sv.test', $this->restCompanyId, 'company_admin', 'pos_admin');
        $this->pendingAdminId = $mk('svpend@sv.test', $this->pendingCompanyId, 'company_admin', 'pos_admin');
        $this->cashierId = $mk('svcash@sv.test', $this->companyId, 'user', 'pos_cashier');

        $this->surveyId = Survey::create([
            'title' => self::TITLE,
            'intro' => 'Intro pitch',
            'questions' => [
                ['key' => 'q1', 'text' => 'Sawal aik?', 'options' => [
                    ['key' => 'haan', 'label' => 'Haan'], ['key' => 'nahi', 'label' => 'Nahi'],
                ]],
                ['key' => 'q2', 'text' => 'Sawal do?', 'options' => [
                    ['key' => 'sim', 'label' => 'SIM'], ['key' => 'whatsapp', 'label' => 'WhatsApp'],
                ]],
            ],
            'allow_comment' => true,
            'audience' => 'pos_all',
            'is_published' => true,
        ])->id;
    }

    private function goodAnswers(): array
    {
        return ['answers' => ['q1' => 'haan', 'q2' => 'sim'], 'comment' => 'Mera mashwara'];
    }

    // ── 1. Rendering + role gating ──────────────────────────────────────

    public function test_popup_renders_for_pos_admin(): void
    {
        $resp = $this->actingAs(User::find($this->adminId), 'pos')->get('/pos/my-profile');
        $resp->assertStatus(200);
        $resp->assertSee(self::TITLE);
        $resp->assertSee('data-pos-survey', false);
    }

    public function test_popup_never_renders_for_cashier(): void
    {
        $resp = $this->actingAs(User::find($this->cashierId), 'pos')->get('/pos/my-profile');
        $resp->assertStatus(200);
        $resp->assertDontSee(self::TITLE);
    }

    public function test_cashier_post_is_forbidden(): void
    {
        $resp = $this->actingAs(User::find($this->cashierId), 'pos')
            ->postJson("/pos/survey/{$this->surveyId}/respond", $this->goodAnswers());
        $resp->assertStatus(403);
        $this->assertSame(0, SurveyResponse::count());
    }

    public function test_pending_company_never_gets_popup(): void
    {
        $resp = $this->actingAs(User::find($this->pendingAdminId), 'pos')->get('/pos/my-profile');
        // Pending companies may be redirected by approval middleware or render
        // a restricted page — either way the survey must not appear.
        $this->assertStringNotContainsString(self::TITLE, $resp->getContent());
    }

    public function test_readonly_impersonation_never_gets_popup(): void
    {
        // A real impersonation always has the admin's own login alive in the same
        // session — without it the flag is orphaned and gets cleared on sight.
        auth('admin')->setUser((new \App\Models\AdminUser())->forceFill(['id' => 1]));

        $resp = $this->actingAs(User::find($this->adminId), 'pos')
            ->withSession(['impersonation' => ['readonly' => true, 'admin_id' => 1]])
            ->get('/pos/my-profile');
        $resp->assertStatus(200);
        $resp->assertDontSee(self::TITLE);
    }

    public function test_master_switch_off_hides_popup(): void
    {
        \App\Models\SystemSetting::set('pos_surveys_enabled', '0', 'test');
        $resp = $this->actingAs(User::find($this->adminId), 'pos')->get('/pos/my-profile');
        $resp->assertStatus(200);
        $resp->assertDontSee(self::TITLE);
    }

    // ── 2. Audience targeting ───────────────────────────────────────────

    public function test_restaurant_audience_hidden_from_non_restaurant_company(): void
    {
        Survey::where('id', $this->surveyId)->update(['audience' => 'pos_restaurant']);

        $nonRest = $this->actingAs(User::find($this->adminId), 'pos')->get('/pos/my-profile');
        $nonRest->assertDontSee(self::TITLE);

        $rest = $this->actingAs(User::find($this->restAdminId), 'pos')->get('/pos/my-profile');
        $rest->assertSee(self::TITLE);
    }

    public function test_restaurant_audience_direct_post_bypass_rejected(): void
    {
        Survey::where('id', $this->surveyId)->update(['audience' => 'pos_restaurant']);

        // Non-restaurant admin POSTing directly (popup never rendered for them)
        // must be refused — Blade filtering is not the authorization control.
        $resp = $this->actingAs(User::find($this->adminId), 'pos')
            ->postJson("/pos/survey/{$this->surveyId}/respond", $this->goodAnswers());
        $resp->assertStatus(404);

        $dismiss = $this->actingAs(User::find($this->adminId), 'pos')
            ->postJson("/pos/survey/{$this->surveyId}/dismiss");
        $dismiss->assertStatus(200); // soft ok — but no seen row recorded
        $this->assertSame(0, SurveyResponse::count());

        // Restaurant admin still goes through.
        $this->actingAs(User::find($this->restAdminId), 'pos')
            ->postJson("/pos/survey/{$this->surveyId}/respond", $this->goodAnswers())
            ->assertStatus(200)->assertJson(['ok' => true]);
        $this->assertSame(1, SurveyResponse::whereNotNull('answered_at')->count());
    }

    // ── 3. Submitting + one-response enforcement ────────────────────────

    public function test_admin_can_submit_once_and_popup_disappears(): void
    {
        $resp = $this->actingAs(User::find($this->adminId), 'pos')
            ->postJson("/pos/survey/{$this->surveyId}/respond", $this->goodAnswers());
        $resp->assertStatus(200)->assertJson(['ok' => true]);

        $row = SurveyResponse::where('survey_id', $this->surveyId)->where('user_id', $this->adminId)->first();
        $this->assertNotNull($row->answered_at);
        $this->assertSame(['q1' => 'haan', 'q2' => 'sim'], $row->answers);
        $this->assertSame('Mera mashwara', $row->comment);
        $this->assertSame($this->companyId, (int) $row->company_id);

        // Popup gone after answering.
        $page = $this->actingAs(User::find($this->adminId), 'pos')->get('/pos/my-profile');
        $page->assertDontSee(self::TITLE);
    }

    public function test_second_submit_never_overwrites_first(): void
    {
        $this->actingAs(User::find($this->adminId), 'pos')
            ->postJson("/pos/survey/{$this->surveyId}/respond", $this->goodAnswers())->assertStatus(200);

        $again = $this->actingAs(User::find($this->adminId), 'pos')
            ->postJson("/pos/survey/{$this->surveyId}/respond", [
                'answers' => ['q1' => 'nahi', 'q2' => 'whatsapp'], 'comment' => 'Dobara',
            ]);
        $again->assertStatus(200)->assertJson(['ok' => true, 'already' => true]);

        $row = SurveyResponse::where('survey_id', $this->surveyId)->where('user_id', $this->adminId)->first();
        $this->assertSame(['q1' => 'haan', 'q2' => 'sim'], $row->answers);
        $this->assertSame('Mera mashwara', $row->comment);
        $this->assertSame(1, SurveyResponse::count());
    }

    public function test_first_write_wins_on_previously_dismissed_row(): void
    {
        // Dismiss first — creates the unanswered (survey,user) row, the exact
        // path where a naive updateOrCreate would let a later submit overwrite.
        $this->actingAs(User::find($this->adminId), 'pos')
            ->postJson("/pos/survey/{$this->surveyId}/dismiss")->assertStatus(200);

        $this->actingAs(User::find($this->adminId), 'pos')
            ->postJson("/pos/survey/{$this->surveyId}/respond", $this->goodAnswers())
            ->assertStatus(200)->assertJson(['ok' => true]);

        // Second submit against the now-answered row loses the conditional write.
        $again = $this->actingAs(User::find($this->adminId), 'pos')
            ->postJson("/pos/survey/{$this->surveyId}/respond", [
                'answers' => ['q1' => 'nahi', 'q2' => 'whatsapp'], 'comment' => 'Overwrite attempt',
            ]);
        $again->assertStatus(200)->assertJson(['ok' => true, 'already' => true]);

        $row = SurveyResponse::where('survey_id', $this->surveyId)->where('user_id', $this->adminId)->first();
        $this->assertSame(['q1' => 'haan', 'q2' => 'sim'], $row->answers);
        $this->assertSame('Mera mashwara', $row->comment);
        $this->assertSame(1, SurveyResponse::count());
    }

    // ── 4. Option validation ────────────────────────────────────────────

    public function test_invalid_option_rejected(): void
    {
        $resp = $this->actingAs(User::find($this->adminId), 'pos')
            ->postJson("/pos/survey/{$this->surveyId}/respond", [
                'answers' => ['q1' => 'haan', 'q2' => 'not-an-option'],
            ]);
        $resp->assertStatus(422);
        $this->assertSame(0, SurveyResponse::count());
    }

    public function test_missing_question_rejected(): void
    {
        $resp = $this->actingAs(User::find($this->adminId), 'pos')
            ->postJson("/pos/survey/{$this->surveyId}/respond", [
                'answers' => ['q1' => 'haan'],
            ]);
        $resp->assertStatus(422);
        $this->assertSame(0, SurveyResponse::count());
    }

    // ── 5. Dismiss + close ──────────────────────────────────────────────

    public function test_dismiss_records_seen_row_and_hides_popup_for_session(): void
    {
        $this->actingAs(User::find($this->adminId), 'pos')
            ->postJson("/pos/survey/{$this->surveyId}/dismiss")->assertStatus(200);

        $row = SurveyResponse::where('survey_id', $this->surveyId)->where('user_id', $this->adminId)->first();
        $this->assertNotNull($row);
        $this->assertNull($row->answered_at);

        // Same session: popup markup renders closed (svOpen false) but the pill stays.
        $page = $this->actingAs(User::find($this->adminId), 'pos')
            ->withSession(['pos_survey_dismissed_' . $this->surveyId => true])
            ->get('/pos/my-profile');
        $page->assertSee('svOpen: false', false);
        $page->assertSee(self::TITLE); // pill + popup markup still present until answered
    }

    public function test_closed_survey_hides_popup_and_rejects_responses_but_keeps_results(): void
    {
        // First user answers while live.
        $this->actingAs(User::find($this->restAdminId), 'pos')
            ->postJson("/pos/survey/{$this->surveyId}/respond", $this->goodAnswers())->assertStatus(200);

        Survey::where('id', $this->surveyId)->update(['closed_at' => now()]);

        $page = $this->actingAs(User::find($this->adminId), 'pos')->get('/pos/my-profile');
        $page->assertDontSee(self::TITLE);

        $resp = $this->actingAs(User::find($this->adminId), 'pos')
            ->postJson("/pos/survey/{$this->surveyId}/respond", $this->goodAnswers());
        $resp->assertStatus(404);

        // Existing results untouched.
        $this->assertSame(1, SurveyResponse::whereNotNull('answered_at')->count());
    }
}

<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\FeatureSuggestion;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Admin elaan tally accuracy for multi-user companies (Task 1208).
 *
 * Invariants:
 *   1. Two users from the SAME company each respond with DIFFERENT choices →
 *      tally.total = 2, tally.companies = 1, per-choice counts correct.
 *   2. A user from a SECOND company also responds →
 *      tally.companies = 2, tally.total = 3.
 *   3. GET /admin/feature-suggestions returns 200 and the summary line
 *      ("N responses from M companies") is embedded in the response body.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create in
 * setUp (identical convention to AdminSurveyTest / PraElaanPopupTest).
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' php vendor/bin/phpunit tests/Feature/PraElaanTallyTest.php
 */
class PraElaanTallyTest extends TestCase
{
    private int $adminUserId;
    private int $companyAId;
    private int $companyBId;
    private int $userA1Id; // admin in company A
    private int $userA2Id; // manager in company A
    private int $userBId;  // admin in company B

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        // ── admin_users ───────────────────────────────────────────────────────
        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role')->default('super_admin');
            $table->rememberToken();
            $table->timestamps();
        });

        // ── admin_audit_logs (admin layout logs impersonation actions) ────────
        Schema::create('admin_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_user_id')->nullable();
            $table->string('action');
            $table->string('entity_type')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->text('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
        });

        // ── companies ─────────────────────────────────────────────────────────
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->nullable();
            $table->string('status')->default('approved');
            $table->string('company_status')->default('approved');
            $table->boolean('restaurant_mode')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });

        // ── users (POS side) ──────────────────────────────────────────────────
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

        // ── system_settings (layout hasTable-guarded reads) ───────────────────
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        // ── feature_suggestions ───────────────────────────────────────────────
        Schema::create('feature_suggestions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('product')->nullable();
            $table->string('title', 300);
            $table->text('details')->nullable();
            $table->string('status')->default('pending');
            $table->text('admin_note')->nullable();
            $table->string('source')->nullable(); // 'pra_elaan' for elaan rows
            $table->timestamps();
        });

        // NOTE: payment_proofs and app_updates/surveys are intentionally absent;
        // the admin layout guards those blocks with Schema::hasTable() checks.

        $this->seedFixtures();
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function actingAsAdmin(): self
    {
        return $this->actingAs(AdminUser::find($this->adminUserId), 'admin');
    }

    private function seedFixtures(): void
    {
        $now = now();

        $this->adminUserId = DB::table('admin_users')->insertGetId([
            'name'       => 'Tally Admin',
            'email'      => 'tally-admin@taxnest.test',
            'password'   => Hash::make('Admin@12345'),
            'role'       => 'super_admin',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->companyAId = DB::table('companies')->insertGetId([
            'name'           => 'Company Alpha',
            'product_type'   => 'pos',
            'status'         => 'approved',
            'company_status' => 'approved',
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        $this->companyBId = DB::table('companies')->insertGetId([
            'name'           => 'Company Beta',
            'product_type'   => 'pos',
            'status'         => 'approved',
            'company_status' => 'approved',
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        $mk = fn (string $email, int $companyId, string $posRole) =>
            DB::table('users')->insertGetId([
                'name'       => ucfirst(explode('@', $email)[0]),
                'email'      => $email,
                'password'   => Hash::make('Secret@12345'),
                'company_id' => $companyId,
                'role'       => 'company_admin',
                'pos_role'   => $posRole,
                'is_active'  => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        $this->userA1Id = $mk('alpha-admin@taxnest.test', $this->companyAId, 'pos_admin');
        $this->userA2Id = $mk('alpha-mgr@taxnest.test',   $this->companyAId, 'pos_manager');
        $this->userBId  = $mk('beta-admin@taxnest.test',  $this->companyBId, 'pos_admin');
    }

    /** Insert a pra_elaan row directly (mirrors what praElaanRespond writes). */
    private function insertElaanRow(int $userId, int $companyId, string $choiceKey): void
    {
        DB::table('feature_suggestions')->insert([
            'user_id'    => $userId,
            'company_id' => $companyId,
            'product'    => 'pos',
            'title'      => FeatureSuggestion::PRA_ELAAN_CHOICES[$choiceKey],
            'status'     => 'pending',
            'source'     => FeatureSuggestion::PRA_ELAAN_SOURCE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1. No responses → tally block must be absent from the page
    // ─────────────────────────────────────────────────────────────────────────

    public function test_tally_block_absent_when_no_elaan_rows(): void
    {
        $response = $this->actingAsAdmin()->get('/admin/feature-suggestions');
        $response->assertStatus(200);
        // computePraElaanTally returns null when empty → @if(!empty($praElaanTally)) skips the block
        $response->assertDontSee('responses from', false);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. Two users from the SAME company, different choices
    // ─────────────────────────────────────────────────────────────────────────

    public function test_two_same_company_users_count_as_one_company(): void
    {
        $this->insertElaanRow($this->userA1Id, $this->companyAId, 'band');
        $this->insertElaanRow($this->userA2Id, $this->companyAId, 'jari');

        $rows = FeatureSuggestion::where('source', FeatureSuggestion::PRA_ELAAN_SOURCE)->get();

        // total = 2 rows
        $this->assertSame(2, $rows->count());

        // companies = 1 distinct company_id
        $this->assertSame(1, $rows->pluck('company_id')->unique()->count());

        // per-choice counts
        $counts = [];
        foreach (FeatureSuggestion::PRA_ELAAN_CHOICES as $key => $title) {
            $counts[$key] = $rows->where('title', $title)->count();
        }
        $this->assertSame(1, $counts['band'], "'band' choice should be counted once");
        $this->assertSame(1, $counts['jari'], "'jari' choice should be counted once");
        $this->assertSame(0, $counts['aur'],  "'aur' choice should be zero");
    }

    public function test_admin_page_shows_2_responses_from_1_company(): void
    {
        $this->insertElaanRow($this->userA1Id, $this->companyAId, 'band');
        $this->insertElaanRow($this->userA2Id, $this->companyAId, 'jari');

        $response = $this->actingAsAdmin()->get('/admin/feature-suggestions');
        $response->assertStatus(200);

        // The view renders: "{total} responses from {companies} company/companies"
        $response->assertSee('2 responses from 1 company', false);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. Add a user from a SECOND company → companies count must become 2
    // ─────────────────────────────────────────────────────────────────────────

    public function test_second_company_response_increments_company_count(): void
    {
        $this->insertElaanRow($this->userA1Id, $this->companyAId, 'band');
        $this->insertElaanRow($this->userA2Id, $this->companyAId, 'jari');
        $this->insertElaanRow($this->userBId,  $this->companyBId, 'band');

        $rows = FeatureSuggestion::where('source', FeatureSuggestion::PRA_ELAAN_SOURCE)->get();

        $this->assertSame(3, $rows->count());
        $this->assertSame(2, $rows->pluck('company_id')->unique()->count());
    }

    public function test_admin_page_shows_3_responses_from_2_companies(): void
    {
        $this->insertElaanRow($this->userA1Id, $this->companyAId, 'band');
        $this->insertElaanRow($this->userA2Id, $this->companyAId, 'jari');
        $this->insertElaanRow($this->userBId,  $this->companyBId, 'band');

        $response = $this->actingAsAdmin()->get('/admin/feature-suggestions');
        $response->assertStatus(200);

        $response->assertSee('3 responses from 2 companies', false);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4. Per-choice counts aggregate correctly across companies
    // ─────────────────────────────────────────────────────────────────────────

    public function test_per_choice_counts_are_correct_across_companies(): void
    {
        $this->insertElaanRow($this->userA1Id, $this->companyAId, 'band');
        $this->insertElaanRow($this->userA2Id, $this->companyAId, 'jari');
        $this->insertElaanRow($this->userBId,  $this->companyBId, 'band');

        $rows = FeatureSuggestion::where('source', FeatureSuggestion::PRA_ELAAN_SOURCE)->get();

        $counts = [];
        foreach (FeatureSuggestion::PRA_ELAAN_CHOICES as $key => $title) {
            $counts[$key] = $rows->where('title', $title)->count();
        }

        // 'band' from user A1 + user B = 2
        $this->assertSame(2, $counts['band'], "'band' should be 2 (A1 + B)");
        // 'jari' from user A2 = 1
        $this->assertSame(1, $counts['jari'], "'jari' should be 1 (A2)");
        // 'aur' never chosen = 0
        $this->assertSame(0, $counts['aur'], "'aur' should be 0");
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 5. Admin route always returns 200 (page never 500s on tally data)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_admin_feature_suggestions_page_returns_200_with_data(): void
    {
        $this->insertElaanRow($this->userA1Id, $this->companyAId, 'aur');

        $response = $this->actingAsAdmin()->get('/admin/feature-suggestions');
        $response->assertStatus(200);

        // The tally block must be visible now that there is at least one row.
        // With 1 row Str::plural gives "response" (singular); the stable marker
        // present in every rendered tally block is the block heading.
        $response->assertSee('1 response from 1 company', false);
    }

    public function test_admin_feature_suggestions_page_returns_200_without_data(): void
    {
        $response = $this->actingAsAdmin()->get('/admin/feature-suggestions');
        $response->assertStatus(200);
    }
}

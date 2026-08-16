<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Survey;
use App\Models\SurveyResponse;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Admin-panel survey CRUD (Task 1023).
 *
 * Covers: create, update (incl. add-question/add-option round-trip),
 * server rejection of duplicate keys, immutable published+responded surveys,
 * and delete gating.
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' php vendor/bin/phpunit tests/Feature/AdminSurveyTest.php
 */
class AdminSurveyTest extends TestCase
{
    private int $adminId;
    private int $companyId;
    private int $posUserId;

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
            $table->unsignedBigInteger('admin_user_id')->nullable();
            $table->string('action');
            $table->string('entity_type')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->text('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
        });

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

        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('surveys', function (Blueprint $table) {
            $table->id();
            $table->string('title', 200);
            $table->text('intro')->nullable();
            $table->text('questions');
            $table->boolean('allow_comment')->default(false);
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

        $now = now();
        $this->adminId = DB::table('admin_users')->insertGetId([
            'name' => 'Test Admin', 'email' => 'admin@survey.test',
            'password' => Hash::make('Secret@12345'), 'role' => 'super_admin',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->companyId = DB::table('companies')->insertGetId([
            'name' => 'Survey Test Shop', 'product_type' => 'pos',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->posUserId = DB::table('users')->insertGetId([
            'name' => 'POS User', 'email' => 'pos@survey.test',
            'password' => Hash::make('Secret@12345'), 'company_id' => $this->companyId,
            'role' => 'company_admin', 'pos_role' => 'pos_admin',
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function admin(): self
    {
        return $this->actingAs(AdminUser::find($this->adminId), 'admin');
    }

    /** Minimal valid payload for creating a survey. */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'title'          => 'Test Survey',
            'intro'          => 'Survey intro text',
            'questions_json' => json_encode([
                [
                    'key'     => 'q_aaa',
                    'text'    => 'Sawal aik?',
                    'options' => [
                        ['key' => 'o_yes', 'label' => 'Haan'],
                        ['key' => 'o_no',  'label' => 'Nahi'],
                    ],
                ],
            ]),
            'allow_comment' => '0',
            'audience'      => 'pos_all',
            'is_published'  => '0',
        ], $overrides);
    }

    // ── Create ────────────────────────────────────────────────────────────

    public function test_admin_can_create_a_draft_survey(): void
    {
        $this->admin()
            ->post('/admin/surveys', $this->validPayload())
            ->assertRedirect('/admin/surveys');

        $this->assertSame(1, Survey::count());
        $sv = Survey::first();
        $this->assertSame('Test Survey', $sv->title);
        $this->assertFalse((bool) $sv->is_published);
        $this->assertCount(1, $sv->questions);
        $this->assertSame('q_aaa', $sv->questions[0]['key']);
    }

    public function test_admin_can_create_and_publish_immediately(): void
    {
        $this->admin()
            ->post('/admin/surveys', $this->validPayload(['is_published' => '1']))
            ->assertRedirect('/admin/surveys');

        $this->assertTrue((bool) Survey::first()->is_published);
    }

    public function test_create_stores_multiple_questions_and_options(): void
    {
        $payload = $this->validPayload([
            'questions_json' => json_encode([
                ['key' => 'q1', 'text' => 'Q one?', 'options' => [
                    ['key' => 'o1a', 'label' => 'Opt A'],
                    ['key' => 'o1b', 'label' => 'Opt B'],
                    ['key' => 'o1c', 'label' => 'Opt C'],
                ]],
                ['key' => 'q2', 'text' => 'Q two?', 'options' => [
                    ['key' => 'o2a', 'label' => 'Yes'],
                    ['key' => 'o2b', 'label' => 'No'],
                ]],
            ]),
            'allow_comment' => '1',
        ]);

        $this->admin()->post('/admin/surveys', $payload)->assertRedirect();

        $sv = Survey::first();
        $this->assertCount(2, $sv->questions);
        $this->assertCount(3, $sv->questions[0]['options']);
        $this->assertTrue((bool) $sv->allow_comment);
    }

    // ── Duplicate key rejection ───────────────────────────────────────────

    public function test_store_rejects_duplicate_question_keys(): void
    {
        $payload = $this->validPayload([
            'questions_json' => json_encode([
                ['key' => 'SAME', 'text' => 'Q1?', 'options' => [
                    ['key' => 'a', 'label' => 'A'], ['key' => 'b', 'label' => 'B'],
                ]],
                ['key' => 'SAME', 'text' => 'Q2?', 'options' => [
                    ['key' => 'c', 'label' => 'C'], ['key' => 'd', 'label' => 'D'],
                ]],
            ]),
        ]);

        $resp = $this->admin()->post('/admin/surveys', $payload);
        $resp->assertRedirect();
        // Session must carry an error (not success).
        $resp->assertSessionHas('error');
        $this->assertSame(0, Survey::count());
    }

    public function test_store_rejects_duplicate_option_keys_within_a_question(): void
    {
        $payload = $this->validPayload([
            'questions_json' => json_encode([
                ['key' => 'q1', 'text' => 'Q?', 'options' => [
                    ['key' => 'DUP', 'label' => 'First'],
                    ['key' => 'DUP', 'label' => 'Second'],
                ]],
            ]),
        ]);

        $resp = $this->admin()->post('/admin/surveys', $payload);
        $resp->assertSessionHas('error');
        $this->assertSame(0, Survey::count());
    }

    public function test_store_rejects_fewer_than_two_options(): void
    {
        $payload = $this->validPayload([
            'questions_json' => json_encode([
                ['key' => 'q1', 'text' => 'Q?', 'options' => [
                    ['key' => 'only', 'label' => 'Only one'],
                ]],
            ]),
        ]);

        $resp = $this->admin()->post('/admin/surveys', $payload);
        $resp->assertSessionHas('error');
        $this->assertSame(0, Survey::count());
    }

    public function test_store_rejects_empty_question_text(): void
    {
        $payload = $this->validPayload([
            'questions_json' => json_encode([
                ['key' => 'q1', 'text' => '   ', 'options' => [
                    ['key' => 'a', 'label' => 'A'], ['key' => 'b', 'label' => 'B'],
                ]],
            ]),
        ]);

        $resp = $this->admin()->post('/admin/surveys', $payload);
        $resp->assertSessionHas('error');
        $this->assertSame(0, Survey::count());
    }

    // ── Update ────────────────────────────────────────────────────────────

    public function test_admin_can_edit_a_draft_survey(): void
    {
        $sv = Survey::create([
            'title' => 'Old Title', 'questions' => [
                ['key' => 'q1', 'text' => 'Old?', 'options' => [
                    ['key' => 'oa', 'label' => 'A'], ['key' => 'ob', 'label' => 'B'],
                ]],
            ], 'audience' => 'pos_all', 'is_published' => false,
        ]);

        $this->admin()
            ->post("/admin/surveys/{$sv->id}/update", $this->validPayload(['title' => 'New Title']))
            ->assertRedirect('/admin/surveys');

        $this->assertSame('New Title', $sv->fresh()->title);
    }

    public function test_admin_can_add_a_question_to_a_draft_survey(): void
    {
        $sv = Survey::create([
            'title' => 'Draft', 'questions' => [
                ['key' => 'q1', 'text' => 'Q1?', 'options' => [
                    ['key' => 'a', 'label' => 'A'], ['key' => 'b', 'label' => 'B'],
                ]],
            ], 'audience' => 'pos_all', 'is_published' => false,
        ]);

        $payload = $this->validPayload([
            'questions_json' => json_encode([
                ['key' => 'q1', 'text' => 'Q1?', 'options' => [
                    ['key' => 'a', 'label' => 'A'], ['key' => 'b', 'label' => 'B'],
                ]],
                ['key' => 'q2_new', 'text' => 'New question?', 'options' => [
                    ['key' => 'c', 'label' => 'C'], ['key' => 'd', 'label' => 'D'],
                ]],
            ]),
        ]);

        $this->admin()->post("/admin/surveys/{$sv->id}/update", $payload)->assertRedirect();
        $this->assertCount(2, $sv->fresh()->questions);
    }

    public function test_update_blocked_when_published_with_responses(): void
    {
        $sv = Survey::create([
            'title' => 'Live Survey', 'questions' => [
                ['key' => 'q1', 'text' => 'Q?', 'options' => [
                    ['key' => 'y', 'label' => 'Yes'], ['key' => 'n', 'label' => 'No'],
                ]],
            ], 'audience' => 'pos_all', 'is_published' => true,
        ]);

        // Simulate a response row existing.
        DB::table('survey_responses')->insert([
            'survey_id' => $sv->id, 'user_id' => $this->posUserId,
            'company_id' => $this->companyId, 'answered_at' => now(),
            'answers' => json_encode(['q1' => 'y']),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $resp = $this->admin()
            ->post("/admin/surveys/{$sv->id}/update", $this->validPayload(['title' => 'Attempted Edit']));

        $resp->assertSessionHas('error');
        // Title must be unchanged.
        $this->assertSame('Live Survey', $sv->fresh()->title);
    }

    public function test_update_allowed_on_published_survey_with_no_responses(): void
    {
        $sv = Survey::create([
            'title' => 'Published No Responses', 'questions' => [
                ['key' => 'q1', 'text' => 'Q?', 'options' => [
                    ['key' => 'y', 'label' => 'Yes'], ['key' => 'n', 'label' => 'No'],
                ]],
            ], 'audience' => 'pos_all', 'is_published' => true,
        ]);

        $this->admin()
            ->post("/admin/surveys/{$sv->id}/update", $this->validPayload(['title' => 'Edited Title']))
            ->assertRedirect('/admin/surveys');

        $this->assertSame('Edited Title', $sv->fresh()->title);
    }

    public function test_update_rejects_duplicate_keys_same_as_store(): void
    {
        $sv = Survey::create([
            'title' => 'Draft', 'questions' => [
                ['key' => 'q1', 'text' => 'Q?', 'options' => [
                    ['key' => 'a', 'label' => 'A'], ['key' => 'b', 'label' => 'B'],
                ]],
            ], 'audience' => 'pos_all', 'is_published' => false,
        ]);

        $payload = $this->validPayload([
            'questions_json' => json_encode([
                ['key' => 'DUP', 'text' => 'Q1?', 'options' => [
                    ['key' => 'a', 'label' => 'A'], ['key' => 'b', 'label' => 'B'],
                ]],
                ['key' => 'DUP', 'text' => 'Q2?', 'options' => [
                    ['key' => 'c', 'label' => 'C'], ['key' => 'd', 'label' => 'D'],
                ]],
            ]),
        ]);

        $resp = $this->admin()->post("/admin/surveys/{$sv->id}/update", $payload);
        $resp->assertSessionHas('error');
        // Questions unchanged.
        $this->assertCount(1, $sv->fresh()->questions);
    }

    // ── Delete ────────────────────────────────────────────────────────────

    public function test_admin_can_delete_a_survey_with_no_responses(): void
    {
        $sv = Survey::create([
            'title' => 'Deletable', 'questions' => [
                ['key' => 'q1', 'text' => 'Q?', 'options' => [
                    ['key' => 'a', 'label' => 'A'], ['key' => 'b', 'label' => 'B'],
                ]],
            ], 'audience' => 'pos_all', 'is_published' => false,
        ]);

        $this->admin()
            ->delete("/admin/surveys/{$sv->id}")
            ->assertRedirect('/admin/surveys');

        $this->assertSame(0, Survey::count());
    }

    public function test_delete_blocked_when_any_response_row_exists(): void
    {
        $sv = Survey::create([
            'title' => 'Has Seen', 'questions' => [
                ['key' => 'q1', 'text' => 'Q?', 'options' => [
                    ['key' => 'a', 'label' => 'A'], ['key' => 'b', 'label' => 'B'],
                ]],
            ], 'audience' => 'pos_all', 'is_published' => true,
        ]);

        // A "seen but not answered" row — the lightest form of a response.
        DB::table('survey_responses')->insert([
            'survey_id' => $sv->id, 'user_id' => $this->posUserId,
            'company_id' => $this->companyId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $resp = $this->admin()->delete("/admin/surveys/{$sv->id}");
        $resp->assertSessionHas('error');
        $this->assertSame(1, Survey::count());
    }
}

<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Duplicate physical print + stranded job guard (Sep 2026).
 *
 * pos_print_jobs states: pending = NOT STARTED, printing = CLAIMED / OUTCOME
 * UNKNOWN, done = PRINT CONFIRMED, failed = PRINT FAILED. Whether paper came
 * out cannot be known once the agent fetched the content, so housekeeping
 * must never blindly requeue a stale 'printing' row. Invariants locked here:
 *
 *  a. stale 'printing' row whose content was NEVER fetched → requeued to
 *     pending (nothing could have printed; safe retry, attempts kept);
 *  b. stale 'printing' row whose content WAS fetched → failed with the
 *     'unconfirmed_after_print_content_fetched' code, never pending again and
 *     never returned by a later claim poll;
 *  c. GET print-jobs/{id}/content stamps content_fetched_at on a live claim;
 *  d. unstamped pending job older than print.pending_expiry_hours → failed
 *     'expired_unclaimed', while a fresh pending job is still claimable;
 *  e. done/failed rows older than 7 days are still purged as before.
 *
 * Pattern: APP_ENV=testing + SQLite :memory: + minimal Schema::create.
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' CACHE_STORE=array \
 *     php vendor/bin/phpunit tests/Feature/PrintJobDuplicateGuardTest.php --testdox
 */
class PrintJobDuplicateGuardTest extends TestCase
{
    private string $agentKey = 'test-agent-key-dup-guard';
    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('product_type')->nullable();
            $t->string('status')->default('approved');
            $t->string('company_status')->default('approved');
            $t->boolean('fbr_pos_enabled')->default(false);
            $t->string('agent_api_key')->nullable();
            $t->boolean('agent_enabled')->default(false);
            $t->timestamp('agent_last_seen')->nullable();
            $t->string('agent_version')->nullable();
            $t->text('pos_printer_settings')->nullable();
            $t->string('receipt_printer_size')->nullable();
            $t->string('default_language')->nullable();
            $t->boolean('pos_cashier_own_sales_only')->nullable();
            $t->softDeletes();
            $t->timestamps();
        });

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password');
            $t->unsignedBigInteger('company_id')->nullable();
            $t->string('role')->nullable();
            $t->string('pos_role')->nullable();
            $t->string('language')->nullable();
            $t->boolean('is_active')->default(true);
            $t->rememberToken();
            $t->timestamps();
        });

        Schema::create('pos_print_jobs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('type');
            $t->string('target_printer')->nullable();
            $t->unsignedBigInteger('transaction_id')->nullable();
            $t->unsignedBigInteger('restaurant_order_id')->nullable();
            $t->string('render_query')->nullable();
            $t->string('status')->default('pending');
            $t->string('claim_token')->nullable();
            $t->string('device_uid')->nullable();
            $t->timestamp('content_fetched_at')->nullable();
            $t->text('printed_item_ids')->nullable();
            $t->text('error')->nullable();
            $t->unsignedInteger('attempts')->default(0);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });

        Schema::create('pos_agent_devices', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('device_uid');
            $t->string('hostname')->nullable();
            $t->string('name')->nullable();
            $t->string('agent_version')->nullable();
            $t->timestamp('last_seen_at')->nullable();
            $t->text('printers')->nullable();
            $t->timestamp('printers_reported_at')->nullable();
            $t->string('receipt_printer')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'device_uid']);
        });

        Schema::create('pos_transactions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('pra_status')->nullable();
            $t->string('pra_invoice_number')->nullable();
            $t->string('invoice_mode')->nullable();
            $t->timestamps();
        });

        $now = now();
        $this->companyId = DB::table('companies')->insertGetId([
            'name' => 'Dup Guard Shop', 'product_type' => 'pos',
            'status' => 'approved', 'company_status' => 'approved',
            'agent_api_key' => $this->agentKey,
            'agent_enabled' => true,
            'agent_last_seen' => $now,
            'pos_cashier_own_sales_only' => false,
            'pos_printer_settings' => json_encode([
                'silent_print_enabled' => true,
                'receipt_printer' => 'XP-80C',
                'available_printers' => [
                    ['name' => 'XP-80C', 'displayName' => 'XP-80C', 'isDefault' => true],
                ],
            ]),
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('users')->insert([
            'name' => 'Owner', 'email' => 'dg-owner@test.pk',
            'password' => Hash::make('Secret@12345'),
            'company_id' => $this->companyId,
            'role' => 'company_admin', 'pos_role' => 'pos_admin',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        // Housekeeping is throttled per company via Cache::add — start clean so
        // the FIRST claim poll of every test runs it.
        Cache::flush();
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function agentGet(string $path): \Illuminate\Testing\TestResponse
    {
        return $this->getJson($path, ['Authorization' => 'Bearer ' . $this->agentKey]);
    }

    private function claimIds(): array
    {
        // Each poll must run housekeeping — drop the 30s throttle marker.
        Cache::forget('print_jobs_housekeeping_' . $this->companyId);
        $res = $this->agentGet('/api/agent/print-jobs')->assertOk();
        return collect($res->json('jobs'))->pluck('id')->all();
    }

    private function seedJob(array $overrides = []): int
    {
        return DB::table('pos_print_jobs')->insertGetId(array_merge([
            'company_id' => $this->companyId,
            'type' => 'test',
            'target_printer' => 'XP-80C',
            'status' => 'pending',
            'attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function job(int $id): ?object
    {
        return DB::table('pos_print_jobs')->where('id', $id)->first();
    }

    // ── a. claimed, content never fetched → safe requeue ───────────────────

    public function test_stale_printing_job_with_content_never_fetched_is_requeued(): void
    {
        $stuck = $this->seedJob([
            'status' => 'printing',
            'claim_token' => 'old-token',
            'attempts' => 1,
            'content_fetched_at' => null,
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ]);

        // Housekeeping runs first, then this same poll claims the requeued job.
        $ids = $this->claimIds();

        $row = $this->job($stuck);
        $this->assertContains($stuck, $ids, 'a never-rendered stale claim must be retried');
        $this->assertSame('printing', $row->status, 'requeued job was re-claimed by the same poll');
        $this->assertSame(2, (int) $row->attempts, 'attempts accounting is kept across the requeue');
        $this->assertNotSame('old-token', $row->claim_token);
        $this->assertNull($row->error);
    }

    // ── b. claimed, content fetched → failed, never requeued/re-claimed ────

    public function test_stale_printing_job_whose_content_was_fetched_becomes_failed_not_pending(): void
    {
        $stuck = $this->seedJob([
            'status' => 'printing',
            'claim_token' => 'old-token',
            'attempts' => 1,
            'content_fetched_at' => now()->subMinutes(5),
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ]);

        $ids = $this->claimIds();

        $row = $this->job($stuck);
        $this->assertNotContains($stuck, $ids, 'a possibly-printed job must never be handed out again');
        $this->assertSame('failed', $row->status);
        $this->assertNull($row->claim_token);
        $this->assertSame(1, (int) $row->attempts, 'no phantom attempt is added when parking the job');
        $this->assertStringStartsWith('unconfirmed_after_print_content_fetched', (string) $row->error);
        $this->assertNotNull($row->content_fetched_at, 'evidence of the hand-off is retained');

        // Subsequent polls (housekeeping re-run + claim) never resurrect it.
        $this->assertSame([], $this->claimIds());
        $this->assertSame([], $this->claimIds());
        $this->assertSame('failed', $this->job($stuck)->status);
    }

    public function test_fetched_job_under_the_stale_window_is_left_alone(): void
    {
        // Agent fetched content 30s ago — the print is simply in flight.
        $live = $this->seedJob([
            'status' => 'printing',
            'claim_token' => 'live-token',
            'attempts' => 1,
            'content_fetched_at' => now()->subSeconds(30),
            'created_at' => now()->subSeconds(40),
            'updated_at' => now()->subSeconds(30),
        ]);

        $this->assertSame([], $this->claimIds());
        $row = $this->job($live);
        $this->assertSame('printing', $row->status);
        $this->assertSame('live-token', $row->claim_token);
    }

    // ── c. content endpoint stamps the hand-off ────────────────────────────

    public function test_content_endpoint_stamps_content_fetched_at_on_a_claimed_job(): void
    {
        $id = $this->seedJob();
        $this->assertNull($this->job($id)->content_fetched_at);

        $this->assertSame([$id], $this->claimIds());
        $this->assertSame('printing', $this->job($id)->status);
        $this->assertNull($this->job($id)->content_fetched_at, 'claiming alone is not a hand-off');

        $html = $this->agentGet("/api/agent/print-jobs/{$id}/content")->assertOk()->getContent();
        $this->assertStringContainsString('XP-80C', $html);

        $row = $this->job($id);
        $this->assertNotNull($row->content_fetched_at);
        $this->assertSame('printing', $row->status, 'fetching content does not change the job state');
        $this->assertSame(1, (int) $row->attempts);

        // A second fetch (agent retry) keeps the FIRST hand-off time.
        $first = $row->content_fetched_at;
        $this->travel(10)->seconds();
        $this->agentGet("/api/agent/print-jobs/{$id}/content")->assertOk();
        $this->assertSame($first, $this->job($id)->content_fetched_at);
    }

    public function test_content_fetch_then_lost_result_ends_failed_not_reprinted(): void
    {
        // End-to-end: claim → fetch → agent dies before POST result → 2 min pass.
        $id = $this->seedJob();
        $this->claimIds();
        $this->agentGet("/api/agent/print-jobs/{$id}/content")->assertOk();

        $this->travel(3)->minutes();
        $this->assertSame([], $this->claimIds(), 'must not print the same bill again');
        $row = $this->job($id);
        $this->assertSame('failed', $row->status);
        $this->assertStringStartsWith('unconfirmed_after_print_content_fetched', (string) $row->error);
    }

    // ── d. unclaimed pending jobs expire (never deleted) ───────────────────

    public function test_pending_unstamped_job_older_than_expiry_horizon_becomes_failed_expired_unclaimed(): void
    {
        config(['print.pending_expiry_hours' => 24]);

        $old = $this->seedJob([
            'created_at' => now()->subHours(25),
            'updated_at' => now()->subHours(25),
        ]);
        $fresh = $this->seedJob();

        $ids = $this->claimIds();

        $this->assertSame([$fresh], $ids, 'only the fresh job is handed out');

        $oldRow = $this->job($old);
        $this->assertSame('failed', $oldRow->status);
        $this->assertStringStartsWith('expired_unclaimed', (string) $oldRow->error);
        $this->assertSame(0, (int) $oldRow->attempts, 'never claimed, never attempted');
        $this->assertNotNull($this->job($old), 'expired jobs are retained as evidence, not deleted');

        $this->assertSame('printing', $this->job($fresh)->status);
    }

    public function test_pending_job_inside_expiry_horizon_stays_claimable(): void
    {
        config(['print.pending_expiry_hours' => 24]);
        $recent = $this->seedJob([
            'created_at' => now()->subHours(23),
            'updated_at' => now()->subHours(23),
        ]);

        $this->assertSame([$recent], $this->claimIds());
    }

    public function test_expiry_horizon_is_configurable(): void
    {
        config(['print.pending_expiry_hours' => 2]);
        $old = $this->seedJob([
            'created_at' => now()->subHours(3),
            'updated_at' => now()->subHours(3),
        ]);

        $this->assertSame([], $this->claimIds());
        $this->assertSame('failed', $this->job($old)->status);
    }

    // ── e. done/failed purge unchanged ─────────────────────────────────────

    public function test_done_and_failed_rows_older_than_seven_days_are_still_purged(): void
    {
        $oldDone = $this->seedJob(['status' => 'done', 'attempts' => 1,
            'created_at' => now()->subDays(8), 'updated_at' => now()->subDays(8)]);
        $oldFailed = $this->seedJob(['status' => 'failed', 'attempts' => 3, 'error' => 'x',
            'created_at' => now()->subDays(8), 'updated_at' => now()->subDays(8)]);
        $recentDone = $this->seedJob(['status' => 'done', 'attempts' => 1,
            'created_at' => now()->subDays(6), 'updated_at' => now()->subDays(6)]);
        // Freshly expired by THIS housekeeping pass: updated_at is now, so the
        // purge must keep it visible for the full 7 days.
        $expiredNow = $this->seedJob([
            'created_at' => now()->subDays(8), 'updated_at' => now()->subDays(8),
        ]);

        $this->assertSame([], $this->claimIds());

        $this->assertNull($this->job($oldDone), 'done >7d purged');
        $this->assertNull($this->job($oldFailed), 'failed >7d purged');
        $this->assertNotNull($this->job($recentDone), 'done <7d kept');
        $row = $this->job($expiredNow);
        $this->assertNotNull($row, 'just-expired job kept as evidence');
        $this->assertSame('failed', $row->status);
        $this->assertStringStartsWith('expired_unclaimed', (string) $row->error);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PosAgentDevice;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Task 1166 — Per-counter silent printer routing ("har cashier ka apna printer").
 *
 * Multi-counter shops run the Desktop Agent on several PCs with the SAME
 * company agent_api_key (Option A). Invariants locked here:
 *
 *  1. Device registry: an agent that sends device_uid on heartbeat /
 *     printers-report gets a pos_agent_devices row (hostname, printers);
 *     an agent that sends none registers nothing (legacy path untouched).
 *  2. Enqueue routing: a cashier assigned to an ONLINE counter with its own
 *     receipt printer gets bill/proof jobs stamped device_uid + that printer;
 *     no assignment / offline device / no per-device printer → unstamped
 *     company-default job (today's behavior, popup fallback preserved).
 *  3. Claim scoping: a device-aware agent claims its own stamped jobs plus
 *     unstamped legacy jobs — NEVER another counter's stamped jobs; a legacy
 *     agent (no UID) claims only unstamped jobs.
 *  4. Stranded rescue: a stamped job left pending >90s (counter died right
 *     after enqueue) is unstamped + retargeted to the company default printer
 *     by housekeeping, so a bill never sits unclaimed forever.
 *
 * Pattern: APP_ENV=testing + SQLite :memory: + minimal Schema::create.
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' CACHE_STORE=array \
 *     php vendor/bin/phpunit tests/Feature/PosPrintJobDeviceRoutingTest.php --testdox
 */
class PosPrintJobDeviceRoutingTest extends TestCase
{
    private string $agentKey = 'test-agent-key-device-routing';
    private int $companyId;
    private int $adminId;
    private int $cashierId;

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
            $t->text('pos_custom_access')->nullable();
            $t->string('pos_device_uid')->nullable();
            $t->boolean('is_active')->default(true);
            $t->rememberToken();
            $t->timestamps();
        });

        Schema::create('branches', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('name');
            $t->boolean('is_head_office')->default(false);
            $t->boolean('is_active')->default(true);
            $t->softDeletes();
            $t->timestamps();
        });
        Schema::create('branch_user', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('user_id');
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

        // Heartbeat self-heal sweeps + apiCreatePrintJob existence checks.
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
            'name' => 'Multi Counter Shop', 'product_type' => 'pos',
            'status' => 'approved', 'company_status' => 'approved',
            'agent_api_key' => $this->agentKey,
            'agent_enabled' => true,
            'agent_last_seen' => $now,
            'pos_printer_settings' => json_encode([
                'silent_print_enabled' => true,
                'receipt_printer' => 'Manager-POS80',
                'available_printers' => [
                    ['name' => 'Manager-POS80', 'displayName' => 'Manager Thermal', 'isDefault' => true],
                ],
            ]),
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->adminId = DB::table('users')->insertGetId([
            'name' => 'Owner', 'email' => 'dr-owner@test.pk',
            'password' => Hash::make('Secret@12345'),
            'company_id' => $this->companyId,
            'role' => 'company_admin', 'pos_role' => 'pos_admin',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->cashierId = DB::table('users')->insertGetId([
            'name' => 'Counter Cashier', 'email' => 'dr-cashier@test.pk',
            'password' => Hash::make('Secret@12345'),
            'company_id' => $this->companyId,
            'role' => 'user', 'pos_role' => 'pos_cashier',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        Cache::flush();
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function agentGet(string $path): \Illuminate\Testing\TestResponse
    {
        return $this->getJson($path, ['Authorization' => 'Bearer ' . $this->agentKey]);
    }

    private function agentPost(string $path, array $body): \Illuminate\Testing\TestResponse
    {
        return $this->postJson($path, $body, ['Authorization' => 'Bearer ' . $this->agentKey]);
    }

    private function seedDevice(string $uid, array $overrides = []): PosAgentDevice
    {
        return PosAgentDevice::create(array_merge([
            'company_id' => $this->companyId,
            'device_uid' => $uid,
            'hostname' => strtoupper($uid) . '-PC',
            'last_seen_at' => now(),
            'printers' => [['name' => 'Counter-' . $uid, 'displayName' => 'Counter ' . $uid, 'isDefault' => true]],
            'receipt_printer' => 'Counter-' . $uid,
        ], $overrides));
    }

    private function seedJob(array $overrides = []): int
    {
        return DB::table('pos_print_jobs')->insertGetId(array_merge([
            'company_id' => $this->companyId,
            'type' => 'bill',
            'target_printer' => 'Manager-POS80',
            'transaction_id' => 1,
            'status' => 'pending',
            'attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function seedTransaction(): int
    {
        return DB::table('pos_transactions')->insertGetId([
            'company_id' => $this->companyId,
            'pra_status' => 'local',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function createBillJob(int $userId, int $txnId): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs(User::find($userId), 'pos')
            ->postJson('/pos/api/print-jobs', ['type' => 'bill', 'transaction_id' => $txnId]);
    }

    // ── 1. Device registry ─────────────────────────────────────────────────

    public function test_heartbeat_with_device_uid_registers_device_row(): void
    {
        $this->agentPost('/api/agent/heartbeat', [
            'version' => '1.9.0',
            'device_uid' => 'dev-abc123',
            'hostname' => 'COUNTER-1-PC',
        ])->assertOk();

        $device = PosAgentDevice::where('company_id', $this->companyId)
            ->where('device_uid', 'dev-abc123')->first();
        $this->assertNotNull($device, 'heartbeat with device_uid must upsert a device row');
        $this->assertSame('COUNTER-1-PC', $device->hostname);
        $this->assertSame('1.9.0', $device->agent_version);
        $this->assertNotNull($device->last_seen_at);
    }

    public function test_legacy_heartbeat_without_uid_registers_nothing(): void
    {
        $this->agentPost('/api/agent/heartbeat', ['version' => '1.6.2'])->assertOk();
        $this->assertSame(0, PosAgentDevice::count());
    }

    public function test_printers_report_stores_per_device_list_and_company_list(): void
    {
        $this->agentPost('/api/agent/printers', [
            'printers' => [['name' => 'Counter-2-XP80', 'displayName' => 'XP-80', 'isDefault' => true]],
            'device_uid' => 'dev-counter2',
            'hostname' => 'COUNTER-2-PC',
        ])->assertOk();

        $device = PosAgentDevice::where('device_uid', 'dev-counter2')->first();
        $this->assertNotNull($device);
        $this->assertSame('Counter-2-XP80', $device->printers[0]['name'] ?? null);
        $this->assertNotNull($device->printers_reported_at);

        // Company-wide list still updated exactly as before (legacy fallback).
        $company = Company::find($this->companyId);
        $names = collect($company->printerSettings()['available_printers'])->pluck('name');
        $this->assertTrue($names->contains('Counter-2-XP80'));
    }

    // ── 2. Enqueue routing ──────────────────────────────────────────────────

    public function test_assigned_cashier_bill_is_stamped_for_their_online_counter(): void
    {
        $this->seedDevice('dev-c1');
        User::where('id', $this->cashierId)->update(['pos_device_uid' => 'dev-c1']);
        $txn = $this->seedTransaction();

        $this->createBillJob($this->cashierId, $txn)->assertOk()->assertJson(['success' => true]);

        $job = DB::table('pos_print_jobs')->orderByDesc('id')->first();
        $this->assertSame('dev-c1', $job->device_uid);
        $this->assertSame('Counter-dev-c1', $job->target_printer);
    }

    public function test_unassigned_admin_bill_stays_company_default_unstamped(): void
    {
        $this->seedDevice('dev-c1');
        $txn = $this->seedTransaction();

        $this->createBillJob($this->adminId, $txn)->assertOk();

        $job = DB::table('pos_print_jobs')->orderByDesc('id')->first();
        $this->assertNull($job->device_uid);
        $this->assertSame('Manager-POS80', $job->target_printer);
    }

    public function test_assigned_but_offline_counter_falls_back_to_company_default(): void
    {
        $this->seedDevice('dev-c1', ['last_seen_at' => now()->subMinutes(10)]);
        User::where('id', $this->cashierId)->update(['pos_device_uid' => 'dev-c1']);
        $txn = $this->seedTransaction();

        $this->createBillJob($this->cashierId, $txn)->assertOk();

        $job = DB::table('pos_print_jobs')->orderByDesc('id')->first();
        $this->assertNull($job->device_uid, 'offline counter must never be stamped');
        $this->assertSame('Manager-POS80', $job->target_printer);
    }

    public function test_assigned_counter_without_printer_falls_back(): void
    {
        $this->seedDevice('dev-c1', ['receipt_printer' => null]);
        User::where('id', $this->cashierId)->update(['pos_device_uid' => 'dev-c1']);
        $txn = $this->seedTransaction();

        $this->createBillJob($this->cashierId, $txn)->assertOk();

        $job = DB::table('pos_print_jobs')->orderByDesc('id')->first();
        $this->assertNull($job->device_uid);
        $this->assertSame('Manager-POS80', $job->target_printer);
    }

    public function test_device_routed_bill_works_even_without_company_default_printer(): void
    {
        // Shop configured ONLY per-counter printers — no company-wide pick.
        $settings = Company::find($this->companyId)->printerSettings();
        $settings['receipt_printer'] = null;
        Company::where('id', $this->companyId)->update(['pos_printer_settings' => json_encode($settings)]);

        $this->seedDevice('dev-c1');
        User::where('id', $this->cashierId)->update(['pos_device_uid' => 'dev-c1']);
        $txn = $this->seedTransaction();

        $this->createBillJob($this->cashierId, $txn)->assertOk()->assertJson(['success' => true]);

        // …and an UNASSIGNED user still gets the 409 popup fallback (no printer).
        $this->createBillJob($this->adminId, $txn)->assertStatus(409);
    }

    // ── 2b. Settings-save path (reviewer case: per-device-ONLY shop) ───────

    public function test_per_device_only_shop_can_enable_silent_print_and_route_bills(): void
    {
        // Shop has NO company-wide receipt/KOT printer at all — printers exist
        // only per counter. The Printer Settings save must still allow the
        // silent-print master toggle, and routed bills must then print.
        Company::where('id', $this->companyId)->update([
            'pos_printer_settings' => json_encode([
                'silent_print_enabled' => false,
                'receipt_printer' => null,
                'kot_printer' => null,
                'available_printers' => [],
            ]),
        ]);
        $this->seedDevice('dev-c1', ['receipt_printer' => null]);
        User::where('id', $this->cashierId)->update(['pos_device_uid' => 'dev-c1']);

        // One save: master ON + per-device printer pick, no company-wide pick.
        $this->actingAs(User::find($this->adminId), 'pos')
            ->post('/pos/printer-settings', [
                'silent_print_enabled' => '1',
                'receipt_printer' => '',
                'kot_printer' => '',
                'device_receipt_printer' => ['dev-c1' => 'Counter-dev-c1'],
                'device_name' => ['dev-c1' => 'Counter 1'],
            ])->assertRedirect();

        $settings = Company::find($this->companyId)->printerSettings();
        $this->assertTrue($settings['silent_print_enabled'],
            'per-device printers alone must satisfy the silent-print master check');
        $this->assertNull($settings['receipt_printer']);
        $this->assertSame('Counter-dev-c1', PosAgentDevice::where('device_uid', 'dev-c1')->value('receipt_printer'));

        // …and the assigned cashier's bill now enqueues routed to that counter.
        $txn = $this->seedTransaction();
        $this->createBillJob($this->cashierId, $txn)->assertOk()->assertJson(['success' => true]);
        $job = DB::table('pos_print_jobs')->orderByDesc('id')->first();
        $this->assertSame('dev-c1', $job->device_uid);
        $this->assertSame('Counter-dev-c1', $job->target_printer);
    }

    public function test_master_toggle_still_forced_off_with_no_printer_anywhere(): void
    {
        Company::where('id', $this->companyId)->update([
            'pos_printer_settings' => json_encode([
                'silent_print_enabled' => false,
                'receipt_printer' => null,
                'kot_printer' => null,
                'available_printers' => [],
            ]),
        ]);
        // A registered counter WITHOUT a printer pick doesn't count.
        $this->seedDevice('dev-c1', ['receipt_printer' => null]);

        $this->actingAs(User::find($this->adminId), 'pos')
            ->post('/pos/printer-settings', [
                'silent_print_enabled' => '1',
                'receipt_printer' => '',
                'kot_printer' => '',
            ])->assertRedirect();

        $this->assertFalse(Company::find($this->companyId)->printerSettings()['silent_print_enabled'],
            'no company printer + no device printer = master stays OFF (unchanged rule)');
    }

    // ── 3. Claim scoping ────────────────────────────────────────────────────

    public function test_device_agent_claims_own_stamped_plus_unstamped_jobs_only(): void
    {
        $own = $this->seedJob(['device_uid' => 'dev-c1', 'target_printer' => 'Counter-dev-c1']);
        $other = $this->seedJob(['device_uid' => 'dev-c2', 'target_printer' => 'Counter-dev-c2']);
        $legacy = $this->seedJob();

        $res = $this->agentGet('/api/agent/print-jobs?device_uid=dev-c1')->assertOk();
        $ids = collect($res->json('jobs'))->pluck('id')->all();

        $this->assertContains($own, $ids);
        $this->assertContains($legacy, $ids);
        $this->assertNotContains($other, $ids, "another counter's stamped job must never be claimed");

        // The other counter's job is still pending for ITS agent.
        $this->assertSame('pending', DB::table('pos_print_jobs')->where('id', $other)->value('status'));
        $res2 = $this->agentGet('/api/agent/print-jobs?device_uid=dev-c2')->assertOk();
        $this->assertSame([$other], collect($res2->json('jobs'))->pluck('id')->all());
    }

    public function test_legacy_agent_never_claims_stamped_jobs(): void
    {
        $stamped = $this->seedJob(['device_uid' => 'dev-c1']);
        $legacy = $this->seedJob();

        $res = $this->agentGet('/api/agent/print-jobs')->assertOk();
        $ids = collect($res->json('jobs'))->pluck('id')->all();

        $this->assertSame([$legacy], $ids);
        $this->assertSame('pending', DB::table('pos_print_jobs')->where('id', $stamped)->value('status'));
    }

    public function test_claim_poll_with_device_uid_beats_device_last_seen(): void
    {
        $this->seedDevice('dev-c1', ['last_seen_at' => now()->subMinutes(30)]);
        $this->agentGet('/api/agent/print-jobs?device_uid=dev-c1')->assertOk();

        $device = PosAgentDevice::where('device_uid', 'dev-c1')->first();
        $this->assertTrue($device->last_seen_at->gt(now()->subMinute()), 'claim poll must refresh device last_seen');
    }

    // ── 4. Stranded stamped-job rescue ─────────────────────────────────────

    public function test_stranded_stamped_job_is_rescued_to_company_scope(): void
    {
        $stuck = $this->seedJob([
            'device_uid' => 'dev-dead',
            'target_printer' => 'Counter-dev-dead',
            'created_at' => now()->subMinutes(3),
            'updated_at' => now()->subMinutes(3),
        ]);

        // Any agent's claim poll triggers housekeeping (throttle flushed in setUp).
        $res = $this->agentGet('/api/agent/print-jobs')->assertOk();

        $job = DB::table('pos_print_jobs')->where('id', $stuck)->first();
        $this->assertNull($job->device_uid, 'stranded job must be unstamped after 90s');
        $this->assertSame('Manager-POS80', $job->target_printer, 'rescued bill retargets the company default printer');
        // And the legacy agent claimed it in the same pass or can claim it now.
        $ids = collect($res->json('jobs'))->pluck('id')->all();
        if (!in_array($stuck, $ids, true)) {
            $ids2 = collect($this->agentGet('/api/agent/print-jobs')->json('jobs'))->pluck('id')->all();
            $this->assertContains($stuck, $ids2);
        }
    }

    public function test_backlog_on_online_counter_is_never_released_to_other_agents(): void
    {
        // Reviewer case: a busy-but-ALIVE counter with a print backlog — jobs
        // aged past 90s (slow printer, >10-job claim batches) while its agent
        // keeps polling. Another agent polling must NOT trigger a rescue or
        // claim any of them; they stay exclusive to their counter.
        $this->seedDevice('dev-busy', ['last_seen_at' => now()]);
        $jobIds = [];
        for ($i = 0; $i < 12; $i++) {
            $jobIds[] = $this->seedJob([
                'device_uid' => 'dev-busy',
                'target_printer' => 'Counter-dev-busy',
                'created_at' => now()->subMinutes(5),
                'updated_at' => now()->subMinutes(5),
            ]);
        }

        // A legacy agent (no UID) polls — housekeeping runs, then it claims.
        $res = $this->agentGet('/api/agent/print-jobs')->assertOk();
        $claimed = collect($res->json('jobs'))->pluck('id')->all();
        $this->assertSame([], $claimed, 'legacy agent must not claim an online counter\'s backlog');

        // Another counter's agent polls too — same rule.
        $this->seedDevice('dev-other');
        $res2 = $this->agentGet('/api/agent/print-jobs?device_uid=dev-other')->assertOk();
        $this->assertSame([], collect($res2->json('jobs'))->pluck('id')->all());

        // Every backlog job is still stamped, pending, on its own printer.
        $rows = DB::table('pos_print_jobs')->whereIn('id', $jobIds)->get();
        foreach ($rows as $row) {
            $this->assertSame('dev-busy', $row->device_uid);
            $this->assertSame('pending', $row->status);
            $this->assertSame('Counter-dev-busy', $row->target_printer);
        }

        // …and the busy counter itself can still claim its own backlog.
        $own = collect($this->agentGet('/api/agent/print-jobs?device_uid=dev-busy')->json('jobs'))->pluck('id')->all();
        $this->assertNotEmpty($own);
        $this->assertEmpty(array_diff($own, $jobIds));
    }

    public function test_stamped_job_for_offline_device_row_is_rescued(): void
    {
        // Same aged job, but the device row EXISTS and is offline — rescue fires.
        $this->seedDevice('dev-gone', ['last_seen_at' => now()->subMinutes(10)]);
        $stuck = $this->seedJob([
            'device_uid' => 'dev-gone',
            'target_printer' => 'Counter-dev-gone',
            'created_at' => now()->subMinutes(3),
            'updated_at' => now()->subMinutes(3),
        ]);

        $this->agentGet('/api/agent/print-jobs')->assertOk();

        $job = DB::table('pos_print_jobs')->where('id', $stuck)->first();
        $this->assertNull($job->device_uid);
        $this->assertSame('Manager-POS80', $job->target_printer);
    }

    public function test_fresh_stamped_job_is_not_rescued(): void
    {
        $fresh = $this->seedJob(['device_uid' => 'dev-c1', 'target_printer' => 'Counter-dev-c1']);

        $this->agentGet('/api/agent/print-jobs')->assertOk();

        $job = DB::table('pos_print_jobs')->where('id', $fresh)->first();
        $this->assertSame('dev-c1', $job->device_uid, 'a fresh stamped job stays with its counter');
        $this->assertSame('pending', $job->status);
    }

    // ── 5. Setup-form printer save (Task 1187) ─────────────────────────────

    private function setSilentPrinting(bool $on): void
    {
        $company = Company::find($this->companyId);
        $settings = $company->printerSettings();
        $settings['silent_print_enabled'] = $on;
        $company->update(['pos_printer_settings' => $settings]);
    }

    public function test_unchanged_printer_resave_never_reactivates_silent_off_shop(): void
    {
        // Owner deliberately turned silent printing OFF; the counter already
        // has this exact printer saved from an earlier explicit pick.
        $this->setSilentPrinting(false);
        $this->seedDevice('dev-c1'); // receipt_printer = Counter-dev-c1

        // Setup form reopened → Save clicked with the SAME printer still selected.
        $res = $this->agentPost('/api/agent/device-printer', [
            'receipt_printer' => 'Counter-dev-c1',
            'explicit'        => true, // even a buggy/old client posting explicit=true
            'device_uid'      => 'dev-c1',
        ])->assertOk();

        $this->assertFalse($res->json('silent_print_enabled'),
            'unchanged re-save must never flip a deliberately-OFF shop back on');
        $this->assertFalse(Company::find($this->companyId)->printerSettings()['silent_print_enabled']);
    }

    public function test_fresh_explicit_printer_pick_activates_silent_printing(): void
    {
        $this->setSilentPrinting(false);
        $this->seedDevice('dev-c1'); // saved: Counter-dev-c1

        // User CHANGES the dropdown to a different real printer → activation.
        $res = $this->agentPost('/api/agent/device-printer', [
            'receipt_printer' => 'XP58-New-Thermal',
            'explicit'        => true,
            'device_uid'      => 'dev-c1',
        ])->assertOk();

        $this->assertTrue($res->json('silent_print_enabled'),
            'a genuinely new explicit pick must enable silent printing in one step');
        $device = PosAgentDevice::where('device_uid', 'dev-c1')->first();
        $this->assertSame('XP58-New-Thermal', $device->receipt_printer);
    }

    public function test_first_ever_explicit_pick_with_no_device_row_activates(): void
    {
        // Brand-new counter (no device row yet) — first explicit pick counts.
        $this->setSilentPrinting(false);

        $res = $this->agentPost('/api/agent/device-printer', [
            'receipt_printer' => 'Counter-1-XP80',
            'explicit'        => true,
            'device_uid'      => 'dev-new',
        ])->assertOk();

        $this->assertTrue($res->json('silent_print_enabled'));
        $this->assertSame('Counter-1-XP80',
            PosAgentDevice::where('device_uid', 'dev-new')->value('receipt_printer'));
    }

    public function test_non_explicit_save_never_touches_silent_flag(): void
    {
        $this->setSilentPrinting(false);
        $this->seedDevice('dev-c1');

        // v1.9.1 renderer: unchanged dropdown posts explicit=false.
        $res = $this->agentPost('/api/agent/device-printer', [
            'receipt_printer' => 'Counter-dev-c1',
            'explicit'        => false,
            'device_uid'      => 'dev-c1',
        ])->assertOk();

        $this->assertFalse($res->json('silent_print_enabled'));
    }
}

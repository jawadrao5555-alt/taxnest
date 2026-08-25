<?php

namespace Tests\Feature;

use App\Models\PosAgentDevice;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Test print — "kaun sa printer asli hai".
 *
 * A counter PC that has had the same thermal printer installed a few times
 * keeps one Windows queue per install ("XP-80C", "XP-80C (copy 2)", "POS-80").
 * Only one is still bound to the live port; the others accept the job, report
 * success and drop it — so every bill looks printed in the panel while the
 * counter gets no paper and the shop can only say "print nahi nikalta".
 *
 * The cure is a slip that names the queue it printed from. Locked here:
 *
 *  1. Admin/manager only — a test print costs the shop paper.
 *  2. Only a printer the agent actually reported can be tested (per counter,
 *     only that counter's own list).
 *  3. Offline agent/counter is reported honestly, never queued into silence.
 *  4. It works with silent printing switched OFF — the point is to find a
 *     working printer BEFORE turning printing on.
 *  5. The slip carries the QUEUE'S OWN NAME.
 *  6. A stranded test slip is never retargeted to another printer — a slip
 *     that names a queue it did not come from is worse than no slip.
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' CACHE_STORE=array \
 *     php vendor/bin/phpunit tests/Feature/PosTestPrintJobTest.php --testdox
 */
class PosTestPrintJobTest extends TestCase
{
    private string $agentKey = 'test-agent-key-test-print';
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
            $t->text('pos_custom_access')->nullable();
            $t->string('pos_device_uid')->nullable();
            $t->string('language')->nullable();
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
            'name' => 'Frosty Grill', 'product_type' => 'pos',
            'status' => 'approved', 'company_status' => 'approved',
            'agent_api_key' => $this->agentKey,
            'agent_enabled' => true,
            'agent_last_seen' => $now,
            'pos_cashier_own_sales_only' => false,
            'pos_printer_settings' => json_encode([
                'silent_print_enabled' => true,
                'receipt_printer' => 'XP-80C',
                'kot_printer' => 'XP-80C',
                // The real shape of the problem: one physical printer, three queues.
                'available_printers' => [
                    ['name' => 'XP-80C', 'displayName' => 'XP-80C', 'isDefault' => false],
                    ['name' => 'XP-80C (copy 2)', 'displayName' => 'XP-80C (copy 2)', 'isDefault' => false],
                    ['name' => 'POS-80', 'displayName' => 'POS-80', 'isDefault' => true],
                ],
            ]),
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->adminId = DB::table('users')->insertGetId([
            'name' => 'Owner', 'email' => 'tp-owner@test.pk',
            'password' => Hash::make('Secret@12345'),
            'company_id' => $this->companyId,
            'role' => 'company_admin', 'pos_role' => 'pos_admin',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->cashierId = DB::table('users')->insertGetId([
            'name' => 'Counter Cashier', 'email' => 'tp-cashier@test.pk',
            'password' => Hash::make('Secret@12345'),
            'company_id' => $this->companyId,
            'role' => 'user', 'pos_role' => 'pos_cashier',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        Cache::flush();
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function testPrint(int $userId, array $body): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs(User::find($userId), 'pos')
            ->postJson('/pos/api/print-jobs/test', $body);
    }

    private function seedDevice(string $uid, array $printers, bool $online = true): PosAgentDevice
    {
        return PosAgentDevice::create([
            'company_id' => $this->companyId,
            'device_uid' => $uid,
            'hostname' => strtoupper($uid),
            'agent_version' => '1.9.1',
            'last_seen_at' => $online ? now() : now()->subMinutes(30),
            'printers' => array_map(fn($n) => ['name' => $n, 'displayName' => $n], $printers),
            'printers_reported_at' => now(),
        ]);
    }

    private function setSilentPrint(bool $on): void
    {
        $settings = json_decode(DB::table('companies')->where('id', $this->companyId)->value('pos_printer_settings'), true);
        $settings['silent_print_enabled'] = $on;
        DB::table('companies')->where('id', $this->companyId)
            ->update(['pos_printer_settings' => json_encode($settings)]);
    }

    // ── tests ──────────────────────────────────────────────────────────────

    public function test_admin_test_print_queues_a_slip_for_the_chosen_queue(): void
    {
        $res = $this->testPrint($this->adminId, ['printer' => 'XP-80C (copy 2)']);
        $res->assertOk()->assertJson(['success' => true, 'printer' => 'XP-80C (copy 2)']);

        $job = DB::table('pos_print_jobs')->latest('id')->first();
        $this->assertSame('test', $job->type);
        $this->assertSame('XP-80C (copy 2)', $job->target_printer);
        $this->assertSame('pending', $job->status);
        $this->assertNull($job->device_uid, 'single-counter test print stays company-wide');
        $this->assertSame($this->adminId, (int) $job->created_by);
    }

    public function test_cashier_cannot_burn_the_shops_paper(): void
    {
        $this->testPrint($this->cashierId, ['printer' => 'XP-80C'])->assertStatus(403);
        $this->assertSame(0, DB::table('pos_print_jobs')->count());
    }

    public function test_a_printer_the_agent_never_reported_is_refused(): void
    {
        $this->testPrint($this->adminId, ['printer' => 'Someone Elses Printer'])
            ->assertStatus(422)
            ->assertJson(['success' => false, 'reason' => 'unknown_printer']);
        $this->assertSame(0, DB::table('pos_print_jobs')->count());
    }

    public function test_offline_agent_is_reported_instead_of_queueing_into_silence(): void
    {
        DB::table('companies')->where('id', $this->companyId)
            ->update(['agent_last_seen' => now()->subHour()]);

        $this->testPrint($this->adminId, ['printer' => 'XP-80C'])
            ->assertStatus(409)
            ->assertJson(['success' => false, 'reason' => 'agent_offline']);
        $this->assertSame(0, DB::table('pos_print_jobs')->count());
    }

    public function test_test_print_works_while_silent_printing_is_still_off(): void
    {
        // Finding a working printer is exactly what a shop does BEFORE enabling
        // silent print — gating this on the master switch would be circular.
        $this->setSilentPrint(false);

        $this->testPrint($this->adminId, ['printer' => 'POS-80'])->assertOk();
        $this->assertSame(1, DB::table('pos_print_jobs')->where('type', 'test')->count());
    }

    public function test_counter_scoped_test_is_stamped_for_that_counter(): void
    {
        $this->seedDevice('dev-till-1', ['Till1-XP80', 'Microsoft Print to PDF']);

        $this->testPrint($this->adminId, ['printer' => 'Till1-XP80', 'device_uid' => 'dev-till-1'])
            ->assertOk();

        $job = DB::table('pos_print_jobs')->latest('id')->first();
        $this->assertSame('dev-till-1', $job->device_uid);
        $this->assertSame('Till1-XP80', $job->target_printer);
    }

    public function test_a_counter_can_only_test_its_own_printers(): void
    {
        $this->seedDevice('dev-till-1', ['Till1-XP80']);
        $this->seedDevice('dev-till-2', ['Till2-XP80']);

        $this->testPrint($this->adminId, ['printer' => 'Till2-XP80', 'device_uid' => 'dev-till-1'])
            ->assertStatus(422)
            ->assertJson(['reason' => 'unknown_printer']);
        $this->assertSame(0, DB::table('pos_print_jobs')->count());
    }

    public function test_offline_counter_is_refused_not_queued(): void
    {
        $this->seedDevice('dev-dead', ['Dead-XP80'], false);

        $this->testPrint($this->adminId, ['printer' => 'Dead-XP80', 'device_uid' => 'dev-dead'])
            ->assertStatus(409)
            ->assertJson(['reason' => 'device_offline']);
        $this->assertSame(0, DB::table('pos_print_jobs')->count());
    }

    public function test_the_slip_names_the_queue_it_printed_from(): void
    {
        $this->testPrint($this->adminId, ['printer' => 'XP-80C (copy 2)'])->assertOk();
        $jobId = DB::table('pos_print_jobs')->latest('id')->value('id');

        $html = $this->getJson("/api/agent/print-jobs/{$jobId}/content", [
            'Authorization' => 'Bearer ' . $this->agentKey,
        ])->assertOk()->getContent();

        $this->assertStringContainsString('XP-80C (copy 2)', $html);
        $this->assertStringContainsString('Frosty Grill', $html);
    }

    public function test_stranded_test_slip_fails_instead_of_moving_to_another_printer(): void
    {
        // Counter died right after the press: retargeting the slip to the
        // company default would print paper naming a queue it never used.
        $this->seedDevice('dev-dead', ['Dead-XP80'], false);
        $jobId = DB::table('pos_print_jobs')->insertGetId([
            'company_id' => $this->companyId,
            'type' => 'test',
            'target_printer' => 'Dead-XP80',
            'status' => 'pending',
            'device_uid' => 'dev-dead',
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ]);

        // Any claim poll runs housekeeping for the company.
        $this->getJson('/api/agent/print-jobs', ['Authorization' => 'Bearer ' . $this->agentKey])
            ->assertOk();

        $job = DB::table('pos_print_jobs')->find($jobId);
        $this->assertSame('failed', $job->status);
        $this->assertSame('Dead-XP80', $job->target_printer, 'a test slip must never name a queue it did not print from');
    }
}

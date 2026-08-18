<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PosAgentDevice;
use App\Models\User;
use App\Services\KotPrintService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Task 1194 — Device-aware KOT printer picking ("kisi bhi counter ka
 * printer, kahin se bhi").
 *
 * Multi-counter shops pick ANY counter's printer for KOT-family jobs
 * (Kitchen/KOT, Counter KOT Copy, per-station) and the server routes each
 * job to the agent whose PC owns that printer. Invariants locked here:
 *
 *  1. Union picker: ≥2 registered counters → every counter's printers,
 *     counter-labeled, values "uid::name"; 0–1 counters → today's plain
 *     company-wide list (single-counter shops see ZERO change).
 *  2. Settings save: a device pick stores name + owning uid, validated
 *     against THAT counter's own reported list; invalid → silent null;
 *     legacy plain names keep the company-wide check with a null device.
 *  3. Enqueue stamping: KOT/counter-copy/station jobs are stamped with the
 *     owning device_uid ONLY when that device is ONLINE; offline/legacy →
 *     unstamped (pre-1194 behavior). All paths: transaction KOT, order KOT,
 *     counter copy, station split/pinned, KotPrintService.
 *  4. Stranded rescue: a KOT stamped for a dead counter is RE-STAMPED to
 *     another online counter reporting the same printer name, else parked
 *     as failed — never blind-unstamped (an agent without the printer would
 *     claim and fail).
 *
 * Pattern: APP_ENV=testing + SQLite :memory: + minimal Schema::create.
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' CACHE_STORE=array \
 *     php vendor/bin/phpunit tests/Feature/PosKotDeviceRoutingTest.php --testdox
 */
class PosKotDeviceRoutingTest extends TestCase
{
    private string $agentKey = 'test-agent-key-kot-routing';
    private int $companyId;
    private int $adminId;

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
            // Station routes sit behind feature:kitchen — internal account
            // short-circuits the plan gate, feature_flags turns the module on.
            $t->boolean('is_internal_account')->default(false);
            $t->text('feature_flags')->nullable();
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

        Schema::create('pos_transactions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('pra_status')->nullable();
            $t->string('pra_invoice_number')->nullable();
            $t->string('invoice_mode')->nullable();
            $t->timestamps();
        });

        Schema::create('restaurant_orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('order_number')->nullable();
            $t->string('order_type')->default('dine_in');
            $t->string('status')->default('active');
            $t->timestamps();
        });
        Schema::create('restaurant_order_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('order_id');
            $t->string('item_type')->default('manual');
            $t->unsignedBigInteger('item_id')->nullable();
            $t->string('item_name');
            $t->integer('quantity')->default(1);
            $t->decimal('unit_price', 12, 2)->default(0);
            $t->decimal('subtotal', 12, 2)->default(0);
            $t->timestamp('kot_printed_at')->nullable();
            $t->unsignedInteger('kot_batch_no')->nullable();
            $t->timestamps();
        });
        // Station CRUD routes pass a plan-aware middleware that looks up the
        // active subscription — empty tables = no subscription (tolerated).
        Schema::create('subscriptions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('pricing_plan_id')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('pricing_plans', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('product_type')->nullable();
            $t->text('features')->nullable();
            $t->timestamps();
        });

        Schema::create('pos_stations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('name');
            $t->text('categories')->nullable();
            $t->string('printer_name')->nullable();
            $t->string('printer_device_uid', 64)->nullable();
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('sort')->default(0);
            $t->timestamps();
        });

        $now = now();
        $this->companyId = DB::table('companies')->insertGetId([
            'name' => 'Multi Counter Restaurant', 'product_type' => 'pos',
            'status' => 'approved', 'company_status' => 'approved',
            'agent_api_key' => $this->agentKey,
            'agent_enabled' => true,
            'agent_last_seen' => $now,
            'is_internal_account' => true,
            'feature_flags' => json_encode(['kot' => true, 'kitchen' => true]),
            'pos_printer_settings' => json_encode([
                'silent_print_enabled' => true,
                'receipt_printer' => 'Manager-POS80',
                'kot_printer' => 'Kitchen-XP80',
                'available_printers' => [
                    ['name' => 'Manager-POS80', 'displayName' => 'Manager Thermal', 'isDefault' => true],
                    ['name' => 'Kitchen-XP80', 'displayName' => 'Kitchen XP-80'],
                ],
            ]),
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->adminId = DB::table('users')->insertGetId([
            'name' => 'Owner', 'email' => 'kot-owner@test.pk',
            'password' => Hash::make('Secret@12345'),
            'company_id' => $this->companyId,
            'role' => 'company_admin', 'pos_role' => 'pos_admin',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        Cache::flush();
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function agentGet(string $path): \Illuminate\Testing\TestResponse
    {
        return $this->getJson($path, ['Authorization' => 'Bearer ' . $this->agentKey]);
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

    private function company(): Company
    {
        return Company::find($this->companyId);
    }

    private function setPrinterSettings(array $overrides): void
    {
        $settings = array_merge($this->company()->printerSettings(), $overrides);
        DB::table('companies')->where('id', $this->companyId)
            ->update(['pos_printer_settings' => json_encode($settings)]);
    }

    private function seedOrder(string $type = 'dine_in'): int
    {
        $orderId = DB::table('restaurant_orders')->insertGetId([
            'company_id' => $this->companyId,
            'order_number' => 'ORD-260818-T' . rand(100, 999),
            'order_type' => $type,
            'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('restaurant_order_items')->insert([
            'order_id' => $orderId,
            'item_type' => 'manual',
            'item_name' => 'Chicken Karahi',
            'quantity' => 1, 'unit_price' => 100, 'subtotal' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $orderId;
    }

    private function enqueueKot(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs(User::find($this->adminId), 'pos')
            ->postJson('/pos/api/print-jobs', array_merge(['type' => 'kot'], $payload));
    }

    private function seedTransaction(): int
    {
        return DB::table('pos_transactions')->insertGetId([
            'company_id' => $this->companyId,
            'pra_status' => 'local',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── 1. Union picker ─────────────────────────────────────────────────────

    public function test_union_options_label_every_counters_printers(): void
    {
        $this->seedDevice('dev-c1');
        $this->seedDevice('dev-c2', ['name' => 'Counter 2']);

        $options = PosAgentDevice::kotPrinterOptions($this->company());
        $values = collect($options)->pluck('value')->all();

        $this->assertContains('dev-c1::Counter-dev-c1', $values);
        $this->assertContains('dev-c2::Counter-dev-c2', $values);
        // Counter label rides the option text.
        $c2 = collect($options)->firstWhere('value', 'dev-c2::Counter-dev-c2');
        $this->assertStringContainsString('Counter 2', $c2['label']);
        // Legacy company-list printers not covered by any device stay pickable.
        $this->assertContains('Manager-POS80', $values);
        $this->assertContains('Kitchen-XP80', $values);
    }

    public function test_single_counter_shop_gets_plain_legacy_options(): void
    {
        $this->seedDevice('dev-only');

        $values = collect(PosAgentDevice::kotPrinterOptions($this->company()))->pluck('value')->all();

        $this->assertSame(['Manager-POS80', 'Kitchen-XP80'], $values,
            'fewer than two counters must produce exactly the legacy list');
    }

    // ── 2. Settings save ────────────────────────────────────────────────────

    public function test_device_pick_saves_name_plus_owning_device(): void
    {
        $this->seedDevice('dev-c1');
        $this->seedDevice('dev-c2');

        $this->actingAs(User::find($this->adminId), 'pos')
            ->post('/pos/printer-settings', [
                'silent_print_enabled' => '1',
                'receipt_printer' => 'Manager-POS80',
                'kot_printer' => 'dev-c2::Counter-dev-c2',
                'counter_kot_printer' => 'dev-c1::Counter-dev-c1',
                'counter_kot_enabled' => '1',
            ])->assertRedirect();

        $s = $this->company()->printerSettings();
        $this->assertSame('Counter-dev-c2', $s['kot_printer']);
        $this->assertSame('dev-c2', $s['kot_printer_device']);
        $this->assertSame('Counter-dev-c1', $s['counter_kot_printer']);
        $this->assertSame('dev-c1', $s['counter_kot_printer_device']);
        $this->assertTrue($s['counter_kot_enabled']);
    }

    public function test_device_pick_rejected_when_printer_not_on_that_device(): void
    {
        $this->seedDevice('dev-c1');
        $this->seedDevice('dev-c2');

        $this->actingAs(User::find($this->adminId), 'pos')
            ->post('/pos/printer-settings', [
                'silent_print_enabled' => '1',
                'receipt_printer' => 'Manager-POS80',
                'kot_printer' => 'dev-c2::Counter-dev-c1', // c1's printer claimed on c2
            ])->assertRedirect();

        $s = $this->company()->printerSettings();
        $this->assertNull($s['kot_printer'], 'a printer another counter reported must not save onto this device');
        $this->assertNull($s['kot_printer_device']);
    }

    public function test_legacy_plain_name_save_keeps_null_device(): void
    {
        $this->seedDevice('dev-c1');
        $this->seedDevice('dev-c2');

        $this->actingAs(User::find($this->adminId), 'pos')
            ->post('/pos/printer-settings', [
                'silent_print_enabled' => '1',
                'receipt_printer' => 'Manager-POS80',
                'kot_printer' => 'Kitchen-XP80',
            ])->assertRedirect();

        $s = $this->company()->printerSettings();
        $this->assertSame('Kitchen-XP80', $s['kot_printer']);
        $this->assertNull($s['kot_printer_device']);
    }

    public function test_station_save_stores_owning_device_and_rejects_foreign_printer(): void
    {
        $this->seedDevice('dev-c1');
        $this->seedDevice('dev-c2');

        $this->actingAs(User::find($this->adminId), 'pos')
            ->post('/pos/restaurant/stations', [
                'name' => 'BBQ Counter',
                'printer_name' => 'dev-c2::Counter-dev-c2',
                'is_active' => '1',
            ])->assertRedirect();

        $st = DB::table('pos_stations')->where('company_id', $this->companyId)->first();
        $this->assertNotNull($st);
        $this->assertSame('Counter-dev-c2', $st->printer_name);
        $this->assertSame('dev-c2', $st->printer_device_uid);

        // Unknown/foreign pick = loud validation error, station NOT saved.
        $this->actingAs(User::find($this->adminId), 'pos')
            ->from('/pos/restaurant/kitchen-settings')
            ->post('/pos/restaurant/stations', [
                'name' => 'Fry Counter',
                'printer_name' => 'dev-c1::Counter-dev-c2',
                'is_active' => '1',
            ])->assertRedirect('/pos/restaurant/kitchen-settings')
            ->assertSessionHasErrors('printer_name');
        $this->assertSame(1, DB::table('pos_stations')->where('company_id', $this->companyId)->count());
    }

    // ── 3. Enqueue stamping ────────────────────────────────────────────────

    public function test_order_kot_stamped_for_online_owning_counter_and_claim_isolated(): void
    {
        $this->seedDevice('dev-c1');
        $this->seedDevice('dev-c2');
        $this->setPrinterSettings([
            'kot_printer' => 'Counter-dev-c2',
            'kot_printer_device' => 'dev-c2',
        ]);
        $orderId = $this->seedOrder();

        $this->enqueueKot(['restaurant_order_id' => $orderId])->assertOk()->assertJson(['success' => true]);

        $job = DB::table('pos_print_jobs')->orderByDesc('id')->first();
        $this->assertSame('dev-c2', $job->device_uid);
        $this->assertSame('Counter-dev-c2', $job->target_printer);

        // Claim isolation: counter 1 never sees it, counter 2 gets it.
        $idsC1 = collect($this->agentGet('/api/agent/print-jobs?device_uid=dev-c1')->json('jobs'))->pluck('id')->all();
        $this->assertNotContains($job->id, $idsC1);
        $idsC2 = collect($this->agentGet('/api/agent/print-jobs?device_uid=dev-c2')->json('jobs'))->pluck('id')->all();
        $this->assertContains($job->id, $idsC2);
    }

    public function test_offline_owner_and_legacy_settings_stay_unstamped(): void
    {
        $this->seedDevice('dev-c1');
        $this->seedDevice('dev-c2', ['last_seen_at' => now()->subMinutes(10)]);

        // Offline owning counter → unstamped (any agent may print, popup rules).
        $this->setPrinterSettings(['kot_printer' => 'Counter-dev-c2', 'kot_printer_device' => 'dev-c2']);
        $this->enqueueKot(['restaurant_order_id' => $this->seedOrder()])->assertOk();
        $this->assertNull(DB::table('pos_print_jobs')->orderByDesc('id')->first()->device_uid);

        // Legacy settings (no owning device) → unstamped, exactly as before.
        $this->setPrinterSettings(['kot_printer' => 'Kitchen-XP80', 'kot_printer_device' => null]);
        $this->enqueueKot(['restaurant_order_id' => $this->seedOrder()])->assertOk();
        $this->assertNull(DB::table('pos_print_jobs')->orderByDesc('id')->first()->device_uid);
    }

    public function test_transaction_kot_stamped_for_owning_counter(): void
    {
        $this->seedDevice('dev-c2');
        $this->setPrinterSettings(['kot_printer' => 'Counter-dev-c2', 'kot_printer_device' => 'dev-c2']);

        $this->enqueueKot(['transaction_id' => $this->seedTransaction()])->assertOk();

        $this->assertSame('dev-c2', DB::table('pos_print_jobs')->orderByDesc('id')->first()->device_uid);
    }

    public function test_counter_copy_stamped_for_its_own_counter(): void
    {
        $this->seedDevice('dev-kitchen');
        $this->seedDevice('dev-front');
        $this->setPrinterSettings([
            'kot_printer' => 'Counter-dev-kitchen',
            'kot_printer_device' => 'dev-kitchen',
            'counter_kot_printer' => 'Counter-dev-front',
            'counter_kot_printer_device' => 'dev-front',
            'counter_kot_enabled' => true,
        ]);

        $this->enqueueKot(['restaurant_order_id' => $this->seedOrder('dine_in')])->assertOk();

        $jobs = DB::table('pos_print_jobs')->orderBy('id')->get();
        $this->assertCount(2, $jobs, 'kitchen KOT + counter copy');
        $byPrinter = $jobs->keyBy('target_printer');
        $this->assertSame('dev-kitchen', $byPrinter['Counter-dev-kitchen']->device_uid);
        $this->assertSame('dev-front', $byPrinter['Counter-dev-front']->device_uid);
    }

    public function test_station_pinned_kot_uses_station_owning_counter(): void
    {
        $this->seedDevice('dev-c1');
        $this->seedDevice('dev-c2');
        $this->setPrinterSettings(['kot_printer' => 'Kitchen-XP80', 'kot_printer_device' => null]);
        $stationId = DB::table('pos_stations')->insertGetId([
            'company_id' => $this->companyId,
            'name' => 'BBQ',
            'printer_name' => 'Counter-dev-c2',
            'printer_device_uid' => 'dev-c2',
            'is_active' => true, 'sort' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->enqueueKot([
            'restaurant_order_id' => $this->seedOrder(),
            'station_id' => $stationId,
        ])->assertOk();

        $job = DB::table('pos_print_jobs')->orderByDesc('id')->first();
        $this->assertSame('Counter-dev-c2', $job->target_printer);
        $this->assertSame('dev-c2', $job->device_uid);
    }

    public function test_kot_print_service_stamps_owning_counter(): void
    {
        $this->seedDevice('dev-c1');
        $this->seedDevice('dev-c2');
        $this->setPrinterSettings(['kot_printer' => 'Counter-dev-c2', 'kot_printer_device' => 'dev-c2']);
        $order = \App\Models\RestaurantOrder::find($this->seedOrder());

        $result = KotPrintService::enqueueForOrder($this->company(), $order, $this->adminId);

        $this->assertTrue($result['printed']);
        $this->assertSame('dev-c2', DB::table('pos_print_jobs')->orderByDesc('id')->first()->device_uid);
    }

    // ── 4. Stranded rescue ─────────────────────────────────────────────────

    private function seedStrandedKot(string $uid, string $printer): int
    {
        return DB::table('pos_print_jobs')->insertGetId([
            'company_id' => $this->companyId,
            'type' => 'kot',
            'target_printer' => $printer,
            'restaurant_order_id' => 1,
            'device_uid' => $uid,
            'status' => 'pending',
            'attempts' => 0,
            'created_at' => now()->subMinutes(3),
            'updated_at' => now()->subMinutes(3),
        ]);
    }

    public function test_stranded_kot_restamped_to_online_counter_with_same_printer(): void
    {
        // LAN printer reported by BOTH counters; owner PC died after enqueue.
        $this->seedDevice('dev-dead', [
            'last_seen_at' => now()->subMinutes(10),
            'printers' => [['name' => 'LAN-Kitchen']],
        ]);
        $this->seedDevice('dev-alive', [
            'printers' => [['name' => 'LAN-Kitchen'], ['name' => 'Counter-dev-alive']],
        ]);
        $stuck = $this->seedStrandedKot('dev-dead', 'LAN-Kitchen');

        $this->agentGet('/api/agent/print-jobs')->assertOk(); // housekeeping fires

        $job = DB::table('pos_print_jobs')->where('id', $stuck)->first();
        $this->assertSame('dev-alive', $job->device_uid, 'rescue must RE-STAMP to the counter that has the printer');
        $this->assertSame('pending', $job->status);
        $this->assertSame('LAN-Kitchen', $job->target_printer);
    }

    public function test_stranded_kot_with_no_capable_counter_parks_failed(): void
    {
        // USB printer only the dead counter has — nobody else can print it.
        $this->seedDevice('dev-dead', [
            'last_seen_at' => now()->subMinutes(10),
            'printers' => [['name' => 'USB-Kitchen']],
        ]);
        $this->seedDevice('dev-alive'); // online, but no USB-Kitchen
        $stuck = $this->seedStrandedKot('dev-dead', 'USB-Kitchen');

        $res = $this->agentGet('/api/agent/print-jobs')->assertOk();

        $job = DB::table('pos_print_jobs')->where('id', $stuck)->first();
        $this->assertSame('failed', $job->status, 'no capable counter → park failed (recent-failed strip), never bounce');
        $this->assertStringContainsString('USB-Kitchen', $job->error);
        // …and no agent claimed it.
        $this->assertNotContains($stuck, collect($res->json('jobs'))->pluck('id')->all());
    }

    public function test_stranded_bill_rescue_unchanged_by_kot_rule(): void
    {
        // Task 1166 behavior for bills must survive: unstamp + retarget to the
        // company default receipt printer.
        $stuck = DB::table('pos_print_jobs')->insertGetId([
            'company_id' => $this->companyId,
            'type' => 'bill',
            'target_printer' => 'Counter-dev-dead',
            'transaction_id' => 1,
            'device_uid' => 'dev-dead',
            'status' => 'pending',
            'attempts' => 0,
            'created_at' => now()->subMinutes(3),
            'updated_at' => now()->subMinutes(3),
        ]);

        $this->agentGet('/api/agent/print-jobs')->assertOk();

        $job = DB::table('pos_print_jobs')->where('id', $stuck)->first();
        $this->assertNull($job->device_uid);
        $this->assertSame('Manager-POS80', $job->target_printer);
        // The same legacy poll may already have CLAIMED the rescued bill —
        // that's the point of the rescue (never stuck on a dead counter).
        $this->assertContains($job->status, ['pending', 'printing']);
    }

    // ── 5. Fingerprint ─────────────────────────────────────────────────────

    public function test_owning_device_change_refreshes_pos_config_rev(): void
    {
        $before = $this->company()->posConfigRev();

        $this->setPrinterSettings(['kot_printer_device' => 'dev-c9']);
        $this->assertNotSame($before, $this->company()->posConfigRev(),
            'a deliberate routing change must refresh cached sale screens');

        // A printers-report telemetry rewrite must NOT fake a change.
        $mid = $this->company()->posConfigRev();
        $settings = $this->company()->printerSettings();
        $settings['available_printers'][] = ['name' => 'New-Printer'];
        $settings['printers_reported_at'] = now()->toIso8601String();
        DB::table('companies')->where('id', $this->companyId)
            ->update(['pos_printer_settings' => json_encode($settings)]);
        $this->assertSame($mid, $this->company()->posConfigRev());
    }
}

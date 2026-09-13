<?php

namespace Tests\Feature;

use App\Http\Controllers\AgentController;
use App\Models\Company;
use App\Models\PosAgentDevice;
use App\Models\PosPrintJob;
use App\Models\RestaurantOrder;
use App\Services\KotPrintService;
use App\Support\KotPrintState;
use App\Support\PrinterIdentity;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Issue #63 — zero-loss instant KOT. Reproduces the local/cloud failure
 * windows on disposable schema, then locks the universal contract:
 * one durable intent, instant local print or instant cloud failover,
 * no silent 3–5 minute wait, no duplicate-print weakening.
 */
class KotZeroLossInstantPrintTest extends TestCase
{
    private string $agentKey = 'test-agent-key-kot-zero-loss';

    private int $companyA;

    private int $companyB;

    private int $cashierA;

    private int $cashierB;

    private int $waiterId;

    /** @var array<string, float> */
    private array $timings = [];

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
            $t->string('agent_api_key')->nullable();
            $t->boolean('agent_enabled')->default(false);
            $t->timestamp('agent_last_seen')->nullable();
            $t->text('pos_printer_settings')->nullable();
            $t->boolean('restaurant_mode')->default(true);
            $t->boolean('kot_on_final_if_unsent')->default(true);
            $t->boolean('pos_kot_full_mode')->default(false);
            $t->softDeletes();
            $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->nullable();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->string('pos_role')->nullable();
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
            $t->text('printed_item_ids')->nullable();
            $t->string('status')->default('pending');
            $t->string('claim_token')->nullable();
            $t->timestamp('content_fetched_at')->nullable();
            $t->string('device_uid')->nullable();
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
            $t->timestamp('last_seen_at')->nullable();
            $t->text('printers')->nullable();
            $t->timestamp('printers_reported_at')->nullable();
            $t->string('receipt_printer')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'device_uid']);
        });
        Schema::create('restaurant_orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('order_number');
            $t->string('order_type')->default('takeaway');
            $t->string('status')->default('held');
            $t->timestamp('kot_sent_at')->nullable();
            $t->unsignedInteger('kot_print_count')->default(0);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });
        Schema::create('restaurant_order_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('order_id');
            $t->string('item_type')->default('manual');
            $t->unsignedBigInteger('item_id')->nullable();
            $t->string('item_name');
            $t->decimal('quantity', 8, 2)->default(1);
            $t->decimal('unit_price', 10, 2)->default(0);
            $t->decimal('subtotal', 10, 2)->default(0);
            $t->timestamp('kot_printed_at')->nullable();
            $t->unsignedInteger('kot_batch_no')->nullable();
            $t->timestamps();
        });
        Schema::create('pos_stations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('name');
            $t->text('categories')->nullable();
            $t->string('printer_name')->nullable();
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('sort')->default(0);
            $t->timestamps();
        });

        $now = now();
        $this->companyA = DB::table('companies')->insertGetId([
            'name' => 'Zero Loss A', 'product_type' => 'pos',
            'status' => 'approved', 'company_status' => 'approved',
            'agent_api_key' => $this->agentKey, 'agent_enabled' => true,
            'agent_last_seen' => $now, 'restaurant_mode' => true,
            'pos_printer_settings' => json_encode([
                'silent_print_enabled' => true,
                'kot_printer' => 'Kitchen Printer',
                'available_printers' => [
                    ['name' => 'Kitchen Printer', 'displayName' => 'Kitchen'],
                ],
            ]),
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->companyB = DB::table('companies')->insertGetId([
            'name' => 'Zero Loss B', 'product_type' => 'pos',
            'status' => 'approved', 'company_status' => 'approved',
            'agent_api_key' => 'other-tenant-key', 'agent_enabled' => true,
            'agent_last_seen' => $now, 'restaurant_mode' => true,
            'pos_printer_settings' => json_encode([
                'silent_print_enabled' => true,
                'kot_printer' => 'Tenant-B-Kitchen',
            ]),
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->cashierA = DB::table('users')->insertGetId([
            'name' => 'Cashier One', 'email' => 'c1@test.pk', 'company_id' => $this->companyA,
            'pos_role' => 'pos_cashier', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->cashierB = DB::table('users')->insertGetId([
            'name' => 'Cashier Two', 'email' => 'c2@test.pk', 'company_id' => $this->companyA,
            'pos_role' => 'pos_cashier', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->waiterId = DB::table('users')->insertGetId([
            'name' => 'Waiter Seven', 'email' => 'w7@test.pk', 'company_id' => $this->companyA,
            'pos_role' => 'pos_waiter', 'created_at' => $now, 'updated_at' => $now,
        ]);
        Cache::flush();
    }

    private function companyA(): Company
    {
        return Company::findOrFail($this->companyA);
    }

    private function holdOrder(int $companyId, string $number, ?int $userId = null): RestaurantOrder
    {
        $orderId = DB::table('restaurant_orders')->insertGetId([
            'company_id' => $companyId,
            'order_number' => $number,
            'order_type' => 'takeaway',
            'status' => 'held',
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('restaurant_order_items')->insert([
            'order_id' => $orderId, 'item_type' => 'manual', 'item_name' => 'Demo Item',
            'quantity' => 1, 'unit_price' => 100, 'subtotal' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return RestaurantOrder::findOrFail($orderId);
    }

    private function seedDevice(string $uid, array $printers): PosAgentDevice
    {
        return PosAgentDevice::create([
            'company_id' => $this->companyA,
            'device_uid' => $uid,
            'hostname' => strtoupper($uid).'-PC',
            'last_seen_at' => now(),
            'printers' => $printers,
            'printers_reported_at' => now(),
        ]);
    }

    private function claim(string $deviceUid = 'dev-kitchen'): array
    {
        $started = hrtime(true);
        $res = $this->getJson('/api/agent/print-jobs?device_uid='.$deviceUid, [
            'Authorization' => 'Bearer '.$this->agentKey,
        ]);
        $this->timings['claim_ms'] = (hrtime(true) - $started) / 1e6;
        $res->assertOk();

        return $res->json('jobs') ?? [];
    }

    public function test_reproduces_exact_printer_name_window_then_claims_after_case_space_fold(): void
    {
        $this->seedDevice('dev-kitchen', [
            ['name' => 'kitchen  printer', 'displayName' => 'Kitchen'],
        ]);
        $order = $this->holdOrder($this->companyA, 'ZL-CASE');
        $queued = KotPrintService::enqueueForOrder($this->companyA(), $order, $this->cashierA, true);
        $this->assertTrue($queued['printed']);
        $this->assertNotSame([], $queued['job_ids']);
        $job = PosPrintJob::find($queued['job_ids'][0]);
        $this->assertSame('Kitchen Printer', $job->target_printer, 'snapshot is not rewritten');
        $this->assertTrue(PrinterIdentity::same($job->target_printer, 'kitchen  printer'));

        $claimed = $this->claim('dev-kitchen');
        $this->assertCount(1, $claimed, 'case/space mismatch must not hide the job');
        $this->assertSame($job->id, $claimed[0]['id']);
    }

    public function test_legacy_null_device_uid_and_second_tenant_stay_isolated(): void
    {
        $this->seedDevice('dev-kitchen', [
            ['name' => 'Kitchen Printer', 'displayName' => 'Kitchen'],
        ]);
        $a = $this->holdOrder($this->companyA, 'ZL-A');
        $b = $this->holdOrder($this->companyB, 'ZL-B');
        KotPrintService::enqueueForOrder($this->companyA(), $a, $this->cashierA, true);
        KotPrintService::enqueueForOrder(Company::findOrFail($this->companyB), $b, null, true);

        $legacy = $this->getJson('/api/agent/print-jobs', [
            'Authorization' => 'Bearer '.$this->agentKey,
        ])->assertOk()->json('jobs');
        $ids = collect($legacy)->pluck('id')->all();
        $this->assertNotSame([], $ids);
        $this->assertSame(
            0,
            PosPrintJob::whereIn('id', $ids)->where('company_id', $this->companyB)->count(),
            'tenant B jobs are invisible to tenant A agent'
        );
        $this->assertSame(1, PosPrintJob::where('company_id', $this->companyB)->where('status', 'pending')->count());
    }

    public function test_concurrent_burst_two_cashiers_and_waiter_create_one_job_per_order(): void
    {
        $this->seedDevice('dev-kitchen', [
            ['name' => 'Kitchen Printer'],
        ]);
        $started = hrtime(true);
        $jobIds = [];
        foreach (range(1, 8) as $n) {
            $user = match ($n % 3) {
                0 => $this->waiterId,
                1 => $this->cashierA,
                default => $this->cashierB,
            };
            $order = $this->holdOrder($this->companyA, 'ZL-BURST-'.$n, $user);
            $first = KotPrintService::enqueueForOrder($this->companyA(), $order, $user, true);
            $retry = KotPrintService::enqueueForOrder($this->companyA(), $order, $user, true);
            $this->assertSame($first['job_ids'], $retry['job_ids'], 'double Enter / retry must collapse');
            $jobIds = array_merge($jobIds, $first['job_ids'] ?? []);
        }
        $this->timings['burst_enqueue_ms'] = (hrtime(true) - $started) / 1e6;
        $this->assertCount(8, $jobIds);
        $this->assertSame(8, PosPrintJob::where('company_id', $this->companyA)->where('type', 'kot')->where('status', 'pending')->count());

        $claimed = $this->claim('dev-kitchen');
        $this->assertCount(8, $claimed, 'batch of 10 covers a >7 order burst');
    }

    public function test_silent_print_off_and_missing_printer_do_not_invent_a_cloud_job(): void
    {
        $company = $this->companyA();
        $settings = $company->printerSettings();
        $settings['silent_print_enabled'] = false;
        $company->pos_printer_settings = $settings;
        $company->save();

        $order = $this->holdOrder($this->companyA, 'ZL-OFF');
        $out = KotPrintService::enqueueForOrder($company->fresh(), $order, $this->cashierA, true);
        $this->assertFalse($out['printed']);
        $this->assertSame('disabled', $out['reason']);
        $this->assertSame(0, PosPrintJob::where('restaurant_order_id', $order->id)->where('status', 'pending')->count());
        $this->assertSame(
            $settings['kot_printer'],
            $company->fresh()->printerSettings()['kot_printer'],
            'saved printer setting is not rewritten'
        );
    }

    public function test_fresh_local_handoff_suppresses_cloud_until_watchdog_or_handback(): void
    {
        $order = $this->holdOrder($this->companyA, 'ZL-HAND');
        $lineIds = DB::table('restaurant_order_items')->where('order_id', $order->id)->pluck('id')->map(fn ($i) => (int) $i)->all();
        $handoff = KotPrintService::openLocalHandoff($this->companyA(), $order, 'zl-hand', $lineIds, 'dev-kitchen', $this->waiterId, now());
        $this->assertSame(KotPrintService::LOCAL_STATUS, $handoff->status);
        $this->assertSame(KotPrintState::PRINTING, KotPrintState::forJob($handoff)['key']);

        $cloud = KotPrintService::enqueueForOrder($this->companyA(), $order->fresh(), $this->cashierA, true);
        $this->assertSame('local_handoff', $cloud['reason']);
        $this->assertSame([], $cloud['job_ids']);

        $this->assertSame(['expired' => 0, 'queued' => 0], KotPrintService::expireLocalHandoffs($this->companyA()));
        $this->assertSame(['expired' => 0, 'queued' => 0, 'companies' => 0], KotPrintService::expireLocalHandoffsAll());
    }

    public function test_independent_watchdog_recovers_overdue_handoff_without_agent_poll(): void
    {
        $order = $this->holdOrder($this->companyA, 'ZL-WD');
        $lineIds = DB::table('restaurant_order_items')->where('order_id', $order->id)->pluck('id')->map(fn ($i) => (int) $i)->all();
        KotPrintService::openLocalHandoff($this->companyA(), $order, 'zl-wd', $lineIds, null, $this->waiterId, now());
        DB::table('pos_print_jobs')->where('claim_token', 'ac:kot:zl-wd')
            ->update(['created_at' => now()->subSeconds(KotPrintService::LOCAL_HANDOFF_TIMEOUT_SECONDS + 5)]);

        $started = hrtime(true);
        $exit = Artisan::call('print:expire-local-handoffs');
        $this->timings['watchdog_ms'] = (hrtime(true) - $started) / 1e6;
        $this->assertSame(0, $exit);
        $this->assertSame(KotPrintService::LOCAL_EXPIRED_STATUS, DB::table('pos_print_jobs')->where('claim_token', 'ac:kot:zl-wd')->value('status'));
        $this->assertSame(1, PosPrintJob::where('restaurant_order_id', $order->id)->where('status', 'pending')->count());
        $this->assertSame(
            KotPrintState::RECOVERED,
            KotPrintState::forJob(PosPrintJob::where('claim_token', 'ac:kot:zl-wd')->first())['key']
        );
    }

    public function test_print_path_timings_and_lost_ack_do_not_duplicate(): void
    {
        $this->seedDevice('dev-kitchen', [
            ['name' => 'Kitchen Printer'],
        ]);
        $order = $this->holdOrder($this->companyA, 'ZL-TIME', $this->cashierA);

        $t0 = hrtime(true);
        $queued = KotPrintService::enqueueForOrder($this->companyA(), $order, $this->cashierA, true);
        $this->timings['job_create_ms'] = (hrtime(true) - $t0) / 1e6;
        $this->assertTrue($queued['printed']);
        $jobId = (int) $queued['job_ids'][0];

        $claimed = $this->claim('dev-kitchen');
        $this->assertSame([$jobId], collect($claimed)->pluck('id')->all());

        $t1 = hrtime(true);
        $this->postJson('/api/agent/print-jobs/'.$jobId.'/result', [
            'success' => true,
            'device_uid' => 'dev-kitchen',
        ], ['Authorization' => 'Bearer '.$this->agentKey])->assertOk();
        $this->timings['result_ack_ms'] = (hrtime(true) - $t1) / 1e6;
        $this->assertSame('done', PosPrintJob::find($jobId)->status);
        $this->assertSame(KotPrintState::PRINTED, KotPrintState::forJob(PosPrintJob::find($jobId))['key']);

        $lost = $this->holdOrder($this->companyA, 'ZL-LOST');
        $lostQ = KotPrintService::enqueueForOrder($this->companyA(), $lost, $this->cashierB, true);
        $lostId = (int) $lostQ['job_ids'][0];
        $this->claim('dev-kitchen');
        PosPrintJob::whereKey($lostId)->update([
            'content_fetched_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ]);
        Cache::flush();
        $again = $this->claim('dev-kitchen');
        $this->assertSame([], collect($again)->pluck('id')->all());
        $this->assertSame('failed', PosPrintJob::find($lostId)->status);
        $this->assertStringStartsWith('unconfirmed_after_print_content_fetched', (string) PosPrintJob::find($lostId)->error);

        fwrite(STDOUT, "\nKOT print-path timings (ms): ".json_encode($this->timings, JSON_PRETTY_PRINT)."\n");
        foreach ($this->timings as $ms) {
            $this->assertLessThan(2000, $ms, 'local print-path step stayed under 2s');
        }
    }

    public function test_agent_without_the_kitchen_queue_cannot_claim_an_unstamped_kot(): void
    {
        $this->seedDevice('dev-office', [
            ['name' => 'Office-Laser'],
        ]);
        $this->seedDevice('dev-kitchen', [
            ['name' => 'Kitchen Printer'],
        ]);
        $order = $this->holdOrder($this->companyA, 'ZL-OFFICE');
        KotPrintService::enqueueForOrder($this->companyA(), $order, $this->cashierA, true);

        $wrong = $this->claim('dev-office');
        $this->assertSame([], $wrong);
        $right = $this->claim('dev-kitchen');
        $this->assertCount(1, $right);
    }
}

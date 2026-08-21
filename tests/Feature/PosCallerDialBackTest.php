<?php

namespace Tests\Feature;

use App\Http\Controllers\PosCallerIdController;
use App\Models\Company;
use App\Models\User;
use App\Services\PosFeatureService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * CALL BACK FROM THE POS (Task 1381) — the dial-request queue.
 *
 * Invariants locked here:
 *   1. A dial request is only queued when a paired phone polled the dial queue
 *      RECENTLY. Otherwise POS gets sent:false + the number to dial by hand:
 *        - device online but never polled (old app) → reason 'old_app'
 *        - nothing paired at all                    → reason 'no_device'
 *        - new app but notifications switched off   → reason 'notif_off'
 *      Both are HTTP 200 — the cashier must never hit a dead end.
 *   2. called_back_at is stamped either way (the cashier acted on the call),
 *      and caller-recent surfaces it as called_back / called_back_at.
 *   3. Gating: caller_id toggle OFF or a plan without Caller ID → 403; a
 *      cashier CAN call back (counter staff handle the calls).
 *   4. Claim is exactly-once: a delivered request is never handed out twice,
 *      and one shop's request never reaches another shop's phone.
 *   5. A stale request is expired, never delivered — a phone that wakes up
 *      late must not fire a random call. A new request also expires the
 *      previous pending one.
 *   6. The poll marks the device dial-capable (supports_dial + dial_seen_at)
 *      — that stamp is what makes the NEXT call back reach the phone. The
 *      phone also reports whether it can actually SHOW the offer; a phone with
 *      notifications switched off keeps polling but must fall back with
 *      reason 'notif_off' instead of a silent "sent".
 *   7. dial-result moves delivered → dialed/failed, bound to the device that
 *      claimed the row — neither another shop's phone nor a second phone in
 *      the same shop can close someone else's request.
 *
 * Pattern: sqlite :memory: + minimal Schema::create, controller invoked
 * directly (same as PosCallerIdV2Test).
 */
class PosCallerDialBackTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        PosFeatureService::flushGateCaches();
        Cache::flush();           // dialSupported()/calledBackSupported() schema probes
        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('product_type')->default('pos');
            $t->boolean('is_internal_account')->default(false);
            $t->boolean('caller_id_enabled')->default(false);
            $t->string('caller_app_token')->nullable();
            $t->timestamp('caller_app_last_seen_at')->nullable();
            $t->string('status')->default('active');
            $t->string('company_status')->default('active');
            $t->softDeletes();
            $t->timestamps();
        });
        Schema::create('pricing_plans', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('product_type')->default('pos');
            $t->boolean('is_trial')->default(false);
            $t->boolean('caller_id_enabled')->default(false);
            $t->timestamps();
        });
        Schema::create('subscriptions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('pricing_plan_id')->nullable();
            $t->boolean('active')->default(true);
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();
            $t->timestamp('trial_ends_at')->nullable();
            $t->string('override_type')->default('none');
            $t->timestamp('override_until')->nullable();
            $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->string('name')->nullable();
            $t->string('email')->nullable();
            $t->string('password')->nullable();
            $t->boolean('is_active')->default(true);
            $t->string('role')->nullable();
            $t->string('pos_role')->nullable();
            $t->text('pos_custom_access')->nullable();
            $t->timestamps();
        });
        Schema::create('pos_caller_devices', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('device', 120)->nullable();
            $t->string('token_hash', 64);
            $t->timestamp('last_seen_at')->nullable();
            $t->boolean('supports_dial')->default(false);
            $t->timestamp('dial_seen_at')->nullable();
            $t->timestamps();
        });
        Schema::create('pos_caller_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('phone')->nullable();
            $t->string('caller_name')->nullable();
            $t->string('source')->default('sim');
            $t->timestamp('ring_at')->nullable();
            $t->timestamp('cleared_at')->nullable();
            $t->timestamp('called_back_at')->nullable();
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('pos_caller_dial_requests', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('event_id')->nullable();
            $t->string('phone', 32);
            $t->string('dial_digits', 32);
            $t->string('caller_name', 120)->nullable();
            $t->string('status', 16)->default('pending');
            $t->unsignedBigInteger('requested_by')->nullable();
            $t->unsignedBigInteger('device_id')->nullable();
            $t->string('claim_token', 40)->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('delivered_at')->nullable();
            $t->timestamp('dialed_at')->nullable();
            $t->string('error', 190)->nullable();
            $t->timestamps();
        });
        Schema::create('pos_customers', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('name')->nullable();
            $t->string('phone')->nullable();
            $t->string('address')->nullable();
            $t->decimal('khata_balance', 12, 2)->default(0);
            $t->timestamps();
        });
        Schema::create('pos_transactions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->string('customer_name')->nullable();
            $t->string('customer_phone')->nullable();
            $t->string('status')->nullable();
            $t->string('transaction_type')->nullable();
            $t->decimal('total_amount', 12, 2)->default(0);
            $t->timestamps();
        });
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function makeCompany(array $companyAttrs = []): Company
    {
        $companyId = DB::table('companies')->insertGetId(array_merge([
            'name' => 'Test Shop', 'product_type' => 'pos',
            'caller_id_enabled' => true,
            'created_at' => now(), 'updated_at' => now(),
        ], $companyAttrs));
        $planId = DB::table('pricing_plans')->insertGetId([
            'name' => 'Unlimited', 'product_type' => 'pos',
            'caller_id_enabled' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('subscriptions')->insert([
            'company_id' => $companyId, 'pricing_plan_id' => $planId, 'active' => true,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'override_type' => 'none', 'created_at' => now(), 'updated_at' => now(),
        ]);
        return Company::findOrFail($companyId);
    }

    private function lockPlan(Company $company): void
    {
        DB::table('pricing_plans')
            ->whereIn('id', DB::table('subscriptions')->where('company_id', $company->id)->pluck('pricing_plan_id'))
            ->update(['name' => 'Business', 'caller_id_enabled' => false]);
        PosFeatureService::flushGateCaches();
    }

    private function makeUser(int $companyId, string $posRole = 'pos_admin', string $email = 'admin@shop.test'): User
    {
        $user = User::forceCreate([
            'company_id' => $companyId, 'name' => 'U ' . $posRole, 'email' => $email,
            'password' => Hash::make('secret123'), 'is_active' => true, 'pos_role' => $posRole,
        ]);
        Auth::guard('pos')->setUser($user);
        return $user;
    }

    /**
     * Pair a phone. $dialSeen = when it last polled the DIAL queue (null = old
     * app). $canShow = whether that poll said notifications are usable; the
     * default follows $dialSeen, pass false for "new app, notifications off".
     */
    private function pairPhone(int $companyId, ?\Carbon\Carbon $dialSeen = null, string $device = 'Counter', ?bool $canShow = null): string
    {
        $plain = $companyId . '|' . bin2hex(random_bytes(16)) . $device;
        DB::table('pos_caller_devices')->insert([
            'company_id' => $companyId,
            'device' => $device,
            'token_hash' => hash('sha256', $plain),
            'last_seen_at' => now(),
            'supports_dial' => $canShow ?? ($dialSeen !== null),
            'dial_seen_at' => $dialSeen,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $plain;
    }

    /** POS → "call back is number par". */
    private function dial(array $payload): array
    {
        $request = Request::create('/pos/api/caller-dial', 'POST', $payload);
        $request->setLaravelSession(app('session.store'));
        app()->instance('request', $request);
        $res = app(PosCallerIdController::class)->dialBack($request);
        return ['status' => $res->getStatusCode(), 'body' => $res->getData(true)];
    }

    /**
     * Phone → pending requests claim karo.
     * $notif = phone khud batata hai ke us par notification dikh sakti hai
     * (Android 13+ ki permission / channel). Asli app hamesha bhejti hai.
     */
    private function poll(string $token, bool $notif = true): array
    {
        $request = Request::create('/api/caller-app/v1/dial-requests', 'GET', ['notif' => $notif ? 1 : 0]);
        $request->headers->set('Authorization', 'Bearer ' . $token);
        app()->instance('request', $request);
        return app(PosCallerIdController::class)->appDialRequests($request)->getData(true);
    }

    /** Phone → nateeja wapas. */
    private function dialResult(string $token, array $payload): array
    {
        $request = Request::create('/api/caller-app/v1/dial-result', 'POST', $payload);
        $request->headers->set('Authorization', 'Bearer ' . $token);
        app()->instance('request', $request);
        return app(PosCallerIdController::class)->appDialResult($request)->getData(true);
    }

    private function recent(): array
    {
        $request = Request::create('/pos/api/caller-recent', 'GET');
        $request->setLaravelSession(app('session.store'));
        app()->instance('request', $request);
        return app(PosCallerIdController::class)->recentCalls($request)->getData(true);
    }

    private function ringEvent(int $companyId, string $phone = '923001234567', string $name = 'Ali'): int
    {
        return (int) DB::table('pos_caller_events')->insertGetId([
            'company_id' => $companyId, 'phone' => $phone, 'caller_name' => $name,
            'source' => 'sim', 'ring_at' => now(), 'created_at' => now(),
        ]);
    }

    // ─── 1. Queue + happy path ──────────────────────────────────────────────

    public function test_call_back_queues_a_request_when_a_dial_ready_phone_is_paired(): void
    {
        $company = $this->makeCompany();
        app()->instance('currentCompanyId', (int) $company->id);
        $this->makeUser((int) $company->id);
        $this->pairPhone((int) $company->id, now());

        $out = $this->dial(['phone' => '0300-1234567', 'name' => 'Ali']);

        $this->assertSame(200, $out['status']);
        $this->assertTrue($out['body']['sent'], 'a dial-ready phone must receive the request');
        $row = DB::table('pos_caller_dial_requests')->first();
        $this->assertNotNull($row);
        $this->assertSame('pending', $row->status);
        $this->assertSame('923001234567', $row->phone);
        // Phone ko local shakal chahiye — +92 wala number kuch dialers par galat lagta hai.
        $this->assertSame('03001234567', $row->dial_digits);
        $this->assertNotNull($row->expires_at, 'request must expire on its own');
    }

    public function test_delivered_request_is_never_handed_out_twice(): void
    {
        $company = $this->makeCompany();
        app()->instance('currentCompanyId', (int) $company->id);
        $this->makeUser((int) $company->id);
        $token = $this->pairPhone((int) $company->id, now());
        $this->dial(['phone' => '03001234567']);

        $first = $this->poll($token);
        $this->assertCount(1, $first['requests']);
        $this->assertSame('03001234567', $first['requests'][0]['dial']);
        $this->assertGreaterThan(0, $first['requests'][0]['expires_in']);
        $this->assertSame('delivered', DB::table('pos_caller_dial_requests')->value('status'));

        $second = $this->poll($token);
        $this->assertCount(0, $second['requests'], 'a claimed request must not come back');
    }

    public function test_dial_result_marks_the_request_dialed(): void
    {
        $company = $this->makeCompany();
        app()->instance('currentCompanyId', (int) $company->id);
        $this->makeUser((int) $company->id);
        $token = $this->pairPhone((int) $company->id, now());
        $this->dial(['phone' => '03001234567']);
        $id = $this->poll($token)['requests'][0]['id'];

        $this->dialResult($token, ['id' => $id, 'status' => 'dialed']);

        $row = DB::table('pos_caller_dial_requests')->find($id);
        $this->assertSame('dialed', $row->status);
        $this->assertNotNull($row->dialed_at);
    }

    // ─── 2. No dead end ─────────────────────────────────────────────────────

    public function test_old_app_phone_gets_the_number_back_not_an_error(): void
    {
        $company = $this->makeCompany();
        app()->instance('currentCompanyId', (int) $company->id);
        $this->makeUser((int) $company->id);
        $this->pairPhone((int) $company->id, null);   // online, dial queue kabhi poll nahi ki

        $out = $this->dial(['phone' => '03001234567']);

        $this->assertSame(200, $out['status'], 'POS must show the fallback card, not an error');
        $this->assertFalse($out['body']['sent']);
        $this->assertSame('old_app', $out['body']['reason']);
        $this->assertSame('03001234567', $out['body']['dial']);
        $this->assertSame(0, DB::table('pos_caller_dial_requests')->count());
    }

    public function test_no_paired_phone_gets_the_number_back(): void
    {
        $company = $this->makeCompany();
        app()->instance('currentCompanyId', (int) $company->id);
        $this->makeUser((int) $company->id);

        $out = $this->dial(['phone' => '03001234567']);

        $this->assertSame(200, $out['status']);
        $this->assertFalse($out['body']['sent']);
        $this->assertSame('no_device', $out['body']['reason']);
        $this->assertSame('03001234567', $out['body']['dial']);
    }

    public function test_a_phone_that_stopped_polling_is_not_dial_ready(): void
    {
        $company = $this->makeCompany();
        app()->instance('currentCompanyId', (int) $company->id);
        $this->makeUser((int) $company->id);
        // last_seen_at fresh (6-hour "paired" window) but the dial poll is old:
        // the request would just rot in the queue, so POS must not promise it.
        $this->pairPhone((int) $company->id, now()->subMinutes(10));

        $out = $this->dial(['phone' => '03001234567']);

        $this->assertFalse($out['body']['sent']);
        $this->assertSame('old_app', $out['body']['reason']);
    }

    // ─── 3. Gating ──────────────────────────────────────────────────────────

    public function test_toggle_off_and_plan_lock_block_call_back(): void
    {
        $company = $this->makeCompany(['caller_id_enabled' => false]);
        app()->instance('currentCompanyId', (int) $company->id);
        $this->makeUser((int) $company->id);
        $this->pairPhone((int) $company->id, now());

        $this->assertSame(403, $this->dial(['phone' => '03001234567'])['status']);

        DB::table('companies')->where('id', $company->id)->update(['caller_id_enabled' => true]);
        $this->lockPlan(Company::findOrFail($company->id));
        $this->assertSame(403, $this->dial(['phone' => '03001234567'])['status']);
        $this->assertSame(0, DB::table('pos_caller_dial_requests')->count());
    }

    public function test_cashier_can_call_back(): void
    {
        $company = $this->makeCompany();
        app()->instance('currentCompanyId', (int) $company->id);
        $this->makeUser((int) $company->id, 'pos_cashier', 'cash@shop.test');
        $this->pairPhone((int) $company->id, now());

        $out = $this->dial(['phone' => '03001234567']);

        $this->assertSame(200, $out['status'], 'counter staff handle the calls');
        $this->assertTrue($out['body']['sent']);
    }

    // ─── 4. Shop isolation ──────────────────────────────────────────────────

    public function test_another_shops_phone_never_sees_the_request(): void
    {
        $a = $this->makeCompany(['name' => 'Shop A']);
        $b = $this->makeCompany(['name' => 'Shop B']);
        $this->makeUser((int) $a->id);
        $this->pairPhone((int) $a->id, now(), 'A phone');
        $tokenB = $this->pairPhone((int) $b->id, now(), 'B phone');

        app()->instance('currentCompanyId', (int) $a->id);
        $this->dial(['phone' => '03001234567']);

        $this->assertCount(0, $this->poll($tokenB)['requests'], "another shop's number must never leave the shop");
        // ...aur B ka dial-result A ki row ko chhoo bhi na sake.
        $id = (int) DB::table('pos_caller_dial_requests')->value('id');
        DB::table('pos_caller_dial_requests')->where('id', $id)->update(['status' => 'delivered']);
        $this->assertSame(0, $this->dialResult($tokenB, ['id' => $id, 'status' => 'dialed'])['updated']);
        $this->assertSame('delivered', DB::table('pos_caller_dial_requests')->value('status'));
    }

    // ─── 5. Nothing stale ever dials ────────────────────────────────────────

    public function test_expired_request_is_never_delivered(): void
    {
        $company = $this->makeCompany();
        app()->instance('currentCompanyId', (int) $company->id);
        $this->makeUser((int) $company->id);
        $token = $this->pairPhone((int) $company->id, now());
        $this->dial(['phone' => '03001234567']);
        // Phone der se jaaga — request budhi ho chuki.
        DB::table('pos_caller_dial_requests')->update(['expires_at' => now()->subMinute()]);

        $this->assertCount(0, $this->poll($token)['requests']);
        $this->assertSame('expired', DB::table('pos_caller_dial_requests')->value('status'));
    }

    public function test_a_new_request_expires_the_previous_pending_one(): void
    {
        $company = $this->makeCompany();
        app()->instance('currentCompanyId', (int) $company->id);
        $this->makeUser((int) $company->id);
        $token = $this->pairPhone((int) $company->id, now());

        $this->dial(['phone' => '03001234567']);
        $this->dial(['phone' => '03219876543']);

        $live = $this->poll($token)['requests'];
        $this->assertCount(1, $live, 'only the latest request may reach the phone');
        $this->assertSame('03219876543', $live[0]['dial']);
        $this->assertSame(1, DB::table('pos_caller_dial_requests')->where('status', 'expired')->count());
    }

    // ─── 6. Poll marks the phone dial-capable ───────────────────────────────

    public function test_polling_makes_the_phone_dial_ready_for_the_next_call_back(): void
    {
        $company = $this->makeCompany();
        app()->instance('currentCompanyId', (int) $company->id);
        $this->makeUser((int) $company->id);
        $token = $this->pairPhone((int) $company->id, null);   // "purani app" jaisa

        $this->assertFalse($this->dial(['phone' => '03001234567'])['body']['sent']);

        $out = $this->poll($token);                            // nai app ne poll kiya
        $this->assertSame(5000, $out['poll_ms']);
        $device = DB::table('pos_caller_devices')->first();
        $this->assertTrue((bool) $device->supports_dial);
        $this->assertNotNull($device->dial_seen_at);

        $this->assertTrue($this->dial(['phone' => '03001234567'])['body']['sent']);
    }

    /**
     * Android 13+ par notification permission na ho to notify() KHAMOSHI se
     * kuch nahi dikhata — koi error nahi aata. Aisa phone poll karta rehta hai,
     * is liye us par "bhej diya" kehna cashier ko seedha dead end par le jata.
     */
    public function test_a_phone_that_cannot_show_notifications_is_not_dial_ready(): void
    {
        $company = $this->makeCompany();
        app()->instance('currentCompanyId', (int) $company->id);
        $this->makeUser((int) $company->id);
        $this->pairPhone((int) $company->id, now(), 'Counter', false);   // nai app, notifications band

        $out = $this->dial(['phone' => '03001234567', 'name' => 'Ali']);

        $this->assertSame(200, $out['status']);
        $this->assertFalse($out['body']['sent'], 'a silenced phone must never count as delivered');
        $this->assertSame('notif_off', $out['body']['reason'], 'cashier ko theek wajah chahiye');
        $this->assertSame('03001234567', $out['body']['dial'], 'fallback must still carry the number');
        $this->assertSame(0, DB::table('pos_caller_dial_requests')->count());
        $this->assertFalse($this->recent()['dial_ready']);
    }

    public function test_the_notification_flag_from_the_phone_drives_dial_readiness(): void
    {
        $company = $this->makeCompany();
        app()->instance('currentCompanyId', (int) $company->id);
        $this->makeUser((int) $company->id);
        $token = $this->pairPhone((int) $company->id, null);

        // Poll aaya magar phone ne kaha "notifications band hain".
        $this->poll($token, false);
        $device = DB::table('pos_caller_devices')->first();
        $this->assertNotNull($device->dial_seen_at, 'poll itself proves the app is new');
        $this->assertFalse((bool) $device->supports_dial, 'but it cannot show the offer');
        $this->assertSame('notif_off', $this->dial(['phone' => '03001234567'])['body']['reason']);

        // User ne settings se on kar diya — agla poll sach badal deta hai.
        $this->poll($token, true);
        $this->assertTrue((bool) DB::table('pos_caller_devices')->value('supports_dial'));
        $this->assertTrue($this->dial(['phone' => '03001234567'])['body']['sent']);
    }

    /**
     * Ek hi shop ke do phone paired ho sakte hain. Row usi phone ki hai jis ne
     * claim ki — doosra phone us ka nateeja likhne ka haq nahi rakhta.
     */
    public function test_another_phone_in_the_same_shop_cannot_close_a_claimed_request(): void
    {
        $company = $this->makeCompany();
        app()->instance('currentCompanyId', (int) $company->id);
        $this->makeUser((int) $company->id);
        $counter = $this->pairPhone((int) $company->id, now(), 'Counter');
        $spare = $this->pairPhone((int) $company->id, now(), 'Spare');

        $this->dial(['phone' => '03001234567']);
        $id = $this->poll($counter)['requests'][0]['id'];

        $this->assertSame(0, $this->dialResult($spare, ['id' => $id, 'status' => 'dialed'])['updated']);
        $this->assertSame('delivered', DB::table('pos_caller_dial_requests')->value('status'));

        // Jis ne claim ki thi, wohi band kar sakta hai.
        $this->assertSame(1, $this->dialResult($counter, ['id' => $id, 'status' => 'dialed'])['updated']);
        $this->assertSame('dialed', DB::table('pos_caller_dial_requests')->value('status'));
    }

    // ─── 7. "Call back kiya" record ─────────────────────────────────────────

    public function test_called_back_is_recorded_and_shown_in_recent_calls(): void
    {
        $company = $this->makeCompany();
        app()->instance('currentCompanyId', (int) $company->id);
        $this->makeUser((int) $company->id);
        $this->pairPhone((int) $company->id, now());
        $eventId = $this->ringEvent((int) $company->id);

        $this->dial(['phone' => '03001234567', 'event_id' => $eventId, 'name' => 'Ali']);

        $this->assertNotNull(DB::table('pos_caller_events')->find($eventId)->called_back_at);
        $calls = $this->recent()['calls'];
        $this->assertTrue($calls[0]['called_back']);
        $this->assertNotEmpty($calls[0]['called_back_at']);
    }

    public function test_called_back_is_recorded_even_when_no_phone_took_it(): void
    {
        $company = $this->makeCompany();
        app()->instance('currentCompanyId', (int) $company->id);
        $this->makeUser((int) $company->id);
        $eventId = $this->ringEvent((int) $company->id);

        // Koi phone nahi — cashier ne khud milaya, magar call handle ho gai.
        $this->dial(['phone' => '03001234567', 'event_id' => $eventId]);

        $this->assertNotNull(DB::table('pos_caller_events')->find($eventId)->called_back_at);
        $this->assertTrue($this->recent()['calls'][0]['called_back']);
    }

    public function test_call_back_cannot_stamp_another_shops_call(): void
    {
        $a = $this->makeCompany(['name' => 'Shop A']);
        $b = $this->makeCompany(['name' => 'Shop B']);
        $this->makeUser((int) $a->id);
        $eventB = $this->ringEvent((int) $b->id);

        app()->instance('currentCompanyId', (int) $a->id);
        $this->dial(['phone' => '03001234567', 'event_id' => $eventB]);

        $this->assertNull(DB::table('pos_caller_events')->find($eventB)->called_back_at);
    }

    public function test_recent_calls_reports_whether_a_phone_can_take_a_call_back(): void
    {
        $company = $this->makeCompany();
        app()->instance('currentCompanyId', (int) $company->id);
        $this->makeUser((int) $company->id);
        $this->ringEvent((int) $company->id);

        $this->assertFalse($this->recent()['dial_ready']);
        $this->pairPhone((int) $company->id, now());
        $this->assertTrue($this->recent()['dial_ready']);
    }

    public function test_a_junk_number_is_rejected_before_it_reaches_the_phone(): void
    {
        $company = $this->makeCompany();
        app()->instance('currentCompanyId', (int) $company->id);
        $this->makeUser((int) $company->id);
        $this->pairPhone((int) $company->id, now());

        // PkPhone::normalize rejects it → 422, nothing queued.
        $out = $this->dial(['phone' => 'abc']);

        $this->assertSame(422, $out['status']);
        $this->assertSame('bad_phone', $out['body']['error']);
        $this->assertSame(0, DB::table('pos_caller_dial_requests')->count());
    }
}

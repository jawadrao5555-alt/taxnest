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
 * "KITNI CALLS ABHI BAQI HAIN" (Task 1397) — bell badge ka number.
 *
 * Cashier ko rush mein "Haaliya calls" kholni na pare: bell par hi dikh jaye
 * ke AAJ ki kitni calls par abhi tak kuch nahi hua. Yeh number HAMESHA server
 * se aata hai — counter apne taur par jama-nafi nahi karta — aur hamesha kisi
 * MOJOOD response ke saath: ring poll, recent-calls list, clear, ya call back.
 * Badge ke liye alag request kabhi nahi.
 *
 * Yahan jo baatein pakki ki ja rahi hain:
 *   1. Ginti = AAJ ki wohi rings jin par na call back hui na jo hatai gai.
 *      Kal ki calls list mein reh sakti hain (panel 24 ghante dikhata hai)
 *      magar aaj ke badge mein nahi.
 *   2. Call back karte hi number girta hai — usi jawab mein, aur agli list
 *      mein bhi. Phone tak request pohanchi ya nahi, is se koi farq nahi:
 *      cashier ne call par amal kar liya.
 *   3. Row hatate hi (aur "sab hatayen" par) number girta/sifar hota hai —
 *      usi clear jawab mein.
 *   4. Doosri shop ki calls kabhi nahi ginti (company scoping).
 *   5. Toggle band ya plan mein Caller ID nahi → sarahatan sifar, koi badge
 *      nahi (sale screen par mustaqil sajawat nahi).
 *   6. Schema drift (called_back_at / cleared_at column ghayab) par 500 nahi:
 *      us shart ke baghair ginti chalti rehti hai.
 *
 * Pattern: sqlite :memory: + minimal Schema::create, controller seedha call
 * (PosCallerDialBackTest / PosCallerIdV2Test jaisa).
 */
class PosCallerPendingCountTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        PosFeatureService::flushGateCaches();
        Cache::flush();           // clearSupported()/calledBackSupported() schema probes
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

    /** Ek ring — waqt dena ho to $at dein (kal ki call test karne ke liye). */
    private function ringEvent(int $companyId, string $phone = '923001234567', ?\Carbon\Carbon $at = null): int
    {
        $at = $at ?: now();
        return (int) DB::table('pos_caller_events')->insertGetId([
            'company_id' => $companyId, 'phone' => $phone, 'caller_name' => 'Ali',
            'source' => 'sim', 'ring_at' => $at, 'created_at' => $at,
        ]);
    }

    /** GET /pos/api/caller-recent — wohi jawab jis se badge bharta hai. */
    private function recent(): array
    {
        $request = Request::create('/pos/api/caller-recent', 'GET');
        $request->setLaravelSession(app('session.store'));
        app()->instance('request', $request);
        return app(PosCallerIdController::class)->recentCalls($request)->getData(true);
    }

    /** GET /pos/api/caller-events — wohi 7-second ring poll jo pehle se chal raha hai. */
    private function poll(int $after = 0): array
    {
        $request = Request::create('/pos/api/caller-events', 'GET', ['after' => $after]);
        $request->setLaravelSession(app('session.store'));
        app()->instance('request', $request);
        return app(PosCallerIdController::class)->events($request)->getData(true);
    }

    /** POST /pos/api/caller-clear */
    private function clear(array $payload): array
    {
        $request = Request::create('/pos/api/caller-clear', 'POST', $payload);
        $request->setLaravelSession(app('session.store'));
        app()->instance('request', $request);
        $res = app(PosCallerIdController::class)->clearCalls($request);
        return ['status' => $res->getStatusCode(), 'body' => $res->getData(true)];
    }

    /** POST /pos/api/caller-dial */
    private function dial(array $payload): array
    {
        $request = Request::create('/pos/api/caller-dial', 'POST', $payload);
        $request->setLaravelSession(app('session.store'));
        app()->instance('request', $request);
        $res = app(PosCallerIdController::class)->dialBack($request);
        return ['status' => $res->getStatusCode(), 'body' => $res->getData(true)];
    }

    // ─── 1. Ginti kis cheez ki hai ──────────────────────────────────────────

    public function test_recent_calls_response_carries_todays_outstanding_count(): void
    {
        $company = $this->makeCompany();
        app()->instance('currentCompanyId', (int) $company->id);
        $this->makeUser((int) $company->id);

        $this->ringEvent((int) $company->id, '923001111111');
        $this->ringEvent((int) $company->id, '923002222222');
        $this->ringEvent((int) $company->id, '923003333333');

        $body = $this->recent();

        // Badge ka number USI jawab mein aata hai jo list laata hai — koi
        // doosri request nahi (yeh is feature ki bunyadi shart hai).
        $this->assertArrayHasKey('pending', $body, 'recent-calls must carry the badge count itself');
        $this->assertSame(3, $body['pending']);
        $this->assertCount(3, $body['calls']);
    }

    public function test_no_calls_means_no_badge_at_all(): void
    {
        $company = $this->makeCompany();
        app()->instance('currentCompanyId', (int) $company->id);
        $this->makeUser((int) $company->id);

        // Sifar = badge hi nahi. Sale screen par mustaqil sajawat nahi honi chahiye.
        $this->assertSame(0, $this->recent()['pending']);
    }

    public function test_yesterdays_ring_is_not_counted_even_though_the_list_may_still_show_it(): void
    {
        $company = $this->makeCompany();
        app()->instance('currentCompanyId', (int) $company->id);
        $this->makeUser((int) $company->id);

        // Kal shaam ki call: panel 24 ghante dikhata hai, magar badge se poocha
        // yeh ja raha hai ke AAJ kya baqi reh gaya.
        $this->ringEvent((int) $company->id, '923009999999', now()->subDay()->setTime(20, 0));
        $this->assertSame(0, $this->recent()['pending'], 'yesterday must not sit on today\'s badge');

        // Aaj ki call ginti mein aati hai.
        $this->ringEvent((int) $company->id, '923001111111');
        $this->assertSame(1, $this->recent()['pending']);
    }

    public function test_an_already_called_back_ring_is_not_outstanding(): void
    {
        $company = $this->makeCompany();
        app()->instance('currentCompanyId', (int) $company->id);
        $this->makeUser((int) $company->id);

        $id = $this->ringEvent((int) $company->id);
        $this->ringEvent((int) $company->id, '923002222222');
        DB::table('pos_caller_events')->where('id', $id)->update(['called_back_at' => now()]);

        $this->assertSame(1, $this->recent()['pending']);
    }

    // ─── 2. Call back karte hi girta hai ────────────────────────────────────

    public function test_calling_back_drops_the_count_in_the_same_response(): void
    {
        $company = $this->makeCompany();
        app()->instance('currentCompanyId', (int) $company->id);
        $this->makeUser((int) $company->id);

        $id = $this->ringEvent((int) $company->id, '923001234567');
        $this->ringEvent((int) $company->id, '923007654321');
        $this->assertSame(2, $this->recent()['pending']);

        // Koi phone paired nahi — request bhej nahi sakti, phir bhi cashier ne
        // call par amal kar liya, is liye badge girna chahiye (page reload ke
        // baghair: number usi jawab mein wapas jata hai).
        $out = $this->dial(['phone' => '0300-1234567', 'event_id' => $id]);

        $this->assertSame(200, $out['status']);
        $this->assertFalse($out['body']['sent']);
        $this->assertArrayHasKey('pending', $out['body'], 'call back must return the fresh badge count');
        $this->assertSame(1, $out['body']['pending']);
        $this->assertSame(1, $this->recent()['pending'], 'and the next list load must agree');
    }

    public function test_a_call_back_without_an_event_id_leaves_the_count_alone(): void
    {
        $company = $this->makeCompany();
        app()->instance('currentCompanyId', (int) $company->id);
        $this->makeUser((int) $company->id);

        $this->ringEvent((int) $company->id);
        // Attached-customer card se call back — kisi ring ka jawab nahi, is
        // liye "Haaliya calls" ki koi row handled nahi hoti.
        $out = $this->dial(['phone' => '03007654321']);

        $this->assertSame(1, $out['body']['pending']);
    }

    // ─── 3. Clear karte hi girta hai ────────────────────────────────────────

    public function test_clearing_one_row_drops_the_count_in_the_same_response(): void
    {
        $company = $this->makeCompany();
        app()->instance('currentCompanyId', (int) $company->id);
        $this->makeUser((int) $company->id);

        $id = $this->ringEvent((int) $company->id);
        $this->ringEvent((int) $company->id, '923002222222');

        $out = $this->clear(['id' => $id]);

        $this->assertSame(200, $out['status']);
        $this->assertTrue($out['body']['ok']);
        $this->assertSame(1, $out['body']['pending']);
        $this->assertSame(1, $this->recent()['pending']);
    }

    public function test_clear_all_zeroes_the_count(): void
    {
        $company = $this->makeCompany();
        app()->instance('currentCompanyId', (int) $company->id);
        $this->makeUser((int) $company->id);

        $this->ringEvent((int) $company->id, '923001111111');
        $this->ringEvent((int) $company->id, '923002222222');

        $out = $this->clear(['all' => true]);

        $this->assertSame(0, $out['body']['pending']);
        $this->assertSame(0, $this->recent()['pending']);
    }

    // ─── 4. Shop ki apni ginti ──────────────────────────────────────────────

    public function test_another_shops_calls_never_reach_this_badge(): void
    {
        $mine = $this->makeCompany(['name' => 'Meri Dukan']);
        $theirs = $this->makeCompany(['name' => 'Doosri Dukan']);
        app()->instance('currentCompanyId', (int) $mine->id);
        $this->makeUser((int) $mine->id);

        $this->ringEvent((int) $mine->id, '923001111111');
        $this->ringEvent((int) $theirs->id, '923002222222');
        $this->ringEvent((int) $theirs->id, '923003333333');

        $this->assertSame(1, $this->recent()['pending']);
    }

    // ─── 5. Sirf Caller ID walon ke liye ────────────────────────────────────

    public function test_toggle_off_shop_gets_an_explicit_zero(): void
    {
        $company = $this->makeCompany(['caller_id_enabled' => false]);
        app()->instance('currentCompanyId', (int) $company->id);
        $this->makeUser((int) $company->id);
        $this->ringEvent((int) $company->id);

        $body = $this->recent();

        $this->assertFalse($body['enabled']);
        // Sarahatan sifar: toggle band hote hi badge apni purani ginti par
        // jama na reh jaye.
        $this->assertSame(0, $body['pending']);
    }

    public function test_plan_without_caller_id_gets_an_explicit_zero(): void
    {
        $company = $this->makeCompany();
        app()->instance('currentCompanyId', (int) $company->id);
        $this->makeUser((int) $company->id);
        $this->ringEvent((int) $company->id);
        $this->lockPlan($company);

        $body = $this->recent();

        $this->assertFalse($body['enabled']);
        $this->assertSame(0, $body['pending']);
    }

    // ─── 6. Schema drift par gir na jaye ────────────────────────────────────

    public function test_a_missing_called_back_column_degrades_instead_of_breaking(): void
    {
        $company = $this->makeCompany();
        app()->instance('currentCompanyId', (int) $company->id);
        $this->makeUser((int) $company->id);
        $this->ringEvent((int) $company->id);
        $this->ringEvent((int) $company->id, '923002222222');

        // prod-schema-drift-selfheal: live par migration "Ran" hone ke bawajood
        // column ghayab ho sakta hai. Ginti tab bhi chale — 500 nahi.
        Schema::table('pos_caller_events', fn (Blueprint $t) => $t->dropColumn('called_back_at'));
        Cache::flush();

        $this->assertSame(2, $this->recent()['pending']);
    }

    public function test_a_missing_cleared_at_column_degrades_instead_of_breaking(): void
    {
        $company = $this->makeCompany();
        app()->instance('currentCompanyId', (int) $company->id);
        $this->makeUser((int) $company->id);
        $this->ringEvent((int) $company->id);

        Schema::table('pos_caller_events', fn (Blueprint $t) => $t->dropColumn('cleared_at'));
        Cache::flush();

        $this->assertSame(1, $this->recent()['pending']);
    }

    // ─── 7. Screen ghanton khuli rehti hai ──────────────────────────────────
    //
    // Sale screen subah khulti hai aur raat tak khuli rehti hai. Is liye badge
    // ki ginti counter ke apne hisaab par nahi chhori ja sakti — har ring poll
    // ke jawab mein server apna number bhejta hai aur screen wohi lagati hai.
    // Yahan wohi teen soortein pakki ki ja rahi hain jo counter khud kabhi
    // theek nahi kar sakta.

    public function test_the_ring_poll_carries_the_badge_count_too(): void
    {
        $company = $this->makeCompany();
        app()->instance('currentCompanyId', (int) $company->id);
        $this->makeUser((int) $company->id);

        $this->ringEvent((int) $company->id, '923001111111');
        $this->ringEvent((int) $company->id, '923002222222');

        $body = $this->poll();

        // Wohi poll jo pehle se har 7 second par chal raha hai — badge ke liye
        // koi doosri request nahi.
        $this->assertTrue($body['enabled']);
        $this->assertArrayHasKey('pending', $body, 'the ring poll must carry the badge count');
        $this->assertSame(2, $body['pending']);
    }

    public function test_a_screen_left_open_past_midnight_starts_the_new_day_at_zero(): void
    {
        $company = $this->makeCompany();
        app()->instance('currentCompanyId', (int) $company->id);
        $this->makeUser((int) $company->id);

        // Kal raat ki do calls jin par kuch nahi hua. Screen band nahi hui.
        $this->ringEvent((int) $company->id, '923001111111', now()->subDay()->setTime(22, 0));
        $this->ringEvent((int) $company->id, '923002222222', now()->subDay()->setTime(23, 30));

        // Agli tick par server nai din ki ginti bhejta hai — cashier ko subah
        // kal ka bojh nahi milta, aur is ke liye page reload nahi karna parta.
        $this->assertSame(0, $this->poll()['pending'], 'the new day must start with no badge');
    }

    public function test_a_call_back_on_another_counter_reaches_this_counters_poll(): void
    {
        $company = $this->makeCompany();
        app()->instance('currentCompanyId', (int) $company->id);
        $this->makeUser((int) $company->id);

        $id = $this->ringEvent((int) $company->id, '923001111111');
        $this->ringEvent((int) $company->id, '923002222222');
        $this->assertSame(2, $this->poll()['pending']);

        // Doosray counter (ya manager) ne yeh call back kar li. Is counter ne
        // kuch nahi kiya — phir bhi agli tick par is ka badge sach bol de.
        DB::table('pos_caller_events')->where('id', $id)->update(['called_back_at' => now()]);

        $this->assertSame(1, $this->poll()['pending']);
    }

    public function test_a_fresh_ring_already_handled_elsewhere_does_not_inflate_the_count(): void
    {
        $company = $this->makeCompany();
        app()->instance('currentCompanyId', (int) $company->id);
        $this->makeUser((int) $company->id);

        // Abhi ki ring, jis ka jawab doosray counter ne foran de diya — us
        // counter tak poll pohanchne se PEHLE.
        $id = $this->ringEvent((int) $company->id, '923001111111');
        DB::table('pos_caller_events')->where('id', $id)->update(['called_back_at' => now()]);

        $body = $this->poll();

        // Row abhi bhi poll ke saath aati hai (hatai nahi gai, popup ka haq
        // rakhti hai) — magar ginti mein nahi. Agar counter khud jama karta
        // to badge 1 dikhata jabke asal mein kuch baqi nahi.
        $this->assertCount(1, $body['events'], 'the ring itself still reaches the counter');
        $this->assertSame(0, $body['pending'], 'but it must not be counted as outstanding');
    }

    public function test_the_poll_zeroes_the_badge_when_caller_id_is_switched_off_mid_shift(): void
    {
        $company = $this->makeCompany();
        app()->instance('currentCompanyId', (int) $company->id);
        $this->makeUser((int) $company->id);
        $this->ringEvent((int) $company->id);
        $this->assertSame(1, $this->poll()['pending']);

        DB::table('companies')->where('id', $company->id)->update(['caller_id_enabled' => false]);

        $body = $this->poll();
        $this->assertFalse($body['enabled']);
        $this->assertSame(0, $body['pending']);
    }

    public function test_the_poll_zeroes_the_badge_when_the_plan_stops_including_caller_id(): void
    {
        $company = $this->makeCompany();
        app()->instance('currentCompanyId', (int) $company->id);
        $this->makeUser((int) $company->id);
        $this->ringEvent((int) $company->id);

        $this->lockPlan($company);

        $body = $this->poll();
        $this->assertFalse($body['enabled']);
        $this->assertSame(0, $body['pending']);
    }
}

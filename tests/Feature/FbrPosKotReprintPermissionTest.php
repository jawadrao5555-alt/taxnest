<?php

namespace Tests\Feature;

use App\Http\Controllers\FbrPosController;
use App\Models\FbrPosHeldSale;
use App\Models\FbrPosTransaction;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * FBR STORE-SLIP REPRINT PERMISSION — Task 1389.
 *
 * The PRA sale screen got a real kitchen-ticket reprint gate (Task 1379); the
 * FBR screen was left reading companies.kot_reprint_enabled to hide ONE button,
 * with no server-side block and no per-cashier control. The setting now promises
 * a shop-wide master switch, so the FBR side must honour the SAME verdict.
 *
 * Locked here:
 *   1. SERVER BLOCK — with the permission withheld, every FBR reprint entry
 *      point refuses: the held-sale store slip, the transaction store slip, and
 *      the silent print-job enqueue the Desktop Agent picks up.
 *   2. NO SILENT BEHAVIOUR CHANGE — a shop that configured nothing keeps all of
 *      them, exactly as before this task.
 *   3. A FIRST SEND IS NEVER GATED — the store must always get the slip once;
 *      only a SECOND send is a reprint. kot_sent_at is the signal, stamped on
 *      the first render / successful enqueue.
 *   4. COMPANY SWITCH IS A MASTER OFF-SWITCH — kot_reprint_enabled = false
 *      blocks the owner too (same precedence as PRA).
 *   5. The verdict is REUSED, not forked — PosAccessService::kotReprintAllowed.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create,
 * controller invoked directly with the currentCompanyId binding — same as
 * PosKotReprintPermissionTest (the PRA twin).
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/FbrPosKotReprintPermissionTest.php --testdox
 */
class FbrPosKotReprintPermissionTest extends TestCase
{
    protected int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('fbr_reporting_enabled')->default(false);
            $table->boolean('inventory_enabled')->default(false);
            // Master switch shared with PRA (default ON — existing shops unchanged).
            $table->boolean('kot_reprint_enabled')->default(true);
            $table->boolean('kot_last_addon_enabled')->default(true);
            // Internal account → planAllows() passes → plan gates are not the subject here.
            $table->boolean('is_internal_account')->default(false);
            // Silent-print path (fbrApiCreatePrintJob).
            $table->text('pos_printer_settings')->nullable();
            $table->boolean('agent_enabled')->default(false);
            $table->timestamp('agent_last_seen')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('name')->nullable();
            $table->string('role')->nullable();
            $table->string('pos_role')->nullable();
            $table->text('pos_custom_access')->nullable();
            $table->timestamps();
        });

        // FBR holds are Phase2 JSON carts — no RestaurantOrder row exists.
        Schema::create('fbr_pos_held_sales', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('hold_name')->nullable();
            $table->string('customer_name')->nullable();
            $table->text('cart_data')->nullable();
            $table->unsignedInteger('token_no')->nullable();
            $table->string('order_code')->nullable();
            $table->timestamp('kot_sent_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('fbr_pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('invoice_number')->nullable();
            $table->string('status')->nullable();
            $table->timestamp('kot_sent_at')->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('fbr_pos_transaction_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->string('product_name')->nullable();
            $table->decimal('quantity', 12, 3)->default(1);
            $table->timestamps();
        });

        Schema::create('pos_print_jobs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('type');
            $table->string('target_printer')->nullable();
            $table->string('device_uid')->nullable();
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->unsignedBigInteger('restaurant_order_id')->nullable();
            $table->string('status')->default('pending');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        $this->companyId = DB::table('companies')->insertGetId([
            'name'                => 'Bismillah Karyana',
            'is_internal_account' => true,
            'kot_reprint_enabled' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        app()->bind('currentCompanyId', fn () => $this->companyId);
    }

    protected function tearDown(): void
    {
        Auth::guard('fbrpos')->logout();
        parent::tearDown();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @param string|null $access JSON Custom Access set (null = nothing configured). */
    protected function actingCashier(?string $access = null): User
    {
        DB::table('users')->insert([
            'company_id'        => $this->companyId,
            'name'              => 'Cashier ' . uniqid(),
            'role'              => 'user',
            'pos_role'          => 'pos_cashier',
            'pos_custom_access' => $access,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::orderByDesc('id')->first();
        Auth::guard('fbrpos')->setUser($user);

        return $user;
    }

    /** A parked cart whose slip is ALREADY out → any further send is a reprint. */
    protected function sentHeld(): int
    {
        return DB::table('fbr_pos_held_sales')->insertGetId([
            'company_id'  => $this->companyId,
            'hold_name'   => 'Counter 1',
            'cart_data'   => json_encode(['items' => [['name' => 'Cooking Oil 5L', 'quantity' => 1, 'unit_price' => 2400]]]),
            'kot_sent_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A parked cart the store has NOT seen yet → sending it is a FIRST print. */
    protected function unsentHeld(): int
    {
        return DB::table('fbr_pos_held_sales')->insertGetId([
            'company_id' => $this->companyId,
            'hold_name'  => 'Counter 2',
            'cart_data'  => json_encode(['items' => [['name' => 'Sugar 10kg', 'quantity' => 2, 'unit_price' => 1300]]]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function transaction(?string $sentAt): int
    {
        return DB::table('fbr_pos_transactions')->insertGetId([
            'company_id'     => $this->companyId,
            'invoice_number' => 'FBR-' . uniqid(),
            'status'         => 'completed',
            'kot_sent_at'    => $sentAt,
            'total_amount'   => 2400,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Silent printing ready: agent online + store-slip printer chosen. */
    protected function enableSilentPrinting(): void
    {
        DB::table('companies')->where('id', $this->companyId)->update([
            'agent_enabled'        => true,
            'agent_last_seen'      => now(),
            'pos_printer_settings' => json_encode([
                'silent_print_enabled' => true,
                'receipt_printer'      => 'EPSON-80',
                'kot_printer'          => 'STORE-80',
            ]),
        ]);
    }

    protected function printJob(array $payload): \Illuminate\Http\JsonResponse
    {
        $request = Request::create('/fbr-pos/api/print-jobs', 'POST', $payload);

        return (new FbrPosController())->fbrApiCreatePrintJob($request);
    }

    /**
     * Run $fn and report the status of a 403 abort, or null when the call did
     * not 403. Rendering the slip pulls in the full store-slip blade, so any
     * OTHER failure is irrelevant here — only a 403 (or its absence) is asserted.
     */
    protected function abortStatus(callable $fn): ?int
    {
        try {
            $fn();
        } catch (HttpException $e) {
            return $e->getStatusCode();
        } catch (\Throwable $e) {
            return null; // render-time failure downstream of the gate
        }

        return null;
    }

    /**
     * Drive the gate with a model instance the caller loaded itself, so two
     * "requests" can both hold a row they read while it was still unsent.
     * Returns 403 when the gate refuses, null when it lets the send through.
     */
    protected function gateVerdict($row, string $table): ?int
    {
        $gate = new \ReflectionMethod(FbrPosController::class, 'fbrKotReprintGate');
        $gate->setAccessible(true);

        try {
            $gate->invoke(new FbrPosController(), $row, \App\Models\Company::find($this->companyId), $table);
        } catch (HttpException $e) {
            return $e->getStatusCode();
        }

        return null;
    }

    /** Call one of the controller's private claim helpers. */
    protected function invokeGateHelper(string $method, array $args)
    {
        $ref = new \ReflectionMethod(FbrPosController::class, $method);
        $ref->setAccessible(true);

        return $ref->invoke(new FbrPosController(), ...$args);
    }

    /** Point the DEFAULT connection somewhere else (the helpers use DB::table). */
    protected function useConnection(string $name): void
    {
        config(['database.default' => $name]);
        \Illuminate\Support\Facades\Facade::clearResolvedInstance('db.schema');
        Schema::clearResolvedInstances();
    }

    /** Raw .env values — the test run forces sqlite into env(), .env still names the dev MySQL. */
    protected function dotenvValues(): array
    {
        $path = base_path('.env');
        if (!is_file($path)) {
            return [];
        }
        $out = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (!preg_match('/^\s*([A-Z0-9_]+)\s*=\s*(.*)$/', $line, $m)) {
                continue;
            }
            $value = trim($m[2]);
            if (strlen($value) > 1 && in_array($value[0], ['"', "'"], true) && substr($value, -1) === $value[0]) {
                $value = substr($value, 1, -1);
            }
            $out[$m[1]] = $value;
        }

        return $out;
    }

    // ── 1. server block: permission withheld ─────────────────────────────────

    public function test_blocked_cashier_cannot_reprint_a_held_store_slip(): void
    {
        $this->actingCashier('["orders"]'); // set saved WITHOUT the kot_reprint tick
        $heldId = $this->sentHeld();

        $this->assertSame(403, $this->abortStatus(fn () => (new FbrPosController())->kotTicket($heldId)));
    }

    public function test_blocked_cashier_cannot_reprint_a_transaction_store_slip(): void
    {
        $this->actingCashier('["orders"]');
        $txnId = $this->transaction(sentAt: (string) now());

        $this->assertSame(403, $this->abortStatus(fn () => (new FbrPosController())->kotReprint($txnId)));
    }

    public function test_blocked_cashier_cannot_enqueue_a_silent_reprint_job(): void
    {
        $this->enableSilentPrinting();
        $this->actingCashier('["orders"]');

        foreach ([
            ['type' => 'fbr_kot', 'restaurant_order_id' => $this->sentHeld()],
            ['type' => 'fbr_kot', 'transaction_id' => $this->transaction(sentAt: (string) now())],
        ] as $payload) {
            $status = $this->abortStatus(fn () => $this->printJob($payload));
            $this->assertSame(403, $status, 'payload: ' . json_encode($payload));
        }

        $this->assertSame(0, DB::table('pos_print_jobs')->count(), 'no job may be queued for a blocked user');
    }

    /**
     * The sale screen's silent-print call sends Accept: application/json and
     * treats ANY non-2xx as "fall back to the browser print path", so the
     * refusal must arrive as a clean JSON 403 — never an HTML error page.
     */
    public function test_the_silent_refusal_is_a_clean_translated_json_403(): void
    {
        $this->enableSilentPrinting();
        $this->actingCashier('["orders"]');
        $heldId = $this->sentHeld();

        $request = Request::create(
            '/fbr-pos/api/print-jobs',
            'POST',
            ['type' => 'fbr_kot', 'restaurant_order_id' => $heldId],
            [], [], ['HTTP_ACCEPT' => 'application/json']
        );
        app()->instance('request', $request); // the gate reads the live request to pick its shape

        try {
            (new FbrPosController())->fbrApiCreatePrintJob($request);
            $this->fail('a blocked reprint must not be enqueued');
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            $payload = json_decode($e->getResponse()->getContent(), true);
            $this->assertSame(403, $e->getResponse()->getStatusCode());
            $this->assertFalse($payload['success']);
            $this->assertSame('not_allowed', $payload['reason']);
            $this->assertSame(__('pos.kot_reprint_not_allowed'), $payload['message']);
        }

        $this->assertSame(0, DB::table('pos_print_jobs')->count());
    }

    // ── 2. default behaviour must not change ─────────────────────────────────

    public function test_default_shop_keeps_every_reprint_path(): void
    {
        // Nothing configured: no Custom Access set, company switch untouched.
        $this->actingCashier(null);

        $this->assertNull($this->abortStatus(fn () => (new FbrPosController())->kotTicket($this->sentHeld())), 'held reprint');
        $this->assertNull($this->abortStatus(fn () => (new FbrPosController())->kotReprint($this->transaction(sentAt: (string) now()))), 'transaction reprint');
    }

    public function test_default_shop_keeps_the_silent_reprint_job(): void
    {
        $this->enableSilentPrinting();
        $this->actingCashier(null);

        $response = $this->printJob(['type' => 'fbr_kot', 'restaurant_order_id' => $this->sentHeld()]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->getData(true)['success']);
        $this->assertGreaterThan(0, DB::table('pos_print_jobs')->count());
    }

    public function test_ticked_cashier_may_reprint(): void
    {
        $this->actingCashier('["orders","kot_reprint"]');

        $this->assertNull($this->abortStatus(fn () => (new FbrPosController())->kotTicket($this->sentHeld())));
        $this->assertNull($this->abortStatus(fn () => (new FbrPosController())->kotReprint($this->transaction(sentAt: (string) now()))));
    }

    // ── 3. a first send is never gated ───────────────────────────────────────

    public function test_blocked_cashier_can_still_send_a_first_held_store_slip(): void
    {
        $this->actingCashier('["orders"]');
        $heldId = $this->unsentHeld();

        $this->assertNull($this->abortStatus(fn () => (new FbrPosController())->kotTicket($heldId)));
        // ...and that first send stamps the row, so the NEXT one is a reprint.
        $this->assertNotNull(DB::table('fbr_pos_held_sales')->where('id', $heldId)->value('kot_sent_at'));
        $this->assertSame(403, $this->abortStatus(fn () => (new FbrPosController())->kotTicket($heldId)));
    }

    /**
     * Despite the route name, /transaction/{id}/kot-reprint also carries the
     * FIRST slip of a straight bill — auto-KOT fires it right after payment.
     * Blocking it would leave the store with no slip at all.
     */
    public function test_blocked_cashier_can_still_send_a_first_transaction_store_slip(): void
    {
        $this->actingCashier('["orders"]');
        $txnId = $this->transaction(sentAt: null);

        $this->assertNull($this->abortStatus(fn () => (new FbrPosController())->kotReprint($txnId)), 'the first store slip must never be refused');
        $this->assertNotNull(DB::table('fbr_pos_transactions')->where('id', $txnId)->value('kot_sent_at'));
    }

    /** Same first send with silent printing on — the job must reach the queue. */
    public function test_blocked_cashier_can_still_enqueue_a_first_silent_job(): void
    {
        $this->enableSilentPrinting();
        $this->actingCashier('["orders"]');
        $heldId = $this->unsentHeld();
        $txnId  = $this->transaction(sentAt: null);

        foreach ([
            ['type' => 'fbr_kot', 'restaurant_order_id' => $heldId],
            ['type' => 'fbr_kot', 'transaction_id' => $txnId],
        ] as $payload) {
            $response = $this->printJob($payload);
            $this->assertSame(200, $response->getStatusCode(), 'payload: ' . json_encode($payload));
            $this->assertTrue($response->getData(true)['success']);
        }

        $this->assertSame(2, DB::table('pos_print_jobs')->count());
        // Enqueue stamps too — the agent renders through an unauthenticated
        // route, so this is the only place the first send can be recorded.
        $this->assertNotNull(DB::table('fbr_pos_held_sales')->where('id', $heldId)->value('kot_sent_at'));
        $this->assertNotNull(DB::table('fbr_pos_transactions')->where('id', $txnId)->value('kot_sent_at'));
    }

    /**
     * A failed enqueue must NOT stamp: the sale screen falls back to the browser
     * print path on any non-2xx, and that fallback has to still count as the
     * first send or a blocked cashier would be left with no slip at all.
     */
    public function test_a_failed_enqueue_leaves_the_slip_unsent(): void
    {
        // Agent offline → 409 before any job is created.
        $this->actingCashier('["orders"]');
        $heldId = $this->unsentHeld();

        $response = $this->printJob(['type' => 'fbr_kot', 'restaurant_order_id' => $heldId]);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertNull(DB::table('fbr_pos_held_sales')->where('id', $heldId)->value('kot_sent_at'));
        $this->assertNull($this->abortStatus(fn () => (new FbrPosController())->kotTicket($heldId)), 'the browser fallback is still a FIRST send');
    }

    // ── 3b. two sends at the same instant = ONE first send ───────────────────

    /**
     * The dangerous read: two requests that BOTH load the row while it is still
     * unsent — a double-click, two tabs, or two of the server's workers. If the
     * gate merely *detected* an unsent row, both would be waved through as
     * "first sends" and a blocked cashier would get their duplicate slip after
     * all. Exactly one of them may win.
     */
    public function test_two_simultaneous_first_sends_only_one_counts_as_the_first(): void
    {
        $this->actingCashier('["orders"]');
        $heldId = $this->unsentHeld();

        // Both requests read the row before either of them acts on it.
        $requestA = FbrPosHeldSale::find($heldId);
        $requestB = FbrPosHeldSale::find($heldId);

        $this->assertNull($this->gateVerdict($requestA, 'fbr_pos_held_sales'), 'the winner must print');
        $this->assertSame(403, $this->gateVerdict($requestB, 'fbr_pos_held_sales'), 'the loser is a reprint and must be refused');
        $this->assertNotNull(DB::table('fbr_pos_held_sales')->where('id', $heldId)->value('kot_sent_at'));
    }

    public function test_two_simultaneous_first_transaction_sends_only_one_counts(): void
    {
        $this->actingCashier('["orders"]');
        $txnId = $this->transaction(sentAt: null);

        $requestA = FbrPosTransaction::find($txnId);
        $requestB = FbrPosTransaction::find($txnId);

        $this->assertNull($this->gateVerdict($requestA, 'fbr_pos_transactions'), 'the winner must print');
        $this->assertSame(403, $this->gateVerdict($requestB, 'fbr_pos_transactions'), 'the loser is a reprint and must be refused');
    }

    /** Losing the race is only a REFUSAL for someone who may not reprint. */
    public function test_the_loser_of_the_race_still_prints_for_a_permitted_cashier(): void
    {
        $this->actingCashier('["orders","kot_reprint"]');
        $heldId = $this->unsentHeld();

        $requestA = FbrPosHeldSale::find($heldId);
        $requestB = FbrPosHeldSale::find($heldId);

        $this->assertNull($this->gateVerdict($requestA, 'fbr_pos_held_sales'));
        $this->assertNull($this->gateVerdict($requestB, 'fbr_pos_held_sales'));
    }

    /**
     * Same race on the silent path, driven through the real endpoint: another
     * worker claims the send in the window between this request loading the
     * held sale and its gate running.
     */
    public function test_a_send_claimed_mid_request_makes_the_silent_job_a_reprint(): void
    {
        $this->enableSilentPrinting();
        $this->actingCashier('["orders"]');
        $heldId = $this->unsentHeld();

        $fired = false;
        FbrPosHeldSale::retrieved(function ($model) use (&$fired, $heldId) {
            if ($fired || (int) $model->id !== $heldId) {
                return;
            }
            $fired = true; // the other worker gets there first
            DB::table('fbr_pos_held_sales')->where('id', $heldId)
                ->whereNull('kot_sent_at')->update(['kot_sent_at' => now()]);
        });

        try {
            $status = $this->abortStatus(fn () => $this->printJob(['type' => 'fbr_kot', 'restaurant_order_id' => $heldId]));
        } finally {
            app('events')->forget('eloquent.retrieved: ' . FbrPosHeldSale::class);
        }

        $this->assertTrue($fired, 'the racing claim never ran — the test is not exercising the race');
        $this->assertSame(403, $status, 'the request that lost the claim is a reprint');
        $this->assertSame(0, DB::table('pos_print_jobs')->count());
    }

    /**
     * A claim is only kept if the send really got queued. If anything between
     * the claim and the job row blows up, the slip must go back to "unsent" —
     * otherwise the browser fallback that follows is refused as a reprint and
     * the store gets nothing at all.
     */
    public function test_a_crashed_enqueue_gives_the_first_send_back(): void
    {
        $this->enableSilentPrinting();
        $this->actingCashier('["orders"]');
        $heldId = $this->unsentHeld();

        Schema::drop('pos_print_jobs'); // the queue itself is broken

        try {
            $this->printJob(['type' => 'fbr_kot', 'restaurant_order_id' => $heldId]);
            $this->fail('a broken print queue must not report success');
        } catch (\Throwable $e) {
            // expected — the screen sees a non-2xx and falls back to the browser
        }

        $this->assertNull(
            DB::table('fbr_pos_held_sales')->where('id', $heldId)->value('kot_sent_at'),
            'a send that never happened must not leave the slip looking sent'
        );
        $this->assertNull($this->abortStatus(fn () => (new FbrPosController())->kotTicket($heldId)), 'the fallback is still a FIRST send');
    }

    /**
     * The release above only works if the claim token is EXACTLY what the column
     * holds. Live runs on MySQL, where a plain `timestamp` keeps whole seconds
     * and silently drops anything finer — a token carrying microseconds would
     * never match its own row again, the claim could never be given back, and a
     * blocked cashier whose enqueue failed would be locked out of the fallback.
     * SQLite stores the string verbatim, so it cannot catch this; run the claim
     * against real MySQL when the dev database is up.
     */
    public function test_the_claim_token_round_trips_through_a_mysql_timestamp_column(): void
    {
        $env = $this->dotenvValues();
        if (empty($env['DB_DATABASE']) || $env['DB_DATABASE'] === ':memory:') {
            $this->markTestSkipped('no MySQL database configured in .env');
        }

        config(['database.connections.t1389_mysql' => array_merge(
            config('database.connections.mysql') ?: [],
            [
                'driver'      => 'mysql',
                'host'        => $env['DB_HOST'] ?? '127.0.0.1',
                'port'        => $env['DB_PORT'] ?? '3306',
                'database'    => $env['DB_DATABASE'],
                'username'    => $env['DB_USERNAME'] ?? 'root',
                'password'    => $env['DB_PASSWORD'] ?? '',
                'unix_socket' => $env['DB_SOCKET'] ?? '',
            ]
        )]);

        try {
            DB::connection('t1389_mysql')->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('dev MySQL is not reachable');
        }

        $probe   = 't1389_claim_probe';
        $default = config('database.default');
        DB::connection('t1389_mysql')->statement("DROP TABLE IF EXISTS `$probe`");
        // Same column type the migration adds: whole-second precision.
        DB::connection('t1389_mysql')->statement(
            "CREATE TABLE `$probe` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `kot_sent_at` TIMESTAMP NULL DEFAULT NULL)"
        );

        try {
            $id = DB::connection('t1389_mysql')->table($probe)->insertGetId(['kot_sent_at' => null]);
            $this->useConnection('t1389_mysql');

            $first  = (object) ['id' => $id, 'kot_sent_at' => null];
            $second = (object) ['id' => $id, 'kot_sent_at' => null]; // raced in while it was unsent
            $claim  = $this->invokeGateHelper('fbrClaimKotSend', [$first, $probe]);

            $this->assertIsString($claim, 'the first send must be claimed');
            $this->assertFalse($this->invokeGateHelper('fbrClaimKotSend', [$second, $probe]), 'only one claimant may win');

            // The send failed → hand the claim back. This is the assertion that
            // fails if the token does not match the stored value byte for byte.
            $this->invokeGateHelper('fbrReleaseKotSend', [$first, $probe, $claim]);
            $this->assertNull(
                DB::connection('t1389_mysql')->table($probe)->where('id', $id)->value('kot_sent_at'),
                'a released claim must actually clear on MySQL, or the retry is refused as a reprint'
            );

            // ...and the slip can be sent again, as a first send.
            $this->assertIsString($this->invokeGateHelper('fbrClaimKotSend', [(object) ['id' => $id, 'kot_sent_at' => null], $probe]));
        } finally {
            $this->useConnection($default);
            DB::connection('t1389_mysql')->statement("DROP TABLE IF EXISTS `$probe`");
        }
    }

    // ── 4. company switch is a master off-switch ─────────────────────────────

    public function test_company_switch_off_blocks_even_the_owner(): void
    {
        DB::table('companies')->where('id', $this->companyId)->update(['kot_reprint_enabled' => false]);

        DB::table('users')->insert([
            'company_id' => $this->companyId,
            'name'       => 'Malik',
            'role'       => 'company_admin',
            'pos_role'   => 'pos_admin',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Auth::guard('fbrpos')->setUser(User::orderByDesc('id')->first());

        $this->assertSame(403, $this->abortStatus(fn () => (new FbrPosController())->kotTicket($this->sentHeld())));
        $this->assertSame(403, $this->abortStatus(fn () => (new FbrPosController())->kotReprint($this->transaction(sentAt: (string) now()))));
        // Still never blocks a first fire — the store must always get the slip.
        $this->assertNull($this->abortStatus(fn () => (new FbrPosController())->kotTicket($this->unsentHeld())));
    }

    // ── 5. the sale screen must not hide a FIRST send ────────────────────────

    /**
     * The server happily prints a not-yet-sent slip for a blocked cashier, but
     * that is worthless if the sale screen removes the only button that fires
     * it. These controls must be state-aware in the markup — the old blanket
     * `@if($company->kot_reprint_enabled)` compiled the button away entirely.
     */
    public function test_fbr_sale_screen_keeps_the_first_send_controls_state_aware(): void
    {
        $blade = file_get_contents(resource_path('views/fbr-pos/universal.blade.php'));

        // Receipt popup slip button + its grid spacer: visible whenever this
        // bill's slip is still pending, regardless of the reprint verdict.
        $this->assertStringContainsString(
            'x-show="lastOrderId && (canKotReprint || lastKotPending)"',
            $blade,
            'the receipt popup store-slip button must survive the block while the slip is unsent'
        );
        $this->assertStringContainsString(
            'x-show="!(lastOrderId && (canKotReprint || lastKotPending))"',
            $blade,
            'the popup grid spacer must mirror the button so the grid stays balanced'
        );

        // K shortcut mirrors that button exactly.
        $this->assertStringContainsString(
            '!this.canKotReprint && !this.lastKotPending',
            $blade,
            'the K shortcut must only refuse a REPRINT, never a pending first send'
        );

        // Held modal: the "view slip" link can still be a first send, so it only
        // disappears once kot_sent_at is stamped; Re-send is always a reprint.
        $this->assertStringContainsString('x-show="canKotReprint || !order.kot_sent_at"', $blade);
        $this->assertStringContainsString('x-show="canKotReprint"', $blade);

        // The raw column must not gate any control again — the verdict is the
        // single source of truth (company switch AND per-cashier tick).
        $this->assertSame(
            0,
            preg_match_all('/@if\([^\n]*kot_reprint_enabled[^\n]*\)/', $blade),
            'the FBR sale screen must read the baked verdict, never companies.kot_reprint_enabled directly'
        );
    }
}

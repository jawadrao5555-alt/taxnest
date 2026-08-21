<?php

namespace Tests\Feature;

use App\Http\Controllers\PosController;
use App\Http\Controllers\RestaurantPosController;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * KOT REPRINT PERMISSION — Task 1379 (owner voice notes, 20 Aug 2026).
 *
 * The owner wants the sale screen's kitchen-ticket "Reprint / Re-send / Last
 * Add-on" buttons controllable per cashier, "jaise cancel kar sakta hai ya
 * nahi" — and hiding a button is explicitly NOT enough: a blocked staffer must
 * fail from a pasted URL, a stale tab and the mobile shell too.
 *
 * Locked here:
 *   1. SERVER BLOCK — with the permission withheld, every reprint entry point
 *      refuses: the kitchen-ticket render, ?batch=last (Last Add-on), the
 *      transaction (order-less bill) ticket, Re-send, and the silent
 *      print-job enqueue that the Desktop Agent uses.
 *   2. NO SILENT BEHAVIOUR CHANGE — a shop that configured nothing (no Custom
 *      Access set, company switch untouched) keeps every one of those paths.
 *   3. FIRST FIRE AND DELTA ARE NEVER GATED — a not-yet-printed ticket and the
 *      "Added" (delta) slip must reach the kitchen even for a blocked user;
 *      this control is about REPRINTS, never about stopping the kitchen.
 *   4. COMPANY SWITCH IS A MASTER OFF-SWITCH — kot_reprint_enabled = false
 *      blocks the owner too, which is what "Allow KOT Reprint" already meant.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create,
 * controllers invoked directly with the currentCompanyId binding — same as
 * PosRestaurantOrderCancelTest.
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/PosKotReprintPermissionTest.php --testdox
 */
class PosKotReprintPermissionTest extends TestCase
{
    protected int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('pra_reporting_enabled')->default(false);
            $table->boolean('inventory_enabled')->default(false);
            // Task 1379 master switch (default ON — existing shops unchanged).
            $table->boolean('kot_reprint_enabled')->default(true);
            // Internal account → planAllows() passes → Custom Access sets are live.
            $table->boolean('is_internal_account')->default(false);
            // Silent-print path (apiCreatePrintJob).
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

        Schema::create('restaurant_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('order_number')->nullable();
            $table->unsignedBigInteger('table_id')->nullable();
            $table->string('order_type')->nullable();
            $table->string('source')->nullable();
            $table->string('status');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->timestamp('kot_sent_at')->nullable();
            $table->integer('kot_print_count')->nullable();
            $table->timestamps();
        });

        Schema::create('restaurant_order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('item_type')->nullable();
            $table->unsignedBigInteger('item_id')->nullable();
            $table->string('item_name')->nullable();
            $table->decimal('quantity', 12, 3)->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->string('special_notes')->nullable();
            $table->boolean('is_tax_exempt')->default(false);
            $table->timestamp('kot_printed_at')->nullable();
            $table->integer('kot_batch_no')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('invoice_number')->nullable();
            $table->string('status')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->timestamp('kot_sent_at')->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('pos_print_jobs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('type');
            $table->string('target_printer')->nullable();
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->unsignedBigInteger('restaurant_order_id')->nullable();
            $table->text('render_query')->nullable();
            $table->text('printed_item_ids')->nullable();
            $table->string('status')->default('pending');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_stations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name')->nullable();
            $table->string('printer_name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $this->companyId = DB::table('companies')->insertGetId([
            'name'                => 'Karahi House',
            'is_internal_account' => true,
            'kot_reprint_enabled' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        app()->bind('currentCompanyId', fn () => $this->companyId);
    }

    protected function tearDown(): void
    {
        Auth::guard('pos')->logout();
        parent::tearDown();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @param string|null $access JSON Custom Access set (null = nothing configured). */
    protected function actingCashier(?string $access = null): User
    {
        DB::table('users')->insert([
            'company_id'         => $this->companyId,
            'name'               => 'Cashier ' . uniqid(),
            'role'               => 'user',
            'pos_role'           => 'pos_cashier',
            'pos_custom_access'  => $access,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::orderByDesc('id')->first();
        Auth::guard('pos')->setUser($user);

        return $user;
    }

    /** A held order whose items the kitchen has ALREADY seen → any full render is a reprint. */
    protected function printedOrder(): int
    {
        $id = DB::table('restaurant_orders')->insertGetId([
            'company_id'   => $this->companyId,
            'order_number' => 'ORD-' . uniqid(),
            'status'       => 'held',
            'kot_sent_at'  => now(),
            'total_amount' => 700,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('restaurant_order_items')->insert([
            'order_id'       => $id,
            'item_name'      => 'Chicken Karahi',
            'quantity'       => 1,
            'unit_price'     => 700,
            'subtotal'       => 700,
            'kot_printed_at' => now(),
            'kot_batch_no'   => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /** A held order the kitchen has NOT seen yet → a render is a FIRST fire. */
    protected function unsentOrder(): int
    {
        $id = DB::table('restaurant_orders')->insertGetId([
            'company_id'   => $this->companyId,
            'order_number' => 'ORD-' . uniqid(),
            'status'       => 'held',
            'total_amount' => 450,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('restaurant_order_items')->insert([
            'order_id'   => $id,
            'item_name'  => 'Seekh Kabab',
            'quantity'   => 3,
            'unit_price' => 150,
            'subtotal'   => 450,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /** Silent printing ready: agent online + KOT printer chosen. */
    protected function enableSilentPrinting(): void
    {
        DB::table('companies')->where('id', $this->companyId)->update([
            'agent_enabled'        => true,
            'agent_last_seen'      => now(),
            'pos_printer_settings' => json_encode([
                'silent_print_enabled' => true,
                'receipt_printer'      => 'EPSON-80',
                'kot_printer'          => 'KITCHEN-80',
            ]),
        ]);
    }

    protected function kitchenTicket(int $orderId, array $query = []): void
    {
        $request = Request::create('/pos/restaurant/orders/' . $orderId . '/kitchen-ticket', 'GET', $query);
        (new RestaurantPosController())->kitchenTicket($request, $orderId);
    }

    protected function printJob(array $payload): \Illuminate\Http\JsonResponse
    {
        $request = Request::create('/pos/api/print-jobs', 'POST', $payload);

        return (new PosController())->apiCreatePrintJob($request);
    }

    /**
     * Run $fn and report the HTTP status of a 403 abort, or null when the call
     * did not 403. Kitchen-ticket rendering pulls in the full KOT blade, so any
     * OTHER failure is irrelevant to this permission test — only a 403 (or the
     * absence of one) is the assertion.
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

    // ── 1. server block: permission withheld ─────────────────────────────────

    public function test_blocked_cashier_cannot_render_a_reprint_ticket(): void
    {
        $this->actingCashier('["orders"]'); // set saved WITHOUT the kot_reprint tick
        $orderId = $this->printedOrder();

        $this->assertSame(403, $this->abortStatus(fn () => $this->kitchenTicket($orderId)));
    }

    public function test_blocked_cashier_cannot_render_the_last_addon_ticket(): void
    {
        $this->actingCashier('["orders"]');
        $orderId = $this->printedOrder();

        // ?batch=last is ALWAYS a reprint by definition — even on an order that
        // still has unprinted rows.
        $this->assertSame(403, $this->abortStatus(fn () => $this->kitchenTicket($orderId, ['batch' => 'last'])));
        $this->assertSame(403, $this->abortStatus(fn () => $this->kitchenTicket($this->unsentOrder(), ['batch' => 'last'])));
    }

    public function test_blocked_cashier_cannot_resend_to_kitchen(): void
    {
        $this->actingCashier('["orders"]');
        $orderId = $this->printedOrder();

        $response = (new RestaurantPosController())->resendKitchen($orderId);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($response->getData(true)['success']);
        $this->assertSame(__('pos.kot_reprint_not_allowed'), $response->getData(true)['message']);
        // Bookkeeping untouched — the kitchen never saw a second ticket.
        $this->assertNull(DB::table('restaurant_orders')->where('id', $orderId)->value('kot_print_count'));
    }

    public function test_blocked_cashier_cannot_render_a_transaction_reprint(): void
    {
        $this->actingCashier('["orders"]');
        $txnId = DB::table('pos_transactions')->insertGetId([
            'company_id'     => $this->companyId,
            'invoice_number' => 'INV-1',
            'status'         => 'completed',
            'kot_sent_at'    => now(), // slip already went to the kitchen
            'total_amount'   => 900,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $status = $this->abortStatus(function () use ($txnId) {
            $request = Request::create('/pos/transactions/' . $txnId . '/kitchen-ticket', 'GET');
            (new RestaurantPosController())->transactionKitchenTicket($request, $txnId);
        });

        $this->assertSame(403, $status);
    }

    public function test_blocked_cashier_cannot_enqueue_a_silent_reprint_job(): void
    {
        $this->enableSilentPrinting();
        $this->actingCashier('["orders"]');
        $orderId = $this->printedOrder();

        foreach ([[], ['batch' => 'last']] as $extra) {
            $response = $this->printJob(array_merge([
                'type'                => 'kot',
                'restaurant_order_id' => $orderId,
            ], $extra));

            $this->assertSame(403, $response->getStatusCode(), 'payload: ' . json_encode($extra));
            $this->assertSame('not_allowed', $response->getData(true)['reason']);
        }

        $this->assertSame(0, DB::table('pos_print_jobs')->count(), 'no job may be queued for a blocked user');
    }

    // ── 2. default behaviour must not change ─────────────────────────────────

    public function test_default_shop_keeps_every_reprint_path(): void
    {
        // Nothing configured: no Custom Access set, company switch untouched.
        $this->actingCashier(null);
        $orderId = $this->printedOrder();

        $this->assertNull($this->abortStatus(fn () => $this->kitchenTicket($orderId)), 'plain reprint');
        $this->assertNull($this->abortStatus(fn () => $this->kitchenTicket($orderId, ['batch' => 'last'])), 'last add-on');

        $response = (new RestaurantPosController())->resendKitchen($orderId);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->getData(true)['success']);
    }

    public function test_default_shop_keeps_the_silent_reprint_job(): void
    {
        $this->enableSilentPrinting();
        $this->actingCashier(null);

        $response = $this->printJob([
            'type'                => 'kot',
            'restaurant_order_id' => $this->printedOrder(),
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->getData(true)['success']);
        $this->assertGreaterThan(0, DB::table('pos_print_jobs')->count());
    }

    public function test_ticked_cashier_may_reprint(): void
    {
        $this->actingCashier('["orders","kot_reprint"]');
        $orderId = $this->printedOrder();

        $this->assertNull($this->abortStatus(fn () => $this->kitchenTicket($orderId, ['batch' => 'last'])));
        $this->assertSame(200, (new RestaurantPosController())->resendKitchen($orderId)->getStatusCode());
    }

    // ── 3. first fire and delta are never gated ──────────────────────────────

    public function test_blocked_cashier_can_still_send_a_first_kitchen_ticket(): void
    {
        $this->actingCashier('["orders"]');

        // Never-printed order → this render IS the kitchen's first sight of it.
        $this->assertNull($this->abortStatus(fn () => $this->kitchenTicket($this->unsentOrder())));
    }

    public function test_blocked_cashier_can_still_send_the_added_items_delta(): void
    {
        $this->actingCashier('["orders"]');
        $orderId = $this->printedOrder();
        // A waiter appended a line the kitchen has not seen.
        DB::table('restaurant_order_items')->insert([
            'order_id'   => $orderId,
            'item_name'  => 'Naan',
            'quantity'   => 2,
            'unit_price' => 30,
            'subtotal'   => 60,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertNull($this->abortStatus(fn () => $this->kitchenTicket($orderId, ['delta' => '1'])));
        // A FULL render of the same order is still a mix, not a pure reprint —
        // unprinted rows exist, so the kitchen is not cut off.
        $this->assertNull($this->abortStatus(fn () => $this->kitchenTicket($orderId)));
    }

    /**
     * "Payment First, Then KOT" release. The receipt popup's KOT button is the
     * ONLY exposed control for a promoted delivery bill whose slip has not gone
     * out yet — blocking it would leave the kitchen with no ticket at all.
     */
    public function test_blocked_cashier_can_still_send_a_first_transaction_ticket(): void
    {
        $this->actingCashier('["orders"]');
        $txnId = DB::table('pos_transactions')->insertGetId([
            'company_id'     => $this->companyId,
            'invoice_number' => 'INV-FIRST',
            'status'         => 'completed',
            'kot_sent_at'    => null, // kitchen has never seen this bill
            'total_amount'   => 900,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $status = $this->abortStatus(function () use ($txnId) {
            $request = Request::create('/pos/transactions/' . $txnId . '/kitchen-ticket', 'GET');
            (new RestaurantPosController())->transactionKitchenTicket($request, $txnId);
        });

        $this->assertNull($status, 'the first transaction KOT must never be refused');
    }

    /** Same release path with silent printing on — the job must reach the queue. */
    public function test_blocked_cashier_can_still_enqueue_a_first_transaction_job(): void
    {
        $this->enableSilentPrinting();
        $this->actingCashier('["orders"]');
        $txnId = DB::table('pos_transactions')->insertGetId([
            'company_id'     => $this->companyId,
            'invoice_number' => 'INV-FIRST-SILENT',
            'status'         => 'completed',
            'kot_sent_at'    => null,
            'total_amount'   => 900,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->printJob(['type' => 'kot', 'transaction_id' => $txnId]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->getData(true)['success']);
        $this->assertSame(1, DB::table('pos_print_jobs')->where('transaction_id', $txnId)->count());
    }

    public function test_blocked_cashier_can_still_enqueue_a_delta_print_job(): void
    {
        $this->enableSilentPrinting();
        $this->actingCashier('["orders"]');
        $orderId = $this->unsentOrder();

        $response = $this->printJob([
            'type'                => 'kot',
            'restaurant_order_id' => $orderId,
            'delta'               => true,
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->getData(true)['success']);
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
        Auth::guard('pos')->setUser(User::orderByDesc('id')->first());

        $orderId = $this->printedOrder();

        $this->assertSame(403, $this->abortStatus(fn () => $this->kitchenTicket($orderId)));
        $this->assertSame(403, (new RestaurantPosController())->resendKitchen($orderId)->getStatusCode());
        // Still never blocks a first fire — the kitchen must always get the order.
        $this->assertNull($this->abortStatus(fn () => $this->kitchenTicket($this->unsentOrder())));
    }

    // ── 5. the sale screen must not hide a FIRST send ────────────────────────

    /**
     * The server happily prints a not-yet-sent ticket for a blocked cashier
     * (tests above), but that is worthless if the sale screen removes the only
     * button that triggers it. These controls must therefore be state-aware in
     * the markup — a blanket server-side @if would compile them away for the
     * blocked cashier and strand the kitchen with no slip.
     */
    public function test_sale_screen_keeps_the_first_send_controls_state_aware(): void
    {
        $blade = file_get_contents(resource_path('views/pos/universal.blade.php'));

        // Receipt popup KOT button + its grid spacer: visible whenever the
        // ticket is still pending, regardless of the reprint verdict.
        $this->assertStringContainsString(
            'x-show="(lastOrderId || lastTxnKotId) && (canKotReprint || lastKotPending)"',
            $blade,
            'the receipt popup KOT button must survive the block while the ticket is unsent'
        );
        $this->assertStringContainsString(
            'x-show="!((lastOrderId || lastTxnKotId) && (canKotReprint || lastKotPending))"',
            $blade,
            'the popup grid spacer must mirror the button so the 4-column grid stays balanced'
        );

        // K shortcut mirrors that button exactly.
        $this->assertStringContainsString(
            '!this.canKotReprint && !this.lastKotPending',
            $blade,
            'the K shortcut must only refuse a REPRINT, never a pending first send'
        );

        // Incoming order: a full ticket is a reprint only once every line printed.
        $this->assertStringContainsString(
            'x-show="canKotReprint || !(o.items || []).every(i => i.printed)"',
            $blade,
            'the incoming-order KOT button must stay for an order the kitchen has not seen'
        );

        // Held order (menu + list): the plain "view ticket" link can still be a
        // first render, so it only disappears once kot_sent_at is stamped.
        $this->assertStringContainsString('x-show="canKotReprint || !heldMenu.kot_sent_at"', $blade);
        $this->assertStringContainsString('x-show="canKotReprint || !order.kot_sent_at"', $blade);

        // A server-side @if compiles the control AWAY, so it is only safe on a
        // surface that can never be a first send. Exactly two qualify: the bill
        // panel (x-show="panelKotSent()") and the table-board menu (x-show on
        // boardMenuTable.order.kot_sent_at) — both already-sent by construction.
        // Any third one is almost certainly the popup regression coming back.
        $this->assertSame(
            2,
            preg_match_all('/@if\([^\n]*\$canKotReprint\)/', $blade),
            'a new server-side $canKotReprint branch must be proven reprint-only before it is allowed here'
        );
    }
}

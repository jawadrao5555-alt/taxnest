<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Services\PosFeatureService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * Task 676 (FBR mirror of PRA Task 661 — ZFC waqia): FBR day-close pending
 * checks + per-close wash override + auto-close skip.
 *
 * Locked here:
 *  1. UNDISPATCHED DELIVERY BILLS HARD-BLOCK the manual FBR close (assigned to
 *     a rider but never dispatched) — closeDayReport refuses with
 *     pos.dayclose_blocked_undispatched. A RIDER-LESS delivery bill never
 *     blocks (owner rule 23 Aug 2026: the shop handed it over itself).
 *  2. 'dispatched' does NOT block — rider khata is WARNING figures only.
 *  3. Feature gate: shops without the delivery feature are never blocked.
 *  4. PER-CLOSE OVERRIDE: wash_override=delete removes the day's provisionals
 *     for THIS close only (standing policy untouched); wash_override=save
 *     keeps them Local even when the standing policy is finalize; a cashier
 *     posting a crafted override is refused outright (ROLE check).
 *  5. fbrpos:auto-dayclose SKIPS a company with undispatched deliveries and
 *     sends the throttled skip-alert email; closes once dispatched.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create; HTTP
 * tests drive the real POST /fbr-pos/day-close (schema copied from
 * FbrPosDayCloseAutoFinalizeTest + rider columns).
 */
class FbrPosDayCloseUndispatchedDeliveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Deterministic mid-day clock — keeps business_date == today.
        \Illuminate\Support\Carbon::setTestNow(now()->setTime(12, 0));

        User::flushScopeColumnCache();
        PosFeatureService::flushGateCaches();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('product_type')->nullable();
            $table->string('status')->nullable();
            $table->string('default_language')->nullable();
            $table->boolean('is_internal_account')->default(false);
            $table->boolean('restaurant_mode')->default(false);
            $table->text('feature_flags')->nullable();
            $table->integer('invoice_limit_override')->nullable();
            $table->boolean('fbr_reporting_enabled')->default(false);
            $table->boolean('agent_enabled')->default(false);
            $table->boolean('agent_submits_pra')->nullable();
            $table->string('fbr_connection_mode')->nullable();
            $table->string('fbr_environment')->nullable();
            $table->string('fbr_pos_environment')->nullable();
            $table->text('fbr_pos_token')->nullable();
            $table->string('fbr_pos_id')->nullable();
            $table->boolean('fbr_pos_enabled')->default(false);
            $table->string('pos_dayclose_provisional_action')->nullable();
            $table->string('pos_business_day_cutoff')->nullable();
            $table->string('pos_dayclose_unassigned_delivery_action')->nullable();
            $table->boolean('pos_auto_dayclose_24h')->default(false);
            $table->boolean('pos_cashier_dayclose')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable()->unique();
            $table->string('password')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('default_branch_id')->nullable();
            $table->string('role')->nullable();
            $table->string('pos_role')->nullable();
            $table->text('pos_custom_access')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('language')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('fbr_pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('invoice_number');
            $table->string('business_date')->nullable();
            $table->string('status')->nullable();
            $table->string('invoice_mode')->nullable();
            $table->string('fbr_status')->nullable();
            $table->string('fbr_invoice_number')->nullable();
            $table->string('fbr_response_code')->nullable();
            $table->text('fbr_response')->nullable();
            $table->string('fbr_submission_hash')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->string('discount_type')->nullable();
            $table->decimal('discount_value', 12, 2)->nullable();
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_rate', 8, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('fbr_service_charge', 12, 2)->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->decimal('cash_received', 12, 2)->nullable();
            $table->decimal('change_due', 12, 2)->nullable();
            $table->unsignedTinyInteger('fbr_auto_retry_count')->default(0);
            // Rider / delivery columns (FBR mirror)
            $table->string('order_type')->nullable();
            $table->unsignedBigInteger('rider_id')->nullable();
            $table->string('delivery_status')->nullable();
            $table->unsignedBigInteger('rider_settlement_id')->nullable();
            $table->timestamp('rider_settled_at')->nullable();
            $table->decimal('rider_partial_paid', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('fbr_pos_transaction_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('item_name');
            $table->string('hs_code')->nullable();
            $table->decimal('quantity', 12, 4)->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('item_discount', 12, 2)->nullable();
            $table->boolean('is_tax_exempt')->default(false);
            $table->decimal('tax_rate', 8, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->nullable();
            $table->decimal('total', 12, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('fbr_pos_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->text('request_payload')->nullable();
            $table->text('response_payload')->nullable();
            $table->string('response_code')->nullable();
            $table->string('status')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_head_office')->default(false);
            $table->timestamps();
        });

        Schema::create('pos_riders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('fbr_day_close_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->date('report_date');
            $table->string('report_number');
            $table->integer('total_invoices')->default(0);
            $table->integer('fbr_invoices')->default(0);
            $table->integer('local_invoices')->default(0);
            $table->integer('failed_invoices')->default(0);
            $table->decimal('gross_sales', 14, 2)->default(0);
            $table->decimal('total_discount', 14, 2)->default(0);
            $table->decimal('net_sales', 14, 2)->default(0);
            $table->decimal('total_tax', 14, 2)->default(0);
            $table->decimal('total_fbr_fee', 14, 2)->nullable();
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->decimal('cash_amount', 14, 2)->default(0);
            $table->decimal('card_amount', 14, 2)->default(0);
            $table->decimal('udhaar_amount', 14, 2)->default(0);
            $table->decimal('other_amount', 14, 2)->default(0);
            $table->string('first_invoice_number')->nullable();
            $table->string('last_invoice_number')->nullable();
            $table->timestamp('first_invoice_time')->nullable();
            $table->timestamp('last_invoice_time')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->text('notes')->nullable();
            $table->string('hash')->nullable();
            $table->decimal('opening_float', 14, 2)->nullable();
            $table->decimal('counted_cash', 14, 2)->nullable();
            $table->decimal('expected_cash', 14, 2)->nullable();
            $table->decimal('cash_variance', 14, 2)->nullable();
            $table->timestamps();
        });

        // Report numbering uses MySQL SUBSTRING_INDEX — polyfill for sqlite.
        DB::connection()->getPdo()->sqliteCreateFunction('SUBSTRING_INDEX', function ($str, $delim, $count) {
            $parts = explode((string) $delim, (string) $str);
            return $count < 0
                ? implode($delim, array_slice($parts, (int) $count))
                : implode($delim, array_slice($parts, 0, (int) $count));
        });
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function makeCompany(bool $delivery = true, array $attrs = []): int
    {
        $flags = ['customer_profile' => true];
        if ($delivery) {
            $flags['delivery'] = true;
        }

        return (int) DB::table('companies')->insertGetId(array_merge([
            'name' => 'FBR ZFC Guard Co',
            'product_type' => 'fbrpos',
            'status' => 'active',
            'is_internal_account' => true, // bypass plan gates — feature flag is the lever
            'restaurant_mode' => false,
            'feature_flags' => json_encode($flags),
            'invoice_limit_override' => -1,
            'fbr_reporting_enabled' => false,
            'agent_enabled' => false,
            'fbr_connection_mode' => 'cloud',
            'fbr_environment' => 'sandbox',
            'fbr_pos_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));
    }

    private function makeUser(int $companyId, ?string $posRole = null, ?array $customAccess = null): User
    {
        $id = DB::table('users')->insertGetId([
            'name' => $posRole ?? 'Owner',
            'email' => ($posRole ?? 'owner') . $companyId . '_' . rand(10000, 99999) . '@fbrzfc.test',
            'password' => bcrypt('Secret@12345'),
            'company_id' => $companyId,
            'role' => $posRole === null ? 'company_admin' : 'user',
            'pos_role' => $posRole,
            'pos_custom_access' => $customAccess === null ? null : json_encode($customAccess),
            'is_active' => true,
            'language' => 'en',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::find($id);
    }

    private function makeRider(int $companyId): int
    {
        return (int) DB::table('pos_riders')->insertGetId([
            'company_id' => $companyId,
            'name' => 'Qaisar',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeBill(int $companyId, array $attrs = []): int
    {
        $id = (int) DB::table('fbr_pos_transactions')->insertGetId(array_merge([
            'company_id' => $companyId,
            'invoice_number' => 'FPOS-2026-' . uniqid(),
            'business_date' => now()->toDateString(),
            'status' => 'completed',
            'invoice_mode' => 'fbr',
            'fbr_status' => null,
            'subtotal' => 500,
            'total_amount' => 500,
            'payment_method' => 'cash',
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));

        DB::table('fbr_pos_transaction_items')->insert([
            'transaction_id' => $id,
            'item_name' => 'Deal Burger',
            'quantity' => 1,
            'unit_price' => 500,
            'subtotal' => 500,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function summary(int $companyId): object
    {
        return app(\App\Http\Controllers\FbrPosController::class)
            ->undispatchedDeliverySummary($companyId, null, now()->toDateString());
    }

    private function closeDay(User $user, array $payload = [])
    {
        return $this->actingAs($user, 'fbrpos')
            ->from('/fbr-pos/day-close')
            ->post('/fbr-pos/day-close', $payload);
    }

    // ── 1. hard block ────────────────────────────────────────────────────────

    public function test_assigned_undispatched_delivery_blocks_manual_close(): void
    {
        $cid = $this->makeCompany();
        $rid = $this->makeRider($cid);
        $this->makeBill($cid, ['rider_id' => $rid, 'delivery_status' => 'assigned', 'order_type' => 'delivery']);

        $res = $this->closeDay($this->makeUser($cid));

        $res->assertRedirect('/fbr-pos/day-close');
        $this->assertStringContainsString(
            __('pos.dayclose_blocked_undispatched', ['count' => 1]),
            (string) session('error'),
            'FBR close must refuse with the undispatched-deliveries error.'
        );
        $this->assertSame(0, DB::table('fbr_day_close_reports')->count(), 'No Z-report may be created.');
    }

    /**
     * OWNER RULE (23 Aug 2026, mirrors PRA): nobody assigned a rider, so the
     * shop handed the order over itself — no rider cash, nothing to settle, so
     * the bill must never hold the day open.
     */
    public function test_rider_less_delivery_never_blocks_the_close(): void
    {
        $cid = $this->makeCompany();
        $riderLess = $this->makeBill($cid, ['order_type' => 'delivery']);

        $sum = $this->summary($cid);
        $this->assertTrue($sum->active);
        $this->assertSame(0, $sum->count, 'A rider-less delivery bill is not a blocker.');
        $this->assertSame(0, $sum->unassigned);

        $res = $this->closeDay($this->makeUser($cid));
        $res->assertSessionHas('success');
        $this->assertSame(1, DB::table('fbr_day_close_reports')->count());
        $this->assertNull(DB::table('fbr_pos_transactions')->find($riderLess)->delivery_status);
    }

    public function test_company_can_require_unassigned_deliveries_before_close(): void
    {
        $cid = $this->makeCompany(true, ['pos_dayclose_unassigned_delivery_action' => 'block']);
        $this->makeBill($cid, ['order_type' => 'delivery']);

        $sum = $this->summary($cid);
        $this->assertSame(1, $sum->count);
        $this->assertSame(1, $sum->unassigned);

        $res = $this->closeDay($this->makeUser($cid));
        $res->assertSessionHas('error', __('pos.dayclose_blocked_undispatched', ['count' => 1]));
        $this->assertSame(0, DB::table('fbr_day_close_reports')->count());
    }

    // ── 2. dispatched = khata warning only, close allowed ───────────────────

    public function test_dispatched_cash_bill_does_not_block_and_shows_khata_warning(): void
    {
        $cid = $this->makeCompany();
        $rid = $this->makeRider($cid);
        $this->makeBill($cid, ['rider_id' => $rid, 'delivery_status' => 'dispatched', 'order_type' => 'delivery']);

        $sum = $this->summary($cid);
        $this->assertSame(0, $sum->count, "'dispatched' must never block the close");
        $this->assertSame(1, $sum->khata_count, 'Unsettled rider cash must surface as khata warning');
        $this->assertEquals(500.0, $sum->khata_amount);

        $res = $this->closeDay($this->makeUser($cid));
        $res->assertRedirect('/fbr-pos/day-close');
        $res->assertSessionHas('success');
        $this->assertSame(1, DB::table('fbr_day_close_reports')->count(), 'Close must go through despite khata.');
    }

    // ── 3. feature gate ──────────────────────────────────────────────────────

    public function test_delivery_feature_off_never_blocks(): void
    {
        $cid = $this->makeCompany(delivery: false);
        $rid = $this->makeRider($cid);
        $this->makeBill($cid, ['rider_id' => $rid, 'delivery_status' => 'assigned', 'order_type' => 'delivery']);

        $sum = $this->summary($cid);
        $this->assertFalse($sum->active);
        $this->assertSame(0, $sum->count);

        $res = $this->closeDay($this->makeUser($cid));
        $res->assertSessionHas('success');
        $this->assertSame(1, DB::table('fbr_day_close_reports')->count());
    }

    // ── 4. per-close wash override ───────────────────────────────────────────

    public function test_override_delete_deletes_provisionals_but_standing_policy_untouched(): void
    {
        $cid = $this->makeCompany(delivery: true, attrs: ['pos_dayclose_provisional_action' => null]);
        $bill = $this->makeBill($cid, ['invoice_mode' => 'local', 'fbr_status' => 'local', 'order_type' => null]);
        // A regular bill too, so the day still has figures after the delete.
        $this->makeBill($cid);

        $res = $this->closeDay($this->makeUser($cid), ['wash_override' => 'delete']);
        $res->assertSessionHas('success');

        $this->assertSame(0, DB::table('fbr_pos_transactions')->where('id', $bill)->count(),
            'Override=delete must hard-delete the provisional for THIS close.');
        $this->assertSame(0, DB::table('fbr_pos_transaction_items')->where('transaction_id', $bill)->count(),
            'Item rows must be cleaned up with the bill.');
        $this->assertNull(DB::table('companies')->where('id', $cid)->value('pos_dayclose_provisional_action'),
            'Standing policy must stay untouched.');
        $this->assertStringContainsString(
            __('pos.dayclose_bills_deleted', ['count' => 1]),
            (string) session('success'),
            'Flash must state how many provisionals were deleted.'
        );
    }

    public function test_override_save_keeps_locals_even_when_standing_policy_is_finalize(): void
    {
        $cid = $this->makeCompany(delivery: true, attrs: ['pos_dayclose_provisional_action' => 'finalize']);
        $bill = $this->makeBill($cid, ['invoice_mode' => 'local', 'fbr_status' => 'local', 'order_type' => null]);

        $res = $this->closeDay($this->makeUser($cid), ['wash_override' => 'save']);
        $res->assertSessionHas('success');

        $tx = DB::table('fbr_pos_transactions')->where('id', $bill)->first();
        $this->assertNotNull($tx, 'Override=save must keep the bill.');
        $this->assertSame('local', $tx->invoice_mode, 'Override=save must NOT run the finalize sweep.');
        $this->assertSame('local', $tx->fbr_status);
        $this->assertSame('finalize', DB::table('companies')->where('id', $cid)->value('pos_dayclose_provisional_action'));
    }

    public function test_cashier_crafted_override_is_refused(): void
    {
        $cid = $this->makeCompany(delivery: true, attrs: ['pos_dayclose_provisional_action' => null]);
        $bill = $this->makeBill($cid, ['invoice_mode' => 'local', 'fbr_status' => 'local', 'order_type' => null]);
        // Cashier WITH day-close custom access — may close days, must NOT override.
        $cashier = $this->makeUser($cid, 'pos_cashier', ['day_close']);

        $this->closeDay($cashier, ['wash_override' => 'delete']);

        $this->assertSame(__('pos.only_admin_change_setting'), (string) session('error'),
            'Cashier override must be refused with an explicit error.');
        $this->assertSame(0, DB::table('fbr_day_close_reports')->count(), 'No close may happen on a refused override.');
        $this->assertSame(1, DB::table('fbr_pos_transactions')->where('id', $bill)->count(), 'Bill must be untouched.');
    }

    // ── 5. auto-close sweep skip + alert ─────────────────────────────────────

    public function test_auto_close_skips_on_undispatched_deliveries_and_alerts_owner(): void
    {
        Mail::fake();
        \Illuminate\Support\Facades\Cache::flush();

        $cid = $this->makeCompany(delivery: true, attrs: ['pos_auto_dayclose_24h' => true]);
        $owner = $this->makeUser($cid); // company_admin — alert recipient
        $rid = $this->makeRider($cid);
        // YESTERDAY's trading day is pending, and its delivery never dispatched.
        $this->makeBill($cid, [
            'rider_id' => $rid,
            'delivery_status' => 'assigned',
            'order_type' => 'delivery',
            'business_date' => now()->subDay()->toDateString(),
            'created_at' => now()->subDay(),
        ]);

        $this->artisan('fbrpos:auto-dayclose')->assertExitCode(0);

        $this->assertSame(0, DB::table('fbr_day_close_reports')->count(),
            'Sweep must SKIP the company while a delivery bill is undispatched.');
        Mail::assertSent(\App\Mail\TrialReminderMail::class, function ($mail) use ($owner) {
            return $mail->hasTo($owner->email);
        });

        // Once dispatched, the next hourly run closes the day.
        DB::table('fbr_pos_transactions')->where('company_id', $cid)->update(['delivery_status' => 'dispatched']);
        $this->artisan('fbrpos:auto-dayclose')->assertExitCode(0);
        $this->assertSame(1, DB::table('fbr_day_close_reports')->count(),
            'After dispatch the sweep must close the pending day.');
    }

    // ── 6. bulk close-all skips blocker days only (Task 684) ─────────────────

    public function test_bulk_close_all_skips_undispatched_day_but_closes_earlier_days(): void
    {
        $cid = $this->makeCompany();
        $rid = $this->makeRider($cid);
        // Day -2: clean stranded day — must close.
        $this->makeBill($cid, [
            'business_date' => now()->subDays(2)->toDateString(),
            'created_at' => now()->subDays(2),
        ]);
        // Day -1: assigned-but-undispatched delivery — must be SKIPPED.
        $this->makeBill($cid, [
            'rider_id' => $rid,
            'delivery_status' => 'assigned',
            'order_type' => 'delivery',
            'business_date' => now()->subDay()->toDateString(),
            'created_at' => now()->subDay(),
        ]);

        $res = $this->actingAs($this->makeUser($cid), 'fbrpos')
            ->from('/fbr-pos/day-close')
            ->post('/fbr-pos/day-close/close-all-prior');
        $res->assertRedirect('/fbr-pos/day-close');

        $this->assertSame(1, DB::table('fbr_day_close_reports')->count(),
            'Only the clean earlier day may close; the blocker day must be skipped.');
        $this->assertSame(
            now()->subDays(2)->toDateString(),
            (string) \Carbon\Carbon::parse(DB::table('fbr_day_close_reports')->value('report_date'))->toDateString()
        );
        $err = (string) session('error');
        $this->assertStringContainsString(
            __('pos.dc_bulk_skipped_undispatched', ['days' => 1, 'count' => 1]),
            $err,
            'Flash must state WHY a day was skipped (undispatched deliveries).'
        );
        $this->assertStringContainsString(
            __('pos.dc_bulk_partial', ['remaining' => 1]),
            $err,
            'Flash must report the skipped day as still pending.'
        );

        // Once dispatched, the next bulk run closes the remaining day.
        DB::table('fbr_pos_transactions')->where('company_id', $cid)
            ->where('delivery_status', 'assigned')->update(['delivery_status' => 'dispatched']);
        $res2 = $this->actingAs($this->makeUser($cid), 'fbrpos')
            ->from('/fbr-pos/day-close')
            ->post('/fbr-pos/day-close/close-all-prior');
        $res2->assertSessionHas('success');
        $this->assertSame(2, DB::table('fbr_day_close_reports')->count(),
            'After dispatch the bulk close must finish the skipped day.');
    }

    // ── 7. rush-recovery auto-close skips undispatched (Task 684) ────────────

    public function test_api_auto_close_all_skips_undispatched_day(): void
    {
        $cid = $this->makeCompany();
        $rid = $this->makeRider($cid);
        // Day -2: clean pending day — closes.
        $this->makeBill($cid, [
            'business_date' => now()->subDays(2)->toDateString(),
            'created_at' => now()->subDays(2),
        ]);
        // Day -1: undispatched delivery — rush recovery must leave it alone.
        $this->makeBill($cid, [
            'rider_id' => $rid,
            'delivery_status' => 'assigned',
            'order_type' => 'delivery',
            'business_date' => now()->subDay()->toDateString(),
            'created_at' => now()->subDay(),
        ]);

        $res = $this->actingAs($this->makeUser($cid), 'fbrpos')
            ->postJson('/fbr-pos/api/auto-close-day', ['all' => true]);

        $res->assertOk()->assertJsonPath('ok', true)->assertJsonPath('count', 1);
        $this->assertSame(1, count($res->json('skipped')), 'Blocker day must be reported as skipped.');
        $this->assertSame(now()->subDay()->toDateString(), $res->json('skipped.0.date'));
        $this->assertSame(1, DB::table('fbr_day_close_reports')->count(),
            'Only the clean day may close; the undispatched day must survive rush recovery.');
        $this->assertSame(
            now()->subDays(2)->toDateString(),
            (string) \Carbon\Carbon::parse(DB::table('fbr_day_close_reports')->value('report_date'))->toDateString()
        );
    }

    public function test_api_auto_close_single_date_refuses_on_undispatched(): void
    {
        $cid = $this->makeCompany();
        $rid = $this->makeRider($cid);
        $this->makeBill($cid, [
            'rider_id' => $rid,
            'delivery_status' => 'assigned',
            'order_type' => 'delivery',
            'business_date' => now()->subDay()->toDateString(),
            'created_at' => now()->subDay(),
        ]);

        $res = $this->actingAs($this->makeUser($cid), 'fbrpos')
            ->postJson('/fbr-pos/api/auto-close-day', ['date' => now()->subDay()->toDateString()]);

        $res->assertStatus(409)->assertJsonPath('ok', false)->assertJsonPath('undispatched', 1);
        $this->assertSame(0, DB::table('fbr_day_close_reports')->count(),
            'Single-date rush recovery must refuse while deliveries are undispatched.');
    }
}

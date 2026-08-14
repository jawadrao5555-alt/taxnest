<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Company;
use App\Models\User;
use App\Services\PosFeatureService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * Task 661 (ZFC waqia) — day-close pending checks + per-close wash override.
 *
 * Locked here:
 *  1. UNDISPATCHED DELIVERY BILLS HARD-BLOCK the manual close: a completed
 *     delivery bill that is assigned-but-not-dispatched, or a fresh UNASSIGNED
 *     delivery bill (7-day window, mirrors the Deliveries board), refuses
 *     closeDayReport with pos.dayclose_blocked_undispatched.
 *  2. 'dispatched' does NOT block — the rider has the order; its unsettled
 *     cash surfaces as khata WARNING figures only (khata carries overnight).
 *  3. Feature gate: shops without the delivery feature (or plan) are NEVER
 *     touched by this check (active=false, zero counts).
 *  4. PER-CLOSE WASH OVERRIDE: wash_override=delete/save/finalize applies to
 *     THIS close only — the standing company policy column stays untouched.
 *     A cashier posting a crafted override is refused outright (role check,
 *     not path-access check), even when custom access lets them close days.
 *  5. AUTO-CLOSE SWEEP skips a company with undispatched delivery bills and
 *     sends the (throttled) skip-alert email — same policy as open orders.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create; the
 * HTTP tests drive the real POST /pos/day-close path (schema copied from
 * PosDayCloseAutoFinalizeTest + rider columns from PosRiderDayCloseFiguresTest).
 */
class PosDayCloseUndispatchedDeliveryTest extends TestCase
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
            $table->string('pos_dayclose_provisional_action')->nullable();
            $table->string('pos_dayclose_final_local_action')->nullable();
            $table->boolean('pos_customer_spend_persist')->default(true);
            $table->string('pos_business_day_cutoff')->nullable();
            $table->boolean('pos_auto_dayclose_24h')->default(false);
            $table->boolean('pos_cashier_dayclose')->default(false);
            $table->boolean('is_internal_account')->default(false);
            $table->boolean('restaurant_mode')->default(false);
            $table->text('feature_flags')->nullable();
            $table->integer('invoice_limit_override')->nullable();
            $table->boolean('pra_reporting_enabled')->default(false);
            $table->boolean('agent_enabled')->default(false);
            $table->boolean('agent_submits_pra')->nullable();
            $table->string('pra_connection_mode')->nullable();
            $table->string('pra_environment')->nullable();
            $table->text('pra_production_token')->nullable();
            $table->string('pra_proxy_url')->nullable();
            $table->string('pra_pos_id')->nullable();
            $table->decimal('pos_tax_rate_cash', 8, 2)->nullable();
            $table->decimal('pos_tax_rate_card', 8, 2)->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable()->unique();
            $table->string('password')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('role')->nullable();
            $table->string('pos_role')->nullable();
            $table->text('pos_custom_access')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('language')->nullable();
            $table->boolean('pra_reporting_enabled')->default(false);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('invoice_number');
            $table->string('business_date')->nullable();
            $table->string('status');
            $table->string('invoice_mode')->nullable();
            $table->string('pra_status')->nullable();
            $table->string('pra_invoice_number')->nullable();
            $table->string('pra_response_code')->nullable();
            $table->text('pra_qr_code')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->unsignedBigInteger('archived_by_report_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_rate', 8, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('exempt_amount', 12, 2)->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->decimal('cash_received', 12, 2)->nullable();
            $table->decimal('change_due', 12, 2)->nullable();
            $table->string('submission_hash')->nullable();
            $table->boolean('tax_inclusive')->default(false);
            $table->decimal('tax_menu_rate', 8, 2)->nullable();
            // Rider / delivery columns (Task 431+)
            $table->string('order_type')->nullable();
            $table->unsignedBigInteger('rider_id')->nullable();
            $table->string('delivery_status')->nullable();
            $table->unsignedBigInteger('rider_settlement_id')->nullable();
            $table->timestamp('rider_settled_at')->nullable();
            $table->decimal('rider_partial_paid', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('pos_transaction_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->string('item_type')->nullable();
            $table->unsignedBigInteger('item_id')->nullable();
            $table->string('item_name');
            $table->decimal('quantity', 12, 3)->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->boolean('is_tax_exempt')->default(false);
            $table->decimal('tax_rate', 8, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('pos_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->string('payment_method')->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('reference_number')->nullable();
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

        Schema::create('pra_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->text('request_payload')->nullable();
            $table->text('response_payload')->nullable();
            $table->string('response_code')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_riders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('pos_day_close_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->date('report_date');
            $table->string('report_number')->nullable();
            $table->integer('deleted_final_count')->default(0);
            $table->integer('deleted_provisional_count')->default(0);
            $table->text('local_summary')->nullable();
            $table->text('rider_summary')->nullable();
            $table->integer('total_invoices')->default(0);
            $table->integer('pra_invoices')->default(0);
            $table->integer('local_invoices')->default(0);
            $table->integer('offline_invoices')->default(0);
            $table->decimal('gross_sales', 14, 2)->default(0);
            $table->decimal('total_discount', 14, 2)->default(0);
            $table->decimal('net_sales', 14, 2)->default(0);
            $table->decimal('total_tax', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->decimal('cash_amount', 14, 2)->default(0);
            $table->decimal('card_amount', 14, 2)->default(0);
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
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function makeCompany(bool $delivery = true, array $attrs = []): int
    {
        $flags = ['customer_profile' => true];
        if ($delivery) {
            $flags['delivery'] = true;
        }

        return (int) DB::table('companies')->insertGetId(array_merge([
            'name' => 'ZFC Guard Co',
            'email' => null,
            'product_type' => 'pos',
            'status' => 'active',
            'is_internal_account' => true, // bypass plan gates — feature flag is the lever
            'restaurant_mode' => false,
            'feature_flags' => json_encode($flags),
            'invoice_limit_override' => -1,
            'pra_reporting_enabled' => false,
            'agent_enabled' => false,
            'pra_connection_mode' => 'cloud',
            'pra_environment' => 'sandbox',
            'pos_tax_rate_cash' => 16.00,
            'pos_tax_rate_card' => 16.00,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));
    }

    private function makeUser(int $companyId, ?string $posRole = null, ?array $customAccess = null): User
    {
        $id = DB::table('users')->insertGetId([
            'name' => $posRole ?? 'Owner',
            'email' => ($posRole ?? 'owner') . $companyId . '_' . rand(10000, 99999) . '@zfc.test',
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
        $id = (int) DB::table('pos_transactions')->insertGetId(array_merge([
            'company_id' => $companyId,
            'invoice_number' => 'POS-2026-' . uniqid(),
            'business_date' => now()->toDateString(),
            'status' => 'completed',
            'invoice_mode' => 'pra',
            'pra_status' => 'submitted',
            'is_archived' => false,
            'subtotal' => 500,
            'total_amount' => 500,
            'payment_method' => 'cash',
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));

        DB::table('pos_transaction_items')->insert([
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
        return app(\App\Http\Controllers\PosController::class)
            ->undispatchedDeliverySummary($companyId, null, \App\Services\PosBusinessDay::current($companyId));
    }

    private function closeDay(User $user, array $payload = [])
    {
        return $this->actingAs($user, 'pos')
            ->from('/pos/day-close')
            ->post('/pos/day-close', $payload);
    }

    // ── 1. hard block ────────────────────────────────────────────────────────

    public function test_assigned_undispatched_delivery_blocks_manual_close(): void
    {
        $cid = $this->makeCompany();
        $rid = $this->makeRider($cid);
        $this->makeBill($cid, ['rider_id' => $rid, 'delivery_status' => 'assigned', 'order_type' => 'delivery']);

        $res = $this->closeDay($this->makeUser($cid));

        $res->assertRedirect('/pos/day-close');
        $this->assertStringContainsString(
            __('pos.dayclose_blocked_undispatched', ['count' => 1]),
            (string) session('error'),
            'Close must refuse with the undispatched-deliveries error.'
        );
        $this->assertSame(0, DB::table('pos_day_close_reports')->count(), 'No Z-report may be created.');
    }

    public function test_unassigned_fresh_delivery_blocks_but_archived_does_not(): void
    {
        $cid = $this->makeCompany();
        // Fresh unassigned delivery — blocks (Task 513 window).
        $this->makeBill($cid, ['order_type' => 'delivery']);
        // Archived delivery — out of the operational stream, never blocks.
        $this->makeBill($cid, ['order_type' => 'delivery', 'is_archived' => true]);

        $sum = $this->summary($cid);
        $this->assertTrue($sum->active);
        $this->assertSame(1, $sum->count);
        $this->assertSame(1, $sum->unassigned);

        $res = $this->closeDay($this->makeUser($cid));
        $this->assertStringContainsString(
            __('pos.dayclose_blocked_undispatched', ['count' => 1]),
            (string) session('error')
        );
        $this->assertSame(0, DB::table('pos_day_close_reports')->count());
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
        $res->assertRedirect('/pos/day-close');
        $res->assertSessionHas('success');
        $this->assertSame(1, DB::table('pos_day_close_reports')->count(), 'Close must go through despite khata.');
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
        $this->assertSame(1, DB::table('pos_day_close_reports')->count());
    }

    // ── 4. per-close wash override ───────────────────────────────────────────

    public function test_override_delete_deletes_provisionals_but_standing_policy_untouched(): void
    {
        $cid = $this->makeCompany(delivery: true, attrs: ['pos_dayclose_provisional_action' => 'save']);
        $bill = $this->makeBill($cid, ['invoice_mode' => 'local', 'pra_status' => 'local', 'order_type' => null]);

        $res = $this->closeDay($this->makeUser($cid), ['wash_override' => 'delete']);
        $res->assertSessionHas('success');

        $this->assertSame(0, DB::table('pos_transactions')->where('id', $bill)->count(),
            'Override=delete must hard-delete the provisional for THIS close.');
        $report = DB::table('pos_day_close_reports')->first();
        $this->assertSame(1, (int) $report->deleted_provisional_count, 'Quota add-back counter must record the delete.');
        $this->assertSame('save', DB::table('companies')->where('id', $cid)->value('pos_dayclose_provisional_action'),
            'Standing policy must stay untouched.');
    }

    public function test_override_save_archives_even_when_standing_policy_is_delete(): void
    {
        $cid = $this->makeCompany(delivery: true, attrs: ['pos_dayclose_provisional_action' => 'delete']);
        $bill = $this->makeBill($cid, ['invoice_mode' => 'local', 'pra_status' => 'local', 'order_type' => null]);

        $res = $this->closeDay($this->makeUser($cid), ['wash_override' => 'save']);
        $res->assertSessionHas('success');

        $tx = DB::table('pos_transactions')->where('id', $bill)->first();
        $this->assertNotNull($tx, 'Override=save must keep the bill.');
        $this->assertSame(1, (int) $tx->is_archived, 'Override=save must archive the bill.');
        $this->assertSame('delete', DB::table('companies')->where('id', $cid)->value('pos_dayclose_provisional_action'));
    }

    public function test_cashier_crafted_override_is_refused(): void
    {
        $cid = $this->makeCompany(delivery: true, attrs: ['pos_dayclose_provisional_action' => 'save']);
        $bill = $this->makeBill($cid, ['invoice_mode' => 'local', 'pra_status' => 'local', 'order_type' => null]);
        // Cashier WITH day-close custom access — may close days, must NOT override.
        $cashier = $this->makeUser($cid, 'pos_cashier', ['day_close']);

        $res = $this->closeDay($cashier, ['wash_override' => 'delete']);

        $this->assertSame(__('pos.only_admin_change_setting'), (string) session('error'),
            'Cashier override must be refused with an explicit error.');
        $this->assertSame(0, DB::table('pos_day_close_reports')->count(), 'No close may happen on a refused override.');
        $this->assertSame(1, DB::table('pos_transactions')->where('id', $bill)->count(), 'Bill must be untouched.');
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

        $this->artisan('pos:auto-dayclose')->assertExitCode(0);

        $this->assertSame(0, DB::table('pos_day_close_reports')->count(),
            'Sweep must SKIP the company while a delivery bill is undispatched.');
        Mail::assertSent(\App\Mail\TrialReminderMail::class, function ($mail) use ($owner) {
            return $mail->hasTo($owner->email);
        });

        // Once dispatched, the next hourly run closes the day.
        DB::table('pos_transactions')->where('company_id', $cid)->update(['delivery_status' => 'dispatched']);
        $this->artisan('pos:auto-dayclose')->assertExitCode(0);
        $this->assertSame(1, DB::table('pos_day_close_reports')->count(),
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

        $res = $this->actingAs($this->makeUser($cid), 'pos')
            ->from('/pos/day-close')
            ->post('/pos/day-close/close-all-prior');
        $res->assertRedirect('/pos/day-close');

        $this->assertSame(1, DB::table('pos_day_close_reports')->count(),
            'Only the clean earlier day may close; the blocker day must be skipped.');
        $this->assertSame(
            now()->subDays(2)->toDateString(),
            (string) \Carbon\Carbon::parse(DB::table('pos_day_close_reports')->value('report_date'))->toDateString()
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
        DB::table('pos_transactions')->where('company_id', $cid)
            ->where('delivery_status', 'assigned')->update(['delivery_status' => 'dispatched']);
        $res2 = $this->actingAs($this->makeUser($cid), 'pos')
            ->from('/pos/day-close')
            ->post('/pos/day-close/close-all-prior');
        $res2->assertSessionHas('success');
        $this->assertSame(2, DB::table('pos_day_close_reports')->count(),
            'After dispatch the bulk close must finish the skipped day.');
    }
}

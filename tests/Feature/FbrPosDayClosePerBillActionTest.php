<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Services\PosFeatureService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * Task 687 — FBR day-close BILL-BY-BILL decision (mirror of PRA Task 677,
 * tests/Feature/PosDayClosePerBillActionTest.php adapted to FBR semantics).
 *
 * Locked here:
 *  1. MIXED PICKS with no all-box: finalize one, delete one, rest follow the
 *     standing policy (null → stay Local). FBR finalize with reporting OFF =
 *     'fbr' mode + NULL fbr_status (Reporting-OFF Finals Invariant).
 *  2. PER-BILL BEATS ALL-BOX: wash_override=finalize + per-bill save/delete →
 *     picked bills follow their own pick, only the rest promote.
 *  3. STANDING DELETE + per-bill save/finalize → those bills survive the wash.
 *  4. CASHIER (even with day-close custom access) posting any real per-bill
 *     pick is refused outright (pos.only_admin_change_setting, no Z-report);
 *     all-'standing' picks are a no-op and the close goes through.
 *  5. RIDER KHATA DELETE-GUARD: a bill whose cash is still WITH the rider
 *     (unsettled cash, not returned) is never deleted — FBR has no archive,
 *     it simply STAYS Local. Applies to per-bill AND whole-set deletes.
 *  6. Crafted foreign-company bill ids match nothing (company-scoped wash).
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create; HTTP
 * tests drive the real POST /fbr-pos/day-close (schema copied from
 * FbrPosDayCloseUndispatchedDeliveryTest).
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' php vendor/bin/phpunit tests/Feature/FbrPosDayClosePerBillActionTest.php
 */
class FbrPosDayClosePerBillActionTest extends TestCase
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
            // Task 691: pending-bill decision audit (PRA local_summary parity).
            $table->text('local_summary')->nullable();
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

    private function makeCompany(array $attrs = []): int
    {
        return (int) DB::table('companies')->insertGetId(array_merge([
            'name' => 'FBR PerBill Co',
            'product_type' => 'fbrpos',
            'status' => 'active',
            'is_internal_account' => true,
            'restaurant_mode' => false,
            'feature_flags' => json_encode(['customer_profile' => true, 'delivery' => true]),
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
            'email' => ($posRole ?? 'owner') . $companyId . '_' . rand(10000, 99999) . '@fbrperbill.test',
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

    /** Pending provisional (local+local) bill of today. */
    private function makeProvisional(int $companyId, array $attrs = []): int
    {
        $id = (int) DB::table('fbr_pos_transactions')->insertGetId(array_merge([
            'company_id' => $companyId,
            'invoice_number' => 'FPOS-2026-' . uniqid(),
            'business_date' => now()->toDateString(),
            'status' => 'completed',
            'invoice_mode' => 'local',
            'fbr_status' => 'local',
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

    private function closeDay(User $user, array $payload = [])
    {
        return $this->actingAs($user, 'fbrpos')
            ->from('/fbr-pos/day-close')
            ->post('/fbr-pos/day-close', $payload);
    }

    private function tx(int $id): ?object
    {
        return DB::table('fbr_pos_transactions')->where('id', $id)->first();
    }

    // ── 1. mixed per-bill picks, no all-box, standing policy null ───────────

    public function test_mixed_per_bill_picks_with_null_standing_policy(): void
    {
        $cid = $this->makeCompany(['pos_dayclose_provisional_action' => null]);
        $fin = $this->makeProvisional($cid);
        $del = $this->makeProvisional($cid);
        $def = $this->makeProvisional($cid);

        $res = $this->closeDay($this->makeUser($cid), [
            'bill_actions' => [$fin => 'finalize', $del => 'delete', $def => 'standing'],
        ]);
        $res->assertSessionHas('success');

        // Picked finalize → FINAL (reporting OFF: fbr + NULL, never 'local'/'pending').
        $t = $this->tx($fin);
        $this->assertSame('fbr', $t->invoice_mode);
        $this->assertNull($t->fbr_status);
        $this->assertEquals(500.0, (float) $t->total_amount, 'Amounts untouched on finalize.');

        // Picked delete → gone, items cleaned up.
        $this->assertNull($this->tx($del));
        $this->assertSame(0, DB::table('fbr_pos_transaction_items')->where('transaction_id', $del)->count());

        // Default → follows standing policy (null) = stays Local.
        $t = $this->tx($def);
        $this->assertSame('local', $t->invoice_mode);
        $this->assertSame('local', $t->fbr_status);
    }

    // ── 2. per-bill beats the all-box ────────────────────────────────────────

    public function test_per_bill_pick_beats_all_box_finalize(): void
    {
        $cid = $this->makeCompany(['pos_dayclose_provisional_action' => null]);
        $save = $this->makeProvisional($cid);
        $del = $this->makeProvisional($cid);
        $rest = $this->makeProvisional($cid);

        $this->closeDay($this->makeUser($cid), [
            'wash_override' => 'finalize',
            'bill_actions' => [$save => 'save', $del => 'delete'],
        ]);

        // save-pick survives Local despite all-box finalize.
        $t = $this->tx($save);
        $this->assertSame('local', $t->invoice_mode, 'Per-bill save must beat all-box finalize.');
        $this->assertSame('local', $t->fbr_status);

        // delete-pick is deleted, not finalized.
        $this->assertNull($this->tx($del));

        // Unpicked bill follows the all-box → FINAL.
        $t = $this->tx($rest);
        $this->assertSame('fbr', $t->invoice_mode);
        $this->assertNull($t->fbr_status);
    }

    public function test_standing_delete_with_per_bill_save_and_finalize(): void
    {
        $cid = $this->makeCompany(['pos_dayclose_provisional_action' => 'delete']);
        $save = $this->makeProvisional($cid);
        $fin = $this->makeProvisional($cid);
        $rest = $this->makeProvisional($cid);

        $this->closeDay($this->makeUser($cid), [
            'bill_actions' => [$save => 'save', $fin => 'finalize'],
        ]);

        $t = $this->tx($save);
        $this->assertSame('local', $t->invoice_mode, 'Per-bill save must survive a standing delete.');
        $this->assertSame('local', $t->fbr_status);

        $t = $this->tx($fin);
        $this->assertSame('fbr', $t->invoice_mode, 'Per-bill finalize must run even when the set action is delete.');
        $this->assertNull($t->fbr_status);

        $this->assertNull($this->tx($rest), 'Unpicked bill follows the standing delete.');
        $this->assertSame('delete', DB::table('companies')->where('id', $cid)->value('pos_dayclose_provisional_action'),
            'Standing policy stays untouched.');
    }

    // ── 3. cashier refusal ───────────────────────────────────────────────────

    public function test_cashier_per_bill_pick_is_refused(): void
    {
        $cid = $this->makeCompany(['pos_dayclose_provisional_action' => null]);
        $bill = $this->makeProvisional($cid);
        $cashier = $this->makeUser($cid, 'pos_cashier', ['day_close']);

        $this->closeDay($cashier, ['bill_actions' => [$bill => 'delete']]);

        $this->assertSame(__('pos.only_admin_change_setting'), (string) session('error'),
            'Cashier per-bill pick must be refused with an explicit error.');
        $this->assertSame(0, DB::table('fbr_day_close_reports')->count(), 'No Z-report on a refused pick.');
        $this->assertNotNull($this->tx($bill), 'Bill must be untouched.');
    }

    public function test_cashier_all_standing_picks_are_a_noop_and_close_goes_through(): void
    {
        $cid = $this->makeCompany(['pos_dayclose_provisional_action' => null]);
        $bill = $this->makeProvisional($cid);
        $cashier = $this->makeUser($cid, 'pos_cashier', ['day_close']);

        $res = $this->closeDay($cashier, ['bill_actions' => [$bill => 'standing'], 'wash_override' => 'standing']);
        $res->assertSessionHas('success');
        $this->assertSame(1, DB::table('fbr_day_close_reports')->count());

        $t = $this->tx($bill);
        $this->assertSame('local', $t->invoice_mode);
        $this->assertSame('local', $t->fbr_status);
    }

    // ── 4. rider khata delete-guard ──────────────────────────────────────────

    public function test_khata_bill_per_bill_delete_stays_local(): void
    {
        $cid = $this->makeCompany(['pos_dayclose_provisional_action' => null]);
        $rid = $this->makeRider($cid);
        // Cash with the rider, unsettled, dispatched (dispatched = close allowed).
        $khata = $this->makeProvisional($cid, [
            'rider_id' => $rid, 'delivery_status' => 'dispatched', 'order_type' => 'delivery',
        ]);

        $res = $this->closeDay($this->makeUser($cid), ['bill_actions' => [$khata => 'delete']]);
        $res->assertSessionHas('success');

        $t = $this->tx($khata);
        $this->assertNotNull($t, 'Unsettled rider-cash bill must NEVER be deleted.');
        $this->assertSame('local', $t->invoice_mode, 'FBR has no archive — khata bill simply stays Local.');
        $this->assertSame('local', $t->fbr_status);
    }

    public function test_khata_bill_survives_whole_set_delete_but_settled_and_returned_do_not(): void
    {
        $cid = $this->makeCompany(['pos_dayclose_provisional_action' => null]);
        $rid = $this->makeRider($cid);
        $khata = $this->makeProvisional($cid, [
            'rider_id' => $rid, 'delivery_status' => 'dispatched', 'order_type' => 'delivery',
        ]);
        $settled = $this->makeProvisional($cid, [
            'rider_id' => $rid, 'delivery_status' => 'delivered', 'order_type' => 'delivery',
            'rider_settlement_id' => 77, 'rider_settled_at' => now(),
        ]);
        $returned = $this->makeProvisional($cid, [
            'rider_id' => $rid, 'delivery_status' => 'returned', 'order_type' => 'delivery',
        ]);
        $plain = $this->makeProvisional($cid);

        $this->closeDay($this->makeUser($cid), ['wash_override' => 'delete']);

        $this->assertNotNull($this->tx($khata), 'Khata bill survives the whole-set delete.');
        $this->assertNull($this->tx($settled), 'Settled rider bill deletes normally.');
        $this->assertNull($this->tx($returned), 'Returned delivery deletes normally (cash came back).');
        $this->assertNull($this->tx($plain), 'Plain provisional deletes normally.');
        $this->assertStringContainsString(
            __('pos.dayclose_bills_deleted', ['count' => 3]),
            (string) session('success')
        );
    }

    // ── Task 691: pending-bill decision audit (local_summary) ───────────────

    public function test_local_summary_audit_records_per_bill_decisions(): void
    {
        $cid = $this->makeCompany(['pos_dayclose_provisional_action' => null]);
        $rid = $this->makeRider($cid);
        $fin = $this->makeProvisional($cid);
        $del = $this->makeProvisional($cid);
        $keep = $this->makeProvisional($cid);
        // Rider-khata bill picked for delete — the guard keeps it Local.
        $khata = $this->makeProvisional($cid, [
            'rider_id' => $rid, 'delivery_status' => 'dispatched', 'order_type' => 'delivery',
        ]);

        $res = $this->closeDay($this->makeUser($cid), [
            'bill_actions' => [$fin => 'finalize', $del => 'delete', $khata => 'delete', $keep => 'save'],
        ]);
        $res->assertSessionHas('success');

        $raw = DB::table('fbr_day_close_reports')->where('company_id', $cid)->value('local_summary');
        $this->assertNotNull($raw, 'Z-report must carry the pending-bill audit.');
        $ls = json_decode((string) $raw, true)['provisional'] ?? null;
        $this->assertIsArray($ls);
        // Snapshot is the post-sweep leftover set: 3 bills (finalized one left).
        $this->assertSame(3, $ls['count']);
        $this->assertEquals(1500, $ls['amount']);
        $this->assertSame(1, $ls['finalized'], 'Sweep outcome merged into the audit.');
        $this->assertSame(['save' => 1, 'delete' => 2, 'carry' => 0], $ls['per_bill']);
        $this->assertSame(1, $ls['rider_guarded'], 'Khata delete-guard skip recorded.');
        $this->assertSame(1, $ls['deleted'], 'Actual delete count recorded.');

        // The Z-report page renders the stored audit block.
        // (sqlite stores the DATE cast as 'Y-m-d 00:00:00' which breaks the
        // page's string-equality report_date lookup; MySQL's DATE column has
        // no such suffix. Normalize so the lookup behaves like production.)
        DB::table('fbr_day_close_reports')->where('company_id', $cid)
            ->update(['report_date' => now()->toDateString()]);
        $page = $this->actingAs($this->makeUser($cid), 'fbrpos')
            ->get('/fbr-pos/day-close?date=' . now()->toDateString());
        $page->assertOk();
        $page->assertSee(__('pos.local_bills_closed_with_day'));
        $page->assertSee(__('pos.dc_rider_guarded_kept', ['count' => 1]));
        $page->assertSee(__('pos.dc_per_bill_split', ['save' => 1, 'delete' => 2, 'carry' => 0]));
    }

    public function test_local_summary_absent_when_nothing_was_pending(): void
    {
        $cid = $this->makeCompany(['pos_dayclose_provisional_action' => null]);
        // A FINAL bill only — no pending provisionals.
        $this->makeProvisional($cid, ['invoice_mode' => 'fbr', 'fbr_status' => null]);

        $this->closeDay($this->makeUser($cid))->assertSessionHas('success');

        $this->assertNull(
            DB::table('fbr_day_close_reports')->where('company_id', $cid)->value('local_summary'),
            'No audit row when the day had no pending bills.'
        );
    }

    // ── 5. company scoping ───────────────────────────────────────────────────

    public function test_foreign_company_bill_id_matches_nothing(): void
    {
        $cid = $this->makeCompany(['pos_dayclose_provisional_action' => null]);
        $other = $this->makeCompany(['name' => 'Other Co']);
        $foreign = $this->makeProvisional($other);
        // Own bill so the day has figures.
        $this->makeProvisional($cid);

        $res = $this->closeDay($this->makeUser($cid), ['bill_actions' => [$foreign => 'delete']]);
        $res->assertSessionHas('success');

        $this->assertNotNull($this->tx($foreign), 'Foreign-company bill must be untouched.');
    }
}

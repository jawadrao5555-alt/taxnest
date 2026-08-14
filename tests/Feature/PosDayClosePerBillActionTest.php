<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Services\PosFeatureService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * Task 677 — bill-by-bill day-close decisions (owner-approved 14 Aug 2026).
 *
 * Locked here:
 *  1. MIXED PER-BILL ACTIONS: each pending local/provisional bill may carry its
 *     own action (bill_actions[id] = finalize|save|delete); bills left on
 *     'standing' follow the all-box (wash_override) / standing policy.
 *  2. PER-BILL BEATS THE ALL-BOX: wash_override=delete + bill_actions[B]=save
 *     deletes everything EXCEPT B, which is archived.
 *  3. CASHIER GUARD: a cashier with day-close custom access posting crafted
 *     bill_actions is refused outright (role check) — same as wash_override.
 *  4. RIDER KHATA DELETE-GUARD applies to per-bill picks too: an unsettled
 *     rider-cash bill picked 'delete' is ARCHIVED, never deleted.
 *  5. FINAL_LOCAL bills ignore a (crafted) 'finalize' pick — they are already
 *     final; they fall back to the standing save/delete action.
 *  6. PER-BILL FINALIZE promotes ONLY the picked provisional (sweep whitelist);
 *     whole-set finalize EXCLUDES bills explicitly picked save/delete.
 *  7. Standing policy columns stay untouched by any per-bill choice.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create; HTTP
 * tests drive the real POST /pos/day-close (schema copied from
 * PosDayCloseUndispatchedDeliveryTest).
 */
class PosDayClosePerBillActionTest extends TestCase
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

    private function makeCompany(array $attrs = []): int
    {
        return (int) DB::table('companies')->insertGetId(array_merge([
            'name' => 'Per-Bill Wash Co',
            'email' => null,
            'product_type' => 'pos',
            'status' => 'active',
            'is_internal_account' => true, // bypass plan gates
            'restaurant_mode' => false,
            'feature_flags' => json_encode(['customer_profile' => true, 'delivery' => true]),
            'invoice_limit_override' => -1,
            'pra_reporting_enabled' => false,
            'agent_enabled' => false,
            'pra_connection_mode' => 'cloud',
            'pra_environment' => 'sandbox',
            'pos_tax_rate_cash' => 16.00,
            'pos_tax_rate_card' => 16.00,
            'pos_dayclose_provisional_action' => 'save',
            'pos_dayclose_final_local_action' => 'save',
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));
    }

    private function makeUser(int $companyId, ?string $posRole = null, ?array $customAccess = null): User
    {
        $id = DB::table('users')->insertGetId([
            'name' => $posRole ?? 'Owner',
            'email' => ($posRole ?? 'owner') . $companyId . '_' . rand(10000, 99999) . '@perbill.test',
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

    /** Provisional (L-series) bill by default; override attrs for finals etc. */
    private function makeBill(int $companyId, array $attrs = []): int
    {
        $id = (int) DB::table('pos_transactions')->insertGetId(array_merge([
            'company_id' => $companyId,
            'invoice_number' => 'L-' . uniqid(),
            'business_date' => now()->toDateString(),
            'status' => 'completed',
            'invoice_mode' => 'local',
            'pra_status' => 'local',
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

    private function closeDay(User $user, array $payload = [])
    {
        return $this->actingAs($user, 'pos')
            ->from('/pos/day-close')
            ->post('/pos/day-close', $payload);
    }

    private function tx(int $id): ?object
    {
        return DB::table('pos_transactions')->where('id', $id)->first();
    }

    // ── 1. mixed per-bill actions ────────────────────────────────────────────

    public function test_mixed_per_bill_actions_delete_save_and_default(): void
    {
        $cid = $this->makeCompany(); // standing = save
        $a = $this->makeBill($cid);  // per-bill: delete
        $b = $this->makeBill($cid);  // per-bill: save (explicit)
        $c = $this->makeBill($cid);  // default → standing (save)

        $res = $this->closeDay($this->makeUser($cid), [
            'bill_actions' => [$a => 'delete', $b => 'save', $c => 'standing'],
        ]);
        $res->assertSessionHas('success');

        $this->assertNull($this->tx($a), 'Per-bill delete must hard-delete bill A.');
        $this->assertSame(0, DB::table('pos_transaction_items')->where('transaction_id', $a)->count(),
            'Deleted bill items must be purged.');
        $this->assertSame(1, (int) $this->tx($b)->is_archived, 'Explicit save must archive bill B.');
        $this->assertSame(1, (int) $this->tx($c)->is_archived, "'standing' pick must follow the standing save policy.");

        $report = DB::table('pos_day_close_reports')->first();
        $this->assertSame(1, (int) $report->deleted_provisional_count,
            'Quota add-back counter must record exactly the one deleted bill.');
        $summary = json_decode((string) $report->local_summary, true);
        $this->assertSame(['save' => 2, 'delete' => 1, 'carry' => 0], $summary['provisional']['per_bill'] ?? null,
            'Z-report must record the per-bill split.');
        $this->assertSame('save', DB::table('companies')->where('id', $cid)->value('pos_dayclose_provisional_action'),
            'Standing policy must stay untouched.');
    }

    // ── 2. per-bill beats the all-box ────────────────────────────────────────

    public function test_per_bill_pick_beats_wash_override_all_box(): void
    {
        $cid = $this->makeCompany();
        $a = $this->makeBill($cid);
        $b = $this->makeBill($cid);

        $res = $this->closeDay($this->makeUser($cid), [
            'wash_override' => 'delete',
            'bill_actions' => [$b => 'save'],
        ]);
        $res->assertSessionHas('success');

        $this->assertNull($this->tx($a), 'All-box delete must apply to the un-picked bill.');
        $txB = $this->tx($b);
        $this->assertNotNull($txB, 'Per-bill save must beat the all-box delete.');
        $this->assertSame(1, (int) $txB->is_archived);
        $this->assertSame(1, (int) DB::table('pos_day_close_reports')->value('deleted_provisional_count'));
    }

    // ── 3. cashier guard ─────────────────────────────────────────────────────

    public function test_cashier_crafted_bill_actions_are_refused(): void
    {
        $cid = $this->makeCompany();
        $bill = $this->makeBill($cid);
        // Cashier WITH day-close custom access — may close days, must NOT pick actions.
        $cashier = $this->makeUser($cid, 'pos_cashier', ['day_close']);

        $res = $this->closeDay($cashier, ['bill_actions' => [$bill => 'delete']]);

        $this->assertSame(__('pos.only_admin_change_setting'), (string) session('error'),
            'Cashier per-bill actions must be refused with an explicit error.');
        $this->assertSame(0, DB::table('pos_day_close_reports')->count(), 'No close may happen on a refused pick.');
        $this->assertNotNull($this->tx($bill), 'Bill must be untouched.');

        // All-'standing' picks are a no-op — the cashier may still close normally.
        $res = $this->closeDay($cashier, ['bill_actions' => [$bill => 'standing']]);
        $res->assertSessionHas('success');
        $this->assertSame(1, DB::table('pos_day_close_reports')->count());
    }

    // ── 4. rider khata delete-guard on per-bill picks ────────────────────────

    public function test_khata_bill_per_bill_delete_is_archived_not_deleted(): void
    {
        $cid = $this->makeCompany();
        $rid = $this->makeRider($cid);
        // Dispatched (does not block the close) unsettled rider-cash bill = khata.
        $khata = $this->makeBill($cid, [
            'rider_id' => $rid, 'delivery_status' => 'dispatched', 'order_type' => 'delivery',
        ]);

        $res = $this->closeDay($this->makeUser($cid), ['bill_actions' => [$khata => 'delete']]);
        $res->assertSessionHas('success');

        $tx = $this->tx($khata);
        $this->assertNotNull($tx, 'Khata bill must NEVER be deleted — khata proof survives.');
        $this->assertSame(1, (int) $tx->is_archived, 'Khata bill must be archived instead.');
        $report = DB::table('pos_day_close_reports')->first();
        $this->assertSame(0, (int) $report->deleted_provisional_count, 'No delete may be counted.');
        $summary = json_decode((string) $report->local_summary, true);
        $this->assertSame(1, $summary['provisional']['rider_guarded'] ?? null,
            'Z-report must record the rider guard.');
    }

    // ── 5. final_local ignores a crafted finalize pick ───────────────────────

    public function test_final_local_bill_ignores_finalize_pick_and_follows_standing(): void
    {
        $cid = $this->makeCompany(); // final_local standing = save
        // Reporting-OFF final: completed + pra/NULL mode + NULL pra_status.
        $final = $this->makeBill($cid, [
            'invoice_number' => 'POS-2026-F1', 'invoice_mode' => 'pra', 'pra_status' => null,
        ]);

        $res = $this->closeDay($this->makeUser($cid), ['bill_actions' => [$final => 'finalize']]);
        $res->assertSessionHas('success');

        $tx = $this->tx($final);
        $this->assertNotNull($tx, "A crafted 'finalize' on a final bill must not delete or corrupt it.");
        $this->assertSame(1, (int) $tx->is_archived, 'It must follow the standing save action.');
        $this->assertSame('pra', $tx->invoice_mode, 'Mode must stay untouched.');
        $this->assertNull($tx->pra_status, 'Status must stay untouched.');
    }

    // ── 6. per-bill finalize promotes ONLY the picked bill ───────────────────

    public function test_per_bill_finalize_promotes_only_picked_bill(): void
    {
        $cid = $this->makeCompany(); // standing = save, reporting OFF
        $a = $this->makeBill($cid, ['invoice_number' => 'L-0001']); // per-bill: finalize
        $b = $this->makeBill($cid, ['invoice_number' => 'L-0002']); // default → save

        $res = $this->closeDay($this->makeUser($cid), ['bill_actions' => [$a => 'finalize']]);
        $res->assertSessionHas('success');

        $txA = $this->tx($a);
        // Reporting-OFF finalize = regulator-mode final: 'pra' + NULL status.
        $this->assertSame('pra', $txA->invoice_mode, 'Picked bill must be promoted to final.');
        $this->assertNull($txA->pra_status);
        // Same-close final wash then archives it exactly like every other
        // reporting-OFF final of the day (pre-existing Task 661 behavior).
        $this->assertSame(1, (int) $txA->is_archived, 'Promoted final joins the day\'s final wash (archive).');

        $txB = $this->tx($b);
        $this->assertSame('local', $txB->invoice_mode, 'Un-picked bill must NOT be promoted.');
        $this->assertSame('local', $txB->pra_status);
        $this->assertSame(1, (int) $txB->is_archived, 'Un-picked bill follows standing save.');

        $summary = json_decode((string) DB::table('pos_day_close_reports')->value('local_summary'), true);
        $this->assertSame(1, $summary['provisional']['finalized'] ?? null, 'Sweep numbers must reach the Z-report.');
    }

    // ── 7. big backlog: EVERY bill stays individually actionable ────────────

    public function test_per_bill_action_works_beyond_150_bills(): void
    {
        $cid = $this->makeCompany(); // standing = save
        // 155 pending provisionals — far past any old display cap. Bulk-insert
        // (no item rows needed: delete path only purges items, never requires them).
        $now = now();
        $rows = [];
        for ($i = 1; $i <= 155; $i++) {
            $rows[] = [
                'company_id' => $cid,
                'invoice_number' => 'L-' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'business_date' => $now->toDateString(),
                'status' => 'completed',
                'invoice_mode' => 'local',
                'pra_status' => 'local',
                'is_archived' => false,
                'subtotal' => 100,
                'total_amount' => 100,
                'payment_method' => 'cash',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('pos_transactions')->insert($rows);
        $last = (int) DB::table('pos_transactions')->where('company_id', $cid)->max('id'); // bill #155

        $res = $this->closeDay($this->makeUser($cid), [
            'wash_override' => 'delete',
            'bill_actions' => [$last => 'save'],
        ]);
        $res->assertSessionHas('success');

        $tx = $this->tx($last);
        $this->assertNotNull($tx, 'Bill #155 must survive: its per-bill save beats the all-box delete.');
        $this->assertSame(1, (int) $tx->is_archived);
        $this->assertSame(0, DB::table('pos_transactions')->where('company_id', $cid)->where('id', '!=', $last)->count(),
            'All 154 un-picked bills follow the all-box delete.');
        $this->assertSame(154, (int) DB::table('pos_day_close_reports')->value('deleted_provisional_count'));
    }

    // ── 8. whole-set finalize excludes explicit save/delete picks ────────────

    public function test_whole_set_finalize_excludes_bills_picked_save_or_delete(): void
    {
        $cid = $this->makeCompany();
        $a = $this->makeBill($cid, ['invoice_number' => 'L-0001']); // follows all-box finalize
        $b = $this->makeBill($cid, ['invoice_number' => 'L-0002']); // per-bill: save
        $c = $this->makeBill($cid, ['invoice_number' => 'L-0003']); // per-bill: delete

        $res = $this->closeDay($this->makeUser($cid), [
            'wash_override' => 'finalize',
            'bill_actions' => [$b => 'save', $c => 'delete'],
        ]);
        $res->assertSessionHas('success');

        $txA = $this->tx($a);
        $this->assertSame('pra', $txA->invoice_mode, 'Un-picked bill must be promoted by the all-box finalize.');
        $this->assertNull($txA->pra_status);
        // Archived by the same close's final wash — pre-existing Task 661 behavior.
        $this->assertSame(1, (int) $txA->is_archived);

        $txB = $this->tx($b);
        $this->assertSame('local', $txB->invoice_mode, 'Save-picked bill must NOT be promoted.');
        $this->assertSame(1, (int) $txB->is_archived, 'Save-picked bill must be archived.');

        $this->assertNull($this->tx($c), 'Delete-picked bill must be deleted, never promoted.');
        $this->assertSame(1, (int) DB::table('pos_day_close_reports')->value('deleted_provisional_count'));
    }
}

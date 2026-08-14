<?php

namespace Tests\Feature;

use App\Http\Controllers\PosReturnController;
use App\Models\PosTransaction;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Quick Return lookup regression net (Task 686, locks Task 681 behavior).
 *
 * PosReturnController::quickLookup resolves a typed bill number into a return
 * form URL. The matching depends on the CURRENT numbering conventions:
 *   - POS serial padding = 5 digits (POS-YYYY-00012)
 *   - L-series padding   = 3 digits (L-012), unpadded also stored historically
 *   - bare digits try this year's + last year's POS series, then L-series
 *   - PRA fiscal number matches exactly
 *   - receipt order code = last segment of ORD-yymmdd-XXXXX ('code' shops)
 *
 * If a future task changes the numbering format (padding width, prefix,
 * per-branch series, ...) without updating quickLookup's candidate builder,
 * these tests fail loudly instead of the lookup silently answering
 * "bill nahi mila" on real shop floors.
 *
 * Also locks the gates: empty → 422, unknown → 404, return-of-return /
 * not-completed → 422 with the reason key, stream-locked staff → 403,
 * cashier without the 'returns' Custom Access tick → 403 (with tick → OK).
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create,
 * controller invoked directly with the currentCompanyId container binding
 * (mirrors PosPraReturnFlowTest).
 */
class PosQuickReturnLookupTest extends TestCase
{
    protected int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();
        User::flushScopeColumnCache();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('pra_reporting_enabled')->default(false);
            $table->string('pra_connection_mode')->nullable();
            $table->boolean('agent_enabled')->default(false);
            $table->boolean('agent_submits_pra')->default(false);
            $table->boolean('inventory_enabled')->default(false);
            $table->boolean('pos_setup_completed')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('name')->nullable();
            $table->string('role')->nullable();
            $table->string('pos_role')->nullable();
            $table->string('pos_billing_scope')->nullable();
            $table->text('pos_custom_access')->nullable();
            $table->unsignedBigInteger('default_branch_id')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('invoice_number');
            $table->string('transaction_type')->nullable()->default('sale');
            $table->unsignedBigInteger('parent_transaction_id')->nullable();
            $table->string('business_date')->nullable();
            $table->string('status');
            $table->string('invoice_mode')->nullable();
            $table->string('pra_status')->nullable();
            $table->string('pra_invoice_number')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->timestamps();
        });

        // Receipt order-code fallback path (quickLookup checks hasTable).
        Schema::create('restaurant_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('pos_transaction_id')->nullable();
            $table->string('order_number')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });

        $this->companyId = DB::table('companies')->insertGetId([
            'name' => 'Lookup Shop',
            'pos_setup_completed' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        app()->bind('currentCompanyId', fn () => $this->companyId);

        \App\Services\PosFeatureService::flushGateCaches();
    }

    protected function tearDown(): void
    {
        Auth::guard('pos')->logout();
        parent::tearDown();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    protected function actAs(string $posRole, array $attrs = []): User
    {
        DB::table('users')->insert(array_merge([
            'company_id' => $this->companyId,
            'name' => 'U-' . $posRole,
            'role' => 'user',
            'pos_role' => $posRole,
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
        $user = User::orderByDesc('id')->first();
        Auth::guard('pos')->setUser($user);

        return $user;
    }

    /** Seed a completed final bill and return its id. */
    protected function seedBill(array $attrs = []): int
    {
        return DB::table('pos_transactions')->insertGetId(array_merge([
            'company_id' => $this->companyId,
            'invoice_number' => 'POS-' . now()->format('Y') . '-00001',
            'transaction_type' => 'sale',
            'status' => 'completed',
            'invoice_mode' => 'pra',
            'pra_status' => null,
            'total_amount' => 500,
            'payment_method' => 'cash',
            'business_date' => now()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    /** Invoke quickLookup with ?q= and return the JSON response. */
    protected function lookup(string $q)
    {
        $request = Request::create('/pos/return-lookup', 'GET', ['q' => $q]);

        return (new PosReturnController())->quickLookup($request);
    }

    protected function assertFinds(string $q, int $expectedId): void
    {
        $res = $this->lookup($q);
        $data = $res->getData(true);
        $this->assertSame(200, $res->getStatusCode(), "input '$q' should match bill #$expectedId, got: " . json_encode($data));
        $this->assertStringContainsString('/pos/transaction/' . $expectedId . '/return', $data['url']);
    }

    // ── numbering shapes (the actual regression net) ─────────────────────────

    public function test_full_pos_serial_exact(): void
    {
        $this->actAs('pos_manager');
        $yr = now()->format('Y');
        $id = $this->seedBill(['invoice_number' => 'POS-' . $yr . '-00012']);

        $this->assertFinds('POS-' . $yr . '-00012', $id);
    }

    public function test_unpadded_and_lowercase_pos_serial(): void
    {
        $this->actAs('pos_manager');
        $yr = now()->format('Y');
        $id = $this->seedBill(['invoice_number' => 'POS-' . $yr . '-00012']);

        // Padding optional + case-insensitive: pos-2026-12 → POS-2026-00012.
        $this->assertFinds('pos-' . $yr . '-12', $id);
        $this->assertFinds('POS' . $yr . '12', $id); // dashes optional too
    }

    public function test_l_series_padded_and_unpadded(): void
    {
        $this->actAs('pos_manager');
        $padded = $this->seedBill([
            'invoice_number' => 'L-012',
            'invoice_mode' => 'local', 'pra_status' => 'local',
        ]);

        // L pad = 3 digits: l-12 and L-012 both resolve to L-012.
        $this->assertFinds('l-12', $padded);
        $this->assertFinds('L-012', $padded);

        // Unpadded stored form (L-1234, pad grows past 999) still matches.
        $unpadded = $this->seedBill([
            'invoice_number' => 'L-1234',
            'invoice_mode' => 'local', 'pra_status' => 'local',
        ]);
        $this->assertFinds('L-1234', $unpadded);
    }

    public function test_bare_digits_match_this_years_pos_series(): void
    {
        $this->actAs('pos_manager');
        $yr = now()->format('Y');
        $id = $this->seedBill(['invoice_number' => 'POS-' . $yr . '-00012']);

        $this->assertFinds('12', $id);
        $this->assertFinds('0012', $id); // leading zeros normalized
    }

    public function test_bare_digits_match_last_years_pos_series(): void
    {
        $this->actAs('pos_manager');
        $lastYr = now()->subYear()->format('Y');
        $id = $this->seedBill(['invoice_number' => 'POS-' . $lastYr . '-00007']);

        $this->assertFinds('7', $id);
    }

    public function test_bare_digits_match_l_series(): void
    {
        $this->actAs('pos_manager');
        $id = $this->seedBill([
            'invoice_number' => 'L-034',
            'invoice_mode' => 'local', 'pra_status' => 'local',
        ]);

        $this->assertFinds('34', $id);
    }

    public function test_bare_digits_newest_match_wins(): void
    {
        $this->actAs('pos_manager');
        $yr = now()->format('Y');
        $old = $this->seedBill([
            'invoice_number' => 'POS-' . $yr . '-00012',
            'created_at' => now()->subDays(30), 'updated_at' => now()->subDays(30),
        ]);
        $newer = $this->seedBill([
            'invoice_number' => 'L-012',
            'invoice_mode' => 'local', 'pra_status' => 'local',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertFinds('12', $newer);
        $this->assertNotSame($old, $newer);
    }

    public function test_pra_fiscal_number_exact(): void
    {
        $this->actAs('pos_manager');
        $id = $this->seedBill([
            'invoice_number' => 'POS-' . now()->format('Y') . '-00099',
            'pra_status' => 'submitted',
            'pra_invoice_number' => '425012345678',
        ]);

        $this->assertFinds('425012345678', $id);
    }

    public function test_receipt_order_code_last_segment(): void
    {
        $this->actAs('pos_manager');
        $id = $this->seedBill(['invoice_number' => 'POS-' . now()->format('Y') . '-00055']);
        DB::table('restaurant_orders')->insert([
            'company_id' => $this->companyId,
            'pos_transaction_id' => $id,
            'order_number' => 'ORD-260814-K7X2Q',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Code style: cashier types only the last segment (case-insensitive).
        $this->assertFinds('k7x2q', $id);
    }

    public function test_archived_reporting_off_final_still_found(): void
    {
        // Parity with returnForm: withoutGlobalScope('hide_archived').
        $this->actAs('pos_manager');
        $yr = now()->format('Y');
        $id = $this->seedBill([
            'invoice_number' => 'POS-' . $yr . '-00060',
            'is_archived' => true,
        ]);

        $this->assertFinds('60', $id);
    }

    // ── negative cases ───────────────────────────────────────────────────────

    public function test_empty_input_422(): void
    {
        $this->actAs('pos_manager');
        $this->assertSame(422, $this->lookup('')->getStatusCode());
        $this->assertSame(422, $this->lookup('   ')->getStatusCode());
        // Over-length inputs refused too (> 40 chars).
        $this->assertSame(422, $this->lookup(str_repeat('9', 41))->getStatusCode());
    }

    public function test_unknown_number_404(): void
    {
        $this->actAs('pos_manager');
        $this->seedBill(['invoice_number' => 'POS-' . now()->format('Y') . '-00012']);

        $res = $this->lookup('99999');
        $this->assertSame(404, $res->getStatusCode());
        $this->assertArrayHasKey('error', $res->getData(true));
    }

    public function test_other_companys_bill_not_found(): void
    {
        $this->actAs('pos_manager');
        $otherCompany = DB::table('companies')->insertGetId([
            'name' => 'Other Shop', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('pos_transactions')->insert([
            'company_id' => $otherCompany,
            'invoice_number' => 'POS-' . now()->format('Y') . '-00012',
            'transaction_type' => 'sale', 'status' => 'completed',
            'invoice_mode' => 'pra',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(404, $this->lookup('12')->getStatusCode());
    }

    public function test_return_of_return_refused(): void
    {
        $this->actAs('pos_manager');
        $this->seedBill([
            'invoice_number' => 'POS-' . now()->format('Y') . '-00021',
            'transaction_type' => 'return',
        ]);

        $res = $this->lookup('21');
        $this->assertSame(422, $res->getStatusCode());
        $this->assertSame(__('pos.return_not_allowed_return_of_return'), $res->getData(true)['error']);
    }

    public function test_not_completed_bill_refused(): void
    {
        $this->actAs('pos_manager');
        $this->seedBill([
            'invoice_number' => 'POS-' . now()->format('Y') . '-00022',
            'status' => 'held',
        ]);

        $res = $this->lookup('22');
        $this->assertSame(422, $res->getStatusCode());
        $this->assertSame(__('pos.return_not_allowed_not_completed'), $res->getData(true)['error']);
    }

    // ── gates ────────────────────────────────────────────────────────────────

    public function test_stream_locked_staff_403_on_pra_bill(): void
    {
        $this->actAs('pos_manager', ['pos_billing_scope' => 'local']);
        $this->seedBill([
            'invoice_number' => 'POS-' . now()->format('Y') . '-00030',
            'pra_status' => 'submitted',
            'pra_invoice_number' => 'PRA-FISCAL-30',
        ]);

        $this->assertSame(403, $this->lookup('30')->getStatusCode());
    }

    public function test_stream_locked_staff_ok_on_local_bill(): void
    {
        $this->actAs('pos_manager', ['pos_billing_scope' => 'local']);
        $id = $this->seedBill([
            'invoice_number' => 'L-030',
            'invoice_mode' => 'local', 'pra_status' => 'local',
        ]);

        $this->assertFinds('L-30', $id);
    }

    public function test_cashier_without_returns_tick_403(): void
    {
        $this->actAs('pos_cashier');
        $this->seedBill(['invoice_number' => 'POS-' . now()->format('Y') . '-00040']);

        try {
            $this->lookup('40');
            $this->fail('cashier without the returns tick must be blocked');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_cashier_with_returns_tick_allowed(): void
    {
        $this->actAs('pos_cashier', ['pos_custom_access' => json_encode(['returns'])]);
        $id = $this->seedBill(['invoice_number' => 'POS-' . now()->format('Y') . '-00041']);

        $this->assertFinds('41', $id);
    }
}

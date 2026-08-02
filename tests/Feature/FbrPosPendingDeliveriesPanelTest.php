<?php

namespace Tests\Feature;

use App\Http\Controllers\FbrPosController;
use App\Models\Company;
use App\Models\FbrPosTransaction;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * PENDING DELIVERIES quick-final panel — FBR POS port (Task 122 of PRA Task 114).
 *
 * Locks the API contract the FBR universal sale-screen panel relies on:
 *
 *   1. apiProvisionalBills mirrors the PRA Task-114 fields (business_date,
 *      order_type, rider_* placeholders, business_today) even though
 *      fbr_pos_transactions has none of those columns — hasColumn guards +
 *      created_at→PosBusinessDay fallback, NEVER a missing key. The fallback
 *      business_date must use the SAME cutoff rule as business_today so a
 *      pre-cutoff (e.g. 01:00) bill can never mismatch the badge's filter.
 *   2. Only local/local provisionals are listed (pending/final never leak in).
 *   3. apiPromoteProvisional accepts the panel's payment_method: 'card'
 *      normalizes to 'debit_card' (card-bucket convention), invalid values
 *      leave the stored method untouched, amounts are NEVER re-derived, and
 *      the Reporting-OFF Finals Invariant holds (fbr mode + NULL status).
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create,
 * controller invoked directly with the currentCompanyId container binding
 * (guard user null → binding fallback), mirroring FbrPosDayCloseAutoFinalizeTest.
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' php vendor/bin/phpunit tests/Feature/FbrPosPendingDeliveriesPanelTest.php
 */
class FbrPosPendingDeliveriesPanelTest extends TestCase
{
    protected int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('confidential_pin')->nullable();
            $table->boolean('fbr_reporting_enabled')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });

        // Deliberately WITHOUT business_date / order_type / delivery_address /
        // rider columns — the live table has none of them either; the API must
        // still return every mirror field.
        Schema::create('fbr_pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('invoice_number');
            $table->string('status')->nullable();
            $table->string('invoice_mode')->nullable();
            $table->string('fbr_status')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->timestamps();
        });

        Schema::create('fbr_pos_transaction_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->string('item_name')->nullable();
            $table->timestamps();
        });

        // PosBusinessDay::forMoment consults PRA day-close reports pre-cutoff.
        Schema::create('pos_day_close_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->date('report_date');
            $table->timestamps();
        });

        $this->companyId = Company::create(['name' => 'FBR Panel Shop'])->id;
        app()->bind('currentCompanyId', fn () => $this->companyId);
    }

    protected function makeProvisional(array $attrs = []): FbrPosTransaction
    {
        return FbrPosTransaction::create(array_merge([
            'company_id' => $this->companyId,
            'invoice_number' => 'L-' . uniqid(),
            'status' => 'completed',
            'invoice_mode' => 'local',
            'fbr_status' => 'local',
            'subtotal' => 100,
            'tax_amount' => 16,
            'total_amount' => 116,
            'payment_method' => 'cash',
        ], $attrs));
    }

    public function test_provisional_bills_api_mirrors_task114_fields_without_columns(): void
    {
        $bill = $this->makeProvisional(['customer_name' => 'Asif', 'customer_phone' => '0300']);
        // Non-provisional rows must never leak into the list.
        $this->makeProvisional(['invoice_mode' => 'fbr', 'fbr_status' => null]);
        $this->makeProvisional(['fbr_status' => 'pending', 'invoice_mode' => 'fbr']);

        $res = (new FbrPosController())->apiProvisionalBills(new Request());
        $data = $res->getData(true);

        $this->assertTrue($data['success']);
        $this->assertSame(1, $data['count']);
        $row = $data['bills'][0];

        // Task 114 mirror fields — all keys present, FBR-safe values.
        $this->assertSame($bill->id, $row['id']);
        $this->assertNull($row['order_type']);
        $this->assertNull($row['delivery_address']);
        $this->assertNull($row['rider_name']);
        $this->assertFalse($row['rider_unsettled']);
        $this->assertNull($row['rider_id']);
        $this->assertSame(0, $row['rider_open_count']);
        $this->assertSame(0, $row['rider_open_amount']);
        $this->assertFalse($row['kot_pending']);
        $this->assertSame('cash', $row['payment_method']);
        $this->assertNotEmpty($row['created_time']);

        // Fallback business_date must equal business_today for a bill created
        // "now" — the client-side badge filter matches them strictly.
        $this->assertNotEmpty($data['business_today']);
        $this->assertSame($data['business_today'], $row['business_date']);
    }

    public function test_pre_cutoff_bill_business_date_matches_business_today_rule(): void
    {
        // 01:30 bill, previous day not day-closed → both the row's fallback
        // business_date and business_today must resolve to YESTERDAY.
        $at = now()->setTime(1, 30);
        $bill = $this->makeProvisional();
        DB::table('fbr_pos_transactions')->where('id', $bill->id)->update(['created_at' => $at]);

        $expected = \App\Services\PosBusinessDay::forMoment($this->companyId, $at);
        $data = (new FbrPosController())->apiProvisionalBills(new Request())->getData(true);

        $this->assertSame($expected, $data['bills'][0]['business_date']);
        $this->assertSame($at->copy()->subDay()->toDateString(), $expected);
    }

    public function test_promote_with_card_method_normalizes_and_finalizes_reporting_off(): void
    {
        $bill = $this->makeProvisional();

        $req = Request::create('/', 'POST', ['payment_method' => 'card']);
        $res = (new FbrPosController())->apiPromoteProvisional($req, $bill->id);
        $this->assertTrue($res->getData(true)['success']);

        $bill->refresh();
        // Card bucket convention: stored as debit_card, never bare 'card'.
        $this->assertSame('debit_card', $bill->payment_method);
        // Reporting-OFF Finals Invariant: fbr mode + NULL status.
        $this->assertSame('fbr', $bill->invoice_mode);
        $this->assertNull($bill->fbr_status);
        // Amounts NEVER re-derived on FBR POS.
        $this->assertSame('116.00', (string) $bill->total_amount);
        $this->assertSame('16.00', (string) $bill->tax_amount);
    }

    public function test_promote_with_invalid_method_keeps_stored_payment_method(): void
    {
        $bill = $this->makeProvisional(['payment_method' => 'cash']);

        $req = Request::create('/', 'POST', ['payment_method' => 'bitcoin']);
        $res = (new FbrPosController())->apiPromoteProvisional($req, $bill->id);
        $this->assertTrue($res->getData(true)['success']);

        $bill->refresh();
        $this->assertSame('cash', $bill->payment_method);
        $this->assertSame('fbr', $bill->invoice_mode);
    }

    public function test_promote_double_claim_rejected(): void
    {
        $bill = $this->makeProvisional();
        $controller = new FbrPosController();
        $req = Request::create('/', 'POST', ['payment_method' => 'cash']);

        $this->assertTrue($controller->apiPromoteProvisional($req, $bill->id)->getData(true)['success']);
        $second = $controller->apiPromoteProvisional($req, $bill->id);
        $this->assertSame(409, $second->getStatusCode());
    }
}

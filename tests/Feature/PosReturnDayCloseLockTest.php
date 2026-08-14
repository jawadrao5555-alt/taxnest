<?php

namespace Tests\Feature;

use App\Http\Controllers\FbrPosPhase2Controller;
use App\Models\FbrPosTransaction;
use App\Models\PosTransaction;
use App\Services\PosReturnService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Return locks added 14 Aug 2026 (owner rules):
 *
 *  1. PRA day-close lock — once a bill's business day has a Z-report
 *     (pos_day_close_reports row), the account is settled: returnableReason
 *     = 'day_closed' and createReturn refuses. Single choke point covers
 *     every entry (list button, detail button, form, quick return, rider).
 *  2. FBR return window — FBR bills may only be returned within
 *     RETURN_WINDOW_DAYS (15; legal ceiling 180 days per Sales Tax Rules
 *     2006 credit-note limit). The blades gate their buttons on the SAME
 *     public constant the server enforces.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create
 * (mirrors PosPraReturnFlowTest).
 */
class PosReturnDayCloseLockTest extends TestCase
{
    protected int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('pos_business_day_cutoff')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('invoice_number');
            $table->string('invoice_mode')->nullable();
            $table->string('transaction_type')->nullable()->default('sale');
            $table->unsignedBigInteger('parent_transaction_id')->nullable();
            $table->string('status')->default('completed');
            $table->string('pra_status')->nullable();
            $table->string('pra_invoice_number')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->date('business_date')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('pos_transaction_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->string('item_name')->nullable();
            $table->decimal('quantity', 12, 3)->default(1);
            $table->decimal('returned_quantity', 10, 3)->default(0);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('pos_day_close_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->date('report_date');
            $table->string('report_number')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'report_date']);
        });

        $this->companyId = (int) DB::table('companies')->insertGetId([
            'name' => 'Return Lock Test Co',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeBill(string $businessDate): PosTransaction
    {
        $bill = PosTransaction::forceCreate([
            'company_id' => $this->companyId,
            'invoice_number' => 'POS-2026-' . rand(10000, 99999),
            'invoice_mode' => 'pra',
            'transaction_type' => 'sale',
            'status' => 'completed',
            'business_date' => $businessDate,
            'subtotal' => 100, 'tax_amount' => 16, 'total_amount' => 116,
        ]);
        DB::table('pos_transaction_items')->insert([
            'transaction_id' => $bill->id, 'item_name' => 'Cheez',
            'quantity' => 2, 'returned_quantity' => 0,
            'unit_price' => 50, 'subtotal' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $bill->fresh();
    }

    private function closeDay(string $date): void
    {
        DB::table('pos_day_close_reports')->insert([
            'company_id' => $this->companyId, 'report_date' => $date,
            'report_number' => 'Z-1', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_open_day_bill_is_returnable(): void
    {
        $bill = $this->makeBill(now()->format('Y-m-d'));
        $this->assertNull(PosReturnService::returnableReason($bill));
        $this->assertFalse(PosReturnService::dayClosed($bill));
    }

    public function test_closed_day_bill_blocks_return(): void
    {
        $date = now()->subDay()->format('Y-m-d');
        $bill = $this->makeBill($date);
        $this->closeDay($date);

        $this->assertTrue(PosReturnService::dayClosed($bill));
        $this->assertSame('day_closed', PosReturnService::returnableReason($bill));

        // createReturn = the single choke point every entry funnels through.
        $result = PosReturnService::createReturn(
            $this->companyId, $bill->id,
            [['item_id' => $bill->items->first()->id, 'return_qty' => 1]],
            'cash', null
        );
        $this->assertArrayHasKey('error', $result);
        $this->assertSame(__('pos.return_not_allowed_day_closed'), $result['error']);
        // Nothing written — no return row, parent line untouched.
        $this->assertSame(0, PosTransaction::withoutGlobalScope('hide_archived')
            ->where('transaction_type', 'return')->count());
        $this->assertEquals(0.0, (float) $bill->items()->first()->returned_quantity);
    }

    public function test_other_days_close_does_not_block(): void
    {
        // Closing YESTERDAY must not lock TODAY's bills.
        $bill = $this->makeBill(now()->format('Y-m-d'));
        $this->closeDay(now()->subDay()->format('Y-m-d'));
        $this->assertNull(PosReturnService::returnableReason($bill));
    }

    public function test_null_business_date_falls_back_to_created_date(): void
    {
        // Pre-migration rows (business_date NULL) use the created_at date.
        $date = now()->subDays(2)->format('Y-m-d');
        $bill = $this->makeBill($date);
        PosTransaction::withoutGlobalScope('hide_archived')
            ->where('id', $bill->id)
            ->update(['business_date' => null, 'created_at' => $date . ' 14:00:00']);
        $this->closeDay($date);
        $this->assertSame('day_closed', PosReturnService::returnableReason($bill->fresh()));
    }

    public function test_fbr_return_window_constant_and_guard(): void
    {
        // Blades gate on this same constant — a silent change would widen
        // the UI window past what the server accepts (or vice versa).
        $this->assertSame(15, FbrPosPhase2Controller::RETURN_WINDOW_DAYS);

        $method = new \ReflectionMethod(FbrPosPhase2Controller::class, 'returnWindowExpired');
        $controller = (new \ReflectionClass(FbrPosPhase2Controller::class))->newInstanceWithoutConstructor();

        $fresh = new FbrPosTransaction();
        $fresh->created_at = now()->subDays(14);
        $this->assertFalse($method->invoke($controller, $fresh));

        $stale = new FbrPosTransaction();
        $stale->created_at = now()->subDays(16);
        $this->assertTrue($method->invoke($controller, $stale));
    }
}

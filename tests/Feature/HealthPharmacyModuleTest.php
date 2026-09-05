<?php

namespace Tests\Feature;

use App\Models\HealthBatchMovement;
use App\Models\HealthMedicine;
use App\Models\HealthMedicineBatch;
use App\Models\HealthPharmacySale;
use App\Models\HealthPharmacySaleItem;
use App\Models\HealthPrescription;
use App\Models\HealthPrescriptionItem;
use App\Services\HealthPharmacyCheckoutService as Checkout;
use App\Services\HealthPharmacyReportService as Reports;
use App\Services\HealthPharmacyService as Pharmacy;
use App\Services\HealthPharmacyStockService as Stock;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * HOSPITAL PHARMACY MODULE (Task 1549).
 *
 * Locks the promises a pharmacy cannot be wrong about:
 *
 *  1. FEFO — the lot that dies first leaves first, and an UNDATED lot goes
 *     last so an unknown expiry never jumps ahead of medicine about to expire.
 *  2. EXPIRY POLICY — expired stock is refused outright; the override only
 *     works when the owner opened it. Short-dated stock still sells, but the
 *     counter is told BEFORE the money is taken.
 *  3. ONE STOCK TRUTH — batch remainders and the shared branch quantity move
 *     together on every path (receive, dispense, return, write-off, transfer),
 *     and reconcile() reports drift instead of silently healing it.
 *  4. QUARANTINE IS STATUS-ONLY — held goods are still in the building, so the
 *     branch quantity must not change. Only a write-off deducts, and an
 *     expired lot files as an expiry write-off, not as wastage.
 *  5. PARTIAL FILLS ARE NORMAL — a prescription may be filled across several
 *     visits; remaining quantity and status follow what actually went out.
 *  6. RETURNS STAY ATTRIBUTABLE — a non-restock return is booked as a restock
 *     immediately followed by a wastage deduct, so nothing vanishes from the
 *     ledger while the shelf stays honest.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + the module's own migration on
 * top of hand-made platform tables, the same shape as HealthcareFoundationTest.
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/HealthPharmacyModuleTest.php --testdox
 */
class HealthPharmacyModuleTest extends TestCase
{
    private int $companyId;
    private int $branchA = 1;
    private int $branchB = 2;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('ntn')->nullable();
            $table->string('product_type')->nullable();
            $table->string('status')->nullable();
            $table->string('health_org_type')->nullable();
            $table->text('health_modules')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->string('sku')->nullable();
            $table->string('barcode')->nullable();
            $table->string('hs_code')->nullable();
            // These four are NOT NULL on the real products table. Copying that
            // exactly is the point: a nullable stand-in here would let a null
            // write pass the test and fail only on a live insert.
            $table->string('uom')->default('PCS');
            $table->decimal('default_price', 12, 2)->default(0);
            $table->decimal('default_tax_rate', 5, 2)->default(18);
            $table->decimal('cost_price', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('inventory_stocks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->decimal('quantity', 12, 4)->default(0);
            $table->decimal('min_stock_level', 12, 4)->default(0);
            $table->decimal('avg_purchase_price', 12, 2)->default(0);
            $table->decimal('last_purchase_price', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->string('type');
            $table->decimal('quantity', 12, 4)->default(0);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('total_price', 14, 2)->default(0);
            $table->decimal('balance_after', 12, 4)->nullable();
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reference_number')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        // The patient registry is the OPD module's table. Only the columns the
        // pharmacy reads are mirrored here — the module under test is this one.
        Schema::create('health_patients', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('mrn', 32)->nullable();
            $table->string('name');
            $table->string('phone', 32)->nullable();
            $table->timestamps();
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->string('contact_person')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('ntn')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('po_number');
            $table->string('status')->nullable();
            $table->string('invoice_number')->nullable();
            $table->date('order_date')->nullable();
            $table->date('expected_date')->nullable();
            $table->date('received_date')->nullable();
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->decimal('paid_amount', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('purchase_order_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->decimal('quantity', 12, 4)->default(0);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('total_price', 14, 2)->default(0);
            $table->decimal('received_quantity', 12, 4)->nullable();
            $table->timestamps();
        });

        // The module's own schema, straight from its migration, so the test
        // fails the day the migration and the code disagree.
        (require base_path('database/migrations/2026_09_06_100000_create_health_pharmacy_module.php'))->up();

        $this->companyId = (int) DB::table('companies')->insertGetId([
            'name' => 'Pharmacy Test Hospital',
            'product_type' => 'health',
            'status' => 'active',
            'health_org_type' => 'hospital',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Pharmacy::forget();
    }

    protected function tearDown(): void
    {
        Pharmacy::forget();
        parent::tearDown();
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function medicine(array $overrides = []): HealthMedicine
    {
        return Pharmacy::createMedicine($this->companyId, array_merge([
            'name' => 'Panadol',
            'generic_name' => 'Paracetamol',
            'strength' => '500mg',
            'form' => 'tablet',
            'unit_uom' => 'tablet',
            'pack_size' => 10,
            'purchase_price' => 4,
            'sale_price' => 6,
            'reorder_level' => 20,
        ], $overrides));
    }

    private function receive(HealthMedicine $medicine, array $line, ?int $branchId = null): HealthMedicineBatch
    {
        return Stock::receive(
            $this->companyId,
            $medicine,
            $line,
            $branchId ?? $this->branchA,
            ['type' => 'test', 'id' => null, 'number' => 'TEST'],
            null
        );
    }

    private function branchQuantity(HealthMedicine $medicine, ?int $branchId = null): float
    {
        $row = DB::table('inventory_stocks')
            ->where('company_id', $this->companyId)
            ->where('product_id', $medicine->product_id)
            ->where('branch_id', $branchId ?? $this->branchA)
            ->first();

        return $row ? (float) $row->quantity : 0.0;
    }

    private function openPolicy(array $values): void
    {
        Pharmacy::saveSettings($this->companyId, $values);
        Pharmacy::forget();
    }

    // ── 1. Catalogue ─────────────────────────────────────────────────────────

    public function test_a_medicine_gets_a_shared_catalogue_row_so_stock_has_one_home(): void
    {
        $medicine = $this->medicine(['code' => 'PAN500', 'barcode' => '112233', 'tax_rate' => 17]);

        $this->assertNotNull($medicine->product_id, 'A medicine without a product row has no stock identity.');

        $product = DB::table('products')->find($medicine->product_id);
        $this->assertSame('Panadol 500mg', $product->name);
        $this->assertSame('PAN500', $product->sku);
        $this->assertSame('112233', $product->barcode);
        $this->assertEquals(6, (float) $product->default_price);
        $this->assertEquals(17, (float) $product->default_tax_rate);
    }

    public function test_substitutes_are_written_both_ways(): void
    {
        $panadol = $this->medicine();
        $calpol = $this->medicine(['name' => 'Calpol']);

        Pharmacy::syncSubstitutes($panadol, [$calpol->id]);

        $this->assertTrue($panadol->substitutes()->where('health_medicines.id', $calpol->id)->exists());
        $this->assertTrue(
            $calpol->substitutes()->where('health_medicines.id', $panadol->id)->exists(),
            'A substitute list that only works one way is the pharmacist trap this module refuses.'
        );
    }

    // ── 2. Receiving and one stock truth ─────────────────────────────────────

    public function test_receiving_moves_the_batch_and_the_branch_together(): void
    {
        $medicine = $this->medicine();

        $batch = $this->receive($medicine, [
            'quantity' => 100,
            'batch_no' => 'B1',
            'expiry_date' => now()->addYear()->toDateString(),
            'cost_price' => 4,
            'sale_price' => 6,
        ]);

        $this->assertEquals(100, (float) $batch->quantity);
        $this->assertEquals(100, $this->branchQuantity($medicine));
        $this->assertSame([], Stock::reconcile($this->companyId, $this->branchA));
    }

    public function test_the_same_lot_received_twice_merges_at_a_weighted_cost(): void
    {
        $medicine = $this->medicine();
        $expiry = now()->addYear()->toDateString();

        $first = $this->receive($medicine, ['quantity' => 100, 'batch_no' => 'B1', 'expiry_date' => $expiry, 'cost_price' => 4]);
        $second = $this->receive($medicine, ['quantity' => 100, 'batch_no' => 'B1', 'expiry_date' => $expiry, 'cost_price' => 6]);

        $this->assertSame($first->id, $second->id, 'Two look-alike rows would be indistinguishable on the shelf.');
        $this->assertEquals(200, (float) $second->quantity);
        $this->assertEquals(5, round((float) $second->cost_price, 2));
    }

    public function test_a_zero_quantity_delivery_is_refused(): void
    {
        $medicine = $this->medicine();

        $this->expectException(ValidationException::class);
        $this->receive($medicine, ['quantity' => 0, 'batch_no' => 'B1']);
    }

    // ── 3. FEFO ──────────────────────────────────────────────────────────────

    public function test_the_lot_that_dies_first_leaves_first_and_undated_stock_goes_last(): void
    {
        $medicine = $this->medicine();

        $undated = $this->receive($medicine, ['quantity' => 50, 'batch_no' => 'NO-DATE']);
        $far = $this->receive($medicine, ['quantity' => 50, 'batch_no' => 'FAR', 'expiry_date' => now()->addYears(2)->toDateString()]);
        $near = $this->receive($medicine, ['quantity' => 50, 'batch_no' => 'NEAR', 'expiry_date' => now()->addMonths(10)->toDateString()]);

        $plan = Stock::plan($this->companyId, $medicine, 120, $this->branchA);
        $order = array_map(fn ($a) => $a['batch']->id, $plan['allocations']);

        $this->assertSame([$near->id, $far->id, $undated->id], $order);
        $this->assertEquals(0, $plan['shortfall']);
    }

    public function test_a_short_supply_is_reported_as_a_shortfall_not_as_a_silent_partial(): void
    {
        $medicine = $this->medicine();
        $this->receive($medicine, ['quantity' => 10, 'batch_no' => 'B1', 'expiry_date' => now()->addYear()->toDateString()]);

        $plan = Stock::plan($this->companyId, $medicine, 25, $this->branchA);

        $this->assertEquals(15, $plan['shortfall']);
        $this->assertContains('short_stock', array_column($plan['warnings'], 'code'));
    }

    // ── 4. Expiry policy ─────────────────────────────────────────────────────

    public function test_expired_stock_is_skipped_while_the_owner_keeps_it_blocked(): void
    {
        $medicine = $this->medicine();
        $this->receive($medicine, ['quantity' => 50, 'batch_no' => 'DEAD', 'expiry_date' => now()->subDay()->toDateString()]);

        $plan = Stock::plan($this->companyId, $medicine, 10, $this->branchA);

        $this->assertSame([], $plan['allocations']);
        $this->assertEquals(10, $plan['shortfall']);
        $this->assertContains('expired_skipped', array_column($plan['warnings'], 'code'));
    }

    public function test_expired_stock_can_only_be_used_once_the_owner_opens_the_policy(): void
    {
        $medicine = $this->medicine();
        $this->receive($medicine, ['quantity' => 50, 'batch_no' => 'DEAD', 'expiry_date' => now()->subDay()->toDateString()]);

        // The override alone is not enough while the policy blocks expired stock.
        $sale = null;
        try {
            $sale = Checkout::sell($this->companyId, [
                'lines' => [['medicine_id' => $medicine->id, 'quantity' => 5]],
                'allow_expired' => true,
            ], $this->branchA, null);
        } catch (ValidationException $e) {
            $sale = null;
        }
        $this->assertNull($sale, 'A blocked policy must beat a ticked override.');

        $this->openPolicy(['block_expired_dispense' => false]);

        $plan = Stock::plan($this->companyId, $medicine, 5, $this->branchA, ['allow_expired' => true]);
        $this->assertCount(1, $plan['allocations']);
        $this->assertContains('expired_used', array_column($plan['warnings'], 'code'));
    }

    public function test_short_dated_stock_still_sells_but_the_counter_is_warned(): void
    {
        $medicine = $this->medicine();
        $this->receive($medicine, ['quantity' => 50, 'batch_no' => 'SOON', 'expiry_date' => now()->addDays(20)->toDateString()]);

        $plan = Stock::plan($this->companyId, $medicine, 5, $this->branchA);

        $this->assertCount(1, $plan['allocations']);
        $this->assertContains('short_dated', array_column($plan['warnings'], 'code'));
    }

    public function test_a_controlled_medicine_may_not_leave_the_counter_without_a_prescription(): void
    {
        $medicine = $this->medicine(['name' => 'Morphine', 'is_controlled' => true, 'requires_prescription' => true]);
        $this->receive($medicine, ['quantity' => 20, 'batch_no' => 'C1', 'expiry_date' => now()->addYear()->toDateString()]);

        $this->expectException(ValidationException::class);
        Checkout::sell($this->companyId, [
            'lines' => [['medicine_id' => $medicine->id, 'quantity' => 1]],
        ], $this->branchA, null);
    }

    // ── 5. Counter checkout ──────────────────────────────────────────────────

    public function test_a_counter_sale_deducts_fefo_and_freezes_its_own_tax_split(): void
    {
        $medicine = $this->medicine(['tax_rate' => 10]);
        $near = $this->receive($medicine, ['quantity' => 6, 'batch_no' => 'NEAR', 'expiry_date' => now()->addMonths(11)->toDateString(), 'cost_price' => 4]);
        $far = $this->receive($medicine, ['quantity' => 20, 'batch_no' => 'FAR', 'expiry_date' => now()->addYears(2)->toDateString(), 'cost_price' => 5]);

        $sale = Checkout::sell($this->companyId, [
            'payment_method' => 'cash',
            'paid_amount' => 100,
            'lines' => [['medicine_id' => $medicine->id, 'quantity' => 10, 'unit_price' => 6]],
        ], $this->branchA, null);

        // Split across two lots, nearest expiry emptied first.
        $items = HealthPharmacySaleItem::withoutGlobalScopes()->where('sale_id', $sale->id)->orderBy('id')->get();
        $this->assertCount(2, $items);
        $this->assertSame($near->id, (int) $items[0]->batch_id);
        $this->assertEquals(6, (float) $items[0]->quantity);
        $this->assertSame($far->id, (int) $items[1]->batch_id);
        $this->assertEquals(4, (float) $items[1]->quantity);

        // 60 net + 10% tax, frozen on the bill itself.
        $this->assertEquals(60, round((float) $sale->subtotal, 2));
        $this->assertEquals(6, round((float) $sale->tax_amount, 2));
        $this->assertEquals(66, round((float) $sale->total_amount, 2));

        $this->assertEquals(0, (float) $near->fresh()->quantity);
        $this->assertEquals(16, (float) $far->fresh()->quantity);
        $this->assertEquals(16, $this->branchQuantity($medicine));
        $this->assertSame([], Stock::reconcile($this->companyId, $this->branchA));
    }

    public function test_a_pinned_lot_beats_fefo_because_the_ward_asked_for_it(): void
    {
        $medicine = $this->medicine();
        $this->receive($medicine, ['quantity' => 20, 'batch_no' => 'NEAR', 'expiry_date' => now()->addMonths(11)->toDateString()]);
        $far = $this->receive($medicine, ['quantity' => 20, 'batch_no' => 'FAR', 'expiry_date' => now()->addYears(2)->toDateString()]);

        $sale = Checkout::sell($this->companyId, [
            'lines' => [['medicine_id' => $medicine->id, 'quantity' => 5, 'batch_id' => $far->id]],
        ], $this->branchA, null);

        $item = HealthPharmacySaleItem::withoutGlobalScopes()->where('sale_id', $sale->id)->first();
        $this->assertSame($far->id, (int) $item->batch_id);
    }

    public function test_a_bill_with_no_line_is_refused(): void
    {
        $this->expectException(ValidationException::class);
        Checkout::sell($this->companyId, ['lines' => []], $this->branchA, null);
    }

    public function test_selling_more_than_the_shelf_holds_is_refused_by_default(): void
    {
        $medicine = $this->medicine();
        $this->receive($medicine, ['quantity' => 3, 'batch_no' => 'B1', 'expiry_date' => now()->addYear()->toDateString()]);

        $this->expectException(ValidationException::class);
        Checkout::sell($this->companyId, [
            'lines' => [['medicine_id' => $medicine->id, 'quantity' => 10]],
        ], $this->branchA, null);
    }

    // ── 6. Prescription dispensing ───────────────────────────────────────────

    private function prescription(HealthMedicine $medicine, float $quantity = 20): HealthPrescription
    {
        $prescription = HealthPrescription::withoutGlobalScopes()->create([
            'company_id' => $this->companyId,
            'branch_id' => $this->branchA,
            'prescription_no' => 'RX001',
            'patient_name' => 'Test Patient',
            'doctor_name' => 'Dr Test',
            'prescribed_on' => now()->toDateString(),
            // Two states, two owners: the doctor issued it, the pharmacy has
            // not started filling it.
            'status' => HealthPrescription::STATUS_ISSUED,
            'dispense_status' => HealthPrescription::DISPENSE_PENDING,
        ]);

        HealthPrescriptionItem::withoutGlobalScopes()->create([
            'company_id' => $this->companyId,
            'health_prescription_id' => $prescription->id,
            'medicine_id' => $medicine->id,
            'medicine_name' => $medicine->display_name,
            'quantity' => $quantity,
            'dispensed_quantity' => 0,
        ]);

        return $prescription->fresh();
    }

    public function test_a_prescription_can_be_filled_across_two_visits(): void
    {
        $medicine = $this->medicine();
        $this->receive($medicine, ['quantity' => 100, 'batch_no' => 'B1', 'expiry_date' => now()->addYear()->toDateString()]);

        $prescription = $this->prescription($medicine, 20);
        $item = $prescription->items()->first();

        Checkout::sell($this->companyId, [
            'prescription_id' => $prescription->id,
            'lines' => [[
                'medicine_id' => $medicine->id,
                'quantity' => 8,
                'prescription_item_id' => $item->id,
            ]],
        ], $this->branchA, null);

        $item->refresh();
        $this->assertEquals(8, (float) $item->dispensed_quantity);
        $this->assertEquals(12, $item->remaining_quantity);
        $this->assertSame(HealthPrescription::DISPENSE_PARTIAL, $prescription->fresh()->dispense_status);

        Checkout::sell($this->companyId, [
            'prescription_id' => $prescription->id,
            'lines' => [[
                'medicine_id' => $medicine->id,
                'quantity' => 12,
                'prescription_item_id' => $item->id,
            ]],
        ], $this->branchA, null);

        $item->refresh();
        $this->assertEquals(0, $item->remaining_quantity);
        $this->assertSame(HealthPrescription::DISPENSE_DISPENSED, $prescription->fresh()->dispense_status);
    }

    public function test_dispensing_more_than_was_prescribed_is_refused(): void
    {
        $medicine = $this->medicine();
        $this->receive($medicine, ['quantity' => 100, 'batch_no' => 'B1', 'expiry_date' => now()->addYear()->toDateString()]);

        $prescription = $this->prescription($medicine, 10);
        $item = $prescription->items()->first();

        $this->expectException(ValidationException::class);
        Checkout::sell($this->companyId, [
            'prescription_id' => $prescription->id,
            'lines' => [[
                'medicine_id' => $medicine->id,
                'quantity' => 11,
                'prescription_item_id' => $item->id,
            ]],
        ], $this->branchA, null);
    }

    public function test_a_cancelled_prescription_cannot_be_dispensed(): void
    {
        $medicine = $this->medicine();
        $this->receive($medicine, ['quantity' => 100, 'batch_no' => 'B1', 'expiry_date' => now()->addYear()->toDateString()]);

        $prescription = $this->prescription($medicine, 10);
        $prescription->update(['dispense_status' => HealthPrescription::DISPENSE_CANCELLED]);

        $this->expectException(ValidationException::class);
        Checkout::sell($this->companyId, [
            'prescription_id' => $prescription->id,
            'lines' => [['medicine_id' => $medicine->id, 'quantity' => 1]],
        ], $this->branchA, null);
    }

    /**
     * A prescription written in the consultation room lands on the SAME table.
     * Until the doctor issues it, it is a draft being typed with the patient in
     * the room — dispensing it would hand out medicine off an unfinished slip.
     */
    public function test_a_draft_prescription_from_the_consultation_room_cannot_be_dispensed(): void
    {
        $medicine = $this->medicine();
        $this->receive($medicine, ['quantity' => 100, 'batch_no' => 'B1', 'expiry_date' => now()->addYear()->toDateString()]);

        $prescription = $this->prescription($medicine, 10);
        $prescription->update(['status' => HealthPrescription::STATUS_DRAFT]);

        $this->expectException(ValidationException::class);
        Checkout::sell($this->companyId, [
            'prescription_id' => $prescription->id,
            'lines' => [['medicine_id' => $medicine->id, 'quantity' => 1]],
        ], $this->branchA, null);
    }

    /**
     * Dispensing must never touch the doctor's own state. The pharmacy moves
     * `dispense_status`; `status` stays exactly as it was signed.
     */
    public function test_dispensing_does_not_rewrite_the_doctors_own_state(): void
    {
        $medicine = $this->medicine();
        $this->receive($medicine, ['quantity' => 100, 'batch_no' => 'B1', 'expiry_date' => now()->addYear()->toDateString()]);

        $prescription = $this->prescription($medicine, 10);
        $item = $prescription->items()->first();

        Checkout::sell($this->companyId, [
            'prescription_id' => $prescription->id,
            'lines' => [[
                'medicine_id' => $medicine->id,
                'quantity' => 10,
                'prescription_item_id' => $item->id,
            ]],
        ], $this->branchA, null);

        $fresh = $prescription->fresh();
        $this->assertSame(HealthPrescription::STATUS_ISSUED, $fresh->status);
        $this->assertSame(HealthPrescription::DISPENSE_DISPENSED, $fresh->dispense_status);
    }

    /**
     * A doctor prescribes in words, not by catalogue id. The line still has to
     * be fillable: the dispenser names the shelf item, and the quantity is
     * recorded against the prescribed line all the same.
     */
    public function test_a_line_with_no_catalogue_link_is_filled_by_naming_the_medicine(): void
    {
        $medicine = $this->medicine();
        $this->receive($medicine, ['quantity' => 100, 'batch_no' => 'B1', 'expiry_date' => now()->addYear()->toDateString()]);

        $prescription = $this->prescription($medicine, 10);

        // What OPD writes: a name, no medicine_id.
        $written = HealthPrescriptionItem::withoutGlobalScopes()->create([
            'company_id' => $this->companyId,
            'health_prescription_id' => $prescription->id,
            'line_no' => 2,
            'medicine_name' => 'Paracetamol 500mg',
            'quantity' => 6,
            'dispensed_quantity' => 0,
        ]);

        $this->assertNull($written->medicine_id);

        Checkout::sell($this->companyId, [
            'prescription_id' => $prescription->id,
            'lines' => [[
                'medicine_id' => $medicine->id,
                'quantity' => 6,
                'prescription_item_id' => $written->id,
            ]],
        ], $this->branchA, null);

        $this->assertEquals(6, (float) $written->fresh()->dispensed_quantity);
        $this->assertEquals(0, $written->fresh()->remaining_quantity);
    }

    /**
     * A patient registered in the hospital wins over a name typed at the
     * counter: the bill must carry the medical record, not a hurried spelling.
     */
    public function test_a_registered_patient_name_is_copied_onto_the_bill(): void
    {
        $medicine = $this->medicine();
        $this->receive($medicine, ['quantity' => 100, 'batch_no' => 'B1', 'expiry_date' => now()->addYear()->toDateString()]);

        $patientId = DB::table('health_patients')->insertGetId([
            'company_id' => $this->companyId,
            'mrn' => 'MR-9001',
            'name' => 'Registered Patient',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $prescription = $this->prescription($medicine, 5);
        $prescription->update(['health_patient_id' => $patientId, 'patient_name' => null, 'patient_mr_no' => null]);

        $sale = Checkout::sell($this->companyId, [
            'prescription_id' => $prescription->id,
            'lines' => [[
                'medicine_id' => $medicine->id,
                'quantity' => 5,
                'prescription_item_id' => $prescription->items()->first()->id,
            ]],
        ], $this->branchA, null);

        $this->assertSame($patientId, (int) $sale->patient_id);
        $this->assertSame('Registered Patient', $sale->patient_name);
        $this->assertSame('MR-9001', $sale->patient_mr_no);
    }

    public function test_a_substitute_is_recorded_against_the_medicine_it_replaced(): void
    {
        $panadol = $this->medicine();
        $calpol = $this->medicine(['name' => 'Calpol']);
        $this->receive($calpol, ['quantity' => 50, 'batch_no' => 'C1', 'expiry_date' => now()->addYear()->toDateString()]);

        $prescription = $this->prescription($panadol, 10);
        $item = $prescription->items()->first();

        $sale = Checkout::sell($this->companyId, [
            'prescription_id' => $prescription->id,
            'lines' => [[
                'medicine_id' => $calpol->id,
                'quantity' => 10,
                'prescription_item_id' => $item->id,
                'is_substitute' => true,
                'substitute_for_medicine_id' => $panadol->id,
            ]],
        ], $this->branchA, null);

        $saleItem = HealthPharmacySaleItem::withoutGlobalScopes()->where('sale_id', $sale->id)->first();
        $this->assertTrue((bool) $saleItem->is_substitute);
        $this->assertSame($panadol->id, (int) $saleItem->substitute_for_medicine_id);
        $this->assertEquals(10, (float) $item->fresh()->dispensed_quantity);
    }

    // ── 7. Returns ───────────────────────────────────────────────────────────

    public function test_a_restocked_return_puts_the_medicine_back_on_its_own_lot(): void
    {
        $medicine = $this->medicine();
        $batch = $this->receive($medicine, ['quantity' => 50, 'batch_no' => 'B1', 'expiry_date' => now()->addYear()->toDateString()]);

        $sale = Checkout::sell($this->companyId, [
            'lines' => [['medicine_id' => $medicine->id, 'quantity' => 10, 'unit_price' => 6]],
        ], $this->branchA, null);

        $item = HealthPharmacySaleItem::withoutGlobalScopes()->where('sale_id', $sale->id)->first();

        $return = Checkout::refund($this->companyId, $sale, [
            ['sale_item_id' => $item->id, 'quantity' => 4],
        ], true, 'damaged', null);

        $this->assertEquals(24, round((float) $return->refund_amount, 2));
        $this->assertEquals(44, (float) $batch->fresh()->quantity);
        $this->assertEquals(44, $this->branchQuantity($medicine));
        $this->assertEquals(4, (float) $item->fresh()->returned_quantity);
        $this->assertSame(HealthPharmacySale::STATUS_PARTIALLY_RETURNED, $sale->fresh()->status);
        $this->assertSame([], Stock::reconcile($this->companyId, $this->branchA));
    }

    public function test_a_non_restock_return_still_leaves_a_trail_both_ways(): void
    {
        $medicine = $this->medicine();
        $batch = $this->receive($medicine, ['quantity' => 50, 'batch_no' => 'B1', 'expiry_date' => now()->addYear()->toDateString()]);

        $sale = Checkout::sell($this->companyId, [
            'lines' => [['medicine_id' => $medicine->id, 'quantity' => 10, 'unit_price' => 6]],
        ], $this->branchA, null);

        $item = HealthPharmacySaleItem::withoutGlobalScopes()->where('sale_id', $sale->id)->first();

        Checkout::refund($this->companyId, $sale, [
            ['sale_item_id' => $item->id, 'quantity' => 4],
        ], false, 'damaged', null);

        // Money came back, but the goods never returned to the shelf.
        $this->assertEquals(40, (float) $batch->fresh()->quantity);
        $this->assertEquals(40, $this->branchQuantity($medicine));

        $types = HealthBatchMovement::withoutGlobalScopes()
            ->where('batch_id', $batch->id)
            ->pluck('type')
            ->all();

        $this->assertContains(HealthBatchMovement::TYPE_SALE_RETURN, $types);
        $this->assertContains(HealthBatchMovement::TYPE_WASTAGE, $types);
    }

    public function test_returning_more_than_was_sold_is_refused(): void
    {
        $medicine = $this->medicine();
        $this->receive($medicine, ['quantity' => 50, 'batch_no' => 'B1', 'expiry_date' => now()->addYear()->toDateString()]);

        $sale = Checkout::sell($this->companyId, [
            'lines' => [['medicine_id' => $medicine->id, 'quantity' => 5, 'unit_price' => 6]],
        ], $this->branchA, null);

        $item = HealthPharmacySaleItem::withoutGlobalScopes()->where('sale_id', $sale->id)->first();

        $this->expectException(ValidationException::class);
        Checkout::refund($this->companyId, $sale, [
            ['sale_item_id' => $item->id, 'quantity' => 6],
        ], true, null, null);
    }

    public function test_returning_every_unit_marks_the_bill_returned(): void
    {
        $medicine = $this->medicine();
        $this->receive($medicine, ['quantity' => 50, 'batch_no' => 'B1', 'expiry_date' => now()->addYear()->toDateString()]);

        $sale = Checkout::sell($this->companyId, [
            'lines' => [['medicine_id' => $medicine->id, 'quantity' => 5, 'unit_price' => 6]],
        ], $this->branchA, null);

        $item = HealthPharmacySaleItem::withoutGlobalScopes()->where('sale_id', $sale->id)->first();

        Checkout::refund($this->companyId, $sale, [
            ['sale_item_id' => $item->id, 'quantity' => 5],
        ], true, null, null);

        $this->assertSame(HealthPharmacySale::STATUS_RETURNED, $sale->fresh()->status);
    }

    // ── 8. Quarantine, write-off, adjustment, transfer ───────────────────────

    public function test_quarantine_holds_a_lot_back_without_pretending_it_left(): void
    {
        $medicine = $this->medicine();
        $batch = $this->receive($medicine, ['quantity' => 30, 'batch_no' => 'B1', 'expiry_date' => now()->addYear()->toDateString()]);

        Stock::quarantine($this->companyId, $batch, 'recall', null);

        $this->assertSame(HealthMedicineBatch::STATUS_QUARANTINED, $batch->fresh()->status);
        $this->assertEquals(30, (float) $batch->fresh()->quantity, 'Quarantine must not move stock.');
        $this->assertEquals(30, $this->branchQuantity($medicine));

        // Held stock is unreachable from the counter.
        $plan = Stock::plan($this->companyId, $medicine, 5, $this->branchA);
        $this->assertSame([], $plan['allocations']);

        Stock::release($this->companyId, $batch->fresh(), null);
        $this->assertSame(HealthMedicineBatch::STATUS_ACTIVE, $batch->fresh()->status);
        $this->assertCount(1, Stock::plan($this->companyId, $medicine, 5, $this->branchA)['allocations']);
    }

    public function test_an_expired_write_off_files_as_an_expiry_write_off(): void
    {
        $medicine = $this->medicine();
        $batch = $this->receive($medicine, ['quantity' => 12, 'batch_no' => 'DEAD', 'expiry_date' => now()->subDays(3)->toDateString()]);

        Stock::writeOff($this->companyId, $batch, null, 'expired', null);

        $batch->refresh();
        $this->assertEquals(0, (float) $batch->quantity);
        $this->assertSame(HealthMedicineBatch::STATUS_WRITTEN_OFF, $batch->status);
        $this->assertEquals(0, $this->branchQuantity($medicine));

        $movement = HealthBatchMovement::withoutGlobalScopes()
            ->where('batch_id', $batch->id)
            ->where('direction', HealthBatchMovement::DIRECTION_OUT)
            ->latest('id')
            ->first();

        $this->assertSame(HealthBatchMovement::TYPE_EXPIRY_WRITEOFF, $movement->type);
    }

    public function test_damaged_goods_are_wastage_not_an_expiry_write_off(): void
    {
        $medicine = $this->medicine();
        $batch = $this->receive($medicine, ['quantity' => 12, 'batch_no' => 'B1', 'expiry_date' => now()->addYear()->toDateString()]);

        Stock::writeOff($this->companyId, $batch, 2, 'breakage', null);

        $movement = HealthBatchMovement::withoutGlobalScopes()
            ->where('batch_id', $batch->id)
            ->where('direction', HealthBatchMovement::DIRECTION_OUT)
            ->latest('id')
            ->first();

        $this->assertSame(HealthBatchMovement::TYPE_WASTAGE, $movement->type);
        $this->assertEquals(10, (float) $batch->fresh()->quantity);
        $this->assertEquals(10, $this->branchQuantity($medicine));
    }

    public function test_a_counted_correction_records_the_difference_in_the_right_direction(): void
    {
        $medicine = $this->medicine();
        $batch = $this->receive($medicine, ['quantity' => 20, 'batch_no' => 'B1', 'expiry_date' => now()->addYear()->toDateString()]);

        Stock::adjust($this->companyId, $batch, 18, 'count_correction', null);
        $this->assertEquals(18, (float) $batch->fresh()->quantity);
        $this->assertEquals(18, $this->branchQuantity($medicine));

        Stock::adjust($this->companyId, $batch->fresh(), 25, 'count_correction', null);
        $this->assertEquals(25, (float) $batch->fresh()->quantity);
        $this->assertEquals(25, $this->branchQuantity($medicine));

        $types = HealthBatchMovement::withoutGlobalScopes()->where('batch_id', $batch->id)->pluck('type')->all();
        $this->assertContains(HealthBatchMovement::TYPE_ADJUSTMENT_OUT, $types);
        $this->assertContains(HealthBatchMovement::TYPE_ADJUSTMENT_IN, $types);
    }

    public function test_a_transfer_moves_the_lot_between_branches_and_keeps_both_honest(): void
    {
        $medicine = $this->medicine();
        $batch = $this->receive($medicine, ['quantity' => 30, 'batch_no' => 'B1', 'expiry_date' => now()->addYear()->toDateString(), 'cost_price' => 4]);

        Stock::transfer($this->companyId, $batch, $this->branchB, 12, null);

        $this->assertEquals(18, (float) $batch->fresh()->quantity);
        $this->assertEquals(18, $this->branchQuantity($medicine, $this->branchA));
        $this->assertEquals(12, $this->branchQuantity($medicine, $this->branchB));

        $received = HealthMedicineBatch::withoutGlobalScopes()
            ->where('medicine_id', $medicine->id)
            ->where('branch_id', $this->branchB)
            ->first();

        $this->assertNotNull($received);
        $this->assertSame('B1', $received->batch_no);
        $this->assertEquals(12, (float) $received->quantity);
        $this->assertSame([], Stock::reconcile($this->companyId, null, true));
    }

    public function test_a_transfer_to_the_same_branch_is_refused(): void
    {
        $medicine = $this->medicine();
        $batch = $this->receive($medicine, ['quantity' => 30, 'batch_no' => 'B1']);

        $this->expectException(ValidationException::class);
        Stock::transfer($this->companyId, $batch, $this->branchA, 5, null);
    }

    public function test_drift_is_reported_and_never_silently_healed(): void
    {
        $medicine = $this->medicine();
        $this->receive($medicine, ['quantity' => 30, 'batch_no' => 'B1', 'expiry_date' => now()->addYear()->toDateString()]);

        // Something wrote branch stock without going through the pharmacy.
        DB::table('inventory_stocks')
            ->where('company_id', $this->companyId)
            ->where('product_id', $medicine->product_id)
            ->update(['quantity' => 25]);

        $drift = Stock::reconcile($this->companyId, $this->branchA);

        $this->assertCount(1, $drift);
        $this->assertEquals(30, (float) $drift[0]['batch_total']);
        $this->assertEquals(25, (float) $drift[0]['branch_total']);
        $this->assertEquals(5, (float) $drift[0]['difference']);
        $this->assertEquals(25, $this->branchQuantity($medicine), 'Reconcile reports; it must not correct.');
    }

    // ── 9. Movement ledger ───────────────────────────────────────────────────

    public function test_every_movement_records_what_was_left_after_it(): void
    {
        $medicine = $this->medicine();
        $batch = $this->receive($medicine, ['quantity' => 30, 'batch_no' => 'B1', 'expiry_date' => now()->addYear()->toDateString()]);

        Checkout::sell($this->companyId, [
            'lines' => [['medicine_id' => $medicine->id, 'quantity' => 5]],
        ], $this->branchA, null);

        Stock::writeOff($this->companyId, $batch->fresh(), 5, 'breakage', null);

        $ledger = HealthBatchMovement::withoutGlobalScopes()
            ->where('batch_id', $batch->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(3, $ledger);
        $this->assertEquals([30.0, 25.0, 20.0], $ledger->pluck('balance_after')->map(fn ($v) => (float) $v)->all());
    }

    // ── 10. Reports ──────────────────────────────────────────────────────────

    public function test_the_reports_see_what_the_shelf_sees(): void
    {
        $medicine = $this->medicine(['reorder_level' => 100]);
        $this->receive($medicine, ['quantity' => 40, 'batch_no' => 'SOON', 'expiry_date' => now()->addDays(15)->toDateString(), 'cost_price' => 4, 'sale_price' => 6]);
        $this->receive($medicine, ['quantity' => 10, 'batch_no' => 'DEAD', 'expiry_date' => now()->subDays(5)->toDateString(), 'cost_price' => 4]);

        Checkout::sell($this->companyId, [
            'lines' => [['medicine_id' => $medicine->id, 'quantity' => 10, 'unit_price' => 6]],
        ], $this->branchA, null);

        $summary = Reports::summary($this->companyId, $this->branchA);
        $this->assertEquals(1, $summary['expired']);
        $this->assertGreaterThan(0, $summary['near_expiry']);
        $this->assertEquals(1, $summary['today_bills']);
        $this->assertEquals(60, round((float) $summary['today_sales'], 2));

        $low = Reports::lowStock($this->companyId, $this->branchA);
        $this->assertCount(1, $low, 'A medicine below its own reorder level must show up.');

        $expired = Reports::expiredQuery($this->companyId, $this->branchA, false)->get();
        $this->assertCount(1, $expired);

        $near = Reports::nearExpiryQuery($this->companyId, $this->branchA, false, 30)->get();
        $this->assertCount(1, $near);

        $valuation = Reports::valuation($this->companyId, $this->branchA);
        // 30 left on the short-dated lot + 10 expired, all at cost 4.
        $this->assertEquals(160, round((float) $valuation['cost_value'], 2));

        $margin = Reports::margin($this->companyId, $this->branchA, false, now()->subDay()->toDateString(), now()->addDay()->toDateString());
        $this->assertCount(1, $margin);
        $this->assertEquals(60, round((float) $margin[0]->revenue, 2));
        $this->assertEquals(40, round((float) $margin[0]->cost, 2));
        $this->assertEquals(20, round((float) $margin[0]->profit, 2));
    }

    public function test_a_second_company_never_sees_the_first_ones_medicine(): void
    {
        $other = (int) DB::table('companies')->insertGetId([
            'name' => 'Other Hospital',
            'product_type' => 'health',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $medicine = $this->medicine();
        $this->receive($medicine, ['quantity' => 30, 'batch_no' => 'B1', 'expiry_date' => now()->addYear()->toDateString()]);

        $this->assertSame([], Reports::lowStock($other, $this->branchA));
        $this->assertEquals(0, (float) Reports::valuation($other, $this->branchA)['cost_value']);
        $this->assertEquals(0, Reports::summary($other, $this->branchA)['expired']);
    }
}

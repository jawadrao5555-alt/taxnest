<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Company;
use App\Models\FbrPosTransaction;
use App\Services\FbrService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * FBR POS CREDIT-NOTE PAYLOAD — "InvoiceType=3, amounts stay POSITIVE" (Aug 2026).
 *
 * PRA live testing (Aug 2026) proved the IMS credit-note model rejects NEGATIVE
 * amounts with Code 102 "Invalid Total Bill Amount…" — the reversal is signalled
 * by InvoiceType=3 alone. The local FBRIMS fiscal component uses the same IMS
 * invoice model, so buildFbrPosPayload() must NEVER sign-flip return amounts.
 *
 * Locks:
 *   1. Return payload: InvoiceType=3 (header + every line), RefUSIN = parent fbr_invoice_number
 *      (fiscal USIN assigned by FBRIMS), NOT invoice_number (local app ref like FPOS-2026-NNNNN).
 *      Bug fixed Aug 2026: using invoice_number caused TaxAsaan to show "no record" for credit
 *      notes (X-Way Shoes live return test, credit note 196354FHGP22214428).
 *   2. ALL amounts and quantities POSITIVE (header + lines) — no sign flip.
 *   3. Header math: TotalBillAmount = TotalSaleValue + TotalTaxCharged - Discount.
 *   4. Sale payload unchanged: InvoiceType=1, RefUSIN null, positive amounts.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create.
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' php vendor/bin/phpunit tests/Feature/FbrPosReturnCreditNotePayloadTest.php
 */
class FbrPosReturnCreditNotePayloadTest extends TestCase
{
    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->nullable();
            $table->string('status')->nullable();
            $table->string('fbr_pos_id')->nullable();
            $table->string('fbr_pos_environment')->nullable();
            $table->boolean('fbr_reporting_enabled')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('fbr_pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('parent_transaction_id')->nullable();
            $table->string('invoice_number');
            $table->string('transaction_type')->default('sale');
            $table->string('status')->nullable();
            $table->string('invoice_mode')->nullable();
            $table->string('fbr_status')->nullable();
            $table->string('fbr_invoice_number')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('customer_ntn')->nullable();
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

        $this->companyId = (int) DB::table('companies')->insertGetId([
            'name' => 'FBR Return Payload Co',
            'product_type' => 'fbrpos',
            'status' => 'approved',
            'fbr_pos_id' => '912345',
            'fbr_pos_environment' => 'production',
            'fbr_reporting_enabled' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Seed a sale (parent) + a partial return referencing it; return [$parent, $return]. */
    private function seedParentAndReturn(): array
    {
        $parentId = DB::table('fbr_pos_transactions')->insertGetId([
            'company_id' => $this->companyId,
            'invoice_number' => 'FBRINV-1001',
            'transaction_type' => 'sale',
            'status' => 'completed',
            'fbr_status' => 'submitted',
            'fbr_invoice_number' => 'FBR-FISCAL-555',
            'subtotal' => 1000.00,
            'tax_amount' => 180.00,
            'discount_amount' => 0,
            'total_amount' => 1180.00,
            'payment_method' => 'cash',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('fbr_pos_transaction_items')->insert([
            'transaction_id' => $parentId,
            'item_name' => 'Widget',
            'quantity' => 4,
            'unit_price' => 250.00,
            'subtotal' => 1000.00,
            'tax_rate' => 18,
            'tax_amount' => 180.00,
            'total' => 1180.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Return of 2 units with a Rs 20 bill-level discount share.
        $returnId = DB::table('fbr_pos_transactions')->insertGetId([
            'company_id' => $this->companyId,
            'parent_transaction_id' => $parentId,
            'invoice_number' => 'FBRRET-2001',
            'transaction_type' => 'return',
            'status' => 'completed',
            'fbr_status' => 'pending',
            'subtotal' => 500.00,
            'tax_amount' => 90.00,
            'discount_amount' => 20.00,
            'total_amount' => 570.00,
            'payment_method' => 'cash',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('fbr_pos_transaction_items')->insert([
            'transaction_id' => $returnId,
            'item_name' => 'Widget',
            'quantity' => 2,
            'unit_price' => 250.00,
            'subtotal' => 500.00,
            'tax_rate' => 18,
            'tax_amount' => 90.00,
            'total' => 590.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            FbrPosTransaction::with('items')->findOrFail($parentId),
            FbrPosTransaction::with('items')->findOrFail($returnId),
        ];
    }

    public function test_return_payload_is_credit_note_with_positive_amounts(): void
    {
        [$parent, $return] = $this->seedParentAndReturn();

        $payload = (new FbrService())->buildFbrPosPayload($return);

        // 1. Credit-note markers.
        $this->assertSame(3, $payload['InvoiceType']);
        // RefUSIN must be the parent's FBR fiscal USIN (fbr_invoice_number), NOT the local
        // invoice_number. Bug fixed Aug 2026: wrong RefUSIN caused TaxAsaan "no record".
        $this->assertSame($parent->fbr_invoice_number, $payload['RefUSIN'], 'RefUSIN = parent FBR fiscal USIN (fbr_invoice_number)');
        $this->assertNotSame($parent->invoice_number, $payload['RefUSIN'], 'RefUSIN must NOT be the local invoice_number');

        // 2. ALL header amounts stay POSITIVE — IMS rejects negatives (Code 102).
        $this->assertSame(500.0, (float) $payload['TotalSaleValue']);
        $this->assertSame(90.0, (float) $payload['TotalTaxCharged']);
        $this->assertSame(2.0, (float) $payload['TotalQuantity']);
        $this->assertSame(20.0, (float) $payload['Discount']);

        // 3. Header math: sale + tax - discount = 570.
        $this->assertSame(570.0, (float) $payload['TotalBillAmount']);

        // Every line: positive amounts, InvoiceType=3, RefUSIN carried.
        $this->assertNotEmpty($payload['Items']);
        foreach ($payload['Items'] as $line) {
            $this->assertGreaterThan(0, $line['Quantity']);
            $this->assertGreaterThan(0, $line['SaleValue']);
            $this->assertGreaterThan(0, $line['TaxCharged']);
            $this->assertGreaterThan(0, $line['TotalAmount']);
            $this->assertGreaterThanOrEqual(0, $line['Discount']);
            $this->assertSame(3, $line['InvoiceType']);
            $this->assertSame($parent->fbr_invoice_number, $line['RefUSIN']);
        }
    }

    public function test_sale_payload_unchanged_invoice_type_1_no_refusin(): void
    {
        [$parent] = $this->seedParentAndReturn();

        $payload = (new FbrService())->buildFbrPosPayload($parent);

        $this->assertSame(1, $payload['InvoiceType']);
        $this->assertNull($payload['RefUSIN']);
        $this->assertSame(1000.0, (float) $payload['TotalSaleValue']);
        $this->assertSame(180.0, (float) $payload['TotalTaxCharged']);
        $this->assertSame(1180.0, (float) $payload['TotalBillAmount']);
        foreach ($payload['Items'] as $line) {
            $this->assertSame(1, $line['InvoiceType']);
            $this->assertNull($line['RefUSIN']);
            $this->assertGreaterThan(0, $line['Quantity']);
        }
    }
}

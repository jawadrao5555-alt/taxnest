<?php

namespace Tests\Feature;

use App\Models\StockCheck;
use App\Models\StockCheckLine;
use App\Services\StockCheckAlreadyOpenException;
use App\Services\StockCheckService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StockCheckIntegrityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('pos_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->string('sku')->nullable();
            $table->string('barcode')->nullable();
            $table->string('category')->nullable();
            $table->string('unit_type')->nullable();
            $table->string('uom')->nullable();
            $table->decimal('cost_price', 15, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('inventory_stocks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->decimal('quantity', 15, 4)->default(0);
            $table->decimal('avg_purchase_price', 15, 2)->default(0);
            $table->timestamps();
        });
        Schema::create('stock_checks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('code');
            $table->string('scope')->default('products');
            $table->string('status')->default('counting');
            $table->text('notes')->nullable();
            $table->unsignedInteger('total_lines')->default(0);
            $table->unsignedInteger('counted_lines')->default(0);
            $table->unsignedInteger('variance_lines')->default(0);
            $table->decimal('short_value', 15, 2)->default(0);
            $table->decimal('excess_value', 15, 2)->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'code']);
        });
        Schema::create('stock_check_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('stock_check_id');
            $table->string('item_type');
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('item_name');
            $table->string('item_code')->nullable();
            $table->string('unit')->nullable();
            $table->decimal('expected_quantity', 15, 4)->default(0);
            $table->decimal('counted_quantity', 15, 4)->nullable();
            $table->decimal('variance', 15, 4)->default(0);
            $table->decimal('unit_cost', 15, 2)->default(0);
            $table->decimal('variance_value', 15, 2)->default(0);
            $table->string('reason')->nullable();
            $table->string('notes')->nullable();
            $table->unsignedBigInteger('counted_by')->nullable();
            $table->timestamp('counted_at')->nullable();
            $table->timestamps();
        });

        DB::table('companies')->insert(['id' => 1, 'name' => 'Fictional Stock Lab']);
    }

    public function test_invalid_count_rejects_the_complete_save_before_any_row_changes(): void
    {
        $check = $this->check();
        $first = $this->line($check, 1, 10);
        $second = $this->line($check, 2, 20);

        try {
            StockCheckService::saveCounts($check, [
                $first->id => ['counted' => '8'],
                $second->id => ['counted' => '-1'],
            ], 7);
            $this->fail('A negative physical count must reject the complete payload.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey("lines.{$second->id}.counted", $e->errors());
        }

        $this->assertNull($first->fresh()->counted_quantity);
        $this->assertNull($second->fresh()->counted_quantity);
        $this->assertSame(0, $check->fresh()->counted_lines);
    }

    public function test_non_numeric_count_is_not_silently_cast_to_zero(): void
    {
        $check = $this->check();
        $line = $this->line($check, 1, 10);

        $this->expectException(ValidationException::class);
        try {
            StockCheckService::saveCounts($check, [
                $line->id => ['counted' => 'ten bags'],
            ], 7);
        } finally {
            $this->assertNull($line->fresh()->counted_quantity);
        }
    }

    public function test_zero_is_a_valid_count_and_blank_remains_not_counted(): void
    {
        $check = $this->check();
        $zero = $this->line($check, 1, 10);
        $blank = $this->line($check, 2, 20);

        $changed = StockCheckService::saveCounts($check, [
            $zero->id => ['counted' => '0'],
            $blank->id => ['counted' => ''],
        ], 7);

        $this->assertSame(1, $changed);
        $this->assertSame(0.0, $zero->fresh()->counted_quantity);
        $this->assertNull($blank->fresh()->counted_quantity);
        $this->assertSame(1, $check->fresh()->counted_lines);
    }

    public function test_completed_sheet_rejects_a_late_count_save(): void
    {
        $check = $this->check();
        $line = $this->line($check, 1, 10);
        $check->update(['status' => StockCheck::STATUS_COMPLETED]);

        $changed = StockCheckService::saveCounts($check->fresh(), [
            $line->id => ['counted' => '8'],
        ], 7);

        $this->assertSame(0, $changed);
        $this->assertNull($line->fresh()->counted_quantity);
    }

    public function test_second_open_sheet_for_the_same_company_and_branch_is_refused(): void
    {
        DB::table('pos_products')->insert([
            'id' => 1,
            'company_id' => 1,
            'name' => 'Fictional Flour Bag',
            'sku' => 'QA-FLOUR',
            'is_active' => 1,
        ]);
        DB::table('inventory_stocks')->insert([
            'company_id' => 1,
            'product_id' => 1,
            'branch_id' => null,
            'quantity' => 12,
        ]);

        StockCheckService::open(1, null, StockCheck::SCOPE_PRODUCTS, 7);

        $this->expectException(StockCheckAlreadyOpenException::class);
        StockCheckService::open(1, null, StockCheck::SCOPE_PRODUCTS, 8);
    }

    private function check(): StockCheck
    {
        return StockCheck::create([
            'company_id' => 1,
            'code' => 'SC-0001',
            'scope' => StockCheck::SCOPE_PRODUCTS,
            'status' => StockCheck::STATUS_COUNTING,
        ]);
    }

    private function line(StockCheck $check, int $itemId, float $expected): StockCheckLine
    {
        return StockCheckLine::create([
            'company_id' => 1,
            'stock_check_id' => $check->id,
            'item_type' => StockCheckLine::TYPE_PRODUCT,
            'item_id' => $itemId,
            'item_name' => 'QA Item ' . $itemId,
            'expected_quantity' => $expected,
            'unit_cost' => 10,
        ]);
    }
}

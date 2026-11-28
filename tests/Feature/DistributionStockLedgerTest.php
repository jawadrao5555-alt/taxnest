<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Services\DistributionStockLedger;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class DistributionStockLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_movements_use_exact_base_units_and_retries_keep_the_original_conversion(): void
    {
        [$actor, $warehouse, $item, $units] = $this->tenant('ONE');
        $ledger = new DistributionStockLedger;
        $key = (string) Str::uuid();
        $receipt = $ledger->record($actor, $warehouse, $units['carton'], '1.25', $key);
        $this->assertSame(30, $receipt->delta_base_units);
        $this->assertSame(30, $receipt->balance_after_base_units);
        $this->assertSame('1.250000', $receipt->quantity);
        $this->assertSame('carton', $receipt->unit_code);
        $this->assertSame(24, $receipt->base_units_per_unit);

        $dispatch = $ledger->record($actor, $warehouse, $units['pack'], '-1', (string) Str::uuid());
        $this->assertSame(-6, $dispatch->delta_base_units);
        $this->assertSame(24, $dispatch->balance_after_base_units);
        $piece = $ledger->record($actor, $warehouse, $units['piece'], '-3', (string) Str::uuid());
        $this->assertSame(21, $piece->balance_after_base_units);

        DB::table('distribution_item_units')->where('id', $units['carton'])->update(['base_units_per_unit' => 48]);
        $retry = $ledger->record($actor, $warehouse, $units['carton'], '1.2500', strtoupper($key));
        $this->assertSame($receipt->id, $retry->id);
        $this->assertSame(24, $retry->base_units_per_unit);
        $this->assertSame(30, $retry->balance_after_base_units);
        $this->assertDatabaseCount('distribution_stock_movements', 3);
        $this->assertDatabaseHas('distribution_stock_balances', [
            'company_id' => $actor->company_id, 'warehouse_id' => $warehouse, 'item_id' => $item, 'quantity_base_units' => 21,
        ]);

        foreach ([fn () => $receipt->update(['quantity' => '2.000000']), fn () => $receipt->delete()] as $mutation) {
            try {
                $mutation();
                $this->fail('Existing movements must remain append-only.');
            } catch (LogicException $exception) {
                $this->assertStringContainsString('append-only', $exception->getMessage());
            }
        }
    }

    public function test_invalid_and_conflicting_movements_leave_stock_unchanged(): void
    {
        [$actor, $warehouse, $item, $units] = $this->tenant('TWO');
        $ledger = new DistributionStockLedger;
        $key = (string) Str::uuid();
        $ledger->record($actor, $warehouse, $units['piece'], '3', $key);

        foreach (['0.4', '-0', '-4', '1E2', '0.0000001'] as $quantity) {
            $this->assertInvalid(fn () => $ledger->record($actor, $warehouse, $units['piece'], $quantity, (string) Str::uuid()), 'quantity');
        }
        $this->assertInvalid(fn () => $ledger->record($actor, $warehouse, $units['piece'], '4', $key), 'request_key');
        $this->assertInvalid(fn () => $ledger->record($actor, $warehouse, $units['piece'], '3', 'invalid'), 'request_key');
        $secondWarehouse = DB::table('distribution_warehouses')->insertGetId([
            'company_id' => $actor->company_id, 'code' => 'SECOND', 'name' => 'Synthetic second warehouse',
        ]);
        $this->assertInvalid(fn () => $ledger->record($actor, $secondWarehouse, $units['piece'], '3', $key), 'request_key');
        $this->assertDatabaseCount('distribution_stock_movements', 1);
        $this->assertDatabaseCount('distribution_stock_balances', 1);
        $this->assertDatabaseHas('distribution_stock_balances', [
            'company_id' => $actor->company_id, 'warehouse_id' => $warehouse, 'item_id' => $item, 'quantity_base_units' => 3,
        ]);
    }

    public function test_tenant_constraints_and_role_checks_contain_each_movement(): void
    {
        [$actor, $warehouse, $item, $units] = $this->tenant('THREE');
        [$other, $otherWarehouse, , $otherUnits] = $this->tenant('FOUR');
        $staff = User::factory()->create(['company_id' => $actor->company_id, 'role' => 'employee', 'is_active' => true]);
        $ledger = new DistributionStockLedger;
        $key = (string) Str::uuid();
        $ledger->record($actor, $warehouse, $units['piece'], '3', $key);
        $ledger->record($other, $otherWarehouse, $otherUnits['piece'], '4', $key);
        $inactive = User::factory()->create(['company_id' => $actor->company_id, 'role' => 'company_admin', 'is_active' => true]);
        User::query()->whereKey($inactive->id)->update(['is_active' => false]);

        foreach ([[$actor, $otherWarehouse, $units['piece'], 404], [$actor, $warehouse, $otherUnits['piece'], 404],
            [$staff, $warehouse, $units['piece'], 403], [$inactive, $warehouse, $units['piece'], 403]] as [$user, $targetWarehouse, $unit, $status]) {
            try {
                $ledger->record($user, $targetWarehouse, $unit, '5', (string) Str::uuid());
                $this->fail('An unauthorized movement was recorded.');
            } catch (HttpException $error) {
                $this->assertSame($status, $error->getStatusCode());
            }
        }
        $this->assertDatabaseCount('distribution_stock_balances', 2);
        $this->assertDatabaseCount('distribution_stock_movements', 2);

        $this->expectException(QueryException::class);
        DB::table('distribution_stock_balances')->insert([
            'company_id' => $actor->company_id, 'warehouse_id' => $otherWarehouse, 'item_id' => $item, 'quantity_base_units' => 1,
        ]);
    }

    public function test_rollback_of_new_schema_preserves_existing_inventory(): void
    {
        [$actor, $warehouse, , $units] = $this->tenant('FIVE');
        $product = Product::create(['company_id' => $actor->company_id, 'name' => 'Synthetic registered product', 'hs_code' => '0000.0000']);
        $legacyStock = ['company_id' => $actor->company_id, 'product_id' => $product->id, 'quantity' => 19];
        DB::table('inventory_stocks')->insert($legacyStock);

        (new DistributionStockLedger)->record($actor, $warehouse, $units['piece'], '2', (string) Str::uuid());
        $this->assertDatabaseHas('inventory_stocks', $legacyStock);
        $migration = require database_path('migrations/2027_01_01_000051_create_distribution_stock_ledger.php');
        $migration->down();
        try {
            $this->assertFalse(Schema::hasTable('distribution_stock_movements'));
            $this->assertFalse(Schema::hasTable('distribution_stock_balances'));
            $this->assertDatabaseHas('inventory_stocks', $legacyStock);
        } finally {
            $migration->up();
        }
        $this->assertDatabaseCount('distribution_stock_movements', 0);
    }

    private function tenant(string $code): array
    {
        $company = Company::create([
            'name' => 'Synthetic distribution '.$code, 'ntn' => 'SYNTH-'.$code,
            'product_type' => 'di', 'status' => 'approved', 'company_status' => 'active',
        ]);
        $actor = User::factory()->create(['company_id' => $company->id, 'role' => 'company_admin', 'is_active' => true]);
        $warehouse = DB::table('distribution_warehouses')->insertGetId([
            'company_id' => $company->id, 'code' => 'MAIN', 'name' => 'Synthetic warehouse',
        ]);
        $item = DB::table('distribution_items')->insertGetId([
            'company_id' => $company->id, 'sku' => 'ITEM-01', 'name' => 'Synthetic item', 'base_unit' => 'piece',
        ]);
        $units = [];
        foreach (['piece' => 1, 'pack' => 6, 'carton' => 24] as $unit => $factor) {
            $units[$unit] = DB::table('distribution_item_units')->insertGetId([
                'company_id' => $company->id, 'item_id' => $item, 'code' => $unit, 'base_units_per_unit' => $factor,
            ]);
        }

        return [$actor, $warehouse, $item, $units];
    }

    private function assertInvalid(callable $operation, string $field): void
    {
        try {
            $operation();
            $this->fail('An invalid movement was recorded.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey($field, $error->errors());
        }
    }
}

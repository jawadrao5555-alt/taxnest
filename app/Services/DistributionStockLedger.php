<?php

namespace App\Services;

use App\Models\DistributionStockMovement;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\Exception\MathException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class DistributionStockLedger
{
    public function record(User $actor, int $warehouseId, int $unitId, string $quantity, string $requestKey): DistributionStockMovement
    {
        $companyId = (int) $actor->company_id;
        abort_unless($actor->exists && $companyId && User::query()->whereKey($actor->getKey())
            ->where('company_id', $companyId)->where('role', 'company_admin')->where('is_active', true)->exists(), 403);
        if (! Str::isUuid($requestKey)) {
            throw ValidationException::withMessages(['request_key' => 'Use a valid request key.']);
        }
        if (! preg_match('/\A-?(0|[1-9]\d{0,11})(\.\d{1,6})?\z/', $quantity)) {
            throw ValidationException::withMessages(['quantity' => 'Enter an exact quantity with up to six decimal places.']);
        }
        $quantity = BigDecimal::of($quantity)->toScale(6);
        $requestKey = strtolower($requestKey);

        try {
            return DB::transaction(function () use ($actor, $companyId, $warehouseId, $unitId, $quantity, $requestKey) {
                // Serialize posts to the same warehouse, including its first stock movement.
                $warehouse = DB::table('distribution_warehouses')->where('company_id', $companyId)
                    ->where('id', $warehouseId)->lockForUpdate()->first();
                abort_unless($warehouse, 404);

                if ($existing = $this->replay($companyId, $requestKey, $warehouseId, $unitId, $quantity)) {
                    return $existing;
                }

                $unit = DB::table('distribution_item_units')->where('company_id', $companyId)
                    ->where('id', $unitId)->lockForUpdate()->first();
                abort_unless($unit, 404);
                $factor = (int) $unit->base_units_per_unit;
                if ($factor < 1 || $quantity->isZero()) {
                    throw ValidationException::withMessages(['quantity' => 'The quantity and unit conversion must produce a nonzero movement.']);
                }

                $stockKey = ['company_id' => $companyId, 'warehouse_id' => $warehouseId, 'item_id' => (int) $unit->item_id];
                $current = DB::table('distribution_stock_balances')->where($stockKey)->lockForUpdate()->first();
                try {
                    $delta = $quantity->multipliedBy($factor)->toBigInteger()->toInt();
                    $balance = BigInteger::of((string) ($current->quantity_base_units ?? 0))->plus($delta)->toInt();
                } catch (MathException) {
                    throw ValidationException::withMessages(['quantity' => 'The quantity must fit as an exact, whole number of base units.']);
                }
                if ($balance < 0) {
                    throw ValidationException::withMessages(['quantity' => 'There is not enough stock at this warehouse.']);
                }

                $movement = DistributionStockMovement::create($stockKey + [
                    'item_unit_id' => $unitId,
                    'request_key' => $requestKey,
                    'quantity' => (string) $quantity,
                    'unit_code' => $unit->code,
                    'base_units_per_unit' => $factor,
                    'delta_base_units' => $delta,
                    'balance_after_base_units' => $balance,
                    'recorded_by' => $actor->getKey(),
                ]);
                DB::table('distribution_stock_balances')->updateOrInsert($stockKey, ['quantity_base_units' => $balance]);

                return $movement;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            // A simultaneous retry for another warehouse can race the tenant-wide key.
            return $this->replay($companyId, $requestKey, $warehouseId, $unitId, $quantity) ?? throw $exception;
        }
    }

    private function replay(int $companyId, string $requestKey, int $warehouseId, int $unitId, BigDecimal $quantity): ?DistributionStockMovement
    {
        $previous = DistributionStockMovement::query()->where('company_id', $companyId)->where('request_key', $requestKey)->first();
        if ($previous && ((int) $previous->warehouse_id !== $warehouseId || (int) $previous->item_unit_id !== $unitId
            || $previous->quantity !== (string) $quantity)) {
            throw ValidationException::withMessages(['request_key' => 'This request key was already used for a different stock movement.']);
        }

        return $previous;
    }
}

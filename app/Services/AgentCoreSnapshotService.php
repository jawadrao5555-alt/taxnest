<?php

namespace App\Services;

use App\Models\AgentCoreScopeLease;
use App\Models\Company;
use App\Models\PosAgentDevice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Produces the only cloud -> Local Core bootstrap document.  Queries are
 * deliberately company scoped first and branch scoped whenever the source
 * table owns a branch column.
 */
class AgentCoreSnapshotService
{
    public function build(Company $company, int $branchId, string $deviceUid, int $leaseId, string $token): array
    {
        $lease = $this->authorize($company, $branchId, $deviceUid, $leaseId, $token);

        return DB::transaction(function () use ($company, $branchId, $deviceUid, $lease): array {
            $tables = [
                'products', 'pos_products', 'pos_tax_rules', 'inventory_stocks',
                'ingredients', 'ingredient_stocks', 'product_recipes',
                'customer_profiles', 'customer_ledgers', 'pos_customers',
                'restaurant_floors', 'restaurant_tables', 'restaurant_orders', 'restaurant_order_items',
                'pos_held_sales', 'pos_day_openings', 'pos_day_close_reports',
                'pos_expenses', 'expenses', 'pos_user_sessions',
            ];
            $rows = [];
            $revision = 1;
            foreach ($tables as $table) {
                $rows[$table] = $this->scopedRows($table, (int) $company->id, $branchId);
                foreach ($rows[$table] as $row) {
                    $stamp = isset($row['updated_at']) ? strtotime((string) $row['updated_at']) : false;
                    if ($stamp !== false) $revision = max($revision, $stamp * 1000);
                }
            }
            // The local `orders` projection means OPEN orders (held / preparing /
            // ready) — the ones a counter or waiter can still recall offline.
            // Closed history stays in the cloud (a busy shop has tens of
            // thousands of settled orders; shipping them made every snapshot
            // import grow without bound). The revision already covered every
            // row above, so it stays monotonic when an order leaves the set.
            $rows['restaurant_orders'] = array_values(array_filter($rows['restaurant_orders'],
                fn (array $row) => in_array((string) ($row['status'] ?? ''), ['held', 'preparing', 'ready'], true)));
            $openOrderIds = array_fill_keys(array_map(fn (array $row) => (string) $row['id'], $rows['restaurant_orders']), true);
            $rows['restaurant_order_items'] = array_values(array_filter($rows['restaurant_order_items'],
                fn (array $row) => isset($openOrderIds[(string) ($row['order_id'] ?? '')])));

            $user = User::query()->whereKey($lease->user_id)->where('company_id', $company->id)->firstOrFail();
            $cashDays = $this->cashDays($rows['pos_day_openings'], $rows['pos_day_close_reports'],
                array_merge($rows['pos_expenses'], $rows['expenses']));
            $today = now()->toDateString();
            $cashDays[$today] ??= ['business_date' => $today, 'opening_cash_cents' => 0,
                'expenses' => [], 'closed' => false];
            // Shape contract (Sep 2026): every key below must match what the
            // clients actually send in their frozen snapshots, or the domain
            // rejects offline holds with ingredient_not_found / table_not_found /
            // already_claimed while the internet-cut test (self-consistent mock)
            // stays green:
            //   • recipe parts + ingredient stock  → 'ingredient-<id>' (PosController / universal)
            //   • direct product consumption       → plain '<product_id>' (inventory_stocks)
            //   • catalog.tables = restaurant_tables rows (findEntity target);
            //     top-level tables = CLAIMS only (table_id → open order), never rows.
            $payload = [
                'catalog' => [
                    // POS products first: Local Core holds come from POS clients,
                    // and findEntity() returns the FIRST id match.
                    'products' => array_values(array_merge($rows['pos_products'], $rows['products'])),
                    'taxes' => $rows['pos_tax_rules'],
                    'ingredients' => $this->ingredientCatalog($rows['ingredients'], $rows['inventory_stocks'], $rows['pos_products']),
                    'tables' => array_values($rows['restaurant_tables']),
                    'floors' => array_values($rows['restaurant_floors']),
                ],
                'stock' => $this->quantities($rows['inventory_stocks'], $rows['ingredients'], $rows['ingredient_stocks']),
                'recipes' => $this->recipes($rows['product_recipes']),
                'customers' => $this->customers($rows['customer_profiles'], $rows['pos_customers'], $rows['customer_ledgers']),
                'tables' => $this->tableClaims($rows['restaurant_tables'], $rows['restaurant_orders']),
                'orders' => $this->orders($rows['restaurant_orders'], $rows['restaurant_order_items'], $rows['pos_held_sales']),
                'cash_days' => $cashDays,
                'staff_sessions' => $this->keyById($rows['pos_user_sessions']),
                'settings' => [
                    'allowed_actions' => array_values($lease->allowed_actions ?: []),
                    'permission_version' => $lease->permission_version,
                    'session_identity' => [
                        'user_id' => (string) $user->id,
                        'name' => (string) $user->name,
                        'role' => (string) ($user->pos_role ?: $user->role),
                    ],
                    // Offline KOT routing for the agent's local print drain
                    // (src/local-kot.js). Same source as the cloud's KotPrintService.
                    'print' => $this->printSettings($company),
                ],
            ];
            $scope = ['company_id' => (string) $company->id, 'branch_id' => (string) $branchId,
                'device_id' => $deviceUid, 'user_id' => (string) $lease->user_id];
            $canonical = ['schema' => 'local-core.snapshot.v1', 'revision' => $revision, 'scope' => $scope, 'payload' => $payload];

            return $canonical + [
                'hash_algorithm' => 'sha256',
                'hash' => hash('sha256', self::canonicalJson($canonical)),
                'generated_at' => now()->toIso8601String(),
                'mode' => 'full-refresh-merge',
            ];
        }, 3);
    }

    private function authorize(Company $company, int $branchId, string $deviceUid, int $leaseId, string $token): AgentCoreScopeLease
    {
        $device = PosAgentDevice::query()->where('company_id', $company->id)->where('device_uid', $deviceUid)->first();
        $lease = AgentCoreScopeLease::query()->whereKey($leaseId)->where('company_id', $company->id)
            ->where('branch_id', $branchId)->where('device_uid', $deviceUid)->first();
        $user = $lease ? User::query()->whereKey($lease->user_id)->where('company_id', $company->id)->first() : null;
        $version = $user ? hash('sha256', implode('|', [$user->updated_at?->getTimestamp(), $user->is_active,
            (string) ($user->pos_role ?: $user->role)])) : '';
        if (!$device || !$device->last_seen_at || $device->last_seen_at->lt(now()->subMinutes(2))
            || !$lease || !$token || !hash_equals((string) $lease->token_hash, hash('sha256', $token))
            || $lease->revoked_at || $lease->expires_at->isPast() || !$user || !$user->is_active
            || !hash_equals((string) $lease->permission_version, $version)) {
            throw ValidationException::withMessages(['scope_lease' => ['A fresh positive heartbeat and trusted scope lease are required.']]);
        }
        return $lease;
    }

    private function scopedRows(string $table, int $companyId, int $branchId): array
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'company_id')) return [];
        $query = DB::table($table)->where('company_id', $companyId);
        if (Schema::hasColumn($table, 'branch_id')) $query->where('branch_id', $branchId);
        return $query->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }

    private function keyById(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) $out[(string) $row['id']] = $row;
        return $out;
    }

    /**
     * Stock keyed exactly as the clients reference it in frozen consumption
     * parts: 'ingredient-<id>' for ingredients (their live balance lives on
     * ingredients.current_stock; an optional ingredient_stocks row wins when
     * present) and the bare product id for direct product consumption.
     */
    private function quantities(array $inventoryStocks, array $ingredients, array $ingredientStocks): array
    {
        $out = [];
        foreach ($inventoryStocks as $row) {
            if (!isset($row['product_id'])) continue;
            $out[(string) $row['product_id']] = ($out[(string) $row['product_id']] ?? 0) + (float) ($row['quantity'] ?? 0);
        }
        foreach ($ingredients as $row) {
            $out['ingredient-'.$row['id']] = (float) ($row['current_stock'] ?? $row['quantity'] ?? 0);
        }
        foreach ($ingredientStocks as $row) {
            if (!isset($row['ingredient_id'])) continue;
            $out['ingredient-'.$row['ingredient_id']] = (float) ($row['quantity'] ?? 0);
        }
        return $out;
    }

    /** Only ACTIVE recipe parts — the same filter the sale screens bake. */
    private function recipes(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (array_key_exists('is_active', $row) && !$row['is_active']) continue;
            $out[(string) $row['product_id']][] = [
                'stock_id' => 'ingredient-'.$row['ingredient_id'], 'quantity' => (float) $row['quantity_needed'],
                'version' => (int) ($row['recipe_version'] ?? 1),
            ];
        }
        return $out;
    }

    /**
     * catalog.ingredients — every consumable the domain may be asked to
     * reserve: ingredient rows under 'ingredient-<id>' plus a product-stock
     * entry under the bare product id for direct consumption.
     */
    private function ingredientCatalog(array $ingredients, array $inventoryStocks, array $products): array
    {
        $out = [];
        foreach ($ingredients as $row) {
            $out[] = array_merge($row, ['id' => 'ingredient-'.$row['id'], 'ingredient_id' => (int) $row['id'], 'kind' => 'ingredient']);
        }
        $names = [];
        foreach ($products as $product) $names[(string) $product['id']] = (string) ($product['name'] ?? '');
        $seen = [];
        foreach ($inventoryStocks as $row) {
            if (!isset($row['product_id']) || isset($seen[(string) $row['product_id']])) continue;
            $seen[(string) $row['product_id']] = true;
            $out[] = ['id' => (string) $row['product_id'], 'product_id' => (int) $row['product_id'], 'kind' => 'product',
                'name' => $names[(string) $row['product_id']] ?? '', 'updated_at' => $row['updated_at'] ?? null];
        }
        return $out;
    }

    /**
     * Top-level tables = CLAIMS (the domain's one-open-order-per-table rule),
     * derived from open restaurant orders and non-available table status —
     * never the raw table rows, which the domain would read as "every table
     * already claimed".
     */
    private function tableClaims(array $tables, array $orders): array
    {
        $out = [];
        foreach ($orders as $order) {
            if (empty($order['table_id']) || !in_array((string) ($order['status'] ?? ''), ['held', 'preparing', 'ready'], true)) continue;
            $tableId = (string) $order['table_id'];
            if (isset($out[$tableId])) continue;
            $stamp = isset($order['created_at']) ? strtotime((string) $order['created_at']) : false;
            $out[$tableId] = ['order_id' => (string) $order['id'], 'claimed_by' => null,
                'claimed_at_ms' => $stamp === false ? 0 : $stamp * 1000, 'table_revision' => null, 'source' => 'cloud'];
        }
        foreach ($tables as $table) {
            $status = (string) ($table['status'] ?? 'available');
            if ($status === 'available' || isset($out[(string) $table['id']])) continue;
            $out[(string) $table['id']] = ['order_id' => null, 'claimed_by' => null, 'claimed_at_ms' => 0,
                'table_revision' => null, 'source' => 'cloud', 'table_status' => $status];
        }
        return $out;
    }

    private function printSettings(Company $company): array
    {
        $settings = method_exists($company, 'printerSettings') ? (array) $company->printerSettings() : [];
        return [
            'silent_print_enabled' => (bool) ($settings['silent_print_enabled'] ?? false),
            'kot_printer' => ($settings['kot_printer'] ?? null) ?: null,
            'kot_printer_device' => ($settings['kot_printer_device'] ?? null) ?: null,
            'counter_kot_enabled' => (bool) ($settings['counter_kot_enabled'] ?? false),
            'counter_kot_printer' => ($settings['counter_kot_printer'] ?? null) ?: null,
            'kot_compact' => (bool) ($company->kot_compact ?? false),
            'kot_align_center' => (bool) ($company->kot_align_center ?? false),
            'kot_left_margin_mm' => max(0, min(30, (int) ($company->kot_left_margin_mm ?? 0))),
        ];
    }

    private function customers(array $profiles, array $pos, array $ledger): array
    {
        $out = $this->keyById(array_merge($profiles, $pos));
        foreach ($ledger as $entry) {
            $key = (string) ($entry['customer_id'] ?? $entry['customer_ntn'] ?? $entry['customer_name']);
            if (!isset($out[$key])) $out[$key] = ['id' => $key, 'name' => $entry['customer_name'] ?? $key, 'ledger' => []];
            $out[$key]['ledger'][] = $entry;
            $out[$key]['balance_cents'] = (int) round(((float) ($entry['balance_after'] ?? 0)) * 100);
        }
        return $out;
    }

    private function orders(array $orders, array $items, array $held): array
    {
        $out = $this->keyById($orders);
        foreach ($out as &$order) $order['lines'] = [];
        unset($order);
        foreach ($items as $item) if (isset($out[(string) $item['order_id']])) $out[(string) $item['order_id']]['lines'][] = $item;
        foreach ($held as $row) $out['held:'.$row['id']] = $row;
        return $out;
    }

    private function cashDays(array $openings, array $closed, array $expenses): array
    {
        $out = [];
        foreach ($openings as $row) {
            $date = substr((string) $row['business_date'], 0, 10);
            $out[$date] ??= ['business_date' => $date, 'opening_cash_cents' => 0, 'expenses' => [], 'closed' => false];
            $out[$date]['opening_cash_cents'] += (int) round(((float) $row['opening_cash']) * 100);
        }
        foreach ($expenses as $row) {
            $date = substr((string) ($row['business_date'] ?? $row['expense_date'] ?? $row['created_at'] ?? ''), 0, 10);
            if ($date !== '') { $out[$date] ??= ['business_date' => $date, 'opening_cash_cents' => 0, 'expenses' => [], 'closed' => false]; $out[$date]['expenses'][] = $row; }
        }
        foreach ($closed as $row) {
            $date = substr((string) $row['report_date'], 0, 10);
            $out[$date] ??= ['business_date' => $date, 'opening_cash_cents' => 0, 'expenses' => []];
            $out[$date]['closed'] = true;
        }
        return $out;
    }

    public static function canonicalJson(array $value): string
    {
        $sort = function ($item) use (&$sort) {
            if (!is_array($item)) return $item;
            if (array_is_list($item)) return array_map($sort, $item);
            ksort($item, SORT_STRING);
            foreach ($item as $key => $child) $item[$key] = $sort($child);
            return $item;
        };
        return json_encode($sort($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}

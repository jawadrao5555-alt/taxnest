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
                'restaurant_tables', 'restaurant_orders', 'restaurant_order_items',
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

            $user = User::query()->whereKey($lease->user_id)->where('company_id', $company->id)->firstOrFail();
            $cashDays = $this->cashDays($rows['pos_day_openings'], $rows['pos_day_close_reports'],
                array_merge($rows['pos_expenses'], $rows['expenses']));
            $today = now()->toDateString();
            $cashDays[$today] ??= ['business_date' => $today, 'opening_cash_cents' => 0,
                'expenses' => [], 'closed' => false];
            $payload = [
                'catalog' => [
                    'products' => array_values(array_merge($rows['products'], $rows['pos_products'])),
                    'taxes' => $rows['pos_tax_rules'],
                ],
                'stock' => $this->quantities($rows['inventory_stocks'], $rows['ingredient_stocks']),
                'recipes' => $this->recipes($rows['product_recipes']),
                'customers' => $this->customers($rows['customer_profiles'], $rows['pos_customers'], $rows['customer_ledgers']),
                'tables' => $this->keyById($rows['restaurant_tables']),
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

    private function quantities(array ...$sets): array
    {
        $out = [];
        foreach ($sets as $rows) foreach ($rows as $row) {
            $id = isset($row['ingredient_id']) ? 'ingredient:'.$row['ingredient_id'] : 'product:'.$row['product_id'];
            $out[$id] = (float) ($row['quantity'] ?? 0);
        }
        return $out;
    }

    private function recipes(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) $out[(string) $row['product_id']][] = [
            'stock_id' => 'ingredient:'.$row['ingredient_id'], 'quantity' => (float) $row['quantity_needed'],
            'version' => (int) ($row['recipe_version'] ?? 1),
        ];
        return $out;
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

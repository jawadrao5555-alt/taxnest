<?php

namespace App\Services\Reporting;

use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Validation\ValidationException;

/** Bounded SELECT-only associations. Record presence never proves acceptance. */
final class DiagnosticBillEvidence
{
    public function read(array $args, ?array $allowed): array
    {
        if (array_diff(array_keys($args), ['company_name', 'company_id', 'bill_id', 'after_id', 'limit'])) {
            throw ValidationException::withMessages(['arguments' => 'Unsupported bill evidence filter.']);
        }
        $v = validator($args, ['company_name' => 'required|string|min:2|max:150',
            'company_id' => 'nullable|integer|min:1', 'bill_id' => 'required|integer|min:1',
            'after_id' => 'nullable|integer|min:0', 'limit' => 'nullable|integer|min:1|max:50'])->validate();
        $q = DB::table('companies')->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($v['company_name']))]);
        if (Schema::hasColumn('companies', 'deleted_at')) $q->whereNull('deleted_at');
        if ($allowed !== null) $q->whereIn('id', $allowed);
        if (isset($v['company_id'])) $q->where('id', $v['company_id']);
        $companies = $q->limit(2)->get(['id', 'name', 'product_type']);
        if ($companies->count() !== 1) throw ValidationException::withMessages(['company_name' => 'Exact company is ambiguous, unavailable or outside this connection scope.']);
        $company = $companies->sole();
        $product = \App\Support\NestErps::canonicalProductType($company->product_type);
        $root = match ($product) {
            'pos' => 'pos_transactions', 'di' => 'invoices', 'fbrpos' => 'fbr_pos_transactions', 'erps' => 'health_bills',
            default => null,
        };
        $base = ['version' => 1, 'company' => ['id' => (int) $company->id, 'name' => $company->name, 'product' => $product],
            'bill_id' => (int) $v['bill_id'], 'complete' => false, 'physical_print' => 'unknown', 'fiscal_acceptance' => 'not_assessed',
            'notes' => ['Only explicit stored foreign-key/reference links are included. Matching numbers, amounts or timestamps are never treated as associations.',
                'Each source has its own immutable ID cursor and may have late arrivals. Missing/deleted/uncollected evidence remains unknown.',
                'No customer/patient details, amounts, payloads, credentials, raw errors or physical/fiscal success claims are returned.']];
        if (!$root || !$this->columns($root, ['id', 'company_id'])) return $base + ['status' => 'UNAVAILABLE', 'sources' => []];
        $bill = DB::table($root)->where('company_id', $company->id)->where('id', $v['bill_id'])->exists();
        if (!$bill) throw ValidationException::withMessages(['bill_id' => 'Bill is unavailable in this company. Deleted records are not reconstructed.']);

        $definitions = match ($product) {
            'pos' => [
                'payments' => ['pos_payments', 'transaction_id', false, []],
                'orders' => ['restaurant_orders', 'pos_transaction_id', true, []],
                'print_events' => ['pos_print_evidence', 'transaction_id', true, ['product_type' => 'pos']],
                'stock' => ['inventory_movements', 'reference_id', true, ['reference_type' => 'pos_transaction']],
                'pra_attempts' => ['pra_logs', 'transaction_id', true, []],
            ],
            'di' => ['fbr_attempts' => ['fbr_logs', 'invoice_id', false, []]],
            'fbrpos' => [
                'fbr_attempts' => ['fbr_pos_logs', 'transaction_id', true, []],
                'stock' => ['inventory_movements', 'reference_id', true, ['reference_type' => 'fbr_pos_transaction']],
            ],
            'erps' => ['payments' => ['health_payments', 'health_bill_id', true, []]],
        };
        $sources = [];
        foreach ($definitions as $key => [$table, $foreign, $tenantColumn, $fixed]) {
            $required = array_merge(['id', $foreign], $tenantColumn ? ['company_id'] : [], array_keys($fixed));
            if (!$this->columns($table, $required)) {
                $sources[$key] = ['status' => 'UNAVAILABLE', 'rows' => [], 'has_more' => false, 'next_after_id' => null];
                continue;
            }
            // No-company child tables are scoped by the already-verified parent
            // identity; tables carrying company_id must satisfy both links.
            $query = DB::table($table)->where($foreign, $v['bill_id'])->where('id', '>', $v['after_id'] ?? 0);
            if ($tenantColumn) $query->where('company_id', $company->id);
            foreach ($fixed as $column => $value) $query->where($column, $value);
            $limit = (int) ($v['limit'] ?? 25);
            $ids = $query->orderBy('id')->limit($limit + 1)->pluck('id');
            $more = $ids->count() > $limit;
            $page = $ids->take($limit);
            $sources[$key] = ['status' => $ids->isEmpty() ? 'NO_RETAINED_LINKS' : 'PARTIAL',
                'rows' => $page->map(fn ($id) => ['id' => (int) $id])->values()->all(),
                'has_more' => $more, 'next_after_id' => $more ? (int) $page->last() : null];
        }

        return $base + ['status' => 'PARTIAL', 'sources' => $sources,
            'unimplemented_links' => match ($product) {
                'pos' => ['offline_agent_events', 'federal_ims_attempts', 'order_only_print_events'],
                'di' => ['payments', 'stock', 'print_events'],
                'fbrpos' => ['payments', 'print_events', 'offline_agent_events'],
                'erps' => ['clinical_events', 'pharmacy_stock', 'print_events'],
            }];
    }

    private function columns(string $table, array $columns): bool
    {
        return Schema::hasTable($table) && !array_diff($columns, Schema::getColumnListing($table));
    }
}

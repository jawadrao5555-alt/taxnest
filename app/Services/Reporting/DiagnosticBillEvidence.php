<?php

namespace App\Services\Reporting;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
        if (Schema::hasColumn('companies', 'deleted_at')) {
            $q->whereNull('deleted_at');
        }
        if ($allowed !== null) {
            $q->whereIn('id', $allowed);
        }
        if (isset($v['company_id'])) {
            $q->where('id', $v['company_id']);
        }
        $companies = $q->limit(2)->get(['id', 'name', 'product_type']);
        if ($companies->count() !== 1) {
            throw ValidationException::withMessages(['company_name' => 'Exact company is ambiguous, unavailable or outside this connection scope.']);
        }
        $company = $companies->sole();
        $product = \App\Support\NestErps::canonicalProductType($company->product_type);
        $root = match ($product) {
            'pos' => 'pos_transactions', 'di' => 'invoices', 'fbrpos' => 'fbr_pos_transactions', 'erps' => 'health_bills',
            default => null,
        };
        $base = ['version' => 4, 'company' => ['id' => (int) $company->id, 'name' => $company->name, 'product' => $product],
            'bill_id' => (int) $v['bill_id'], 'complete' => false, 'physical_print' => 'unknown', 'fiscal_acceptance' => 'not_assessed',
            'notes' => ['Only explicit stored foreign-key/reference links are included. Matching numbers, amounts or timestamps are never treated as associations.',
                'Each source has its own immutable ID cursor and may have late arrivals. Missing/deleted/uncollected evidence remains unknown.',
                'No customer/patient details, amounts, payloads, credentials, raw errors or physical/fiscal success claims are returned.']];
        if (! $root || ! $this->columns($root, ['id', 'company_id'])) {
            return $base + ['status' => 'UNAVAILABLE', 'sources' => []];
        }
        $bill = DB::table($root)->where('company_id', $company->id)->where('id', $v['bill_id'])->exists();
        if (! $bill) {
            throw ValidationException::withMessages(['bill_id' => 'Bill is unavailable in this company. Deleted records are not reconstructed.']);
        }

        $definitions = match ($product) {
            'pos' => [
                'payments' => ['pos_payments', 'transaction_id', false, []],
                'orders' => ['restaurant_orders', 'pos_transaction_id', true, []],
                'print_events' => ['pos_print_evidence', 'transaction_id', true, ['product_type' => 'pos']],
                'stock' => ['inventory_movements', 'reference_id', true, ['reference_type' => 'pos_transaction']],
                'pra_attempts' => ['pra_logs', 'transaction_id', true, []],
                'federal_ims_attempts' => ['pos_ims_submissions', 'source_transaction_id', true, []],
                'federal_di_attempts' => ['pos_di_sandbox_submissions', 'source_transaction_id', true, []],
                'order_only_print_events' => ['pos_print_evidence', 'order_id', true, ['product_type' => 'pos']],
            ],
            'di' => ['fbr_attempts' => ['fbr_logs', 'invoice_id', false, []]],
            'fbrpos' => [
                'fbr_attempts' => ['fbr_pos_logs', 'transaction_id', true, []],
                'stock' => ['inventory_movements', 'reference_id', true, ['reference_type' => 'fbr_pos_transaction']],
                'callbacks' => ['fbr_pos_callback_diagnostics', 'transaction_id', true, []],
                'print_events' => ['pos_print_evidence', 'transaction_id', true, ['product_type' => 'fbrpos']],
            ],
            'erps' => [
                'payments' => ['health_payments', 'health_bill_id', true, []],
                'bill_lines' => ['health_bill_lines', 'health_bill_id', true, []],
                'charges' => ['health_charges', 'health_bill_id', true, []],
                'fbr_attempts' => ['health_fbr_submissions', 'health_bill_id', true, []],
                'bill_audit_events' => ['health_audit_events', 'entity_id', true, ['entity_type' => 'health_bills']],
            ],
        };
        $sources = [];
        foreach ($definitions as $key => [$table, $foreign, $tenantColumn, $fixed]) {
            try {
                $required = array_merge(['id', $foreign], $tenantColumn ? ['company_id'] : [], array_keys($fixed));
                if (! $this->columns($table, $required)) {
                    $sources[$key] = ['status' => 'UNAVAILABLE', 'rows' => [], 'has_more' => false, 'next_after_id' => null];

                    continue;
                }
                // No-company child tables are scoped by the already-verified parent
                // identity; tables carrying company_id must satisfy both links.
                $query = DB::table($table)->where('id', '>', $v['after_id'] ?? 0);
                if ($key === 'order_only_print_events') {
                    if (! $this->columns('restaurant_orders', ['id', 'company_id', 'pos_transaction_id'])
                        || ! $this->columns($table, ['transaction_id'])) {
                        $sources[$key] = ['status' => 'UNAVAILABLE', 'rows' => [], 'has_more' => false, 'next_after_id' => null];

                        continue;
                    }
                    $orders = DB::table('restaurant_orders')->where('company_id', $company->id)->where('pos_transaction_id', $v['bill_id'])->select('id');
                    $query->whereNull('transaction_id')->whereIn('order_id', $orders);
                } else {
                    $query->where($foreign, $v['bill_id']);
                }
                if ($tenantColumn) {
                    $query->where('company_id', $company->id);
                }
                foreach ($fixed as $column => $value) {
                    $query->where($column, $value);
                }
                $limit = (int) ($v['limit'] ?? 25);
                $ids = $query->orderBy('id')->limit($limit + 1)->pluck('id');
                $more = $ids->count() > $limit;
                $page = $ids->take($limit);
                $sources[$key] = ['status' => $ids->isEmpty() ? 'NO_RETAINED_LINKS' : 'PARTIAL',
                    'rows' => $page->map(fn ($id) => ['id' => (int) $id])->values()->all(),
                    'has_more' => $more, 'next_after_id' => $more ? (int) $page->last() : null];
            } catch (\Illuminate\Database\QueryException) {
                $sources[$key] = ['status' => 'SOURCE_QUERY_FAILED', 'rows' => [], 'has_more' => false, 'next_after_id' => null];
            }
        }

        // Healthcare charges freeze the source type and immutable source ID
        // that produced a bill line. Follow that explicit link to the original
        // clinical/pharmacy row, but require both the charge and the source row
        // to belong to the already-authorized company. Never infer a relation
        // from patient, amount, reference text or timestamps.
        if ($product === 'erps') {
            foreach ([
                'clinical_visits' => ['health_visits', 'visit'],
                'pharmacy_sales' => ['health_pharmacy_sales', 'pharmacy_sale'],
                'admission_charges' => ['health_admission_charges', 'admission_charge'],
                'operations' => ['health_operations', 'operation'],
            ] as $key => [$table, $sourceType]) {
                try {
                    if (! $this->columns('health_charges', ['id', 'company_id', 'health_bill_id', 'source_type', 'source_id'])
                        || ! $this->columns($table, ['id', 'company_id'])) {
                        $sources[$key] = ['status' => 'UNAVAILABLE', 'rows' => [], 'has_more' => false, 'next_after_id' => null];

                        continue;
                    }
                    $limit = (int) ($v['limit'] ?? 25);
                    $ids = DB::table($table.' as source')
                        ->join('health_charges as charge', function ($join) use ($company, $sourceType) {
                            $join->on('charge.source_id', '=', 'source.id')
                                ->where('charge.company_id', '=', $company->id)
                                ->where('charge.source_type', '=', $sourceType);
                        })
                        ->where('source.company_id', $company->id)
                        ->where('charge.health_bill_id', $v['bill_id'])
                        ->where('source.id', '>', $v['after_id'] ?? 0)
                        ->select('source.id')->distinct()->orderBy('source.id')->limit($limit + 1)->pluck('id');
                    $more = $ids->count() > $limit;
                    $page = $ids->take($limit);
                    $sources[$key] = ['status' => $ids->isEmpty() ? 'NO_RETAINED_LINKS' : 'PARTIAL',
                        'rows' => $page->map(fn ($id) => ['id' => (int) $id])->values()->all(),
                        'has_more' => $more, 'next_after_id' => $more ? (int) $page->last() : null];
                } catch (\Illuminate\Database\QueryException) {
                    $sources[$key] = ['status' => 'SOURCE_QUERY_FAILED', 'rows' => [], 'has_more' => false, 'next_after_id' => null];
                }
            }

            // A pharmacy charge freezes the sale ID that produced the bill.
            // Stock movements independently freeze that same sale ID as their
            // reference. Follow the complete charge -> sale -> movement chain
            // and require every retained row to belong to this tenant. Amount,
            // medicine, batch, reference text and timestamps are never used to
            // guess or expose a relationship.
            try {
                if (! $this->columns('health_charges', ['id', 'company_id', 'health_bill_id', 'source_type', 'source_id'])
                    || ! $this->columns('health_pharmacy_sales', ['id', 'company_id'])
                    || ! $this->columns('health_batch_movements', ['id', 'company_id', 'reference_type', 'reference_id'])) {
                    $sources['pharmacy_stock'] = ['status' => 'UNAVAILABLE', 'rows' => [], 'has_more' => false, 'next_after_id' => null];
                } else {
                    $limit = (int) ($v['limit'] ?? 25);
                    $ids = DB::table('health_batch_movements as movement')
                        ->join('health_pharmacy_sales as sale', function ($join) use ($company) {
                            $join->on('sale.id', '=', 'movement.reference_id')
                                ->where('sale.company_id', '=', $company->id);
                        })
                        ->join('health_charges as charge', function ($join) use ($company) {
                            $join->on('charge.source_id', '=', 'sale.id')
                                ->where('charge.company_id', '=', $company->id)
                                ->where('charge.source_type', '=', 'pharmacy_sale');
                        })
                        ->where('movement.company_id', $company->id)
                        ->where('movement.reference_type', 'health_pharmacy_sale')
                        ->where('charge.health_bill_id', $v['bill_id'])
                        ->where('movement.id', '>', $v['after_id'] ?? 0)
                        ->select('movement.id')->distinct()->orderBy('movement.id')->limit($limit + 1)->pluck('id');
                    $more = $ids->count() > $limit;
                    $page = $ids->take($limit);
                    $sources['pharmacy_stock'] = ['status' => $ids->isEmpty() ? 'NO_RETAINED_LINKS' : 'PARTIAL',
                        'rows' => $page->map(fn ($id) => ['id' => (int) $id])->values()->all(),
                        'has_more' => $more, 'next_after_id' => $more ? (int) $page->last() : null];
                }
            } catch (\Illuminate\Database\QueryException) {
                $sources['pharmacy_stock'] = ['status' => 'SOURCE_QUERY_FAILED', 'rows' => [], 'has_more' => false, 'next_after_id' => null];
            }
        }

        return $base + ['status' => 'PARTIAL', 'sources' => $sources,
            'unimplemented_links' => match ($product) {
                'pos' => ['offline_agent_events'],
                'di' => ['payments', 'stock', 'print_events'],
                'fbrpos' => ['payments', 'offline_agent_events'],
                'erps' => ['lab_orders', 'print_events'],
            }];
    }

    private function columns(string $table, array $columns): bool
    {
        return Schema::hasTable($table) && ! array_diff($columns, Schema::getColumnListing($table));
    }
}

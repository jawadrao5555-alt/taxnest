<?php

namespace App\Services\LiveOps;

use App\Models\Company;
use App\Models\PosTransaction;
use App\Services\PosTaxMath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Company-scoped, redacted, read-only proof for one POS invoice calculation.
 *
 * No customer/item names, fiscal payloads, credentials or arbitrary SQL are
 * returned. The stored bill snapshot is authoritative; current company
 * settings are deliberately not used to explain a historical bill.
 */
final class LiveOpsInvoiceDiagnostic
{
    public function run(int $companyId, ?string $invoiceReference): array
    {
        $reference = trim((string) $invoiceReference);
        if ($reference === '' || ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._\/-]{0,79}\z/', $reference)) {
            throw new \InvalidArgumentException('invoice_reference is required (1-80 safe characters)');
        }

        $company = Company::query()
            ->whereIn('product_type', config('live_ops.product_types', ['pos']))
            ->whereKey($companyId)
            ->first();
        if (! $company) {
            throw new \InvalidArgumentException("Company {$companyId} not found in NestPOS PRA scope");
        }
        if (! Schema::hasTable('pos_transactions') || ! Schema::hasColumn('pos_transactions', 'invoice_number')) {
            throw new \InvalidArgumentException('POS invoice records are unavailable');
        }

        $candidateColumns = [
            'id', 'company_id', 'invoice_number', 'business_date', 'created_at',
            'status', 'transaction_type', 'payment_method', 'subtotal',
            'discount_type', 'discount_value', 'discount_amount', 'tax_rate',
            'tax_amount', 'exempt_amount', 'total_amount', 'tax_inclusive',
            'tax_menu_rate', 'pra_status', 'pra_invoice_number', 'is_archived',
        ];
        $columns = array_values(array_filter(
            $candidateColumns,
            fn (string $column): bool => Schema::hasColumn('pos_transactions', $column)
        ));

        $matches = PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $companyId)
            ->where('invoice_number', $reference)
            ->limit(2)->get($columns);
        if ($matches->count() > 1) {
            throw new \InvalidArgumentException('Invoice reference matches multiple saved invoices; calculation cannot be confirmed');
        }
        $bill = $matches->first();
        if (! $bill) {
            throw new \InvalidArgumentException("Invoice {$reference} not found for the selected company");
        }

        $lineSummary = $this->lineSummary((int) $bill->id);
        $inclusiveAvailable = in_array('tax_inclusive', $columns, true) && $bill->tax_inclusive !== null;
        $inclusive = $inclusiveAvailable ? (bool) $bill->tax_inclusive : null;
        $rate = $this->money($bill->tax_rate ?? null);
        $menuRate = $this->money($bill->tax_menu_rate ?? null);
        $mode = ! $inclusiveAvailable
            ? 'unknown'
            : (! $inclusive
                ? 'exclusive'
                : ($menuRate !== null && $menuRate > 0 && $rate !== null && abs($menuRate - $rate) >= 0.005
                    ? 'inclusive_card_save'
                    : 'inclusive'));

        $discount = $this->money($bill->discount_amount ?? null);
        $gaps = [];
        if (! $inclusiveAvailable) {
            $gaps[] = 'tax_inclusive';
        }
        if (! $lineSummary['available']) {
            $gaps[] = 'complete_item_lines_and_exemption_flags';
        }
        foreach (['discount_amount', 'tax_rate', 'subtotal', 'tax_amount', 'total_amount'] as $key) {
            if ($this->money($bill->{$key} ?? null) === null) {
                $gaps[] = $key;
            }
        }
        $recalculation = null;
        $confirmed = false;
        if ($inclusive === true && $lineSummary['available'] && $rate !== null && $discount !== null) {
            $expected = PosTaxMath::inclusiveHeader(
                $lineSummary['menu_total'],
                $lineSummary['taxable_menu_total'],
                $discount,
                $rate,
                $mode === 'inclusive_card_save' ? $menuRate : null,
            );
            $stored = [
                'subtotal' => $this->money($bill->subtotal ?? null),
                'tax_amount' => $this->money($bill->tax_amount ?? null),
                'total_amount' => $this->money($bill->total_amount ?? null),
            ];
            $deltas = [
                'subtotal' => $this->delta($stored['subtotal'], $expected['subtotal_col']),
                'tax_amount' => $this->delta($stored['tax_amount'], $expected['tax_amount']),
                'total_amount' => $this->delta($stored['total_amount'], $expected['total_amount']),
            ];
            $confirmed = $mode === 'inclusive_card_save'
                && collect($deltas)->every(fn (?float $delta): bool => $delta !== null && $delta <= 0.01);
            $recalculation = [
                'expected_from_saved_lines' => [
                    'subtotal' => $this->money($expected['subtotal_col']),
                    'tax_amount' => $this->money($expected['tax_amount']),
                    'total_amount' => $this->money($expected['total_amount']),
                ],
                'stored_minus_expected_absolute' => $deltas,
                'matches_saved_snapshot' => collect($deltas)
                    ->every(fn (?float $delta): bool => $delta !== null && $delta <= 0.01),
            ];
        }

        return [
            'company' => ['id' => (int) $company->id, 'name' => (string) $company->name],
            'invoice' => [
                'transaction_id' => (int) $bill->id,
                'invoice_number' => (string) $bill->invoice_number,
                'business_date' => $bill->business_date ?? null,
                'created_at' => optional($bill->created_at)->toIso8601String(),
                'status' => $bill->status ?? null,
                'transaction_type' => $bill->transaction_type ?? null,
                'payment_method' => $bill->payment_method ?? null,
                'archived' => isset($bill->is_archived) ? (bool) $bill->is_archived : null,
                'pra_status' => $bill->pra_status ?? null,
                'has_pra_fiscal_number' => trim((string) ($bill->pra_invoice_number ?? '')) !== '',
            ],
            'saved_calculation' => [
                'menu_total_from_lines' => $lineSummary['available'] ? $lineSummary['menu_total'] : null,
                'taxable_menu_total_from_lines' => $lineSummary['available'] ? $lineSummary['taxable_menu_total'] : null,
                'item_line_count' => $lineSummary['available'] ? $lineSummary['line_count'] : null,
                'subtotal' => $this->money($bill->subtotal ?? null),
                'discount_type' => $bill->discount_type ?? null,
                'discount_value' => $this->money($bill->discount_value ?? null),
                'discount_amount' => $this->money($bill->discount_amount ?? null),
                'tax_rate' => $rate,
                'tax_amount' => $this->money($bill->tax_amount ?? null),
                'exempt_amount' => $this->money($bill->exempt_amount ?? null),
                'total_amount' => $this->money($bill->total_amount ?? null),
            ],
            'pricing_snapshot' => [
                'tax_inclusive' => $inclusive,
                'menu_tax_rate' => $menuRate,
                'payment_tax_rate' => $rate,
                'inferred_mode' => $mode,
                'source' => 'stored_bill_snapshot',
            ],
            'evidence_gaps' => $gaps,
            'recalculation' => $recalculation,
            'finding' => [
                'card_save_confirmed_from_saved_snapshot' => $confirmed,
                'reason' => $confirmed
                    ? 'Saved invoice lines, menu rate, payment rate and stored totals reproduce inclusive_card_save within one paisa.'
                    : 'Saved evidence does not fully prove inclusive_card_save; inspect unavailable or mismatched fields.',
            ],
            'privacy' => [
                'customer_details_returned' => false,
                'item_names_returned' => false,
                'fiscal_payload_returned' => false,
            ],
        ];
    }

    /** @return array{available:bool,line_count:int,menu_total:float,taxable_menu_total:float} */
    private function lineSummary(int $transactionId): array
    {
        if (! Schema::hasTable('pos_transaction_items')
            || ! Schema::hasColumn('pos_transaction_items', 'transaction_id')
            || ! Schema::hasColumn('pos_transaction_items', 'subtotal')) {
            return ['available' => false, 'line_count' => 0, 'menu_total' => 0.0, 'taxable_menu_total' => 0.0];
        }

        $hasExempt = Schema::hasColumn('pos_transaction_items', 'is_tax_exempt');
        $rows = DB::table('pos_transaction_items')
            ->where('transaction_id', $transactionId)
            ->get($hasExempt ? ['subtotal', 'is_tax_exempt'] : ['subtotal']);

        return [
            'available' => $hasExempt && $rows->isNotEmpty() && $rows->every(
                fn ($row): bool => $this->money($row->subtotal) !== null
                    && in_array($row->is_tax_exempt, [0, 1, '0', '1', false, true], true)
            ),
            'line_count' => $rows->count(),
            'menu_total' => round((float) $rows->sum(fn ($row) => (float) $row->subtotal), 2),
            'taxable_menu_total' => round((float) $rows->sum(
                fn ($row) => $hasExempt && (bool) $row->is_tax_exempt ? 0.0 : (float) $row->subtotal
            ), 2),
        ];
    }

    private function money(mixed $value): ?float
    {
        return ! is_numeric($value) || ! is_finite((float) $value) ? null : round((float) $value, 2);
    }

    private function delta(?float $stored, float $expected): ?float
    {
        return $stored === null ? null : round(abs($stored - $expected), 2);
    }
}

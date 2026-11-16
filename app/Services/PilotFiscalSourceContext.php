<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Internal source evidence, NOT an IMS/DI payload or permission to dispatch. */
final class PilotFiscalSourceContext
{
    public static function inspect(int $companyId, string $category, string $source, int $id): array
    {
        // Reuse the scoped immutable-route boundary before reading monetary data.
        $plan = PilotFiscalRouteSnapshot::recoveryPlan($companyId, $category, $source, $id);
        $base = ['routing' => $plan, 'can_dispatch' => false, 'regulator_payload' => null,
            'reportable_event' => null, 'tax_mapping' => null,
            'blockers' => ['category_reportable_event_unverified', 'category_tax_mapping_unverified', 'category_adapter_unavailable']];
        if ($source === 'school_receipt') {
            $row = DB::table('school_fee_receipts')->where('company_id', $companyId)->where('id', $id)->firstOrFail();
            return $base + ['amounts' => [
                'charge_paisa' => self::nullableAmount($row->payable_paisa ?? null),
                'current_payment_paisa' => (int) $row->amount_paisa,
                'paid_at_receipt_paisa' => self::nullableAmount($row->paid_paisa ?? null),
                'balance_at_receipt_paisa' => self::nullableAmount($row->balance_paisa ?? null),
            ], 'local_lines' => self::lines($row->fee_lines ?? null), 'line_semantics' => 'fee_charge_not_installment'];
        }
        $payment = $source === 'hospital_payment'
            ? DB::table('hospital_payments')->where('company_id', $companyId)->where('id', $id)->firstOrFail() : null;
        $invoice = DB::table('hospital_invoices')->where('company_id', $companyId)
            ->where('id', $payment ? $payment->invoice_id : $id)->first();
        abort_unless($invoice, 404);
        // A bill has no implied payment. An installment preserves its historical balance,
        // never today's aggregate (later installments must not rewrite earlier evidence).
        return $base + ['amounts' => [
            'charge_paisa' => (int) $invoice->total_paisa,
            'current_payment_paisa' => $payment ? (int) $payment->amount_paisa : null,
            'paid_at_receipt_paisa' => $payment ? (int) $invoice->total_paisa - (int) $payment->balance_paisa : null,
            'balance_at_receipt_paisa' => $payment ? (int) $payment->balance_paisa : null,
        ], 'local_lines' => self::lines($invoice->service_lines ?? null), 'line_semantics' => 'service_bill_not_installment'];
    }

    private static function nullableAmount(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private static function lines(?string $value): ?array
    {
        if ($value === null) return null;
        try { $lines = json_decode($value, true, 512, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { return null; }
        return is_array($lines) && array_is_list($lines) ? $lines : null;
    }
}

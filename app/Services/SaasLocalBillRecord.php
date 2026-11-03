<?php

namespace App\Services;

use App\Models\PosTransaction;
use Illuminate\Support\Facades\{Crypt, DB, Schema};
use RuntimeException;

/** Platform records are independent of company-visible bills and optional support copies. */
class SaasLocalBillRecord
{
    public function capture(PosTransaction $bill): void
    {
        // Rolling deployment: old workers/schema keep their existing behavior.
        if (!Schema::hasTable('saas_local_bill_records')) return;
        if (DB::transactionLevel() < 1) throw new RuntimeException('SaaS bill capture requires the deletion transaction.');

        // Same company lock as sale allocation, cleanup and the admin retention action.
        DB::table('companies')->where('id', $bill->company_id)->lockForUpdate()->first();
        $policy = DB::table('saas_local_bill_retention')->where('company_id', $bill->company_id)->lockForUpdate()->first();
        if ($policy && !$policy->retain) return;

        $current = PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $bill->company_id)->whereKey($bill->id)->lockForUpdate()->firstOrFail();
        if ($current->pra_invoice_number || $current->status !== 'completed'
            || ($current->transaction_type ?? 'sale') !== 'sale'
            || !in_array($current->pra_status, [null, 'local'], true)) return;
        // Never copy regulator credentials, request payloads, share/tracking tokens,
        // payment references or customer location. Keep the bill's business facts.
        $header = array_intersect_key($current->getAttributes(), array_flip([
            'id', 'company_id', 'branch_id', 'terminal_id', 'invoice_number', 'business_date',
            'created_at', 'created_by', 'status', 'invoice_mode', 'transaction_type',
            'customer_id', 'customer_name', 'customer_phone', 'customer_address', 'delivery_address', 'notes',
            'subtotal', 'discount_type', 'discount_value', 'discount_amount', 'tax_rate',
            'tax_amount', 'total_amount', 'exempt_amount', 'payment_method', 'cash_received',
            'change_due', 'order_type', 'rider_id', 'rider_settlement_id', 'rider_settled_at',
            'delivery_status', 'delivery_charges', 'tax_inclusive', 'tax_menu_rate',
        ]));
        $items = $current->items()->orderBy('id')->get()->map(fn ($item) =>
            array_intersect_key($item->getAttributes(), array_flip([
                'id', 'item_type', 'item_id', 'item_name', 'quantity', 'unit_price', 'cost_price',
                'subtotal', 'tax_rate', 'tax_amount', 'is_tax_exempt', 'discount',
                'item_discount_type', 'item_discount_value', 'item_discount_amount',
                'deal_group', 'deal_id', 'deal_name', 'deal_quantity', 'deal_unit_price', 'deal_snapshot',
            ])))->all();
        $payments = Schema::hasTable('pos_payments')
            ? DB::table('pos_payments')->where('transaction_id', $current->id)->orderBy('id')
                ->get(['payment_method', 'amount'])->map(fn ($p) => (array) $p)->all()
            : [];
        $json = json_encode(['version' => 1, 'header' => $header, 'items' => $items, 'payments' => $payments],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $hash = hash('sha256', $json);
        $existing = DB::table('saas_local_bill_records')->where('company_id', $current->company_id)
            ->where('source_id', $current->id)->first();
        if ($existing) {
            if (!hash_equals($existing->payload_hash, $hash)) throw new RuntimeException('SaaS bill record collision; cleanup cancelled.');
            return;
        }
        DB::table('saas_local_bill_records')->insert([
            'company_id' => $current->company_id, 'source_id' => $current->id,
            'branch_id' => $current->branch_id, 'reference' => $current->invoice_number,
            'business_date' => $current->business_date ?: $current->created_at?->toDateString(),
            'total_amount' => $current->total_amount,
            'encrypted_payload' => Crypt::encryptString($json), 'payload_hash' => $hash,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function read(int $companyId, int $id): array
    {
        $record = DB::table('saas_local_bill_records')->where('company_id', $companyId)->where('id', $id)->first();
        abort_unless($record, 404);
        $json = Crypt::decryptString($record->encrypted_payload);
        if (!hash_equals($record->payload_hash, hash('sha256', $json))) throw new RuntimeException('SaaS record integrity check failed.');
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }
}

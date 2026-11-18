<?php

namespace App\Services;

use App\Models\Company;
use App\Models\FbrPosTransaction;
use App\Models\FbrPosTransactionItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adapts one finalized School payment receipt to the existing standalone
 * FBR POS Desktop Agent -> local IMS -> callback transport.
 *
 * Charges, prior payments and outstanding balance stay inside the School
 * ledger. The fiscal mirror contains this receipt's received amount only.
 */
final class SchoolFeeImsService
{
    private const REQUIRED_COLUMNS = [
        'fbr_pos_transaction_id', 'fbr_status', 'fbr_invoice_number',
        'fbr_response_code', 'fbr_error_message', 'fbr_qr_payload',
    ];

    public static function queueFinalizedReceipt(int $companyId, int $receiptId): array
    {
        if (!Schema::hasTable('school_fee_receipts')
            || !Schema::hasColumns('school_fee_receipts', self::REQUIRED_COLUMNS)) {
            return ['queued' => false, 'reason' => 'school_ims_schema_pending'];
        }

        return DB::transaction(function () use ($companyId, $receiptId) {
            $company = Company::whereKey($companyId)->lockForUpdate()->firstOrFail();
            abort_unless($company->isSchoolFeePilot(), 404);

            $receipt = DB::table('school_fee_receipts')
                ->where('company_id', $companyId)->where('id', $receiptId)
                ->lockForUpdate()->firstOrFail();

            $snapshot = self::snapshot($receipt->fiscal_route_snapshot ?? null);
            if (($snapshot['route'] ?? 'off') !== 'ims' || !($snapshot['eligible'] ?? false)) {
                return ['queued' => false, 'reason' => 'local_only'];
            }

            if (!CollectionFbrSubmission::matchesRoute($company, $snapshot)) {
                return ['queued' => false, 'reason' => 'original_route_changed'];
            }

            abort_unless((int) $receipt->amount_paisa > 0 && $receipt->collected_at !== null, 422);

            $existing = $receipt->fbr_pos_transaction_id
                ? FbrPosTransaction::where('company_id', $companyId)->find($receipt->fbr_pos_transaction_id)
                : null;
            if ($existing) {
                self::copyBack($receiptId, $existing);
                return ['queued' => true, 'transaction_id' => $existing->id, 'existing' => true];
            }

            [$pct, $rate] = self::fiscalDefaults($companyId, $receipt);
            $gross = round(((int) $receipt->amount_paisa) / 100, 2);
            $subtotal = $rate > 0 ? round($gross / (1 + ($rate / 100)), 2) : $gross;
            $tax = round($gross - $subtotal, 2);
            $method = ($receipt->payment_method ?? 'cash') === 'bank' ? 'bank_transfer' : ($receipt->payment_method ?? 'cash');

            $mirror = FbrPosTransaction::create([
                'company_id' => $companyId,
                'branch_id' => $receipt->branch_id,
                'invoice_number' => 'SF-'.$receiptId,
                'invoice_mode' => 'fbr',
                // Do not disclose student/guardian identity in the fiscal payload.
                'customer_name' => null,
                'customer_phone' => null,
                'customer_ntn' => null,
                'subtotal' => $subtotal,
                'discount_amount' => 0,
                'tax_rate' => $rate,
                'tax_amount' => $tax,
                'total_amount' => $gross,
                'payment_method' => $method,
                'status' => 'completed',
                'transaction_type' => 'sale',
                'fbr_status' => 'pending',
                'created_by' => $receipt->collected_by,
                'cash_received' => $gross,
                'change_due' => 0,
            ]);
            $mirror->forceFill([
                'created_at' => $receipt->collected_at,
                'updated_at' => $receipt->collected_at,
            ])->save();

            FbrPosTransactionItem::create([
                'transaction_id' => $mirror->id,
                'item_name' => 'School fee payment',
                'hs_code' => $pct ?: null,
                'uom' => 'U',
                'quantity' => 1,
                'unit_price' => $subtotal,
                'discount' => 0,
                'item_discount' => 0,
                'tax_rate' => $rate,
                'tax_amount' => $tax,
                'subtotal' => $subtotal,
                'total' => $gross,
                'is_tax_exempt' => $rate <= 0,
            ]);

            DB::table('school_fee_receipts')->where('id', $receiptId)->update([
                'fbr_pos_transaction_id' => $mirror->id,
                'fbr_status' => 'pending',
                'fbr_error_message' => null,
                'updated_at' => now(),
            ]);

            CollectionFbrSubmission::dispatch($mirror);

            return ['queued' => true, 'transaction_id' => $mirror->id, 'existing' => false];
        }, 3);
    }

    public static function reconcileTransaction(FbrPosTransaction $transaction): void
    {
        if (!Schema::hasTable('school_fee_receipts')
            || !Schema::hasColumns('school_fee_receipts', self::REQUIRED_COLUMNS)) {
            return;
        }

        $ids = DB::table('school_fee_receipts')
            ->where('company_id', $transaction->company_id)
            ->where('fbr_pos_transaction_id', $transaction->id)
            ->pluck('id');
        foreach ($ids as $id) {
            self::copyBack((int) $id, $transaction);
        }
    }

    public static function reconcileReceipt(int $companyId, int $receiptId): void
    {
        if (!Schema::hasColumns('school_fee_receipts', self::REQUIRED_COLUMNS)) {
            return;
        }
        $mirrorId = DB::table('school_fee_receipts')->where('company_id', $companyId)
            ->where('id', $receiptId)->value('fbr_pos_transaction_id');
        if ($mirrorId && ($mirror = FbrPosTransaction::where('company_id', $companyId)->find($mirrorId))) {
            self::copyBack($receiptId, $mirror);
        }
    }

    private static function copyBack(int $receiptId, FbrPosTransaction $mirror): void
    {
        $number = trim((string) ($mirror->fbr_invoice_number ?? ''));
        DB::table('school_fee_receipts')->where('id', $receiptId)
            ->where('company_id', $mirror->company_id)->update([
                'fbr_status' => $mirror->fbr_status,
                'fbr_invoice_number' => $number !== '' ? $number : null,
                'fbr_response_code' => $mirror->fbr_response_code,
                'fbr_error_message' => $mirror->fbr_error_message,
                // The existing FBR POS receipt convention encodes the accepted
                // fiscal number itself. No QR is shown before acceptance.
                'fbr_qr_payload' => $number !== '' ? $number : null,
                'updated_at' => now(),
            ]);
    }

    private static function snapshot(?string $raw): array
    {
        if (!$raw) return [];
        try {
            $value = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            return is_array($value) ? $value : [];
        } catch (\JsonException) {
            return [];
        }
    }

    private static function fiscalDefaults(int $companyId, object $receipt): array
    {
        if (!Schema::hasTable('school_fee_heads')
            || !Schema::hasColumns('school_fee_heads', ['ims_pct_code', 'ims_tax_rate'])) {
            return [null, 0.0];
        }
        $lines = json_decode((string) ($receipt->fee_lines ?? '[]'), true);
        $codes = collect(is_array($lines) ? $lines : [])->pluck('fee_head')->filter()->unique()->values();
        if ($codes->isEmpty() && !empty($receipt->fee_head) && $receipt->fee_head !== 'multiple') {
            $codes = collect([$receipt->fee_head]);
        }
        $heads = DB::table('school_fee_heads')->where('company_id', $companyId)
            ->whereIn('code', $codes)->get(['ims_pct_code', 'ims_tax_rate']);
        $pcts = $heads->pluck('ims_pct_code')->filter()->unique()->values();
        $rates = $heads->pluck('ims_tax_rate')->filter(fn ($v) => $v !== null)->map(fn ($v) => round((float) $v, 2))->unique()->values();
        return [$pcts->count() === 1 ? $pcts->first() : null, $rates->count() === 1 ? (float) $rates->first() : 0.0];
    }
}

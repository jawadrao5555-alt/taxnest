<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * Owner-selected product event, not an assertion of FBR applicability or an IMS payload.
 * Only a persisted finalized receipt is a candidate; no enqueue, callback write or HTTP.
 */
final class SchoolImsPaymentEvent
{
    public static function prepare(int $companyId, int $receiptId, string $trigger): array
    {
        $company = Company::findOrFail($companyId);
        abort_unless($company->isSchoolFeePilot(), 404);
        $base = ['product_event' => null, 'official_event_verified' => false,
            'can_dispatch' => false, 'ims_payload' => null, 'fiscal_number' => null, 'fiscal_qr' => null,
            'blockers' => ['school_ims_mapping_unverified', 'school_ims_enrollment_unverified',
                'school_ims_sandbox_unverified', 'school_ims_recovery_contract_unverified']];
        // A view, reprint or draft cannot become a reporting event, even with an existing ID.
        if ($trigger !== 'payment_finalized') {
            return $base + ['reason' => 'not_payment_finalization'];
        }
        $context = PilotFiscalSourceContext::inspect($companyId, 'school', 'school_receipt', $receiptId);
        $row = DB::table('school_fee_receipts')->where('company_id', $companyId)->where('id', $receiptId)->firstOrFail();
        abort_unless($row->collected_at !== null && (int) $row->amount_paisa > 0, 422);
        // Receipt identity is stable across application retries, and different installments
        // have different receipt IDs. This is NOT an asserted regulator idempotency key.
        $base['product_event'] = [
            'version' => 1, 'type' => 'school_payment_received',
            'company_id' => $companyId, 'receipt_id' => $receiptId,
            'local_identity' => 'school-payment:'.$companyId.':'.$receiptId,
            'received_amount_paisa' => $context['amounts']['current_payment_paisa'],
            'received_at' => (string) $row->collected_at,
            'local_payment_method' => $row->payment_method,
        ];
        // Deliberately omit the fee account, charge lines, payable/cumulative totals,
        // balance and student/payer details. No allocation or tax exemption is invented.
        return $base + ['routing' => $context['routing'], 'reason' => 'official_ims_contract_required'];
    }
}

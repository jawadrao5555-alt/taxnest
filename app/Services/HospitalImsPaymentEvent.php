<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * Owner-selected product event, not an assertion of FBR applicability or an IMS payload.
 * Only a persisted finalized receipt is a candidate; no enqueue, callback write or HTTP.
 */
final class HospitalImsPaymentEvent
{
    public static function prepare(int $companyId, int $paymentId, string $trigger): array
    {
        $company = Company::findOrFail($companyId);
        abort_unless($company->isHospitalCollectionPilot(), 404);
        $base = ['product_event' => null, 'official_event_verified' => false,
            'can_dispatch' => false, 'ims_payload' => null, 'fiscal_number' => null, 'fiscal_qr' => null,
            'blockers' => ['hospital_ims_mapping_unverified', 'hospital_ims_enrollment_unverified',
                'hospital_ims_sandbox_unverified', 'hospital_ims_recovery_contract_unverified']];
        // A view, reprint or draft cannot become a reporting event, even with an existing ID.
        if ($trigger !== 'payment_finalized') {
            return $base + ['reason' => 'not_payment_finalization'];
        }
        $context = PilotFiscalSourceContext::inspect($companyId, 'hospital', 'hospital_payment', $paymentId);
        $row = DB::table('hospital_payments')->where('company_id', $companyId)->where('id', $paymentId)->firstOrFail();
        abort_unless($row->collected_at !== null && (int) $row->amount_paisa > 0, 422);
        // Receipt identity is stable across application retries, and different installments
        // have different payment receipt IDs. This is NOT an asserted regulator idempotency key.
        $base['product_event'] = [
            'version' => 1, 'type' => 'hospital_payment_received',
            'company_id' => $companyId, 'payment_id' => $paymentId,
            'local_identity' => 'hospital-payment:'.$companyId.':'.$paymentId,
            'received_amount_paisa' => $context['amounts']['current_payment_paisa'],
            'received_at' => (string) $row->collected_at,
            'local_payment_method' => $row->payment_method,
        ];
        // Deliberately omit the patient ledger, service bill lines, payable/cumulative totals,
        // balance and patient/payer details. No allocation or tax exemption is invented.
        return $base + ['routing' => $context['routing'], 'reason' => 'official_ims_contract_required'];
    }
}

<?php

namespace App\Services;

use App\Jobs\SubmitCollectionFbrReceipt;
use App\Models\Company;
use App\Models\FbrPosTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Connects category payment receipts to the shared FBR POS transport. */
final class CollectionFbrSubmission
{
    public static function matchesRoute(Company $company, array $snapshot): bool
    {
        $mode = $company->fbr_connection_mode ?? 'cloud';
        // Earlier collection receipts used the Agent exclusively.
        $originalMode = $snapshot['connection_mode'] ?? 'fiscal_device';
        $category = $company->isSchoolFeePilot() ? 'school' : 'hospital';

        return ($snapshot['company_id'] ?? null) === (int) $company->id
            && ($snapshot['category'] ?? null) === $category
            && ($snapshot['route'] ?? null) === 'ims'
            && ($snapshot['eligible'] ?? false) === true
            && $originalMode === $mode
            && ($snapshot['environment'] ?? null) === ($company->fbr_pos_environment ?? 'sandbox')
            && (!isset($snapshot['pos_id']) || $snapshot['pos_id'] === (string) $company->fbr_pos_id)
            && $company->fbr_reporting_enabled
            && $company->fbr_pos_enabled
            && $company->fbrPosIntegrationConfigured();
    }

    public static function canSubmit(FbrPosTransaction $transaction): bool
    {
        $company = $transaction->company;
        if (!$company?->isLocalCollectionPilot() || $transaction->invoice_mode !== 'fbr') {
            return false;
        }
        $school = $company->isSchoolFeePilot();
        $table = $school ? 'school_fee_receipts' : 'hospital_payments';
        if (!Schema::hasColumns($table, ['fiscal_route_snapshot', 'fbr_pos_transaction_id'])) {
            return false;
        }
        $receipt = DB::table($table)->where('company_id', $company->id)
            ->where('fbr_pos_transaction_id', $transaction->id)->first();
        if (!$receipt || !$receipt->collected_at || (int) $receipt->amount_paisa <= 0) {
            return false;
        }
        $snapshot = json_decode($receipt->fiscal_route_snapshot ?? 'null', true);
        if (!is_array($snapshot) || !self::matchesRoute($company, $snapshot)) {
            return false;
        }
        $plan = PilotFiscalRouteSnapshot::recoveryPlan((int) $company->id,
            $school ? 'school' : 'hospital', $school ? 'school_receipt' : 'hospital_payment', (int) $receipt->id, 'ims');

        return ($plan['state'] ?? null) === 'reconciliation_required';
    }

    public static function receiptPath(FbrPosTransaction $transaction): ?string
    {
        $company = $transaction->company;
        if (!$company?->isLocalCollectionPilot()) {
            return null;
        }

        $school = $company->isSchoolFeePilot();
        $table = $school ? 'school_fee_receipts' : 'hospital_payments';
        if (!Schema::hasColumn($table, 'fbr_pos_transaction_id')) {
            return null;
        }
        $receipt = DB::table($table)->where('company_id', $company->id)
            ->where('fbr_pos_transaction_id', $transaction->id)
            ->first(['id', 'branch_id']);
        if (!$receipt) {
            return null;
        }

        $path = $school ? '/fbr-pos/school-fees/'.$receipt->id.'/receipt' : '/fbr-pos/hospital/receipts/'.$receipt->id;

        return $path.($receipt->branch_id === null ? '?unassigned=1' : '');
    }

    public static function dispatch(FbrPosTransaction $transaction): void
    {
        if (($transaction->company->fbr_connection_mode ?? 'cloud') === 'cloud') {
            SubmitCollectionFbrReceipt::dispatch($transaction->id)->afterCommit();
        }
    }

    public static function receiptStatus(object $receipt): \Illuminate\Http\JsonResponse
    {
        $mirror = empty($receipt->fbr_pos_transaction_id) ? null : FbrPosTransaction::query()
            ->where('company_id', $receipt->company_id)
            ->find($receipt->fbr_pos_transaction_id, ['id', 'fbr_status']);

        return response()->json(['status' => $mirror?->fbr_status ?? $receipt->fbr_status ?? null])
            ->header('Cache-Control', 'private, no-store');
    }

    public static function reconcile(FbrPosTransaction $transaction): void
    {
        SchoolFeeImsService::reconcileTransaction($transaction);
        HospitalPaymentImsService::reconcileTransaction($transaction);
    }
}

<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Facades\DB;

/** Immutable routing metadata only; no regulator transport or acknowledgement writer. */
final class PilotFiscalRouteSnapshot
{
    private const SOURCES = [
        'school_receipt' => ['school', 'school_fee_receipts'],
        'hospital_invoice' => ['hospital', 'hospital_invoices'],
        'hospital_payment' => ['hospital', 'hospital_payments'],
    ];

    public static function newBill(Company $company, string $category, string $source): string
    {
        abort_unless(isset(self::SOURCES[$source]) && self::SOURCES[$source][0] === $category, 422);
        abort_unless($category === 'school' ? $company->isSchoolFeePilot() : $company->isHospitalCollectionPilot(), 422);
        $ims = (bool) $company->fbr_reporting_enabled
            && $company->agentServesFbr()
            && $company->fbrPosIntegrationConfigured();
        return json_encode([
            'version' => 1, 'company_id' => (int) $company->id, 'category' => $category,
            'source' => $source, 'route' => $ims ? 'ims' : 'off', 'eligible' => $ims,
            'adapter_version' => $ims ? 'standalone-fbr-pos-agent-v1' : null,
            'environment' => $ims ? ($company->fbr_pos_environment === 'production' ? 'production' : 'sandbox') : null,
        ], JSON_THROW_ON_ERROR);
    }

    /** New payment receipts have their own routing event, independently of the internal bill. */
    public static function newHospitalPayment(Company $company, int $invoiceId): string
    {
        abort_unless($company->isHospitalCollectionPilot(), 422);
        abort_unless(DB::table('hospital_invoices')->where('company_id', $company->id)->where('id', $invoiceId)->exists(), 404);
        $snapshot = json_decode(self::newBill($company, 'hospital', 'hospital_invoice'), true, 512, JSON_THROW_ON_ERROR);
        $snapshot['version'] = 2;
        $snapshot['source'] = 'hospital_payment';
        $snapshot['invoice_id'] = $invoiceId;
        return json_encode($snapshot, JSON_THROW_ON_ERROR);
    }

    public static function recoveryPlan(int $companyId, string $category, string $source, int $id, ?string $requestedRoute = null): array
    {
        abort_unless(isset(self::SOURCES[$source]) && self::SOURCES[$source][0] === $category, 404);
        $row = DB::table(self::SOURCES[$source][1])->where('company_id', $companyId)->where('id', $id)->first();
        abort_unless($row, 404);
        $base = ['company_id' => $companyId, 'category' => $category, 'source' => $source,
            'source_id' => $id, 'route' => null, 'can_submit' => false, 'can_retry' => false,
            'historical_backfill_allowed' => false, 'requires_reconciliation' => false];
        $raw = $row->fiscal_route_snapshot ?? null;
        if ($raw === null) return $base + ['state' => 'legacy_unverified', 'reason' => 'missing_immutable_route'];
        try { $snapshot = json_decode($raw, true, 512, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { return $base + ['state' => 'blocked', 'reason' => 'invalid_snapshot']; }
        $ownPaymentRoute = $source === 'hospital_payment' && is_array($snapshot) && ($snapshot['version'] ?? null) === 2;
        $expectedSource = $source === 'hospital_payment' && !$ownPaymentRoute ? 'hospital_invoice' : $source;
        if (!is_array($snapshot) || ($snapshot['version'] ?? null) !== ($ownPaymentRoute ? 2 : 1)
            || ($snapshot['company_id'] ?? null) !== $companyId || ($snapshot['category'] ?? null) !== $category
            || ($snapshot['source'] ?? null) !== $expectedSource
            || !in_array($snapshot['route'] ?? null, ['off', 'ims', 'di'], true)) {
            return $base + ['state' => 'blocked', 'reason' => 'invalid_snapshot_scope'];
        }
        // New payment events bind to a scoped internal bill, not its reporting route.
        // Preserve the strict equality rule for historical inherited version-one records.
        if ($source === 'hospital_payment') {
            $parent = DB::table('hospital_invoices')->where('company_id', $companyId)->where('id', $row->invoice_id)->first();
            if (!$parent || ($ownPaymentRoute
                ? ($snapshot['invoice_id'] ?? null) !== (int) $row->invoice_id
                : ($parent->fiscal_route_snapshot ?? null) !== $raw)) {
                return $base + ['state' => 'blocked', 'reason' => 'parent_snapshot_mismatch'];
            }
        }
        $base['route'] = $snapshot['route'];
        if ($requestedRoute !== null && $requestedRoute !== $snapshot['route']) {
            return $base + ['state' => 'blocked', 'reason' => 'route_mismatch'];
        }
        if ($snapshot['route'] === 'off') return $base + ['state' => 'local_only', 'reason' => 'off_at_creation'];
        // Preserve an existing route's uncertainty even while today's selection is OFF.
        // No invented status lookup, fallback transport, receipt mutation or blind resend.
        $base['requires_reconciliation'] = true;
        return $base + ['state' => 'reconciliation_required', 'reason' => 'official_same_route_recovery_unavailable'];
    }
}

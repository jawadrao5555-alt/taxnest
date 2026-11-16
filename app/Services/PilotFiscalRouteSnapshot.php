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
        // Both real adapters remain unavailable. Ignore mutable/raw reporting settings.
        return json_encode([
            'version' => 1, 'company_id' => (int) $company->id, 'category' => $category,
            'source' => $source, 'route' => 'off', 'eligible' => false,
            'adapter_version' => null, 'environment' => null,
        ], JSON_THROW_ON_ERROR);
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
        $expectedSource = $source === 'hospital_payment' ? 'hospital_invoice' : $source;
        if (!is_array($snapshot) || ($snapshot['version'] ?? null) !== 1
            || ($snapshot['company_id'] ?? null) !== $companyId || ($snapshot['category'] ?? null) !== $category
            || ($snapshot['source'] ?? null) !== $expectedSource
            || !in_array($snapshot['route'] ?? null, ['off', 'ims', 'di'], true)) {
            return $base + ['state' => 'blocked', 'reason' => 'invalid_snapshot_scope'];
        }
        // Payment routing must match its own scoped parent, not just a matching category.
        if ($source === 'hospital_payment') {
            $parent = DB::table('hospital_invoices')->where('company_id', $companyId)->where('id', $row->invoice_id)->first();
            if (!$parent || ($parent->fiscal_route_snapshot ?? null) !== $raw) {
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

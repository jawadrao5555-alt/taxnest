<?php

namespace App\Services;

use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The DI fiscal submission state contract.
 *
 * `status` and `fbr_status` pre-date this contract and remain the compatibility
 * fields used by the panel.  The additive fields record the unambiguous
 * regulator outcome without pretending that a transport response was accepted.
 */
class DiFiscalSubmissionState
{
    public const DRAFT = 'draft';
    public const SUBMITTING = 'submitting';
    public const ACCEPTED = 'accepted';
    public const REJECTED = 'rejected';
    public const VERIFICATION_REQUIRED = 'verification_required';
    public const SIMULATED = 'simulated';
    /** Must remain greater than every DI queue reservation/job timeout. */
    public const LEASE_SECONDS = 600;

    public static function isAuthoritativeAcknowledgement(?string $reference, ?string $environment): bool
    {
        return trim((string) $reference) !== ''
            && in_array($environment, ['sandbox', 'production'], true);
    }

    /**
     * Atomically take an invoice for a new attempt. A verification-required
     * invoice is deliberately never reclaimable: an operator must reconcile it
     * with the regulator before any new POST is possible.
     */
    public static function reserve(int $invoiceId, string $mode, ?string $environment = null, ?int $batchId = null): ?Invoice
    {
        return DB::transaction(function () use ($invoiceId, $mode, $environment, $batchId) {
            $invoice = Invoice::withoutGlobalScopes()->whereKey($invoiceId)->lockForUpdate()->first();
            if ($invoice && $invoice->is_fbr_processing && self::expiredLease($invoice)) {
                // A dead worker after claiming but before callback has an
                // unknown send boundary. Expiry never unlocks it for replay.
                self::verificationRequired(
                    $invoice,
                    $environment ?: ($invoice->fiscal_submission_environment ?? 'sandbox'),
                    'expired_submission_lease'
                );
                $invoice->save();
                return null;
            }
            if (!$invoice
                || !in_array($invoice->status, ['draft', 'failed'], true)
                || $invoice->is_fbr_processing
                || !empty($invoice->fbr_invoice_number)
                || $invoice->status === 'pending_verification') {
                return null;
            }

            $invoice->is_fbr_processing = true;
            $invoice->submitted_at = now();
            $invoice->submission_mode = $mode;
            // This is the sole DI claim. FbrService must consume it, not make
            // a second, incompatible claim after callers already reserved it.
            $invoice->fbr_submission_hash = hash(
                'sha256',
                $invoice->id . '|' . ($invoice->internal_invoice_number ?? $invoice->invoice_number)
            );
            self::setMetadata($invoice, [
                'fiscal_submission_state' => self::SUBMITTING,
                'fiscal_submission_environment' => $environment,
                'fiscal_submission_provenance' => 'awaiting_regulator_acknowledgement',
                'fiscal_submission_batch_id' => $batchId,
                'fiscal_lease_expires_at' => now()->addSeconds(self::LEASE_SECONDS),
            ]);
            $invoice->save();

            return $invoice->fresh();
        });
    }

    public static function accepted(Invoice $invoice, string $reference, string $environment, string $provenance): void
    {
        if (!self::isAuthoritativeAcknowledgement($reference, $environment)) {
            throw new \InvalidArgumentException('An authoritative regulator reference and environment are required.');
        }

        $invoice->status = 'locked';
        $invoice->fbr_status = $environment;
        $invoice->fbr_invoice_number = $reference;
        $invoice->fbr_invoice_id = $reference;
        $invoice->fbr_submission_date = now();
        $invoice->is_fbr_processing = false;
        self::setMetadata($invoice, [
            'fiscal_submission_state' => self::ACCEPTED,
            'fiscal_submission_environment' => $environment,
            'fiscal_submission_provenance' => $provenance,
            'fiscal_acknowledged_at' => now(),
            'fiscal_lease_expires_at' => null,
        ]);
    }

    public static function rejected(Invoice $invoice): void
    {
        $invoice->status = 'failed';
        $invoice->fbr_status = 'failed';
        $invoice->is_fbr_processing = false;
        $invoice->fbr_submission_hash = null;
        self::setMetadata($invoice, [
            'fiscal_submission_state' => self::REJECTED,
            'fiscal_submission_provenance' => 'explicit_regulator_rejection',
            'fiscal_lease_expires_at' => null,
        ]);
    }

    public static function verificationRequired(Invoice $invoice, string $environment, string $reason): void
    {
        $invoice->status = 'pending_verification';
        $invoice->fbr_status = 'pending_verification';
        $invoice->is_fbr_processing = false;
        self::setMetadata($invoice, [
            'fiscal_submission_state' => self::VERIFICATION_REQUIRED,
            'fiscal_submission_environment' => $environment,
            'fiscal_submission_provenance' => $reason,
            'fiscal_lease_expires_at' => null,
        ]);
    }

    public static function simulated(Invoice $invoice): void
    {
        // A demo response is useful for the demo, but it is never a fiscal
        // acceptance and must not lock an invoice or create a ledger entry.
        $invoice->status = 'draft';
        $invoice->fbr_status = 'simulated';
        $invoice->is_fbr_processing = false;
        $invoice->fbr_submission_hash = null;
        self::setMetadata($invoice, [
            'fiscal_submission_state' => self::SIMULATED,
            'fiscal_submission_environment' => 'demo',
            'fiscal_submission_provenance' => 'synthetic_demo_response',
            'fiscal_lease_expires_at' => null,
        ]);
    }

    private static function setMetadata(Invoice $invoice, array $values): void
    {
        // This preserves compatibility with historical queue payloads and with
        // installations that have not yet applied the additive RC migration.
        foreach ($values as $column => $value) {
            if (Schema::hasColumn('invoices', $column)) {
                $invoice->setAttribute($column, $value);
            }
        }
    }

    private static function expiredLease(Invoice $invoice): bool
    {
        if (!Schema::hasColumn('invoices', 'fiscal_lease_expires_at')) {
            return false;
        }
        $lease = $invoice->fiscal_lease_expires_at;
        return $lease !== null && $lease->isPast();
    }
}
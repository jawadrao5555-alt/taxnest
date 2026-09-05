<?php

namespace App\Services;

use App\Models\FbrPosLog;
use App\Models\FbrPosTransaction;
use App\Models\FbrPosTransactionItem;
use App\Models\HealthBill;
use App\Models\HealthBillLine;
use App\Models\HealthFbrSubmission;
use App\Models\HealthTaxCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Filing a healthcare bill with FBR (Task 1551, step 4).
 *
 * The platform already has a proven IMS fiscalization path: hash-locked
 * submission, config-error semantics, FbrPosLog evidence, a two-minute sync job
 * that sweeps up anything left offline and a retry job with backoff. It has
 * been filing live retail bills for a year.
 *
 * So healthcare does not grow a second one. An eligible finalized bill is
 * MIRRORED into a real fbr_pos_transactions row and submitted through
 * FbrService, which means every hardening that path has already earned applies
 * here for free — including the shared sync job picking a stranded mirror back
 * up on its own, hours later, with nobody watching.
 *
 * Two things this adapter must get right on top of that:
 *
 *  1. ONLY FBR-TREATED LINES GO. A local or exempt charge is not sent, is not
 *     summed into the payload, and cannot arrive at the regulator by accident.
 *     The mirror's totals are rebuilt from the reportable lines alone, which is
 *     what keeps local and reported money genuinely separate rather than merely
 *     labelled differently.
 *  2. EVERY ATTEMPT LEAVES EVIDENCE. health_fbr_submissions records the exact
 *     payload sent and the exact body returned, per attempt, forever. When a
 *     filing is disputed, re-deriving what "would have been" sent from data that
 *     has since moved answers a different question.
 */
class HealthBillFbrService
{
    /**
     * May this bill be filed at all?
     *
     * @return array{ok:bool,reason?:string}
     */
    public static function eligibility(HealthBill $bill): array
    {
        if ($bill->isEstimate()) {
            return ['ok' => false, 'reason' => 'estimate_not_filable'];
        }
        if (!$bill->isFinal()) {
            return ['ok' => false, 'reason' => 'bill_not_finalized'];
        }
        if ($bill->isFbrFiled()) {
            return ['ok' => false, 'reason' => 'already_filed'];
        }

        $lines = self::reportableLines($bill);
        if ($lines->isEmpty()) {
            return ['ok' => false, 'reason' => 'no_reportable_lines'];
        }

        $company = self::company($bill);
        if (!$company) {
            return ['ok' => false, 'reason' => 'company_missing'];
        }
        if (!$company->fbr_reporting_enabled) {
            return ['ok' => false, 'reason' => 'reporting_off'];
        }
        if (empty($company->fbr_pos_id)) {
            return ['ok' => false, 'reason' => 'pos_id_missing'];
        }

        $fbr = app(FbrService::class);
        if (method_exists($fbr, 'hasUsableFbrPosToken') && !$fbr->hasUsableFbrPosToken($company)) {
            return ['ok' => false, 'reason' => 'token_missing'];
        }

        return ['ok' => true];
    }

    /**
     * File a bill, recording the attempt whatever happens.
     *
     * @return array{ok:bool,reason?:string,status?:string,invoice_number?:?string,message?:?string}
     */
    public static function submit(HealthBill $bill, $actor = null, string $trigger = HealthFbrSubmission::TRIGGER_MANUAL): array
    {
        // Somebody else's retry (the shared sync job, most likely) may have
        // succeeded since this bill was last read. Ask the mirror first, so a
        // second filing of the same money is impossible.
        self::reconcile($bill);
        $bill->refresh();

        $eligible = self::eligibility($bill);
        if (!$eligible['ok']) {
            return ['ok' => false, 'reason' => $eligible['reason']];
        }

        $mirror = self::mirror($bill, $actor);
        if (!$mirror) {
            return ['ok' => false, 'reason' => 'mirror_failed'];
        }

        $fbr = app(FbrService::class);

        // Snapshot the payload BEFORE submitting. On a transport failure the
        // service returns an error and no payload, and "what did we send" is
        // exactly the question that matters then.
        $payload = null;
        try {
            $payload = $fbr->buildFbrPosPayload($mirror);
        } catch (\Throwable $e) {
            Log::warning('health.fbr.payload_build_failed', [
                'bill_id' => $bill->id,
                'error' => $e->getMessage(),
            ]);
        }

        $attemptNo = (int) HealthFbrSubmission::withoutGlobalScopes()
            ->where('company_id', $bill->company_id)
            ->where('health_bill_id', $bill->id)
            ->max('attempt_no') + 1;

        $startedAt = microtime(true);
        try {
            $result = $fbr->submitFbrPosTransaction($mirror);
        } catch (\Throwable $e) {
            $result = ['status' => 'failed', 'errors' => [$e->getMessage()]];
        }
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        $mirror->refresh();

        // The service logs the raw exchange against the mirror; pick up the
        // response body from there rather than re-inventing a summary of it.
        $log = Schema::hasTable('fbr_pos_logs')
            ? FbrPosLog::where('transaction_id', $mirror->id)->orderByDesc('id')->first()
            : null;

        $status = self::mapStatus($result['status'] ?? 'failed', $mirror);
        $errorText = self::errorText($result, $mirror);

        self::record($bill, $mirror, [
            'attempt_no' => $attemptNo,
            'status' => $status,
            'trigger' => $trigger,
            'request_payload' => self::encode($payload ?? ($log->request_payload ?? null)),
            'response_payload' => self::encode($mirror->fbr_response ?? ($log->response_payload ?? null)),
            'response_code' => $mirror->fbr_response_code,
            'invoice_number' => $mirror->fbr_invoice_number,
            'error_message' => $errorText,
            'duration_ms' => $durationMs,
            'actor_id' => $actor->id ?? null,
        ]);

        self::copyBack($bill, $mirror, $status, $errorText);

        return [
            'ok' => $status === HealthFbrSubmission::STATUS_SUBMITTED,
            'status' => $status,
            'invoice_number' => $mirror->fbr_invoice_number,
            'message' => $errorText,
        ];
    }

    /**
     * Pull the mirror's current fiscal state back onto the bill.
     *
     * This is what makes the SHARED retry infrastructure useful to healthcare.
     * SyncFbrPosOfflineInvoicesJob does not know this bill exists — it sees a
     * pending fbr_pos_transactions row and files it. Without this pass the money
     * would be filed with FBR while the hospital's own screen still said
     * "failed", which is worse than not filing at all.
     *
     * @return bool TRUE when the bill changed.
     */
    public static function reconcile(HealthBill $bill): bool
    {
        if (!$bill->fbr_pos_transaction_id || !Schema::hasTable('fbr_pos_transactions')) {
            return false;
        }

        $mirror = FbrPosTransaction::find($bill->fbr_pos_transaction_id);
        if (!$mirror) {
            return false;
        }

        $status = self::mapStatus($mirror->fbr_status ?: 'pending', $mirror);
        $before = [$bill->fbr_invoice_number, $bill->fbr_status, $bill->fbr_response_code];

        self::copyBack($bill, $mirror, $status, $mirror->fbr_error_message ?? null);

        $bill->refresh();

        $changed = $before !== [$bill->fbr_invoice_number, $bill->fbr_status, $bill->fbr_response_code];

        // A success that arrived through somebody else's retry still deserves an
        // evidence row, or the bill's history would show only the failures.
        if ($changed && $bill->isFbrFiled()) {
            $known = HealthFbrSubmission::withoutGlobalScopes()
                ->where('company_id', $bill->company_id)
                ->where('health_bill_id', $bill->id)
                ->where('invoice_number', $bill->fbr_invoice_number)
                ->exists();

            if (!$known) {
                $attemptNo = (int) HealthFbrSubmission::withoutGlobalScopes()
                    ->where('company_id', $bill->company_id)
                    ->where('health_bill_id', $bill->id)
                    ->max('attempt_no') + 1;

                self::record($bill, $mirror, [
                    'attempt_no' => $attemptNo,
                    'status' => HealthFbrSubmission::STATUS_SUBMITTED,
                    'trigger' => HealthFbrSubmission::TRIGGER_AUTO,
                    'request_payload' => null,
                    'response_payload' => self::encode($mirror->fbr_response),
                    'response_code' => $mirror->fbr_response_code,
                    'invoice_number' => $mirror->fbr_invoice_number,
                    'error_message' => null,
                    'duration_ms' => null,
                    'actor_id' => null,
                ]);
            }
        }

        return $changed;
    }

    /**
     * Reconcile every bill of a company that is still waiting on the regulator.
     *
     * @return int number of bills whose fiscal state changed
     */
    public static function reconcileCompany(int $companyId, int $limit = 200): int
    {
        if (!Schema::hasTable('health_bills')) {
            return 0;
        }

        $bills = HealthBill::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereNotNull('fbr_pos_transaction_id')
            ->whereNull('fbr_invoice_number')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $changed = 0;
        foreach ($bills as $bill) {
            if (self::reconcile($bill)) {
                $changed++;
            }
        }

        return $changed;
    }

    /**
     * Get (or build) the mirror transaction this bill files through.
     *
     * Idempotent: a bill files through exactly one mirror for its whole life, so
     * a retry re-uses the same row and the submission hash keeps doing its job.
     * The mirror is rebuilt only while it has never reached FBR — once it holds
     * an invoice number it is evidence and is left alone.
     */
    public static function mirror(HealthBill $bill, $actor = null): ?FbrPosTransaction
    {
        if (!Schema::hasTable('fbr_pos_transactions')) {
            return null;
        }

        $lines = self::reportableLines($bill);
        if ($lines->isEmpty()) {
            return null;
        }

        $existing = $bill->fbr_pos_transaction_id
            ? FbrPosTransaction::find($bill->fbr_pos_transaction_id)
            : null;

        if ($existing && $existing->fbr_invoice_number) {
            return $existing;
        }

        $patient = HealthBillingService::patient((int) $bill->company_id, (int) $bill->health_patient_id);

        // Totals from the REPORTABLE lines only. Never from the bill's own
        // totals — those include the local and exempt money that must not reach
        // the regulator.
        $subtotal = round($lines->sum(fn ($l) => (float) $l->net_amount), 2);
        $tax = round($lines->sum(fn ($l) => (float) $l->tax_amount), 2);
        $total = round($subtotal + $tax, 2);
        $rates = $lines->pluck('tax_rate')->map(fn ($r) => round((float) $r, 2))->unique();

        $mirror = null;

        DB::transaction(function () use ($bill, $lines, $existing, $patient, $subtotal, $tax, $total, $rates, $actor, &$mirror) {
            $attrs = [
                'company_id' => $bill->company_id,
                'branch_id' => $bill->branch_id,
                'invoice_number' => $bill->bill_no,
                // A healthcare bill that reaches this point is real, reported
                // money — never the provisional 'local' mode.
                'invoice_mode' => 'fbr',
                'customer_name' => $patient->name ?? null,
                'customer_phone' => $patient->phone ?? null,
                'subtotal' => $subtotal,
                // ZERO on purpose. A healthcare concession is granted on the
                // CHARGE, so every line here is already net of it and the
                // payload's item SaleValues carry the reduced figure. The shared
                // FBR payload builder subtracts this header discount once more
                // (TotalBillAmount = sale + tax − discount), so repeating the
                // concession here would file the bill for less than the bill the
                // patient was actually handed. The per-item Discount field still
                // shows the regulator what was waived.
                'discount_amount' => 0,
                // A single blended rate is meaningless when lines differ, and
                // the payload reads each line's own rate anyway.
                'tax_rate' => $rates->count() === 1 ? (float) $rates->first() : 0,
                'tax_amount' => $tax,
                'total_amount' => $total,
                'payment_method' => 'cash',
                'status' => 'completed',
                'created_by' => $actor->id ?? $bill->created_by,
            ];

            if (Schema::hasColumn('fbr_pos_transactions', 'health_bill_id')) {
                $attrs['health_bill_id'] = $bill->id;
            }

            if ($existing) {
                // A never-filed mirror is refreshed in place so a bill corrected
                // before its first successful submission does not file stale
                // money. The submission hash is cleared with it — a hash left
                // over from an abandoned attempt would block the retry forever.
                $existing->forceFill(array_merge($attrs, [
                    'fbr_submission_hash' => null,
                ]))->save();
                FbrPosTransactionItem::where('transaction_id', $existing->id)->delete();
                $mirror = $existing;
            } else {
                $mirror = FbrPosTransaction::create($attrs);
            }

            foreach ($lines as $line) {
                FbrPosTransactionItem::create([
                    'transaction_id' => $mirror->id,
                    'item_name' => mb_substr((string) $line->description, 0, 200),
                    // IMS PCTCode. Blank is accepted for POS fiscalization, so a
                    // hospital that has not mapped codes is never blocked.
                    'hs_code' => $line->pct_code ?: null,
                    // The column is an integer; a fractional healthcare quantity
                    // (0.5 of a dose) still has to bill as at least one unit
                    // rather than round away to nothing.
                    'quantity' => max(1, (int) round((float) $line->quantity)),
                    'unit_price' => round((float) $line->unit_price, 2),
                    'discount' => round((float) $line->concession_amount, 2),
                    'tax_rate' => round((float) $line->tax_rate, 2),
                    'tax_amount' => round((float) $line->tax_amount, 2),
                    'subtotal' => round((float) $line->net_amount, 2),
                    'total' => round((float) $line->total_amount, 2),
                    'is_tax_exempt' => false,
                ]);
            }

            $bill->forceFill([
                'fbr_pos_transaction_id' => $mirror->id,
                'fbr_status' => $bill->fbr_status ?: HealthBill::FBR_PENDING,
            ])->save();
        });

        return $mirror;
    }

    /** The lines the regulator is actually told about. */
    public static function reportableLines(HealthBill $bill)
    {
        if (!Schema::hasTable('health_bill_lines')) {
            return collect();
        }

        return HealthBillLine::withoutGlobalScopes()
            ->where('company_id', $bill->company_id)
            ->where('health_bill_id', $bill->id)
            ->where('tax_treatment', HealthTaxCategory::TREATMENT_FBR)
            ->orderBy('line_no')
            ->get();
    }

    /** Write one immutable attempt row. */
    private static function record(HealthBill $bill, ?FbrPosTransaction $mirror, array $data): ?HealthFbrSubmission
    {
        if (!Schema::hasTable('health_fbr_submissions')) {
            return null;
        }

        try {
            return HealthFbrSubmission::withoutGlobalScopes()->create([
                'company_id' => $bill->company_id,
                'health_bill_id' => $bill->id,
                'fbr_pos_transaction_id' => $mirror->id ?? null,
                'attempt_no' => $data['attempt_no'],
                'status' => $data['status'],
                'trigger' => $data['trigger'],
                'request_payload' => $data['request_payload'],
                'response_payload' => $data['response_payload'],
                'response_code' => $data['response_code'] ? mb_substr((string) $data['response_code'], 0, 16) : null,
                'invoice_number' => $data['invoice_number'],
                'error_message' => $data['error_message'] ? mb_substr((string) $data['error_message'], 0, 1000) : null,
                'submitted_at' => now(),
                'duration_ms' => $data['duration_ms'],
                'actor_id' => $data['actor_id'],
            ]);
        } catch (\Throwable $e) {
            // Evidence failing to write must never swallow the filing itself —
            // the money reached FBR either way and the bill has to learn that.
            Log::warning('health.fbr.evidence_write_failed', [
                'bill_id' => $bill->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** Copy the mirror's fiscal state onto the bill. */
    private static function copyBack(HealthBill $bill, FbrPosTransaction $mirror, string $status, ?string $error): void
    {
        $patch = [
            'fbr_status' => $status,
            'fbr_invoice_number' => $mirror->fbr_invoice_number ?: $bill->fbr_invoice_number,
            'fbr_response_code' => $mirror->fbr_response_code,
            'fbr_error_message' => $error ? mb_substr($error, 0, 1000) : null,
            'fbr_pos_transaction_id' => $mirror->id,
        ];

        if ($status === HealthFbrSubmission::STATUS_SUBMITTED) {
            $patch['fbr_submitted_at'] = $mirror->updated_at ?: now();
            $patch['fbr_error_message'] = null;
        } else {
            $patch['fbr_retry_count'] = (int) $bill->fbr_retry_count + 1;
        }

        $bill->forceFill($patch)->save();
    }

    /**
     * FbrService's outcome vocabulary onto the bill's.
     *
     * The mirror's own fbr_status is trusted over the returned status when it
     * shows a success: a queued-agent submission completes later and the return
     * value of the call that queued it says nothing about how it ended.
     */
    private static function mapStatus(string $raw, ?FbrPosTransaction $mirror = null): string
    {
        if ($mirror && $mirror->fbr_invoice_number) {
            return HealthFbrSubmission::STATUS_SUBMITTED;
        }

        return match ($raw) {
            'success', 'submitted' => HealthFbrSubmission::STATUS_SUBMITTED,
            'queued_agent', 'pending', 'offline' => HealthFbrSubmission::STATUS_QUEUED_AGENT,
            'config_error' => HealthFbrSubmission::STATUS_CONFIG_ERROR,
            'blocked' => HealthFbrSubmission::STATUS_BLOCKED,
            default => HealthFbrSubmission::STATUS_FAILED,
        };
    }

    private static function errorText(array $result, FbrPosTransaction $mirror): ?string
    {
        if (!empty($mirror->fbr_error_message)) {
            return (string) $mirror->fbr_error_message;
        }
        if (!empty($result['errors']) && is_array($result['errors'])) {
            return implode(' | ', array_map('strval', $result['errors']));
        }
        if (!empty($result['message'])) {
            return (string) $result['message'];
        }

        return null;
    }

    private static function encode($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value)) {
            return $value;
        }

        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json === false ? null : $json;
    }

    private static function company(HealthBill $bill)
    {
        return \App\Models\Company::find($bill->company_id);
    }
}

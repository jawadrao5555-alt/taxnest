<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Task 1475 — repair bills stamped "reported to PRA" that carry no fiscal number.
 *
 * Live data (22 Aug 2026) held 8 such rows: 7 at PIZZA MASTER (company 23) and one
 * at the QA company. Every one had pra_response_code NULL, pra_qr_code NULL and —
 * decisively — ZERO pra_logs rows. PraLog::create() runs BEFORE the curl call, so
 * no log means PRA was never contacted at all. These bills were never reported.
 *
 * Cause (the 7 real ones): the pre-Task-631 restaurant settle path. sendInvoice()
 * used to short-circuit an all-exempt bill with ['success' => true, 'exempt_only' => true]
 * without contacting PRA, and payOrder() then overwrote the row with
 * pra_status='submitted' + ('pra_invoice_number' => ... ?? null). Commit 03733d27
 * closed that specific overwrite on 13 Aug — which is exactly why the newest bad
 * row is 12 Aug — but the rows it already created were never cleaned up.
 *
 * Why this matters: both thermal receipts gate the Sahulat QR on
 * pra_status === 'submitted' AND pra_invoice_number. With the number missing the
 * bill falls through to the local/menu-QR branch, so the customer receives a
 * receipt recorded as reported to PRA that carries a menu QR — while the shop's
 * "reported" totals silently count it.
 *
 * The repair is rule-based, not an id list, so it also heals any older shop DB
 * carrying the same footprint:
 *
 *   1. Company has PRA reporting OFF  -> pra_status NULL (a reporting-OFF final;
 *      "submitted" was never possible for it). This is the QA row's case.
 *   2. Bill is all-exempt with zero tax -> 'exempt_internal', the status the code
 *      of that era intended before payOrder overwrote it. Matches the shape of
 *      the exempt_internal rows already on live and the precedent set when the
 *      same bug was hand-corrected for two other bills on 13 Aug 2026.
 *   3. Anything else -> 'failed' with a reason, so it surfaces in the F11 Failed
 *      Bills modal and the owner can retry it deliberately.
 *
 * Deliberately NOT done: auto-resubmitting these bills to PRA. The receipts were
 * handed to customers weeks ago, and back-reporting a 31-Jul bill today is a
 * regulatory call for the shop owner, not a migration. Both landing statuses stay
 * re-queueable from the existing admin tooling.
 *
 * Idempotent: re-running finds nothing, because a repaired row no longer matches.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pos_transactions')
            || ! Schema::hasColumn('pos_transactions', 'pra_status')
            || ! Schema::hasColumn('pos_transactions', 'pra_invoice_number')) {
            return;
        }

        // prod-schema-drift-selfheal: live cPanel can lag any of these columns.
        $hasQr = Schema::hasColumn('pos_transactions', 'pra_qr_code');
        $hasError = Schema::hasColumn('pos_transactions', 'pra_error_message');
        $hasTax = Schema::hasColumn('pos_transactions', 'tax_amount');
        $canReadExempt = Schema::hasTable('pos_transaction_items')
            && Schema::hasColumn('pos_transaction_items', 'is_tax_exempt');
        $canReadReporting = Schema::hasTable('companies')
            && Schema::hasColumn('companies', 'pra_reporting_enabled');

        $broken = DB::table('pos_transactions')
            ->where('pra_status', 'submitted')
            ->where(function ($q) {
                $q->whereNull('pra_invoice_number')
                    ->orWhereRaw("TRIM(pra_invoice_number) = ''");
            })
            ->get(['id', 'company_id', 'invoice_number', $hasTax ? 'tax_amount' : 'id as tax_amount']);

        if ($broken->isEmpty()) {
            return;
        }

        // Which of these companies report to PRA at all?
        $reportingOff = [];
        if ($canReadReporting) {
            $reportingOff = DB::table('companies')
                ->whereIn('id', $broken->pluck('company_id')->unique()->all())
                ->where(function ($q) {
                    $q->where('pra_reporting_enabled', 0)->orWhereNull('pra_reporting_enabled');
                })
                ->pluck('id')
                ->all();
        }
        $reportingOff = array_flip($reportingOff);

        // Which of these bills are all-exempt? (has lines, and none of them taxable)
        $allExempt = [];
        if ($canReadExempt) {
            $ids = $broken->pluck('id')->all();
            $lineCounts = DB::table('pos_transaction_items')
                ->whereIn('transaction_id', $ids)
                ->groupBy('transaction_id')
                ->selectRaw('transaction_id, COUNT(*) as total, SUM(CASE WHEN is_tax_exempt = 1 THEN 1 ELSE 0 END) as exempt')
                ->get();

            foreach ($lineCounts as $c) {
                if ((int) $c->total > 0 && (int) $c->total === (int) $c->exempt) {
                    $allExempt[(int) $c->transaction_id] = true;
                }
            }
        }

        $summary = ['reporting_off' => 0, 'exempt_internal' => 0, 'failed' => 0];

        foreach ($broken as $row) {
            $update = ['updated_at' => now()];

            // No fiscal number ever existed, so no fiscal QR can be legitimate.
            if ($hasQr) {
                $update['pra_qr_code'] = null;
            }

            if (isset($reportingOff[$row->company_id])) {
                // Rule 1 — reporting is OFF for this shop: a plain local final.
                $update['pra_status'] = null;
                if ($hasError) {
                    $update['pra_error_message'] = null;
                }
                $summary['reporting_off']++;
            } elseif (isset($allExempt[(int) $row->id]) && (! $hasTax || (float) $row->tax_amount == 0.0)) {
                // Rule 2 — the all-exempt bill the old short-circuit meant to stamp.
                $update['pra_status'] = 'exempt_internal';
                if ($hasError) {
                    $update['pra_error_message'] = null;
                }
                $summary['exempt_internal']++;
            } else {
                // Rule 3 — genuinely unexplained: mark it failed so a human sees it.
                $update['pra_status'] = 'failed';
                if ($hasError) {
                    $update['pra_error_message'] = 'Bill "submitted" mark tha magar PRA fiscal number kabhi nahi mila — PRA ko report nahi hua. Retry karein.';
                }
                $summary['failed']++;
            }

            DB::table('pos_transactions')->where('id', $row->id)->update($update);
        }

        Log::info('Task 1475: repaired half-submitted PRA bills', $summary + [
            'total' => $broken->count(),
            'ids' => $broken->pluck('id')->all(),
        ]);
    }

    public function down(): void
    {
        // Irreversible on purpose: restoring pra_status='submitted' would put the
        // bills back into the state where a receipt claims PRA reporting that
        // never happened.
    }
};

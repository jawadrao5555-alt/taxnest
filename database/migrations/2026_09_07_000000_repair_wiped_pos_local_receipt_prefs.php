<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Task 1392 — repair the Local receipt sets a STALE Receipt Settings form wiped.
 *
 * Background (Task 1377 fixed the cause, this repairs the damage it already did):
 * PosController::receiptSettings rewrote invoice_display_prefs['pos_local'] as a
 * WHOLESALE dump of checkbox presence. A POST from a copy of the page rendered
 * BEFORE the Local tab shipped (the service worker used to runtime-cache
 * /pos/receipt-settings) carried no lp_* fields at all, so every key in the block
 * was written false — the shop's local bill lost its tax line, address, cashier
 * and footer without anybody unticking a thing. Every lp_* field shipped in the
 * SAME commit as the Local tab, so this is all-or-nothing: a wipe is always the
 * complete all-false block, never a single key.
 *
 * WHAT IS REPAIRED — only that unmistakable signature:
 *   1. a 'pos_local' block exists and every display toggle stored in it is false,
 *   2. it carries no footer text (a wipe cannot carry one), and
 *   3. the PRA block next to it is NOT itself all-false (a shop that deliberately
 *      hides everything on BOTH bill types is left exactly as it is).
 *
 * HOW: the block is removed, which is precisely the "never customized the Local
 * tab" state — Company::posReceiptPrefs('local') then mirrors that company's PRA
 * set again, the same fallback a brand-new shop gets. Nothing is invented.
 *
 * A company that switched individual Local options off keeps every one of them:
 * a single stored true (or a footer text) means the POST really came from the
 * Local tab and the choice is the user's.
 *
 * Idempotent (PROD runs `migrate --force`, never seeders): once the block is
 * gone there is nothing left to match, so re-running is a no-op.
 */
return new class extends Migration
{
    /** Display toggles a Local block can hold (footer_text is handled separately). */
    private const TOGGLES = [
        'show_address', 'show_ntn', 'show_email', 'show_mobile', 'show_cashier',
        'show_footer', 'show_business_name', 'show_developed_by', 'show_verify_line',
        'show_tax',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('companies') || !Schema::hasColumn('companies', 'invoice_display_prefs')) {
            return;
        }

        $repaired = [];

        DB::table('companies')
            ->whereNotNull('invoice_display_prefs')
            ->select('id', 'invoice_display_prefs')
            ->orderBy('id')
            ->chunk(200, function ($rows) use (&$repaired) {
                foreach ($rows as $row) {
                    $prefs = json_decode((string) $row->invoice_display_prefs, true);
                    if (!is_array($prefs) || !array_key_exists('pos_local', $prefs)) {
                        continue;
                    }
                    if (!$this->isWiped($prefs)) {
                        continue;
                    }

                    unset($prefs['pos_local']);
                    DB::table('companies')->where('id', $row->id)->update([
                        'invoice_display_prefs' => json_encode($prefs),
                    ]);
                    $repaired[] = (int) $row->id;
                }
            });

        if ($repaired !== []) {
            Log::info('[Task 1392] Restored wiped POS Local receipt prefs (now mirroring the PRA set) for companies: '
                . implode(', ', $repaired));
        }
    }

    /**
     * True only for the all-false, no-footer-text Local block written by a stale
     * form, and only when the PRA set beside it still shows something.
     */
    private function isWiped(array $prefs): bool
    {
        $local = $prefs['pos_local'] ?? null;
        if (!is_array($local) || $local === []) {
            return false; // no block / corrupt value — posReceiptPrefs already mirrors PRA
        }

        // 1. Every toggle the block actually stores must be false.
        $seen = 0;
        foreach (self::TOGGLES as $key) {
            if (!array_key_exists($key, $local)) {
                continue;
            }
            $seen++;
            if (filter_var($local[$key], FILTER_VALIDATE_BOOLEAN)) {
                return false; // a deliberate save — the user kept something on
            }
        }
        if ($seen === 0) {
            return false; // nothing recognisable to judge; leave it alone
        }

        // 2. A wipe carries no footer text (the stale form had no lp_footer_text).
        if (trim((string) ($local['footer_text'] ?? '')) !== '') {
            return false;
        }

        // 3. The PRA block must not be all-false too — a shop that hides
        //    everything everywhere means it, and mirroring would change nothing
        //    anyway. An absent/empty PRA block = defaults (all on) = repairable.
        $pra = $prefs['pos'] ?? null;
        if (is_array($pra)) {
            $praSeen = 0;
            $praAllFalse = true;
            foreach (self::TOGGLES as $key) {
                if (!array_key_exists($key, $pra)) {
                    continue;
                }
                $praSeen++;
                if (filter_var($pra[$key], FILTER_VALIDATE_BOOLEAN)) {
                    $praAllFalse = false;
                    break;
                }
            }
            if ($praSeen > 0 && $praAllFalse && trim((string) ($pra['footer_text'] ?? '')) === '') {
                return false;
            }
        }

        return true;
    }

    public function down(): void
    {
        // Intentionally irreversible: the "previous state" is the all-false block
        // nobody chose. Re-writing it would simply re-break those shops' bills.
    }
};

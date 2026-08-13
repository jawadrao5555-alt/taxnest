<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\PosTransaction;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EXEMPT STREAM PREDICATE LOCK — Task 647 (14 Aug 2026).
 *
 * exempt_internal bills (all items tax-exempt, NEVER reported to PRA) are
 * their OWN stream. The single source of truth is PosTransaction:
 *   - applyStreamTab():          'pra' | 'local' | 'exempt' tab split
 *   - applyBillingScopeFilter(): reprint-list / scope-lock query filter
 *   - allowedForBillingScope():  row-level read guard
 *
 * Invariants under lock:
 *   1. exempt_internal appears ONLY in the 'exempt' tab — excluded from BOTH
 *      the PRA and Local tabs, REGARDLESS of invoice_mode (exempt status takes
 *      precedence even over a data-drifted invoice_mode='local' row).
 *   2. Owner decision (14 Aug 2026): exempt bills are visible to BOTH billing
 *      scopes ('pra' AND 'local'), again regardless of invoice_mode.
 *   3. The classic split is unchanged for everything else: PRA = pipeline
 *      bills (non-NULL status or fiscal number); Local = provisionals +
 *      reporting-OFF finals (NULL status + no fiscal).
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/PosExemptStreamPredicateTest.php --testdox
 */
class PosExemptStreamPredicateTest extends TestCase
{
    private const COMPANY_ID = 701;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();

        Schema::create('pos_transactions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('invoice_number');
            $t->string('invoice_mode')->nullable();
            $t->string('pra_status')->nullable();
            $t->string('pra_invoice_number')->nullable();
            $t->string('status')->default('completed');
            $t->boolean('is_archived')->default(false);
            $t->timestamps();
        });

        // The full stream matrix — one row per classification case.
        $rows = [
            // [invoice_number, invoice_mode, pra_status, pra_invoice_number]
            ['PRA-PENDING',   'pra',   'pending',         null],
            ['PRA-FISCAL',    'pra',   'submitted',       '1234567890123456789012345'],
            ['PRA-FAILED',    'pra',   'failed',          null],
            ['LOCAL-PROV',    'local', 'local',           null],
            ['LOCAL-FINAL',   'pra',   null,              null], // reporting-OFF final
            ['EXEMPT-PRA',    'pra',   'exempt_internal', null], // normal stamping path
            ['EXEMPT-LOCAL',  'local', 'exempt_internal', null], // data-drift: status must win
        ];
        foreach ($rows as [$num, $mode, $status, $fiscal]) {
            PosTransaction::forceCreate([
                'company_id' => self::COMPANY_ID,
                'invoice_number' => $num,
                'invoice_mode' => $mode,
                'pra_status' => $status,
                'pra_invoice_number' => $fiscal,
                'status' => 'completed',
            ]);
        }
    }

    private function tabInvoices(string $tab): array
    {
        $q = PosTransaction::where('company_id', self::COMPANY_ID);
        PosTransaction::applyStreamTab($q, $tab);

        return $q->pluck('invoice_number')->sort()->values()->all();
    }

    private function scopeInvoices(string $scope): array
    {
        // Mirrors the callers (apiTodaysBills): the filter is applied inside a
        // wrapping where-closure so the internal orWheres stay grouped.
        return PosTransaction::where('company_id', self::COMPANY_ID)
            ->where(function ($q) use ($scope) {
                PosTransaction::applyBillingScopeFilter($q, $scope);
            })
            ->pluck('invoice_number')->sort()->values()->all();
    }

    // ── 1. Tab split ──────────────────────────────────────────────────────

    public function test_exempt_tab_contains_only_exempt_bills_regardless_of_mode(): void
    {
        $this->assertSame(['EXEMPT-LOCAL', 'EXEMPT-PRA'], $this->tabInvoices('exempt'));
    }

    public function test_pra_tab_excludes_exempt_bills(): void
    {
        $this->assertSame(['PRA-FAILED', 'PRA-FISCAL', 'PRA-PENDING'], $this->tabInvoices('pra'));
    }

    public function test_local_tab_excludes_exempt_bills_even_with_local_mode(): void
    {
        $this->assertSame(['LOCAL-FINAL', 'LOCAL-PROV'], $this->tabInvoices('local'));
    }

    // ── 2. Billing scope (reprint list / stream lock) ─────────────────────

    public function test_pra_scope_sees_pra_stream_plus_all_exempt_bills(): void
    {
        $this->assertSame(
            ['EXEMPT-LOCAL', 'EXEMPT-PRA', 'PRA-FAILED', 'PRA-FISCAL', 'PRA-PENDING'],
            $this->scopeInvoices('pra')
        );
    }

    public function test_local_scope_sees_local_stream_plus_all_exempt_bills(): void
    {
        $this->assertSame(
            ['EXEMPT-LOCAL', 'EXEMPT-PRA', 'LOCAL-FINAL', 'LOCAL-PROV'],
            $this->scopeInvoices('local')
        );
    }

    public function test_both_scope_filter_is_a_noop(): void
    {
        $this->assertCount(7, $this->scopeInvoices('both'));
    }

    // ── 3. Row-level read guard ───────────────────────────────────────────

    public function test_row_guard_grants_exempt_bills_to_every_scope(): void
    {
        foreach (['pra', 'local'] as $mode) {
            $txn = new PosTransaction([
                'invoice_mode' => $mode,
                'pra_status' => PosTransaction::EXEMPT_INTERNAL,
            ]);
            foreach (['both', 'pra', 'local'] as $scope) {
                $this->assertTrue(
                    $txn->allowedForBillingScope($scope),
                    "exempt bill (mode={$mode}) must be visible to scope={$scope}"
                );
            }
        }
    }

    public function test_row_guard_keeps_classic_streams_locked(): void
    {
        $praBill = new PosTransaction(['invoice_mode' => 'pra', 'pra_status' => 'pending']);
        $localBill = new PosTransaction(['invoice_mode' => 'local', 'pra_status' => 'local']);

        $this->assertTrue($praBill->allowedForBillingScope('pra'));
        $this->assertFalse($praBill->allowedForBillingScope('local'));
        $this->assertTrue($localBill->allowedForBillingScope('local'));
        $this->assertFalse($localBill->allowedForBillingScope('pra'));
    }
}

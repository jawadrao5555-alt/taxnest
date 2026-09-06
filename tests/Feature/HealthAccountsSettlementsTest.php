<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\HealthAccountReconciliation;
use App\Models\HealthAccountingSetting;
use App\Models\HealthBill;
use App\Models\HealthBillLine;
use App\Models\HealthCharge;
use App\Models\HealthDoctor;
use App\Models\HealthDoctorShare;
use App\Models\HealthDoctorShareRule;
use App\Models\HealthDoctorSettlement;
use App\Models\HealthExpense;
use App\Models\HealthFiscalPeriod;
use App\Models\HealthJournal;
use App\Models\HealthJournalLine;
use App\Models\HealthPatient;
use App\Models\HealthChargeAdjustment;
use App\Models\HealthPayment;
use App\Models\HealthPharmacySale;
use App\Models\HealthPharmacySaleItem;
use App\Models\HealthVisit;
use App\Models\User;
use App\Services\HealthAccountingReportService as Reports;
use App\Services\HealthBillingService as Billing;
use App\Services\HealthChargeIngestService as Ingest;
use App\Services\HealthChargeService as Charges;
use App\Services\HealthPharmacyCheckoutService as Checkout;
use App\Services\HealthPharmacyService as Pharmacy;
use App\Services\HealthPharmacyStockService as Stock;
use App\Services\HealthChartOfAccountsService as Chart;
use App\Services\HealthDoctorShareService as Shares;
use App\Services\HealthFiscalPeriodService as Periods;
use App\Services\HealthLedgerService as Ledger;
use App\Services\HealthPostingService as Posting;
use App\Support\HealthPanel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * HEALTHCARE ACCOUNTS & SETTLEMENTS (Task 1552).
 *
 * Runs against the REAL migrations, so the accounting schema is under test
 * alongside the behaviour. Locks the promises a set of books cannot be wrong
 * about:
 *
 *  1. IT BALANCES — every posting path writes equal debits and credits, and a
 *     trial balance over the lot comes to zero. An unbalanced ledger makes
 *     every report downstream of it a guess.
 *  2. THE CONCESSION IS VISIBLE — a discount is booked as contra-income, not
 *     netted quietly off the fee, so a hospital giving away money can see how
 *     much.
 *  3. AN ADVANCE IS A LIABILITY — money taken before treatment is owed back
 *     until it is applied, and applying it moves the liability to the
 *     receivable WITHOUT pretending cash arrived a second time.
 *  4. A WRITE-OFF IS A COST — nothing arrived, so nothing may touch cash.
 *  5. POST ONCE — the same source event posted twice produces one journal.
 *  6. NEVER EDIT, ONLY REVERSE — the original entry stays on the record and an
 *     opposite one joins it.
 *  7. DOCTOR SHARES — the most specific rule wins, the rule is frozen onto the
 *     accrual, an excluded share cannot be paid, the expense lands at approval
 *     and the cash only at payment, and nothing can be settled twice.
 *  8. A CLOSED PERIOD IS CLOSED — no later posting may be dated into it.
 *  9. FINANCE CANNOT READ THE FILE — an accountant gets the money, never the
 *     diagnosis.
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/HealthAccountsSettlementsTest.php --testdox
 */
class HealthAccountsSettlementsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $owner;
    private HealthDoctor $doctor;
    private HealthDoctor $surgeon;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Shifa Accounts Test',
            'ntn' => 'ACC-TEST-1',
            'product_type' => HealthPanel::PRODUCT_TYPE,
            'status' => 'approved',
            'company_status' => 'active',
            'health_org_type' => 'hospital',
            'health_modules' => json_encode(['opd', 'billing', 'accounts']),
        ]);

        $this->owner = User::create([
            'name' => 'Accounts Owner',
            'email' => 'accowner@example.test',
            'password' => Hash::make('Passw0rd!2026'),
            'company_id' => $this->company->id,
            'role' => 'user',
            'health_role' => 'health_owner',
            'is_active' => true,
        ]);

        $this->doctor = HealthDoctor::create([
            'company_id' => $this->company->id,
            'name' => 'Dr Physician',
            'consultation_fee' => 1000,
            'is_active' => true,
        ]);

        $this->surgeon = HealthDoctor::create([
            'company_id' => $this->company->id,
            'name' => 'Dr Surgeon',
            'consultation_fee' => 3000,
            'is_active' => true,
        ]);

        Chart::flush();
        Chart::seed($this->company->id, $this->owner);
        Periods::settings($this->company->id);
    }

    // ═══════════════════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════════════════

    private int $seq = 0;

    private function companyId(): int
    {
        return (int) $this->company->id;
    }

    private function accountId(string $key): int
    {
        return (int) Chart::id($this->companyId(), $key);
    }

    /** What one account is worth right now, in debit-positive terms. */
    private function balance(string $key): float
    {
        return round(Ledger::accountBalance($this->companyId(), $this->accountId($key)), 2);
    }

    /**
     * A finalized invoice with one line, plus the charge behind it, which is
     * what the doctor-share accrual reads.
     */
    private function makeBill(
        float $gross,
        float $concession = 0,
        float $tax = 0,
        string $category = 'opd',
        ?HealthDoctor $doctor = null,
        ?string $date = null
    ): HealthBill {
        $this->seq++;
        $date = $date ?: now()->toDateString();
        $net = round($gross - $concession, 2);
        $total = round($net + $tax, 2);

        $patient = HealthPatient::create([
            'company_id' => $this->companyId(),
            'mrn' => 'MR' . str_pad((string) $this->seq, 6, '0', STR_PAD_LEFT),
            'name' => 'Patient ' . $this->seq,
            'gender' => 'male',
            'age_years' => 40,
            'is_active' => true,
        ]);

        $visit = null;
        if ($doctor) {
            $visit = HealthVisit::create([
                'company_id' => $this->companyId(),
                'health_patient_id' => $patient->id,
                'health_doctor_id' => $doctor->id,
                'visit_no' => 'V' . str_pad((string) $this->seq, 6, '0', STR_PAD_LEFT),
                'visit_date' => $date,
                'visit_type' => 'new',
                'status' => 'completed',
                'fee_amount' => $gross,
            ]);
        }

        $bill = HealthBill::create([
            'company_id' => $this->companyId(),
            'health_patient_id' => $patient->id,
            'health_visit_id' => $visit?->id,
            'bill_no' => 'B' . str_pad((string) $this->seq, 6, '0', STR_PAD_LEFT),
            'doc_type' => HealthBill::TYPE_INVOICE,
            'status' => HealthBill::STATUS_FINALIZED,
            'bill_date' => $date,
            'business_date' => $date,
            'gross_amount' => $gross,
            'concession_amount' => $concession,
            'net_amount' => $net,
            'tax_amount' => $tax,
            'total_amount' => $total,
            'patient_payable' => $total,
            'outstanding_amount' => $total,
        ]);

        HealthCharge::create([
            'company_id' => $this->companyId(),
            'health_patient_id' => $patient->id,
            'health_visit_id' => $visit?->id,
            'health_bill_id' => $bill->id,
            'charge_no' => 'C' . str_pad((string) $this->seq, 6, '0', STR_PAD_LEFT),
            'charge_date' => $date,
            'category' => $category,
            'description' => 'Test charge',
            'unit_price' => $gross,
            'quantity' => 1,
            'gross_amount' => $gross,
            'concession_amount' => $concession,
            'net_amount' => $net,
            'tax_amount' => $tax,
            'total_amount' => $total,
        ]);

        HealthBillLine::create([
            'company_id' => $this->companyId(),
            'health_bill_id' => $bill->id,
            'line_no' => 1,
            'category' => $category,
            'description' => 'Test charge',
            'unit_price' => $gross,
            'quantity' => 1,
            'gross_amount' => $gross,
            'concession_amount' => $concession,
            'net_amount' => $net,
            'tax_amount' => $tax,
            'total_amount' => $total,
        ]);

        return $bill->fresh();
    }

    private function makeReceipt(HealthBill $bill, float $amount, string $kind, string $method = 'cash'): HealthPayment
    {
        $this->seq++;

        return HealthPayment::create([
            'company_id' => $this->companyId(),
            'health_patient_id' => $bill->health_patient_id,
            'health_bill_id' => $bill->id,
            'receipt_no' => 'R' . str_pad((string) $this->seq, 6, '0', STR_PAD_LEFT),
            'kind' => $kind,
            'amount' => $amount,
            'method' => $method,
            'received_at' => now(),
            'business_date' => now()->toDateString(),
        ]);
    }

    /** Every journal in the company, and whether each one balances. */
    private function assertEveryJournalBalances(): void
    {
        $journals = HealthJournal::withoutGlobalScopes()
            ->where('company_id', $this->companyId())
            ->get();

        $this->assertGreaterThan(0, $journals->count(), 'nothing was posted at all');

        foreach ($journals as $journal) {
            $lines = HealthJournalLine::withoutGlobalScopes()
                ->where('health_journal_id', $journal->id)
                ->get();

            $this->assertGreaterThanOrEqual(2, $lines->count(), "journal {$journal->journal_no} has too few lines");
            $this->assertEqualsWithDelta(
                round((float) $lines->sum('debit'), 2),
                round((float) $lines->sum('credit'), 2),
                0.005,
                "journal {$journal->journal_no} does not balance"
            );
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // FOUNDATION
    // ═══════════════════════════════════════════════════════════════════

    public function test_chart_seeds_once_and_every_system_account_resolves(): void
    {
        // A second seed on the same company must not duplicate anything.
        Chart::flush();
        Chart::seed($this->companyId(), $this->owner);
        Chart::flush();

        foreach (array_keys(Chart::DEFAULTS) as $key) {
            $this->assertNotNull(Chart::id($this->companyId(), $key), "system account {$key} did not resolve");
        }

        $duplicates = \DB::table('health_accounts')
            ->where('company_id', $this->companyId())
            ->whereNotNull('system_key')
            ->selectRaw('system_key, COUNT(*) as n')
            ->groupBy('system_key')
            ->having('n', '>', 1)
            ->count();

        $this->assertSame(0, $duplicates, 'seeding twice duplicated a system account');
    }

    public function test_an_unbalanced_journal_is_refused(): void
    {
        $result = Ledger::post($this->companyId(), [
            'date' => now()->toDateString(),
            'lines' => [
                ['account' => Chart::CASH, 'debit' => 500],
                ['account' => Chart::INCOME_OPD, 'credit' => 400],
            ],
            'memo' => 'wrong on purpose',
        ], $this->owner);

        $this->assertFalse($result['ok']);
        $this->assertSame('unbalanced', $result['reason']);
        $this->assertSame(0, HealthJournal::withoutGlobalScopes()->where('company_id', $this->companyId())->count());
    }

    public function test_a_journal_cannot_reach_another_hospitals_account(): void
    {
        $other = Company::create([
            'name' => 'Other Hospital',
            'ntn' => 'ACC-TEST-2',
            'product_type' => HealthPanel::PRODUCT_TYPE,
            'status' => 'approved',
            'health_org_type' => 'clinic',
        ]);
        Chart::flush();
        Chart::seed((int) $other->id, null);

        $foreignAccount = (int) Chart::id((int) $other->id, Chart::CASH);

        $result = Ledger::post($this->companyId(), [
            'date' => now()->toDateString(),
            'lines' => [
                ['account_id' => $foreignAccount, 'debit' => 100],
                ['account' => Chart::INCOME_OPD, 'credit' => 100],
            ],
            'memo' => 'cross-company',
        ], $this->owner);

        $this->assertFalse($result['ok']);
        $this->assertSame('unknown_account', $result['reason']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // SOURCE POSTING
    // ═══════════════════════════════════════════════════════════════════

    public function test_a_bill_books_income_receivable_and_a_visible_concession(): void
    {
        $bill = $this->makeBill(gross: 10000, concession: 2000, tax: 400);

        $result = Posting::postBill($bill, $this->owner);
        $this->assertTrue($result['ok']);

        // Income at the FULL fee, the giveaway shown separately.
        $this->assertEqualsWithDelta(10000, $this->balance(Chart::INCOME_OPD), 0.005);
        $this->assertEqualsWithDelta(-2000, $this->balance(Chart::INCOME_CONCESSION), 0.005);
        $this->assertEqualsWithDelta(400, $this->balance(Chart::TAX_PAYABLE), 0.005);
        $this->assertEqualsWithDelta(8400, $this->balance(Chart::PATIENT_RECEIVABLE), 0.005);

        $this->assertEveryJournalBalances();
    }

    public function test_posting_the_same_bill_twice_writes_one_journal(): void
    {
        $bill = $this->makeBill(gross: 5000);

        Posting::postBill($bill, $this->owner);
        Posting::postBill($bill, $this->owner);

        $this->assertSame(1, HealthJournal::withoutGlobalScopes()
            ->where('company_id', $this->companyId())
            ->where('dedupe_key', 'bill:' . $bill->id)
            ->count());

        $this->assertEqualsWithDelta(5000, $this->balance(Chart::PATIENT_RECEIVABLE), 0.005);
    }

    public function test_a_card_receipt_lands_in_card_clearing_not_cash(): void
    {
        $bill = $this->makeBill(gross: 4000);
        Posting::postBill($bill, $this->owner);

        $payment = $this->makeReceipt($bill, 4000, HealthPayment::KIND_PAYMENT, 'card');
        Posting::postPayment($payment, $this->owner);

        $this->assertEqualsWithDelta(0, $this->balance(Chart::CASH), 0.005);
        $this->assertEqualsWithDelta(4000, $this->balance(Chart::CARD_CLEARING), 0.005);
        $this->assertEqualsWithDelta(0, $this->balance(Chart::PATIENT_RECEIVABLE), 0.005);
    }

    public function test_an_advance_is_a_liability_until_it_is_applied(): void
    {
        $this->seq++;
        $patient = HealthPatient::create([
            'company_id' => $this->companyId(),
            'mrn' => 'MRADV01',
            'name' => 'Advance Patient',
            'gender' => 'female',
            'age_years' => 30,
            'is_active' => true,
        ]);

        // Money taken at the counter with no bill yet.
        $deposit = HealthPayment::create([
            'company_id' => $this->companyId(),
            'health_patient_id' => $patient->id,
            'receipt_no' => 'RADV01',
            'kind' => HealthPayment::KIND_DEPOSIT,
            'amount' => 6000,
            'method' => 'cash',
            'received_at' => now(),
            'business_date' => now()->toDateString(),
        ]);

        Posting::postPayment($deposit, $this->owner);

        $this->assertEqualsWithDelta(6000, $this->balance(Chart::CASH), 0.005);
        $this->assertEqualsWithDelta(6000, $this->balance(Chart::PATIENT_ADVANCE), 0.005);
        $this->assertEqualsWithDelta(0, $this->balance(Chart::PATIENT_RECEIVABLE), 0.005);

        // Now it is pointed at a bill. The liability clears against the
        // receivable — and no second rupee of cash appears.
        $bill = $this->makeBill(gross: 6000);
        Posting::postBill($bill, $this->owner);

        $deposit->update(['health_bill_id' => $bill->id]);
        Posting::postPayment($deposit->fresh(), $this->owner);

        $this->assertEqualsWithDelta(6000, $this->balance(Chart::CASH), 0.005, 'cash was counted twice');
        $this->assertEqualsWithDelta(0, $this->balance(Chart::PATIENT_ADVANCE), 0.005);
        $this->assertEqualsWithDelta(0, $this->balance(Chart::PATIENT_RECEIVABLE), 0.005);

        $this->assertEveryJournalBalances();
    }

    public function test_a_write_off_is_a_cost_and_never_touches_cash(): void
    {
        $bill = $this->makeBill(gross: 3000);
        Posting::postBill($bill, $this->owner);

        $writeOff = $this->makeReceipt($bill, 3000, HealthPayment::KIND_WRITE_OFF, 'other');
        Posting::postPayment($writeOff, $this->owner);

        $this->assertEqualsWithDelta(0, $this->balance(Chart::CASH), 0.005);
        $this->assertEqualsWithDelta(3000, $this->balance(Chart::EXPENSE_WRITE_OFF), 0.005);
        $this->assertEqualsWithDelta(0, $this->balance(Chart::PATIENT_RECEIVABLE), 0.005);
    }

    public function test_a_refund_takes_cash_back_out(): void
    {
        $bill = $this->makeBill(gross: 2000);
        Posting::postBill($bill, $this->owner);
        Posting::postPayment($this->makeReceipt($bill, 2000, HealthPayment::KIND_PAYMENT), $this->owner);

        $refund = $this->makeReceipt($bill, 500, HealthPayment::KIND_REFUND);
        Posting::postPayment($refund, $this->owner);

        $this->assertEqualsWithDelta(1500, $this->balance(Chart::CASH), 0.005);
        $this->assertEqualsWithDelta(500, $this->balance(Chart::PATIENT_RECEIVABLE), 0.005);
    }

    public function test_an_expense_on_credit_creates_a_payable_not_a_payment(): void
    {
        $expense = HealthExpense::create([
            'company_id' => $this->companyId(),
            'expense_no' => 'E000001',
            'expense_date' => now()->toDateString(),
            'payee' => 'Landlord',
            'amount' => 50000,
            'total_amount' => 50000,
            'pay_mode' => 'credit',
            'status' => 'posted',
            'description' => 'Monthly rent',
        ]);

        Posting::postExpense($expense, $this->owner);

        $this->assertEqualsWithDelta(0, $this->balance(Chart::CASH), 0.005);
        $this->assertEqualsWithDelta(50000, $this->balance(Chart::EXPENSE_PAYABLE), 0.005);
        $this->assertEqualsWithDelta(50000, $this->balance(Chart::EXPENSE_GENERAL), 0.005);
    }

    // ═══════════════════════════════════════════════════════════════════
    // REVERSAL
    // ═══════════════════════════════════════════════════════════════════

    public function test_reversing_leaves_the_original_on_the_record(): void
    {
        $bill = $this->makeBill(gross: 7000);
        Posting::postBill($bill, $this->owner);

        $journal = HealthJournal::withoutGlobalScopes()
            ->where('company_id', $this->companyId())
            ->where('dedupe_key', 'bill:' . $bill->id)
            ->first();

        $result = Ledger::reverse($journal, $this->owner, 'wrong patient');
        $this->assertTrue($result['ok']);

        $this->assertSame(HealthJournal::STATUS_REVERSED, $journal->fresh()->status);
        $this->assertSame(2, HealthJournal::withoutGlobalScopes()->where('company_id', $this->companyId())->count());
        $this->assertEqualsWithDelta(0, $this->balance(Chart::PATIENT_RECEIVABLE), 0.005);

        // Twice is refused — otherwise a reversal could be used to invent money.
        $second = Ledger::reverse($journal->fresh(), $this->owner, 'again');
        $this->assertFalse($second['ok']);
        $this->assertSame('already_reversed', $second['reason']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // DOCTOR SHARES
    // ═══════════════════════════════════════════════════════════════════

    /** Branch A, branch B, and an accountant who may only reach A. */
    private function branchConfinedAccountant(): array
    {
        $branchA = \App\Models\Branch::create([
            'company_id' => $this->companyId(),
            'name' => 'Main Campus',
            'code' => 'MAIN',
            'is_head_office' => true,
            'is_active' => true,
        ]);
        $branchB = \App\Models\Branch::create([
            'company_id' => $this->companyId(),
            'name' => 'City Branch',
            'code' => 'CITY',
            'is_active' => true,
        ]);

        $this->seq++;
        $accountant = User::create([
            'name' => 'Branch Accountant',
            'email' => 'branchacc' . $this->seq . '@example.test',
            'password' => Hash::make('Passw0rd!2026'),
            'company_id' => $this->companyId(),
            'role' => 'user',
            'health_role' => 'health_accountant',
            'is_active' => true,
        ]);

        \DB::table('branch_user')->insert([
            'user_id' => $accountant->id,
            'branch_id' => $branchA->id,
            'access_level' => 'full',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \App\Services\HealthScopeService::forget();

        return [$branchA, $branchB, $accountant];
    }

    private function makeShare(int $branchId, HealthDoctor $doctor, float $amount, string $label): HealthDoctorShare
    {
        return HealthDoctorShare::create([
            'company_id' => $this->companyId(),
            'branch_id' => $branchId,
            'health_doctor_id' => $doctor->id,
            'accrual_date' => now()->toDateString(),
            'charge_category' => 'opd',
            'description' => $label,
            'basis' => 'percent',
            'rate' => 10,
            'base' => 'net',
            'base_amount' => $amount * 10,
            'share_amount' => $amount,
            'status' => HealthDoctorShare::STATUS_ACCRUED,
        ]);
    }

    private function makeRule(array $overrides = []): HealthDoctorShareRule
    {
        return HealthDoctorShareRule::create(array_merge([
            'company_id' => $this->companyId(),
            'name' => 'Rule',
            'basis' => 'percent',
            'value' => 40,
            'base' => 'net',
            'priority' => 0,
            'is_active' => true,
        ], $overrides));
    }

    public function test_the_most_specific_rule_wins(): void
    {
        $this->makeRule(['name' => 'House default', 'value' => 30]);
        $this->makeRule([
            'name' => 'This doctor',
            'health_doctor_id' => $this->doctor->id,
            'value' => 55,
        ]);

        $bill = $this->makeBill(gross: 10000, doctor: $this->doctor);
        Posting::postBill($bill, $this->owner);

        $out = Shares::accrue($this->companyId(), now()->startOfMonth()->toDateString(), now()->toDateString(), $this->owner);
        $this->assertSame(1, $out['created']);

        $share = HealthDoctorShare::withoutGlobalScopes()->where('company_id', $this->companyId())->first();
        $this->assertSame($this->doctor->id, (int) $share->health_doctor_id);
        $this->assertEqualsWithDelta(5500, (float) $share->share_amount, 0.005);

        // The rule is frozen onto the accrual, so changing it later cannot
        // silently restate what a doctor was already told they earned.
        $this->assertEqualsWithDelta(55, (float) $share->rate, 0.005);
        $this->assertSame('percent', $share->basis);
        $this->assertSame('net', $share->base);
    }

    public function test_accruing_twice_does_not_pay_a_doctor_twice(): void
    {
        $this->makeRule(['value' => 50]);
        $bill = $this->makeBill(gross: 4000, doctor: $this->doctor);
        Posting::postBill($bill, $this->owner);

        $from = now()->startOfMonth()->toDateString();
        $to = now()->toDateString();

        Shares::accrue($this->companyId(), $from, $to, $this->owner);
        $second = Shares::accrue($this->companyId(), $from, $to, $this->owner);

        $this->assertSame(0, $second['created']);
        $this->assertSame(1, HealthDoctorShare::withoutGlobalScopes()->where('company_id', $this->companyId())->count());
    }

    public function test_an_excluded_share_cannot_be_settled_and_a_rerun_does_not_revive_it(): void
    {
        $this->makeRule(['value' => 50]);
        $bill = $this->makeBill(gross: 4000, doctor: $this->doctor);
        Posting::postBill($bill, $this->owner);

        $from = now()->startOfMonth()->toDateString();
        $to = now()->toDateString();
        Shares::accrue($this->companyId(), $from, $to, $this->owner);

        $share = HealthDoctorShare::withoutGlobalScopes()->where('company_id', $this->companyId())->first();
        Shares::exclude($share, 'charged in error', $this->owner);

        // A re-run must not quietly undo a decision somebody made on purpose.
        Shares::accrue($this->companyId(), $from, $to, $this->owner);
        $this->assertSame(HealthDoctorShare::STATUS_EXCLUDED, $share->fresh()->status);

        $this->expectException(ValidationException::class);
        Shares::buildSettlement($this->companyId(), (int) $this->doctor->id, $from, $to, $this->owner);
    }

    public function test_a_settlement_expenses_at_approval_and_pays_cash_only_at_payment(): void
    {
        $this->makeRule(['value' => 50]);
        $bill = $this->makeBill(gross: 10000, doctor: $this->doctor);
        Posting::postBill($bill, $this->owner);
        Posting::postPayment($this->makeReceipt($bill, 10000, HealthPayment::KIND_PAYMENT), $this->owner);

        $from = now()->startOfMonth()->toDateString();
        $to = now()->toDateString();
        Shares::accrue($this->companyId(), $from, $to, $this->owner);

        $settlement = Shares::buildSettlement($this->companyId(), (int) $this->doctor->id, $from, $to, $this->owner);
        $this->assertEqualsWithDelta(5000, (float) $settlement->gross_amount, 0.005);
        $this->assertSame(HealthDoctorSettlement::STATUS_DRAFT, $settlement->status);

        // Nothing in the books yet — a draft is a proposal, not a cost.
        $this->assertEqualsWithDelta(0, $this->balance(Chart::EXPENSE_DOCTOR_SHARE), 0.005);

        Shares::approve($settlement, $this->owner);
        $settlement = $settlement->fresh();

        $this->assertSame(HealthDoctorSettlement::STATUS_APPROVED, $settlement->status);
        $this->assertEqualsWithDelta(5000, $this->balance(Chart::EXPENSE_DOCTOR_SHARE), 0.005);
        $this->assertEqualsWithDelta(5000, $this->balance(Chart::DOCTOR_SHARE_PAYABLE), 0.005);
        $this->assertEqualsWithDelta(10000, $this->balance(Chart::CASH), 0.005, 'cash moved before it was paid');

        Shares::pay($settlement, 'cash', null, 'CH-1001', $this->owner);
        $settlement = $settlement->fresh();

        $this->assertSame(HealthDoctorSettlement::STATUS_PAID, $settlement->status);
        $this->assertEqualsWithDelta(5000, $this->balance(Chart::CASH), 0.005);
        $this->assertEqualsWithDelta(0, $this->balance(Chart::DOCTOR_SHARE_PAYABLE), 0.005);
        $this->assertEqualsWithDelta(5000, $this->balance(Chart::EXPENSE_DOCTOR_SHARE), 0.005);

        $this->assertEveryJournalBalances();
    }

    public function test_a_share_already_on_a_settlement_cannot_join_a_second_one(): void
    {
        $this->makeRule(['value' => 50]);
        $bill = $this->makeBill(gross: 8000, doctor: $this->doctor);
        Posting::postBill($bill, $this->owner);

        $from = now()->startOfMonth()->toDateString();
        $to = now()->toDateString();
        Shares::accrue($this->companyId(), $from, $to, $this->owner);
        Shares::buildSettlement($this->companyId(), (int) $this->doctor->id, $from, $to, $this->owner);

        $this->expectException(ValidationException::class);
        Shares::buildSettlement($this->companyId(), (int) $this->doctor->id, $from, $to, $this->owner);
    }

    public function test_reversing_a_paid_settlement_undoes_the_books_and_frees_the_shares(): void
    {
        $this->makeRule(['value' => 50]);
        $bill = $this->makeBill(gross: 6000, doctor: $this->doctor);
        Posting::postBill($bill, $this->owner);
        Posting::postPayment($this->makeReceipt($bill, 6000, HealthPayment::KIND_PAYMENT), $this->owner);

        $from = now()->startOfMonth()->toDateString();
        $to = now()->toDateString();
        Shares::accrue($this->companyId(), $from, $to, $this->owner);

        $settlement = Shares::buildSettlement($this->companyId(), (int) $this->doctor->id, $from, $to, $this->owner);
        Shares::approve($settlement, $this->owner);
        Shares::pay($settlement->fresh(), 'cash', null, null, $this->owner);

        $result = Shares::reverse($settlement->fresh(), 'paid the wrong doctor', $this->owner);
        $this->assertTrue($result['ok']);

        $this->assertSame(HealthDoctorSettlement::STATUS_REVERSED, $settlement->fresh()->status);
        $this->assertEqualsWithDelta(0, $this->balance(Chart::EXPENSE_DOCTOR_SHARE), 0.005);
        $this->assertEqualsWithDelta(0, $this->balance(Chart::DOCTOR_SHARE_PAYABLE), 0.005);
        $this->assertEqualsWithDelta(6000, $this->balance(Chart::CASH), 0.005);

        $this->assertEveryJournalBalances();
    }

    public function test_shares_do_not_accrue_when_the_hospital_has_switched_them_off(): void
    {
        HealthAccountingSetting::withoutGlobalScopes()
            ->where('company_id', $this->companyId())
            ->update(['doctor_shares_enabled' => false]);

        $this->makeRule(['value' => 50]);
        $bill = $this->makeBill(gross: 4000, doctor: $this->doctor);
        Posting::postBill($bill, $this->owner);

        $out = Shares::accrue($this->companyId(), now()->startOfMonth()->toDateString(), now()->toDateString(), $this->owner);

        $this->assertSame('disabled', $out['skipped']);
        $this->assertSame(0, HealthDoctorShare::withoutGlobalScopes()->where('company_id', $this->companyId())->count());
    }

    public function test_on_a_collected_basis_an_unpaid_bill_earns_nothing_yet(): void
    {
        HealthAccountingSetting::withoutGlobalScopes()
            ->where('company_id', $this->companyId())
            ->update(['doctor_share_basis' => HealthAccountingSetting::BASIS_COLLECTED]);

        $this->makeRule(['value' => 50]);
        $bill = $this->makeBill(gross: 9000, doctor: $this->doctor);
        Posting::postBill($bill, $this->owner);

        $from = now()->startOfMonth()->toDateString();
        $to = now()->toDateString();

        $this->assertSame(0, Shares::accrue($this->companyId(), $from, $to, $this->owner)['created']);

        // Once the money is actually in, the same charge does earn.
        $bill->update(['paid_amount' => 9000, 'outstanding_amount' => 0]);

        $this->assertSame(1, Shares::accrue($this->companyId(), $from, $to, $this->owner)['created']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // PERIODS
    // ═══════════════════════════════════════════════════════════════════

    public function test_a_closed_period_refuses_a_later_posting(): void
    {
        $date = now()->startOfMonth()->toDateString();
        $period = Periods::ensureFor($this->companyId(), $date);

        $closed = Periods::close($period, $this->owner, 'month end');
        $this->assertTrue($closed['ok']);
        $this->assertSame(HealthFiscalPeriod::STATUS_CLOSED, $period->fresh()->status);

        $result = Ledger::post($this->companyId(), [
            'date' => $date,
            'lines' => [
                ['account' => Chart::CASH, 'debit' => 100],
                ['account' => Chart::INCOME_OTHER, 'credit' => 100],
            ],
            'memo' => 'too late',
        ], $this->owner);

        $this->assertFalse($result['ok']);
        $this->assertSame('period_closed', $result['reason']);
    }

    public function test_a_second_period_close_never_overwrites_the_frozen_snapshot(): void
    {
        $date = now()->startOfMonth()->toDateString();

        $this->assertTrue(Ledger::post($this->companyId(), [
            'date' => $date,
            'lines' => [
                ['account' => Chart::CASH, 'debit' => 700],
                ['account' => Chart::INCOME_OTHER, 'credit' => 700],
            ],
            'memo' => 'in the month',
        ], $this->owner)['ok']);

        $period = Periods::ensureFor($this->companyId(), $date);
        $this->assertTrue(Periods::close($period, $this->owner, 'month end')['ok']);

        // The close hands the caller a shut period, not the stale open copy it
        // arrived with.
        $this->assertSame(HealthFiscalPeriod::STATUS_CLOSED, $period->status);

        $frozen = $period->fresh()->closing_snapshot;
        $this->assertNotEmpty($frozen['rows'] ?? []);

        $second = Periods::close($period->fresh(), $this->owner, 'again');
        $this->assertFalse($second['ok']);
        $this->assertSame('already_closed', $second['reason']);

        $this->assertSame(
            $frozen,
            $period->fresh()->closing_snapshot,
            'a second close rewrote the statement the first one froze'
        );
    }

    public function test_an_entry_cannot_slip_into_a_period_that_closes_under_it(): void
    {
        $date = now()->startOfMonth()->toDateString();
        $period = Periods::ensureFor($this->companyId(), $date);

        $entry = [
            'date' => $date,
            'lines' => [
                ['account' => Chart::CASH, 'debit' => 250],
                ['account' => Chart::INCOME_OTHER, 'credit' => 250],
            ],
            'memo' => 'in flight',
        ];

        // Proof the entry itself is acceptable while the month is open.
        $this->assertTrue(Ledger::post($this->companyId(), $entry, $this->owner)['ok']);
        $before = HealthJournal::withoutGlobalScopes()->count();

        /*
         * The race, made deterministic: the month is open when the poster
         * checks, and shut by the time it writes. The trap fires only for a
         * read taken INSIDE the posting transaction (the test itself already
         * holds one, so the posting savepoint is the level above), which is
         * exactly the read that must decide the outcome.
         */
        $trapped = 0;
        HealthFiscalPeriod::retrieved(function ($model) use (&$trapped) {
            if (DB::transactionLevel() >= 2) {
                $trapped++;
                $model->status = HealthFiscalPeriod::STATUS_CLOSED;
            }
        });

        $late = Ledger::post($this->companyId(), $entry + ['memo' => 'too late'], $this->owner);

        $this->assertSame(1, $trapped, 'the posting transaction never re-read the period it was writing into');
        $this->assertFalse($late['ok'] ?? false);
        $this->assertSame('period_closed', $late['reason']);
        $this->assertSame($before, HealthJournal::withoutGlobalScopes()->count(), 'an entry landed in a period that had closed');
        $this->assertSame(
            $before,
            HealthJournal::withoutGlobalScopes()->where('health_fiscal_period_id', $period->id)->count()
        );
    }

    public function test_closing_a_reconciliation_twice_parks_the_difference_only_once(): void
    {
        $recon = HealthAccountReconciliation::withoutGlobalScopes()->create([
            'company_id' => $this->companyId(),
            'health_account_id' => $this->accountId(Chart::CASH),
            'statement_date' => now()->toDateString(),
            'book_balance' => 1000,
            'statement_balance' => 900,
            'difference' => -100,
            'status' => HealthAccountReconciliation::STATUS_OPEN,
        ]);

        $close = fn () => $this->actingAs($this->owner->fresh(), HealthPanel::GUARD)
            ->post('/health/accounts/reconciliations/' . $recon->id . '/close', ['adjust' => 1]);

        $close();

        $this->assertSame(HealthAccountReconciliation::STATUS_CLOSED, $recon->fresh()->status);
        $this->assertEqualsWithDelta(100, $this->balance(Chart::SUSPENSE), 0.005);
        $parked = $this->balance(Chart::SUSPENSE);
        $journalId = (int) $recon->fresh()->adjustment_journal_id;
        $this->assertGreaterThan(0, $journalId);

        // The button pressed a second time.
        $close();

        $this->assertSame(1, HealthJournal::withoutGlobalScopes()->where('dedupe_key', 'recadj:' . $recon->id)->count());
        $this->assertEqualsWithDelta($parked, $this->balance(Chart::SUSPENSE), 0.005, 'the same difference was parked twice');
        $this->assertSame($journalId, (int) $recon->fresh()->adjustment_journal_id);
    }

    public function test_a_reconciliation_close_that_died_halfway_cannot_park_the_difference_again(): void
    {
        $recon = HealthAccountReconciliation::withoutGlobalScopes()->create([
            'company_id' => $this->companyId(),
            'health_account_id' => $this->accountId(Chart::CASH),
            'statement_date' => now()->toDateString(),
            'book_balance' => 500,
            'statement_balance' => 560,
            'difference' => 60,
            'status' => HealthAccountReconciliation::STATUS_OPEN,
        ]);

        $this->actingAs($this->owner->fresh(), HealthPanel::GUARD)
            ->post('/health/accounts/reconciliations/' . $recon->id . '/close', ['adjust' => 1]);

        $parked = $this->balance(Chart::SUSPENSE);
        $journalId = (int) $recon->fresh()->adjustment_journal_id;

        // A run that posted the adjustment and died before stamping the row
        // would leave exactly this behind: an open reconciliation whose money
        // is already in the books.
        HealthAccountReconciliation::withoutGlobalScopes()->where('id', $recon->id)->update([
            'status' => HealthAccountReconciliation::STATUS_OPEN,
            'adjustment_journal_id' => null,
            'closed_at' => null,
        ]);

        $this->actingAs($this->owner->fresh(), HealthPanel::GUARD)
            ->post('/health/accounts/reconciliations/' . $recon->id . '/close', ['adjust' => 1]);

        $this->assertSame(1, HealthJournal::withoutGlobalScopes()->where('dedupe_key', 'recadj:' . $recon->id)->count());
        $this->assertEqualsWithDelta($parked, $this->balance(Chart::SUSPENSE), 0.005);
        $this->assertSame(HealthAccountReconciliation::STATUS_CLOSED, $recon->fresh()->status);
        $this->assertSame($journalId, (int) $recon->fresh()->adjustment_journal_id, 'the retry pointed the reconciliation at a different entry');
        $this->assertEveryJournalBalances();
    }

    public function test_a_period_cannot_be_closed_while_an_earlier_one_is_open(): void
    {
        $earlier = Periods::ensureFor($this->companyId(), now()->subMonth()->startOfMonth()->toDateString());
        $later = Periods::ensureFor($this->companyId(), now()->startOfMonth()->toDateString());

        $this->assertNotNull($earlier);

        $result = Periods::close($later, $this->owner, '');
        $this->assertFalse($result['ok']);
        $this->assertSame('earlier_period_open', $result['reason']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // REPORTING
    // ═══════════════════════════════════════════════════════════════════

    public function test_the_trial_balance_comes_to_zero_across_every_posting_path(): void
    {
        $this->makeRule(['value' => 40]);

        $billA = $this->makeBill(gross: 12000, concession: 2000, tax: 500, doctor: $this->doctor);
        Posting::postBill($billA, $this->owner);
        Posting::postPayment($this->makeReceipt($billA, 6000, HealthPayment::KIND_PAYMENT), $this->owner);
        Posting::postPayment($this->makeReceipt($billA, 2000, HealthPayment::KIND_PAYMENT, 'card'), $this->owner);
        Posting::postPayment($this->makeReceipt($billA, 2500, HealthPayment::KIND_WRITE_OFF, 'other'), $this->owner);

        $billB = $this->makeBill(gross: 20000, category: 'operation', doctor: $this->surgeon);
        Posting::postBill($billB, $this->owner);
        Posting::postPayment($this->makeReceipt($billB, 20000, HealthPayment::KIND_PAYMENT), $this->owner);

        Posting::postExpense(HealthExpense::create([
            'company_id' => $this->companyId(),
            'expense_no' => 'E000009',
            'expense_date' => now()->toDateString(),
            'payee' => 'Utility Co',
            'amount' => 3000,
            'total_amount' => 3000,
            'pay_mode' => 'cash',
            'status' => 'posted',
            'description' => 'Electricity',
        ]), $this->owner);

        $from = now()->startOfMonth()->toDateString();
        $to = now()->toDateString();
        Shares::accrue($this->companyId(), $from, $to, $this->owner);

        foreach ([$this->doctor, $this->surgeon] as $doc) {
            $settlement = Shares::buildSettlement($this->companyId(), (int) $doc->id, $from, $to, $this->owner);
            Shares::approve($settlement, $this->owner);
            Shares::pay($settlement->fresh(), 'cash', null, null, $this->owner);
        }

        $this->assertEveryJournalBalances();

        $trial = Reports::trialBalance($this->companyId(), null, $to);
        $this->assertEqualsWithDelta(
            round((float) $trial['total_debit'], 2),
            round((float) $trial['total_credit'], 2),
            0.005,
            'the trial balance does not balance'
        );
        $this->assertTrue((bool) $trial['balanced']);

        // The balance sheet has to agree with itself on the same date.
        $sheet = Reports::balanceSheet($this->companyId(), $to);
        $this->assertEqualsWithDelta(
            round((float) $sheet['asset_total'], 2),
            round((float) $sheet['liability_total'] + (float) $sheet['equity_total'], 2),
            0.005,
            'the balance sheet does not balance'
        );
        $this->assertTrue((bool) $sheet['balanced']);

        // And profit and loss has to agree with the accounts it came from.
        $pnl = Reports::profitAndLoss($this->companyId(), $from, $to);
        $this->assertEqualsWithDelta(
            round((float) $pnl['income_total'] - (float) $pnl['cost_of_sales_total'] - (float) $pnl['expense_total'], 2),
            round((float) $pnl['net_profit'], 2),
            0.005
        );
    }

    public function test_receivables_ageing_only_counts_what_is_still_owed(): void
    {
        $paid = $this->makeBill(gross: 5000);
        Posting::postBill($paid, $this->owner);
        Posting::postPayment($this->makeReceipt($paid, 5000, HealthPayment::KIND_PAYMENT), $this->owner);
        $paid->update(['paid_amount' => 5000, 'outstanding_amount' => 0, 'status' => HealthBill::STATUS_SETTLED]);

        $owing = $this->makeBill(gross: 8000, date: now()->subDays(45)->toDateString());
        Posting::postBill($owing, $this->owner);

        $aging = Reports::receivablesAging($this->companyId(), now()->toDateString());

        $this->assertEqualsWithDelta(8000, round((float) $aging['total'], 2), 0.005);
        $this->assertEqualsWithDelta(8000, round((float) $aging['buckets']['d60'], 2), 0.005);
    }

    // ═══════════════════════════════════════════════════════════════════
    // WHO MAY SEE WHAT
    // ═══════════════════════════════════════════════════════════════════

    // ═══════════════════════════════════════════════════════════════════
    // THE WORKSPACE ACTUALLY OPENS
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Every accountant page renders with real data behind it.
     *
     * A page that compiles is not a page that opens: a missing view variable,
     * an un-eager-loaded relation (fatal on live, silent here) or a stray route
     * name all pass a syntax check and fail in front of the accountant.
     */
    public function test_every_accounts_page_opens_with_real_data_behind_it(): void
    {
        $this->makeRule(['value' => 40]);

        $bill = $this->makeBill(gross: 15000, concession: 1000, tax: 300, doctor: $this->doctor);
        Posting::postBill($bill, $this->owner);
        Posting::postPayment($this->makeReceipt($bill, 5000, HealthPayment::KIND_PAYMENT), $this->owner);

        Posting::postExpense(HealthExpense::create([
            'company_id' => $this->companyId(),
            'expense_no' => 'E000021',
            'expense_date' => now()->toDateString(),
            'payee' => 'Cleaning Co',
            'amount' => 1200,
            'total_amount' => 1200,
            'pay_mode' => 'cash',
            'status' => 'posted',
            'description' => 'Housekeeping',
        ]), $this->owner);

        $from = now()->startOfMonth()->toDateString();
        $to = now()->toDateString();
        Shares::accrue($this->companyId(), $from, $to, $this->owner);
        $settlement = Shares::buildSettlement($this->companyId(), (int) $this->doctor->id, $from, $to, $this->owner);

        $journal = HealthJournal::withoutGlobalScopes()
            ->where('company_id', $this->companyId())
            ->orderBy('id')
            ->first();

        $this->actingAs($this->owner->fresh(), HealthPanel::GUARD);

        $pages = [
            '/health/accounts',
            '/health/accounts/chart',
            '/health/accounts/journals',
            '/health/accounts/journals/' . $journal->id,
            '/health/accounts/expenses',
            '/health/accounts/transfers',
            '/health/accounts/reconciliations',
            '/health/accounts/periods',
            '/health/accounts/settings',
            '/health/accounts/doctor-shares',
            '/health/accounts/doctor-shares/rules',
            '/health/accounts/settlements',
            '/health/accounts/settlements/' . $settlement->id,
            '/health/accounts/doctors/' . $this->doctor->id . '/statement',
            '/health/accounts/reports',
            '/health/accounts/reports/trial-balance',
            '/health/accounts/reports/ledger?account_id=' . $this->accountId(Chart::CASH),
            '/health/accounts/reports/profit-loss',
            '/health/accounts/reports/balance-sheet',
            '/health/accounts/reports/cash-flow',
            '/health/accounts/reports/receivables',
            '/health/accounts/reports/payables',
            '/health/accounts/reports/profitability',
        ];

        foreach ($pages as $page) {
            $this->get($page)->assertOk();
        }

        // And the CSV side of a report, which a different code path builds.
        $csv = $this->get('/health/accounts/reports/trial-balance?export=csv');
        $csv->assertOk();
        $this->assertStringContainsString('text/csv', (string) $csv->headers->get('content-type'));
    }

    /**
     * A consultant sees their OWN money and nothing else.
     *
     * The earnings page sits outside the accounts group on purpose: it resolves
     * the doctor from the signed-in account, so it can be given to every
     * consultant without handing any of them the hospital's books.
     */
    public function test_a_doctor_sees_their_own_earnings_but_not_the_books(): void
    {
        $this->makeRule(['value' => 40]);
        $bill = $this->makeBill(gross: 10000, doctor: $this->doctor);
        Posting::postBill($bill, $this->owner);
        Shares::accrue($this->companyId(), now()->startOfMonth()->toDateString(), now()->toDateString(), $this->owner);

        $doctorUser = User::create([
            'name' => 'Dr Physician',
            'email' => 'accdoc@example.test',
            'password' => Hash::make('Passw0rd!2026'),
            'company_id' => $this->companyId(),
            'role' => 'user',
            'health_role' => 'health_doctor',
            'is_active' => true,
        ]);
        $this->doctor->update(['user_id' => $doctorUser->id]);

        $this->actingAs($doctorUser->fresh(), HealthPanel::GUARD);

        $this->get('/health/my/earnings')->assertOk();

        // The workspace itself, and anybody else's statement, stay shut.
        $this->get('/health/accounts')->assertForbidden();
        $this->get('/health/accounts/doctors/' . $this->surgeon->id . '/statement')->assertForbidden();
    }

    /**
     * An overpayment is in the books the moment it is taken.
     *
     * The counter takes a round 5,000 against a 4,300 bill. Two receipts are
     * written — the 4,300 the bill owed and the 700 that became the patient's
     * credit — and BOTH must post there and then. Posting only the first leaves
     * the drawer understated by the surplus and the hospital not showing the
     * money it owes back, until somebody happens to run a sweep. Cash the books
     * do not know about is the shape every till shortage investigation starts
     * from.
     */
    public function test_an_overpayment_posts_its_surplus_straight_away(): void
    {
        $bill = $this->makeBill(gross: 4300);
        Posting::postBill($bill, $this->owner);

        $cashBefore = $this->balance(Chart::CASH);
        $advanceBefore = $this->balance(Chart::PATIENT_ADVANCE);

        $out = \App\Services\HealthBillingService::recordPayment(
            $this->companyId(),
            (int) $bill->health_patient_id,
            ['health_bill_id' => $bill->id, 'amount' => 5000, 'method' => 'cash'],
            $this->owner
        );

        $this->assertTrue($out['ok']);
        $this->assertEqualsWithDelta(700, (float) $out['credited'], 0.005, 'the surplus was not split off');

        // The whole 5,000 is in the drawer, not just the part the bill owed.
        $this->assertEqualsWithDelta($cashBefore + 5000, $this->balance(Chart::CASH), 0.005);

        // And the hospital is now showing the 700 it owes back.
        $this->assertEqualsWithDelta($advanceBefore + 700, $this->balance(Chart::PATIENT_ADVANCE), 0.005);

        // Both receipts, not one, reached the ledger.
        $receiptIds = HealthPayment::withoutGlobalScopes()
            ->where('company_id', $this->companyId())
            ->pluck('id')
            ->all();
        $this->assertCount(2, $receiptIds, 'the surplus did not get its own receipt');

        $posted = HealthJournal::withoutGlobalScopes()
            ->where('company_id', $this->companyId())
            ->where('source_type', HealthJournal::SRC_PAYMENT)
            ->whereIn('source_id', $receiptIds)
            ->pluck('source_id')
            ->unique();
        $this->assertCount(2, $posted, 'a receipt was created without being posted');

        $this->assertEveryJournalBalances();
    }

    /**
     * A branch boundary holds on the finance screens too.
     *
     * An accountant posted to one branch is not a smaller owner: the other
     * branch's journals, expenses and doctor accruals are out of reach on the
     * lists, on a typed-in URL, in an export, and on every write. Hiding a row
     * from a list while the id still opens it is not a boundary, it is a
     * decoration.
     */
    public function test_a_branch_confined_accountant_cannot_reach_another_branch(): void
    {
        $branchA = \App\Models\Branch::create([
            'company_id' => $this->companyId(),
            'name' => 'Main Campus',
            'code' => 'MAIN',
            'is_head_office' => true,
            'is_active' => true,
        ]);
        $branchB = \App\Models\Branch::create([
            'company_id' => $this->companyId(),
            'name' => 'City Branch',
            'code' => 'CITY',
            'is_active' => true,
        ]);

        $accountant = User::create([
            'name' => 'Branch Accountant',
            'email' => 'branchacc@example.test',
            'password' => Hash::make('Passw0rd!2026'),
            'company_id' => $this->companyId(),
            'role' => 'user',
            'health_role' => 'health_accountant',
            'is_active' => true,
        ]);

        \DB::table('branch_user')->insert([
            'user_id' => $accountant->id,
            'branch_id' => $branchA->id,
            'access_level' => 'full',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \App\Services\HealthScopeService::forget();

        $expense = function (int $branchId, string $no, float $amount) {
            return Posting::postExpense(HealthExpense::create([
                'company_id' => $this->companyId(),
                'branch_id' => $branchId,
                'expense_no' => $no,
                'expense_date' => now()->toDateString(),
                'payee' => 'Vendor ' . $no,
                'amount' => $amount,
                'total_amount' => $amount,
                'pay_mode' => 'cash',
                'status' => 'posted',
                'description' => 'Branch expense ' . $no,
            ]), $this->owner);
        };

        $expense((int) $branchA->id, 'E900001', 1100);
        $expense((int) $branchB->id, 'E900002', 2200);

        $mine = HealthExpense::withoutGlobalScopes()->where('expense_no', 'E900001')->first();
        $theirs = HealthExpense::withoutGlobalScopes()->where('expense_no', 'E900002')->first();

        $otherJournal = HealthJournal::withoutGlobalScopes()
            ->where('company_id', $this->companyId())
            ->where('branch_id', $branchB->id)
            ->first();
        $this->assertNotNull($otherJournal, 'the other branch never got a journal to test against');

        // An accrual in each branch, so the CSV export has something to leak.
        foreach ([[$branchA->id, 'Mine', 111.0], [$branchB->id, 'Theirs', 222.0]] as [$branchId, $label, $amount]) {
            HealthDoctorShare::create([
                'company_id' => $this->companyId(),
                'branch_id' => $branchId,
                'health_doctor_id' => $this->doctor->id,
                'accrual_date' => now()->toDateString(),
                'charge_category' => 'opd',
                'description' => $label . ' share',
                'basis' => 'percent',
                'rate' => 10,
                'base' => 'net',
                'base_amount' => $amount * 10,
                'share_amount' => $amount,
                'status' => HealthDoctorShare::STATUS_ACCRUED,
            ]);
        }

        $category = \App\Models\HealthExpenseCategory::create([
            'company_id' => $this->companyId(),
            'name' => 'General',
            'is_active' => true,
        ]);

        $this->actingAs($accountant->fresh(), HealthPanel::GUARD);

        // LISTS — their branch is simply not there.
        $this->get('/health/accounts/expenses')
            ->assertOk()
            ->assertSee('E900001')
            ->assertDontSee('E900002');

        $this->get('/health/accounts/journals')
            ->assertOk()
            ->assertDontSee($otherJournal->journal_no);

        // A TYPED ID — refused, not quietly served.
        $this->get('/health/accounts/journals/' . $otherJournal->id)->assertForbidden();

        // AN EXPORT — the same boundary, on the other code path.
        $csv = $this->get('/health/accounts/doctor-shares?export=csv');
        $csv->assertOk();
        $body = $csv->streamedContent();
        $this->assertStringContainsString('Mine share', $body);
        $this->assertStringNotContainsString('Theirs share', $body);

        // WRITES — neither reversing their row nor filing a new one into their
        // branch.
        $this->post('/health/accounts/expenses/' . $theirs->id . '/reverse', [
            'reason' => 'Not mine to touch',
        ])->assertForbidden();

        $this->post('/health/accounts/expenses', [
            'expense_date' => now()->toDateString(),
            'health_expense_category_id' => $category->id,
            'branch_id' => $branchB->id,
            'payee' => 'Vendor X',
            'amount' => 500,
            'pay_mode' => 'cash',
            'description' => 'Filed into someone else\'s branch',
        ])->assertForbidden();

        // Their own branch still works, or the boundary has just broken the job.
        $this->post('/health/accounts/expenses/' . $mine->id . '/reverse', [
            'reason' => 'Duplicate entry',
        ])->assertRedirect();
    }

    /**
     * A payout may only sweep up work the person building it can see.
     *
     * The header branch is a label; the shares are the money. A settlement
     * stamped "branch A" that quietly gathers branch B's open accruals pays a
     * doctor out of a branch its builder may not even look at, and books the
     * expense in the wrong place — which the branch it was actually earned in
     * will never find, because the accruals simply disappeared from its pool.
     */
    public function test_a_branch_confined_accountant_cannot_settle_another_branchs_accruals(): void
    {
        [$branchA, $branchB, $accountant] = $this->branchConfinedAccountant();

        $mine = $this->makeShare((int) $branchA->id, $this->doctor, 500, 'Branch A work');
        $theirs = $this->makeShare((int) $branchB->id, $this->doctor, 900, 'Branch B work');

        // A payout that already exists in the other branch, to try the buttons on.
        $this->makeShare((int) $branchB->id, $this->surgeon, 300, 'Their surgeon');
        $otherSettlement = Shares::buildSettlement(
            $this->companyId(),
            (int) $this->surgeon->id,
            now()->startOfMonth()->toDateString(),
            now()->toDateString(),
            $this->owner,
            (int) $branchB->id
        );

        $this->actingAs($accountant->fresh(), HealthPanel::GUARD);

        $this->post('/health/accounts/settlements', [
            'health_doctor_id' => $this->doctor->id,
            'period_from' => now()->startOfMonth()->toDateString(),
            'period_to' => now()->toDateString(),
        ])->assertRedirect();

        $built = HealthDoctorSettlement::withoutGlobalScopes()
            ->where('company_id', $this->companyId())
            ->where('health_doctor_id', $this->doctor->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($built);
        $this->assertEqualsWithDelta(500, (float) $built->gross_amount, 0.005, 'the payout swept up another branch');
        $this->assertSame(1, (int) $built->share_count);
        $this->assertSame((int) $built->id, (int) $mine->fresh()->health_doctor_settlement_id);

        // The other branch's accrual is untouched and still open to its own people.
        $this->assertNull($theirs->fresh()->health_doctor_settlement_id);
        $this->assertSame(HealthDoctorShare::STATUS_ACCRUED, $theirs->fresh()->status);

        // And every button on the other branch's payout is shut.
        $this->get('/health/accounts/settlements/' . $otherSettlement->id)->assertForbidden();
        $this->post('/health/accounts/settlements/' . $otherSettlement->id . '/approve')->assertForbidden();
        $this->post('/health/accounts/settlements/' . $otherSettlement->id . '/pay', [
            'pay_method' => 'cash',
        ])->assertForbidden();
        $this->post('/health/accounts/settlements/' . $otherSettlement->id . '/reverse', [
            'reason' => 'Not mine to reverse',
        ])->assertForbidden();

        $this->assertSame(
            HealthDoctorSettlement::STATUS_DRAFT,
            $otherSettlement->fresh()->status,
            'the other branch\'s payout moved'
        );
    }

    /**
     * A reversal that cannot undo the ledger must not undo anything else.
     *
     * Two journals stand behind a paid settlement — the expense at approval and
     * the payment. If the second one refuses and the shares are handed back
     * anyway, the hospital keeps the posted expense AND the posted payment while
     * the same shares queue up to be paid a second time. Nobody notices until a
     * doctor is paid twice.
     */
    public function test_a_refused_ledger_reversal_leaves_the_settlement_alone(): void
    {
        $this->makeRule(['value' => 40]);

        $bill = $this->makeBill(gross: 10000, doctor: $this->doctor);
        Posting::postBill($bill, $this->owner);

        $from = now()->startOfMonth()->toDateString();
        $to = now()->toDateString();
        Shares::accrue($this->companyId(), $from, $to, $this->owner);

        $settlement = Shares::buildSettlement($this->companyId(), (int) $this->doctor->id, $from, $to, $this->owner);
        Shares::approve($settlement->fresh(), $this->owner);
        Shares::pay($settlement->fresh(), 'cash', null, null, $this->owner);

        $settlement = $settlement->fresh();
        $this->assertSame(HealthDoctorSettlement::STATUS_PAID, $settlement->status);

        // Break the APPROVAL journal so its reversal is refused. The payment
        // journal reverses first and cleanly, which is the whole point: it must
        // be rolled back too.
        $approvalJournal = HealthJournal::withoutGlobalScopes()
            ->where('company_id', $this->companyId())
            ->where('dedupe_key', 'dset:' . $settlement->id)
            ->firstOrFail();
        HealthJournalLine::withoutGlobalScopes()
            ->where('health_journal_id', $approvalJournal->id)
            ->delete();

        $paymentJournal = HealthJournal::withoutGlobalScopes()
            ->where('company_id', $this->companyId())
            ->where('dedupe_key', 'dsetpay:' . $settlement->id)
            ->firstOrFail();

        $this->expectException(ValidationException::class);

        try {
            Shares::reverse($settlement, 'Paid in error', $this->owner);
        } finally {
            // Nothing moved: not the payout, not the shares, not the one journal
            // that could have been reversed.
            $this->assertSame(HealthDoctorSettlement::STATUS_PAID, $settlement->fresh()->status);
            $this->assertSame(
                HealthJournal::STATUS_POSTED,
                $paymentJournal->fresh()->status,
                'a partial reversal survived the refusal'
            );
            $this->assertSame(
                0,
                HealthDoctorShare::withoutGlobalScopes()
                    ->where('company_id', $this->companyId())
                    ->whereNull('health_doctor_settlement_id')
                    ->count(),
                'shares were released back to the open pool by a refused reversal'
            );
        }
    }

    /**
     * A rule with no branch is an organisation-wide instrument.
     *
     * It applies to every branch's charges, so it changes what doctors are paid
     * in branches its author cannot even look at. The form has no branch field,
     * which is exactly why the crafted request matters: the boundary has to be
     * decided on the server, not by which inputs the page happens to render.
     */
    public function test_a_branch_confined_accountant_cannot_write_an_organisation_wide_share_rule(): void
    {
        [$branchA, $branchB, $accountant] = $this->branchConfinedAccountant();

        $globalRule = $this->makeRule(['name' => 'House default', 'value' => 30]);
        $theirDoctor = HealthDoctor::create([
            'company_id' => $this->companyId(),
            'branch_id' => $branchB->id,
            'name' => 'Dr Other Branch',
            'consultation_fee' => 2000,
            'is_active' => true,
        ]);

        $this->actingAs($accountant->fresh(), HealthPanel::GUARD);

        // No branch on the form — theirs is filled in, not left global.
        $this->post('/health/accounts/doctor-shares/rules', [
            'name' => 'My branch rule',
            'basis' => 'percent',
            'value' => 35,
            'base' => 'net',
        ])->assertRedirect();

        $mine = HealthDoctorShareRule::withoutGlobalScopes()->where('name', 'My branch rule')->first();
        $this->assertNotNull($mine);
        $this->assertSame((int) $branchA->id, (int) $mine->branch_id, 'the rule was written organisation-wide');

        // A branch they cannot reach, typed straight into the request.
        $this->post('/health/accounts/doctor-shares/rules', [
            'name' => 'Their branch rule',
            'branch_id' => $branchB->id,
            'basis' => 'percent',
            'value' => 90,
            'base' => 'net',
        ])->assertForbidden();

        // A doctor who belongs to that branch, same trick.
        $this->post('/health/accounts/doctor-shares/rules', [
            'name' => 'Their doctor rule',
            'health_doctor_id' => $theirDoctor->id,
            'basis' => 'percent',
            'value' => 90,
            'base' => 'net',
        ])->assertRedirect()->assertSessionHasErrors('health_doctor_id');

        // And the existing organisation-wide rule stays out of reach entirely.
        $this->put('/health/accounts/doctor-shares/rules/' . $globalRule->id, [
            'name' => 'House default',
            'basis' => 'percent',
            'value' => 90,
            'base' => 'net',
        ])->assertForbidden();

        $this->post('/health/accounts/doctor-shares/rules/' . $globalRule->id . '/toggle')->assertForbidden();

        $this->assertEqualsWithDelta(30, (float) $globalRule->fresh()->value, 0.005);
        $this->assertTrue((bool) $globalRule->fresh()->is_active);
        $this->assertSame(
            0,
            HealthDoctorShareRule::withoutGlobalScopes()->whereIn('name', ['Their branch rule', 'Their doctor rule'])->count()
        );
    }

    /**
     * An opening balance the ledger refuses must not be left on the screen.
     *
     * Re-stating one reverses the previous entry before posting the new one. If
     * the replacement is refused and the account row was already saved, the
     * chart shows a figure the books never accepted — and the old entry is gone
     * too, so the balance sheet quietly moves.
     */
    public function test_an_opening_balance_the_ledger_refuses_saves_nothing(): void
    {
        // Last month is shut; this month is still open.
        $date = now()->subMonthNoOverflow()->startOfMonth()->toDateString();
        $period = Periods::ensureFor($this->companyId(), $date);
        $this->assertTrue(Periods::close($period, $this->owner, 'month end')['ok']);

        $this->actingAs($this->owner->fresh(), HealthPanel::GUARD);

        $this->post('/health/accounts/chart', [
            'name' => 'Petty Cash Drawer',
            'type' => 'asset',
            'opening_balance' => 5000,
            'opening_balance_date' => $date,
        ])->assertRedirect()->assertSessionHasErrors('opening_balance');

        $this->assertSame(
            0,
            \App\Models\HealthAccount::withoutGlobalScopes()
                ->where('company_id', $this->companyId())
                ->where('name', 'Petty Cash Drawer')
                ->count(),
            'the account was kept with a balance the ledger refused'
        );

        // The same edit on an OPEN date goes through, balance and all.
        $open = now()->toDateString();
        $this->post('/health/accounts/chart', [
            'name' => 'Petty Cash Drawer',
            'type' => 'asset',
            'opening_balance' => 5000,
            'opening_balance_date' => $open,
        ])->assertRedirect();

        // The proof is the row and its ledger entry, not the session bag: the
        // refused attempt's flashed error is still riding along.
        $account = \App\Models\HealthAccount::withoutGlobalScopes()
            ->where('company_id', $this->companyId())
            ->where('name', 'Petty Cash Drawer')
            ->firstOrFail();

        $this->assertEqualsWithDelta(
            5000,
            Ledger::accountBalance($this->companyId(), (int) $account->id, $open),
            0.005
        );
    }

    /**
     * Correcting an opening balance twice must not add up.
     *
     * Each restatement gets a fresh dedupe key because the old one is spent, so
     * looking the previous entry up by the FIRST key finds only the first one —
     * and every correction after that piles on top of the last instead of
     * replacing it. Type 5,000, fix it to 8,000, fix it again to 3,000 and the
     * account is worth 3,000, not 16,000.
     */
    public function test_correcting_an_opening_balance_twice_replaces_it_both_times(): void
    {
        $this->actingAs($this->owner->fresh(), HealthPanel::GUARD);

        $this->post('/health/accounts/chart', [
            'name' => 'Site Safe',
            'type' => 'asset',
            'opening_balance' => 5000,
            'opening_balance_date' => now()->toDateString(),
        ])->assertRedirect();

        $account = \App\Models\HealthAccount::withoutGlobalScopes()
            ->where('company_id', $this->companyId())
            ->where('name', 'Site Safe')
            ->firstOrFail();

        $equityId = $this->accountId(\App\Services\HealthChartOfAccountsService::OPENING_EQUITY);

        foreach ([8000, 3000] as $restated) {
            $this->put('/health/accounts/chart/' . $account->id, [
                'name' => 'Site Safe',
                'opening_balance' => $restated,
                'opening_balance_date' => now()->toDateString(),
            ])->assertRedirect();

            $this->assertEqualsWithDelta(
                $restated,
                Ledger::accountBalance($this->companyId(), (int) $account->id),
                0.005,
                'restating the opening balance added to it instead of replacing it'
            );
            $this->assertEqualsWithDelta(
                $restated,
                Ledger::accountBalance($this->companyId(), $equityId),
                0.005,
                'opening equity drifted away from the account it funds'
            );
        }

        $this->assertEveryJournalBalances();

        // One live opening entry, whatever the history behind it.
        $this->assertSame(
            1,
            HealthJournal::withoutGlobalScopes()
                ->where('company_id', $this->companyId())
                ->where('source_type', HealthJournal::SRC_OPENING)
                ->where('source_id', $account->id)
                ->whereNull('reverses_journal_id')
                ->where('status', HealthJournal::STATUS_POSTED)
                ->count()
        );
    }

    /**
     * A reversal the books refuse must leave the operational row alone.
     *
     * Stamping "reversed" on an expense whose journal is still posted is the one
     * state nobody can explain: the screen says the money came back and the
     * trial balance says it never left. The same rule covers a fund transfer and
     * an opening balance being restated.
     */
    public function test_a_refused_ledger_reversal_leaves_the_source_record_alone(): void
    {
        $this->actingAs($this->owner->fresh(), HealthPanel::GUARD);

        $cashId = $this->accountId(\App\Services\HealthChartOfAccountsService::CASH);
        $bankId = $this->accountId(\App\Services\HealthChartOfAccountsService::BANK);

        $category = \App\Models\HealthExpenseCategory::create([
            'company_id' => $this->companyId(),
            'name' => 'Utilities',
            'is_active' => true,
        ]);

        $this->post('/health/accounts/expenses', [
            'expense_date' => now()->toDateString(),
            'health_expense_category_id' => $category->id,
            'payee' => 'Fuel Station',
            'amount' => 4000,
            'pay_mode' => 'cash',
            'description' => 'Generator diesel',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $expense = \App\Models\HealthExpense::withoutGlobalScopes()
            ->where('company_id', $this->companyId())
            ->latest('id')
            ->firstOrFail();

        $this->post('/health/accounts/transfers', [
            'transfer_date' => now()->toDateString(),
            'kind' => 'deposit',
            'from_account_id' => $cashId,
            'to_account_id' => $bankId,
            'amount' => 2500,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $transfer = \App\Models\HealthFundTransfer::withoutGlobalScopes()
            ->where('company_id', $this->companyId())
            ->latest('id')
            ->firstOrFail();

        // Strip the lines off both journals so the books cannot mirror them.
        foreach (['exp:' . $expense->id, 'xfer:' . $transfer->id] as $key) {
            $journal = HealthJournal::withoutGlobalScopes()
                ->where('company_id', $this->companyId())
                ->where('dedupe_key', $key)
                ->firstOrFail();
            HealthJournalLine::withoutGlobalScopes()->where('health_journal_id', $journal->id)->delete();
        }

        $this->post('/health/accounts/expenses/' . $expense->id . '/reverse', [
            'reason' => 'Wrong supplier',
        ])->assertRedirect()->assertSessionHasErrors('expense');

        $this->post('/health/accounts/transfers/' . $transfer->id . '/reverse', [
            'reason' => 'Wrong account',
        ])->assertRedirect()->assertSessionHasErrors('transfer');

        $this->assertNotSame(\App\Models\HealthExpense::STATUS_REVERSED, $expense->fresh()->status);
        $this->assertNull($expense->fresh()->reversed_at);
        $this->assertNotSame(\App\Models\HealthFundTransfer::STATUS_REVERSED, $transfer->fresh()->status);
        $this->assertNull($transfer->fresh()->reversed_at);

        // And the same for an opening balance that cannot be restated.
        $this->post('/health/accounts/chart', [
            'name' => 'Old Vault',
            'type' => 'asset',
            'opening_balance' => 1000,
            'opening_balance_date' => now()->toDateString(),
        ])->assertRedirect();

        $vault = \App\Models\HealthAccount::withoutGlobalScopes()
            ->where('company_id', $this->companyId())
            ->where('name', 'Old Vault')
            ->firstOrFail();

        $opening = HealthJournal::withoutGlobalScopes()
            ->where('company_id', $this->companyId())
            ->where('dedupe_key', 'opening:' . $vault->id)
            ->firstOrFail();
        HealthJournalLine::withoutGlobalScopes()->where('health_journal_id', $opening->id)->delete();

        $this->put('/health/accounts/chart/' . $vault->id, [
            'name' => 'Old Vault',
            'opening_balance' => 9000,
            'opening_balance_date' => now()->toDateString(),
        ])->assertRedirect()->assertSessionHasErrors('opening_balance');

        $this->assertEqualsWithDelta(1000, (float) $vault->fresh()->opening_balance, 0.005);
        $this->assertSame(HealthJournal::STATUS_POSTED, $opening->fresh()->status);
    }

    /**
     * The catch-up sweep has to reach work that sits BEHIND a full page.
     *
     * It takes the oldest rows first and stops at a limit. Applying that limit
     * before the already-posted rows are filtered out means a busy hospital
     * re-reads the same first page of posted rows on every press and can never
     * reach the one row that failed to post behind them — the dashboard reports
     * pending work the button is physically unable to clear.
     */
    public function test_the_sweep_reaches_a_row_sitting_behind_a_full_page_of_posted_ones(): void
    {
        $companyId = $this->companyId();
        $today = now()->toDateString();

        $category = \App\Models\HealthExpenseCategory::create([
            'company_id' => $companyId,
            'name' => 'Sundries',
            'is_active' => true,
        ]);

        $rows = [];
        for ($i = 1; $i <= Posting::SWEEP_LIMIT; $i++) {
            $rows[] = [
                'company_id' => $companyId,
                'health_expense_category_id' => $category->id,
                'expense_no' => 'EXP-OLD-' . $i,
                'expense_date' => $today,
                'payee' => 'Sundry vendor',
                'amount' => 10,
                'tax_amount' => 0,
                'total_amount' => 10,
                'pay_mode' => 'cash',
                'status' => \App\Models\HealthExpense::STATUS_POSTED,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        DB::table('health_expenses')->insert($rows);

        // First press fills the page: every one of them is now in the books.
        $first = Posting::sweep($companyId, null, null, $this->owner);
        $this->assertSame(Posting::SWEEP_LIMIT, $first['expenses']);
        $this->assertSame(0, $first['failed']);

        // And now the row that arrives behind all of them.
        $late = \App\Models\HealthExpense::create([
            'company_id' => $companyId,
            'health_expense_category_id' => $category->id,
            'expense_no' => 'EXP-LATE',
            'expense_date' => $today,
            'payee' => 'Ambulance fuel',
            'amount' => 777,
            'tax_amount' => 0,
            'total_amount' => 777,
            'pay_mode' => 'cash',
            'status' => \App\Models\HealthExpense::STATUS_POSTED,
        ]);

        $this->assertSame(1, (int) Posting::pendingCounts($companyId)['expenses']);

        $second = Posting::sweep($companyId, null, null, $this->owner);

        $this->assertSame(1, $second['expenses'], 'the sweep never got past its own limit');
        $this->assertSame(0, $second['failed']);
        $this->assertTrue(
            HealthJournal::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('dedupe_key', 'exp:' . $late->id)
                ->exists(),
            'the row behind the page never reached the books'
        );
        $this->assertSame(0, (int) Posting::pendingCounts($companyId)['expenses']);
    }

    /**
     * A deduction is money recovered, not money that stopped being earned.
     *
     * The doctor earned the gross. If the payout books only the net, the
     * hospital under-states what it pays its doctors, under-states what it owes
     * them, and the advance it handed over stays on the books forever — so it
     * gets recovered a second time and nobody can show where the first one
     * went.
     */
    public function test_a_payout_deduction_clears_the_doctor_advance_instead_of_erasing_it(): void
    {
        $this->actingAs($this->owner->fresh(), HealthPanel::GUARD);

        // The hospital hands the doctor 1,000 up front: cash out, asset in.
        $this->post('/health/accounts/transfers', [
            'transfer_date' => now()->toDateString(),
            'kind' => 'transfer',
            'from_account_id' => $this->accountId(Chart::CASH),
            'to_account_id' => $this->accountId(Chart::DOCTOR_ADVANCE),
            'amount' => 1000,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(1000, $this->balance(Chart::DOCTOR_ADVANCE), 0.005);
        $cashBeforePayout = $this->balance(Chart::CASH);

        HealthDoctorShare::create([
            'company_id' => $this->companyId(),
            'health_doctor_id' => $this->doctor->id,
            'accrual_date' => now()->toDateString(),
            'charge_category' => 'opd',
            'description' => 'Consultation share',
            'basis' => 'percent',
            'rate' => 50,
            'base' => 'net',
            'base_amount' => 10000,
            'share_amount' => 5000,
            'status' => HealthDoctorShare::STATUS_ACCRUED,
        ]);

        $settlement = Shares::buildSettlement(
            $this->companyId(),
            (int) $this->doctor->id,
            now()->startOfMonth()->toDateString(),
            now()->toDateString(),
            $this->owner
        );

        $this->put('/health/accounts/settlements/' . $settlement->id, [
            'deduction_amount' => 1000,
            'deduction_reason' => 'Advance drawn last month',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->post('/health/accounts/settlements/' . $settlement->id . '/approve')
            ->assertRedirect()->assertSessionHasNoErrors();

        // Gross expense, net liability, advance cleared — all three at once.
        $this->assertEqualsWithDelta(5000, $this->balance(Chart::EXPENSE_DOCTOR_SHARE), 0.005, 'the deduction ate into the expense');
        $this->assertEqualsWithDelta(4000, $this->balance(Chart::DOCTOR_SHARE_PAYABLE), 0.005);
        $this->assertEqualsWithDelta(0, $this->balance(Chart::DOCTOR_ADVANCE), 0.005, 'the advance was never recovered');

        $this->post('/health/accounts/settlements/' . $settlement->id . '/pay', [
            'pay_method' => 'cash',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $paid = $settlement->fresh();
        $this->assertSame(HealthDoctorSettlement::STATUS_PAID, $paid->status);
        $this->assertEqualsWithDelta(4000, (float) $paid->paid_amount, 0.005);

        $this->assertEqualsWithDelta(5000, $this->balance(Chart::EXPENSE_DOCTOR_SHARE), 0.005);
        $this->assertEqualsWithDelta(0, $this->balance(Chart::DOCTOR_SHARE_PAYABLE), 0.005, 'the payout left a liability behind');
        $this->assertEqualsWithDelta(0, $this->balance(Chart::DOCTOR_ADVANCE), 0.005);
        $this->assertEqualsWithDelta(
            $cashBeforePayout - 4000,
            $this->balance(Chart::CASH),
            0.005,
            'the drawer moved by something other than the net payout'
        );

        $this->assertEveryJournalBalances();
    }

    /**
     * A double-click may not reverse the same entry twice.
     *
     * Two requests landing together both used to read the entry as posted, both
     * wrote an opposite journal, and both stamped the original reversed. The
     * books then held the entry plus TWO mirrors of it — the transaction was
     * undone twice, which invents money out of an impatient hand.
     *
     * Simulated the only way a single-threaded test can: two copies of the row
     * read BEFORE either reversal runs, exactly as two requests would hold.
     */
    public function test_the_same_entry_cannot_be_reversed_twice(): void
    {
        $this->actingAs($this->owner->fresh(), HealthPanel::GUARD);

        $category = \App\Models\HealthExpenseCategory::create([
            'company_id' => $this->companyId(),
            'name' => 'Ambulance',
            'is_active' => true,
        ]);

        $this->post('/health/accounts/expenses', [
            'expense_date' => now()->toDateString(),
            'health_expense_category_id' => $category->id,
            'payee' => 'Tyre shop',
            'amount' => 3000,
            'pay_mode' => 'cash',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $expense = HealthExpense::withoutGlobalScopes()
            ->where('company_id', $this->companyId())
            ->latest('id')
            ->firstOrFail();

        $journal = HealthJournal::withoutGlobalScopes()
            ->where('company_id', $this->companyId())
            ->where('dedupe_key', 'exp:' . $expense->id)
            ->firstOrFail();

        $cashAfterExpense = $this->balance(Chart::CASH);

        // Both requests read the entry while it is still posted.
        $firstRead = HealthJournal::withoutGlobalScopes()->with('lines')->findOrFail($journal->id);
        $secondRead = HealthJournal::withoutGlobalScopes()->with('lines')->findOrFail($journal->id);

        $first = Ledger::reverse($firstRead, $this->owner, 'Wrong expense');
        $this->assertTrue($first['ok'] ?? false);

        $second = Ledger::reverse($secondRead, $this->owner, 'Pressed again');
        $this->assertFalse($second['ok'] ?? false, 'the entry was reversed a second time');
        $this->assertSame('already_reversed', $second['reason'] ?? null);

        // Exactly one mirror, and the cash is back exactly once.
        $this->assertSame(
            1,
            HealthJournal::withoutGlobalScopes()
                ->where('company_id', $this->companyId())
                ->where('reverses_journal_id', $journal->id)
                ->count()
        );
        $this->assertEqualsWithDelta($cashAfterExpense + 3000, $this->balance(Chart::CASH), 0.005);
        $this->assertEqualsWithDelta(0, $this->balance(Chart::EXPENSE_GENERAL), 0.005);

        // And the database refuses a second mirror even if code ever asked for one.
        $mirror = HealthJournal::withoutGlobalScopes()
            ->where('company_id', $this->companyId())
            ->where('reverses_journal_id', $journal->id)
            ->firstOrFail();

        $this->expectException(\Illuminate\Database\QueryException::class);
        HealthJournal::withoutGlobalScopes()->create([
            'company_id' => $this->companyId(),
            'journal_no' => 'JV-DUP-1',
            'journal_date' => now()->toDateString(),
            'type' => HealthJournal::TYPE_MANUAL,
            'total_debit' => $mirror->total_debit,
            'total_credit' => $mirror->total_credit,
            'status' => HealthJournal::STATUS_POSTED,
            'reverses_journal_id' => $journal->id,
        ]);
    }

    // ── Pharmacy returns ────────────────────────────────────────────────────

    /** A stocked medicine, priced and taxed, ready to sell. */
    private function stockedMedicine(float $taxRate = 10, float $cost = 40, float $price = 100): \App\Models\HealthMedicine
    {
        $this->seq++;
        $medicine = Pharmacy::createMedicine($this->companyId(), [
            'name' => 'Medicine ' . $this->seq,
            'form' => 'tablet',
            'unit_uom' => 'tablet',
            'pack_size' => 10,
            'purchase_price' => $cost,
            'sale_price' => $price,
            'tax_rate' => $taxRate,
        ]);

        Stock::receive(
            $this->companyId(),
            $medicine,
            [
                'quantity' => 50,
                'batch_no' => 'B' . $this->seq,
                'expiry_date' => now()->addYear()->toDateString(),
                'cost_price' => $cost,
            ],
            null,
            ['type' => 'test', 'id' => null, 'number' => 'T' . $this->seq],
            null
        );

        return $medicine;
    }

    private function saleItem(HealthPharmacySale $sale): HealthPharmacySaleItem
    {
        return HealthPharmacySaleItem::withoutGlobalScopes()
            ->where('sale_id', $sale->id)
            ->orderBy('id')
            ->firstOrFail();
    }

    public function test_a_discounted_taxed_counter_return_gives_back_every_piece_of_the_sale(): void
    {
        $medicine = $this->stockedMedicine();

        $sale = Checkout::sell($this->companyId(), [
            'payment_method' => 'cash',
            'lines' => [[
                'medicine_id' => $medicine->id,
                'quantity' => 10,
                'unit_price' => 100,
                'discount_amount' => 100,
            ]],
        ], null, null);

        // 1000 gross, 100 off, 10% tax on what is left.
        $this->assertEqualsWithDelta(1000, (float) $sale->subtotal, 0.005);
        $this->assertEqualsWithDelta(100, (float) $sale->discount_amount, 0.005);
        $this->assertEqualsWithDelta(90, (float) $sale->tax_amount, 0.005);
        $this->assertEqualsWithDelta(990, (float) $sale->total_amount, 0.005);

        $this->assertEqualsWithDelta(1000, $this->balance(Chart::INCOME_PHARMACY), 0.005);
        $this->assertEqualsWithDelta(-100, $this->balance(Chart::INCOME_CONCESSION), 0.005);
        $this->assertEqualsWithDelta(90, $this->balance(Chart::TAX_PAYABLE), 0.005);
        $this->assertEqualsWithDelta(990, $this->balance(Chart::CASH), 0.005);
        $this->assertEqualsWithDelta(400, $this->balance(Chart::COGS_PHARMACY), 0.005);

        $item = $this->saleItem($sale);

        // Four of the ten come back: 400 gross, 40 of the discount and 36 of
        // the tax travel back with them, and 396 leaves the drawer.
        Checkout::refund($this->companyId(), $sale->fresh(), [
            ['sale_item_id' => $item->id, 'quantity' => 4],
        ], true, 'wrong strength', null);

        $this->assertEqualsWithDelta(600, $this->balance(Chart::INCOME_PHARMACY), 0.005, 'income kept revenue that came back');
        $this->assertEqualsWithDelta(-60, $this->balance(Chart::INCOME_CONCESSION), 0.005, 'the concession on returned goods was never undone');
        $this->assertEqualsWithDelta(54, $this->balance(Chart::TAX_PAYABLE), 0.005, 'tax is still owed on medicine that came back');
        $this->assertEqualsWithDelta(594, $this->balance(Chart::CASH), 0.005);
        $this->assertEqualsWithDelta(240, $this->balance(Chart::COGS_PHARMACY), 0.005);

        // The rest follows, and the sale is as if it never happened.
        Checkout::refund($this->companyId(), $sale->fresh(), [
            ['sale_item_id' => $item->id, 'quantity' => 6],
        ], true, 'changed her mind', null);

        $this->assertEqualsWithDelta(0, $this->balance(Chart::INCOME_PHARMACY), 0.005);
        $this->assertEqualsWithDelta(0, $this->balance(Chart::INCOME_CONCESSION), 0.005);
        $this->assertEqualsWithDelta(0, $this->balance(Chart::TAX_PAYABLE), 0.005);
        $this->assertEqualsWithDelta(0, $this->balance(Chart::CASH), 0.005);
        $this->assertEqualsWithDelta(0, $this->balance(Chart::COGS_PHARMACY), 0.005);

        $this->assertEveryJournalBalances();
    }

    public function test_a_returned_medicine_stops_being_billed_to_the_patient(): void
    {
        $medicine = $this->stockedMedicine(0, 40, 100);

        $patient = HealthPatient::create([
            'company_id' => $this->companyId(),
            'mrn' => 'MRPH01',
            'name' => 'Ward Patient',
            'gender' => 'female',
            'age_years' => 33,
            'is_active' => true,
        ]);

        $sale = Checkout::sell($this->companyId(), [
            'patient_id' => $patient->id,
            'payment_method' => 'credit',
            'paid_amount' => 0,
            'lines' => [['medicine_id' => $medicine->id, 'quantity' => 10, 'unit_price' => 100]],
        ], null, null);

        // Nothing at the counter: this one is going on the patient's bill.
        $this->assertEqualsWithDelta(0, $this->balance(Chart::CASH), 0.005);
        $this->assertEqualsWithDelta(0, $this->balance(Chart::INCOME_PHARMACY), 0.005);

        Ingest::syncPatient($this->companyId(), $patient->id);

        $charge = HealthCharge::withoutGlobalScopes()
            ->where('source_type', HealthCharge::SOURCE_PHARMACY_SALE)
            ->where('source_id', $sale->id)
            ->firstOrFail();
        $this->assertEqualsWithDelta(1000, (float) $charge->gross_amount, 0.005);

        $item = $this->saleItem($sale);
        Checkout::refund($this->companyId(), $sale->fresh(), [
            ['sale_item_id' => $item->id, 'quantity' => 4],
        ], true, 'not needed', null);

        // The original charge is gone and a smaller one stands in its place.
        $this->assertSame(HealthCharge::STATUS_REVERSED, $charge->fresh()->status);

        $live = HealthCharge::withoutGlobalScopes()
            ->where('source_type', HealthCharge::SOURCE_PHARMACY_SALE)
            ->where('source_id', $sale->id)
            ->where('status', '!=', HealthCharge::STATUS_REVERSED)
            ->get();
        $this->assertCount(1, $live, 'a return must leave exactly one live charge behind');
        $this->assertEqualsWithDelta(600, (float) $live->first()->gross_amount, 0.005);

        // Nothing had reached the books yet, so the return posts no revenue.
        $this->assertEqualsWithDelta(0, $this->balance(Chart::INCOME_PHARMACY), 0.005);
        $this->assertEqualsWithDelta(0, $this->balance(Chart::CASH), 0.005, 'a ward return must never touch the drawer');
        $this->assertEqualsWithDelta(240, $this->balance(Chart::COGS_PHARMACY), 0.005);

        // And the bill charges for what the patient kept.
        $created = Billing::createBill($this->companyId(), $patient->id, [$live->first()->id]);
        $this->assertTrue($created['ok'] ?? false);
        $final = Billing::finalize($created['bill']);
        $this->assertTrue($final['ok'] ?? false);
        $this->assertEqualsWithDelta(600, (float) $created['bill']->fresh()->total_amount, 0.005);

        $this->assertEveryJournalBalances();
    }

    public function test_a_return_after_the_bill_was_printed_credits_the_patient_not_the_drawer(): void
    {
        $medicine = $this->stockedMedicine(0, 40, 100);

        $patient = HealthPatient::create([
            'company_id' => $this->companyId(),
            'mrn' => 'MRPH02',
            'name' => 'Discharged Patient',
            'gender' => 'male',
            'age_years' => 52,
            'is_active' => true,
        ]);

        $sale = Checkout::sell($this->companyId(), [
            'patient_id' => $patient->id,
            'payment_method' => 'credit',
            'paid_amount' => 0,
            'lines' => [[
                'medicine_id' => $medicine->id,
                'quantity' => 10,
                'unit_price' => 100,
                'discount_amount' => 200,
            ]],
        ], null, null);

        Ingest::syncPatient($this->companyId(), $patient->id);

        $charge = HealthCharge::withoutGlobalScopes()
            ->where('source_type', HealthCharge::SOURCE_PHARMACY_SALE)
            ->where('source_id', $sale->id)
            ->firstOrFail();

        $created = Billing::createBill($this->companyId(), $patient->id, [$charge->id]);
        $this->assertTrue($created['ok'] ?? false);
        $this->assertTrue(Billing::finalize($created['bill'])['ok'] ?? false);
        Posting::auto('postBill', $created['bill']->fresh());

        $this->assertEqualsWithDelta(1000, $this->balance(Chart::INCOME_PHARMACY), 0.005);
        $this->assertEqualsWithDelta(-200, $this->balance(Chart::INCOME_CONCESSION), 0.005);
        $this->assertEqualsWithDelta(800, $this->balance(Chart::PATIENT_RECEIVABLE), 0.005);

        $item = $this->saleItem($sale);
        Checkout::refund($this->companyId(), $sale->fresh(), [
            ['sale_item_id' => $item->id, 'quantity' => 10],
        ], true, 'discharged early', null);

        // The printed bill stands; what the patient owes is what changes.
        $this->assertEqualsWithDelta(0, $this->balance(Chart::PATIENT_RECEIVABLE), 0.005, 'the patient is still being chased for returned medicine');
        $this->assertEqualsWithDelta(0, $this->balance(Chart::INCOME_PHARMACY), 0.005);
        $this->assertEqualsWithDelta(0, $this->balance(Chart::INCOME_CONCESSION), 0.005, 'the concession outlived the sale it was given on');
        $this->assertEqualsWithDelta(0, $this->balance(Chart::CASH), 0.005, 'no money left a drawer that never took any');

        // A frozen charge is corrected on the record, never rewritten.
        $this->assertNotSame(HealthCharge::STATUS_REVERSED, $charge->fresh()->status);
        $this->assertTrue(
            HealthChargeAdjustment::withoutGlobalScopes()
                ->where('health_charge_id', $charge->id)
                ->where('kind', HealthChargeAdjustment::KIND_CORRECTION)
                ->exists(),
            'a return against a printed bill must leave a trace on the charge'
        );

        $this->assertEveryJournalBalances();
    }

    public function test_a_bill_never_charges_for_a_line_whose_charge_was_reversed(): void
    {
        $patient = HealthPatient::create([
            'company_id' => $this->companyId(),
            'mrn' => 'MRPH03',
            'name' => 'Draft Patient',
            'gender' => 'female',
            'age_years' => 27,
            'is_active' => true,
        ]);

        $one = Charges::post([
            'company_id' => $this->companyId(),
            'health_patient_id' => $patient->id,
            'charge_date' => now()->toDateString(),
            'category' => 'opd',
            'description' => 'Consultation',
            'unit_price' => 2000,
            'quantity' => 1,
        ]);
        $two = Charges::post([
            'company_id' => $this->companyId(),
            'health_patient_id' => $patient->id,
            'charge_date' => now()->toDateString(),
            'category' => 'lab',
            'description' => 'Blood test',
            'unit_price' => 1500,
            'quantity' => 1,
        ]);

        $created = Billing::createBill($this->companyId(), $patient->id, [$one->id, $two->id]);
        $this->assertTrue($created['ok'] ?? false);
        $bill = $created['bill'];
        $this->assertSame(2, $bill->lines()->count());

        // The lab test is cancelled while the bill is still a draft.
        $this->assertTrue(Charges::reverse($two->fresh(), null, 'sample spoiled')['ok'] ?? false);

        $this->assertTrue(Billing::finalize($bill)['ok'] ?? false);

        $bill = $bill->fresh();
        $this->assertSame(1, $bill->lines()->count(), 'a dead charge was still billed to the patient');
        $this->assertEqualsWithDelta(2000, (float) $bill->total_amount, 0.005);
    }

    public function test_an_accountant_gets_the_money_but_never_the_diagnosis(): void
    {
        $accountant = User::create([
            'name' => 'Accountant',
            'email' => 'acc@example.test',
            'password' => Hash::make('Passw0rd!2026'),
            'company_id' => $this->companyId(),
            'role' => 'user',
            'health_role' => 'health_accountant',
            'is_active' => true,
        ]);

        $caps = \App\Services\HealthAccessService::capabilitiesFor($accountant, $this->company);

        $this->assertContains('accounts.view', $caps);
        $this->assertContains('accounts.manage', $caps);

        // Clinical reading rights, and the right to sign off their own pay,
        // both stay out of finance's hands.
        $this->assertNotContains('clinical.view', $caps);
        $this->assertNotContains('clinical.record', $caps);
        $this->assertNotContains('accounts.approve', $caps);
    }

    // ═══════════════════════════════════════════════════════════════════
    // DEPARTMENT BOUNDARY
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Their ward, another ward, and an accountant posted to the first.
     *
     * One branch on purpose: a hospital is usually ONE building, so the branch
     * fence lets this person through everywhere and the department fence is the
     * only thing standing between them and the other ward's books.
     */
    private function departmentConfinedAccountant(): array
    {
        $mine = \App\Models\HealthDepartment::create([
            'company_id' => $this->companyId(),
            'name' => 'Radiology',
            'code' => 'RAD',
            'type' => 'radiology',
            'is_active' => true,
        ]);
        $theirs = \App\Models\HealthDepartment::create([
            'company_id' => $this->companyId(),
            'name' => 'Cardiology',
            'code' => 'CARD',
            'type' => 'opd',
            'is_active' => true,
        ]);

        $this->seq++;
        $accountant = User::create([
            'name' => 'Ward Accountant',
            'email' => 'wardacc' . $this->seq . '@example.test',
            'password' => Hash::make('Passw0rd!2026'),
            'company_id' => $this->companyId(),
            'role' => 'user',
            'health_role' => 'health_accountant',
            'is_active' => true,
        ]);

        // Written straight to the column: a posting that Eloquent silently drops
        // would leave the user unconfined and the whole test green for nothing.
        \DB::table('users')->where('id', $accountant->id)->update(['health_department_id' => $mine->id]);

        // One branch, and they are on it — so every branch check passes and the
        // department fence is the only thing being tested here.
        $branch = \App\Models\Branch::create([
            'company_id' => $this->companyId(),
            'name' => 'Main Campus',
            'code' => 'MAIN',
            'is_head_office' => true,
            'is_active' => true,
        ]);
        \DB::table('branch_user')->insert([
            'user_id' => $accountant->id,
            'branch_id' => $branch->id,
            'access_level' => 'full',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \App\Services\HealthScopeService::forget();

        return [$mine, $theirs, $accountant->fresh(), $branch];
    }

    private function wardBill(int $departmentId, string $tag, float $amount, ?int $branchId = null): HealthBill
    {
        $this->seq++;
        $patient = HealthPatient::create([
            'company_id' => $this->companyId(),
            'mrn' => 'MRN' . $tag,
            'name' => $tag . ' Patient',
            'gender' => 'male',
            'age_years' => 44,
            'is_active' => true,
        ]);

        return HealthBill::create([
            'company_id' => $this->companyId(),
            'health_patient_id' => $patient->id,
            'health_department_id' => $departmentId,
            'branch_id' => $branchId,
            'bill_no' => 'BW' . $tag,
            'doc_type' => HealthBill::TYPE_INVOICE,
            'status' => HealthBill::STATUS_FINALIZED,
            'bill_date' => now()->toDateString(),
            'business_date' => now()->toDateString(),
            'gross_amount' => $amount,
            'net_amount' => $amount,
            'total_amount' => $amount,
            'patient_payable' => $amount,
            'outstanding_amount' => $amount,
        ]);
    }

    /**
     * A ward accountant reads their ward's money and nobody else's.
     *
     * The reports are the leak that matters here: most of them have no
     * department picker at all, so before the fence existed a Radiology
     * accountant opening the ledger, the ageing list or the profitability page
     * simply got the whole hospital — Cardiology's income, and the patient
     * names and MRNs behind its unpaid bills. Nothing had to be crafted; they
     * only had to open the page.
     */
    public function test_a_department_confined_accountant_reads_only_their_own_ward(): void
    {
        [$mine, $theirs, $accountant, $branch] = $this->departmentConfinedAccountant();

        $entry = function (int $departmentId, string $memo, float $amount) use ($branch) {
            return Ledger::post($this->companyId(), [
                'date' => now()->toDateString(),
                'type' => HealthJournal::TYPE_MANUAL,
                'branch_id' => $branch->id,
                'memo' => $memo,
                'lines' => [
                    ['account' => Chart::CASH, 'debit' => $amount, 'health_department_id' => $departmentId],
                    ['account' => Chart::INCOME_OPD, 'credit' => $amount, 'health_department_id' => $departmentId],
                ],
            ], $this->owner);
        };

        $mineEntry = $entry((int) $mine->id, 'Radiology film income', 400);
        $theirEntry = $entry((int) $theirs->id, 'Cardiology echo income', 900);

        $this->assertTrue($mineEntry['ok'] ?? false);
        $this->assertTrue($theirEntry['ok'] ?? false);

        $this->wardBill((int) $mine->id, 'RAD1', 1500, (int) $branch->id);
        $this->wardBill((int) $theirs->id, 'CARD1', 2500, (int) $branch->id);

        $from = now()->startOfMonth()->toDateString();
        $to = now()->toDateString();

        $this->actingAs($accountant, HealthPanel::GUARD);

        // The journal register, and one entry opened by id.
        $this->get('/health/accounts/journals')
            ->assertOk()
            ->assertSee('Radiology film income')
            ->assertDontSee('Cardiology echo income');

        $this->get('/health/accounts/journals/' . $mineEntry['journal']->id)->assertOk();
        $this->get('/health/accounts/journals/' . $theirEntry['journal']->id)->assertForbidden();

        // The ledger, exported — a CSV leaks just as well as a screen.
        $ledgerCsv = $this->get(
            '/health/accounts/reports/ledger?account_id=' . $this->accountId(Chart::INCOME_OPD)
            . '&from=' . $from . '&to=' . $to . '&export=csv'
        )->streamedContent();

        $this->assertStringContainsString('Radiology film income', $ledgerCsv);
        $this->assertStringNotContainsString('Cardiology echo income', $ledgerCsv);

        // Profitability, which is department-shaped by definition.
        $profitCsv = $this->get(
            '/health/accounts/reports/profitability?dimension=department&from=' . $from . '&to=' . $to . '&export=csv'
        )->streamedContent();

        $this->assertStringContainsString('Radiology', $profitCsv);
        $this->assertStringNotContainsString('Cardiology', $profitCsv);

        // The ageing list, where the leak carries names and MRNs.
        $this->get('/health/accounts/reports/receivables')
            ->assertOk()
            ->assertSee('MRNRAD1')
            ->assertDontSee('MRNCARD1')
            ->assertDontSee('CARD1 Patient');

        // And the id typed straight into the query string.
        foreach (['profit-loss', 'receivables', 'trial-balance', 'cash-flow', 'profitability'] as $report) {
            $this->get('/health/accounts/reports/' . $report . '?department_id=' . $theirs->id)
                ->assertForbidden();
        }

        $this->get('/health/accounts?department_id=' . $theirs->id)->assertForbidden();
        $this->get('/health/accounts/reports/receivables?department_id=' . $mine->id)->assertOk();
    }

    /**
     * A ward accountant cannot file money into a ward they cannot read.
     *
     * The read fence alone would still let them push costs onto Cardiology's
     * profit line, or an accrual onto another ward's consultant, and then not be
     * able to see what they had done. A dimension on a financial line is an
     * attribution, so it is refused rather than quietly blanked — blanking it
     * files the amount as organisation-wide, which is a different wrong answer.
     */
    public function test_a_department_confined_accountant_cannot_write_into_another_ward(): void
    {
        [$mine, $theirs, $accountant, $branch] = $this->departmentConfinedAccountant();

        $category = \App\Models\HealthExpenseCategory::withoutGlobalScopes()->create([
            'company_id' => $this->companyId(),
            'name' => 'Utilities',
            'health_account_id' => $this->accountId(Chart::EXPENSE_UTILITIES),
            'is_active' => true,
        ]);

        $theirDoctor = HealthDoctor::create([
            'company_id' => $this->companyId(),
            'health_department_id' => $theirs->id,
            'name' => 'Dr Cardiology',
            'consultation_fee' => 2500,
            'is_active' => true,
        ]);

        $this->actingAs($accountant, HealthPanel::GUARD);

        $journalWith = fn (array $dimension) => array_merge([
            'journal_date' => now()->toDateString(),
            'type' => HealthJournal::TYPE_MANUAL,
        ], $dimension);

        // Income attributed to the other ward.
        $this->post('/health/accounts/journals', $journalWith([
            'memo' => 'Slipped into cardiology',
            'lines' => [
                ['account_id' => $this->accountId(Chart::CASH), 'debit' => 500, 'health_department_id' => $theirs->id],
                ['account_id' => $this->accountId(Chart::INCOME_OPD), 'credit' => 500, 'health_department_id' => $theirs->id],
            ],
        ]))->assertForbidden();

        // The same entry aimed at that ward's consultant instead.
        $this->post('/health/accounts/journals', $journalWith([
            'memo' => 'Slipped onto their consultant',
            'lines' => [
                ['account_id' => $this->accountId(Chart::EXPENSE_DOCTOR_SHARE), 'debit' => 500, 'health_doctor_id' => $theirDoctor->id],
                ['account_id' => $this->accountId(Chart::CASH), 'credit' => 500],
            ],
        ]))->assertForbidden();

        $this->assertSame(
            0,
            HealthJournal::withoutGlobalScopes()
                ->where('company_id', $this->companyId())
                ->whereIn('memo', ['Slipped into cardiology', 'Slipped onto their consultant'])
                ->count(),
            'a refused attribution still wrote a journal'
        );

        $expense = fn (int $departmentId, string $payee) => [
            'expense_date' => now()->toDateString(),
            'health_expense_category_id' => $category->id,
            'amount' => 1200,
            'pay_mode' => 'cash',
            'payee' => $payee,
            'health_department_id' => $departmentId,
        ];

        $this->post('/health/accounts/expenses', $expense((int) $theirs->id, 'Their ward vendor'))
            ->assertForbidden();

        $this->assertSame(
            0,
            HealthExpense::withoutGlobalScopes()->where('payee', 'Their ward vendor')->count(),
            'a cost was pushed onto another ward'
        );

        // Their own ward still works — a fence that stops the day job is a bug.
        $this->post('/health/accounts/expenses', $expense((int) $mine->id, 'My ward vendor'))
            ->assertRedirect();

        $this->assertSame(
            1,
            HealthExpense::withoutGlobalScopes()->where('payee', 'My ward vendor')->count(),
            'the accountant could not record their own ward\'s expense'
        );

        // The share rules screen is the other way onto a doctor's pay.
        $this->post('/health/accounts/doctor-shares/rules', [
            'name' => 'Their ward rule',
            'health_department_id' => $theirs->id,
            'basis' => 'percent',
            'value' => 60,
            'base' => 'net',
        ])->assertForbidden();

        $this->post('/health/accounts/doctor-shares/rules', [
            'name' => 'Their doctor rule',
            'health_doctor_id' => $theirDoctor->id,
            'basis' => 'percent',
            'value' => 60,
            'base' => 'net',
        ])->assertRedirect()->assertSessionHasErrors('health_doctor_id');

        $this->assertSame(
            0,
            HealthDoctorShareRule::withoutGlobalScopes()
                ->whereIn('name', ['Their ward rule', 'Their doctor rule'])
                ->count()
        );
    }

    /**
     * A branch's bank account is that branch's money.
     *
     * "Paid from City branch's bank" typed in by somebody who cannot reach City
     * used to be accepted and quietly rewritten to the cash drawer: the books
     * showed a payment that never left the bank, and the person who filed it was
     * never told. Refused instead, with the field named.
     */
    public function test_an_expense_cannot_be_paid_out_of_another_branchs_bank(): void
    {
        [$branchA, $branchB, $accountant] = $this->branchConfinedAccountant();

        $bankLedgerAccount = \App\Models\HealthAccount::withoutGlobalScopes()->create([
            'company_id' => $this->companyId(),
            'code' => '1102-CITY',
            'name' => 'City Branch Bank',
            'type' => 'asset',
            'subtype' => 'bank',
            'is_bank' => true,
            'is_active' => true,
        ]);

        \App\Models\HealthBankAccount::withoutGlobalScopes()->create([
            'company_id' => $this->companyId(),
            'branch_id' => $branchB->id,
            'health_account_id' => $bankLedgerAccount->id,
            'title' => 'City Current Account',
            'bank_name' => 'Meezan',
            'is_active' => true,
        ]);

        $category = \App\Models\HealthExpenseCategory::withoutGlobalScopes()->create([
            'company_id' => $this->companyId(),
            'name' => 'Repairs',
            'health_account_id' => $this->accountId(Chart::EXPENSE_UTILITIES),
            'is_active' => true,
        ]);

        $this->actingAs($accountant->fresh(), HealthPanel::GUARD);

        $this->post('/health/accounts/expenses', [
            'expense_date' => now()->toDateString(),
            'health_expense_category_id' => $category->id,
            'amount' => 3000,
            'pay_mode' => 'bank',
            'paid_from_account_id' => $bankLedgerAccount->id,
            'payee' => 'Out of their bank',
        ])->assertRedirect()->assertSessionHasErrors('paid_from_account_id');

        $this->assertSame(
            0,
            HealthExpense::withoutGlobalScopes()->where('payee', 'Out of their bank')->count(),
            'an expense was paid out of a branch the accountant cannot reach'
        );

        $this->assertSame(
            0.0,
            round(Ledger::accountBalance($this->companyId(), (int) $bankLedgerAccount->id), 2),
            'the other branch bank balance moved'
        );
    }

    /**
     * A payout leaves the drawer it was actually paid from — or it is refused.
     *
     * The pay form takes an optional "paid from" account, and the service falls
     * back to the generic cash/bank line when it is empty. That fallback is what
     * made a bad id dangerous: an out-of-reach or wrong-kind account used to be
     * dropped to null, so the slip said "paid from the bank" while the ledger
     * credited the cash drawer, and nobody was told the account they picked had
     * been thrown away. A named account is a decision — honour it or refuse it.
     */
    public function test_a_settlement_payout_refuses_an_account_it_may_not_credit(): void
    {
        [$branchA, $branchB, $accountant] = $this->branchConfinedAccountant();

        $bankOf = function ($branch, string $code, string $title) {
            $account = \App\Models\HealthAccount::withoutGlobalScopes()->create([
                'company_id' => $this->companyId(),
                'code' => $code,
                'name' => $title,
                'type' => 'asset',
                'subtype' => 'bank',
                'is_bank' => true,
                'is_active' => true,
            ]);

            \App\Models\HealthBankAccount::withoutGlobalScopes()->create([
                'company_id' => $this->companyId(),
                'branch_id' => $branch->id,
                'health_account_id' => $account->id,
                'title' => $title,
                'bank_name' => 'Meezan',
                'is_active' => true,
            ]);

            return $account;
        };

        $myBank = $bankOf($branchA, '1102-MAIN', 'Main Campus Current');
        $theirBank = $bankOf($branchB, '1102-CITY', 'City Branch Current');

        $this->makeShare((int) $branchA->id, $this->doctor, 1000, 'Main campus work');

        $settlement = Shares::buildSettlement(
            $this->companyId(),
            (int) $this->doctor->id,
            now()->startOfMonth()->toDateString(),
            now()->toDateString(),
            $this->owner,
            (int) $branchA->id
        );

        // Approval is the owner's to give; paying it out is the accountant's job.
        Shares::approve($settlement, $this->owner);
        $this->assertSame(HealthDoctorSettlement::STATUS_APPROVED, $settlement->fresh()->status);

        $this->actingAs($accountant->fresh(), HealthPanel::GUARD);

        $payWith = fn (array $payload) => $this->post(
            '/health/accounts/settlements/' . $settlement->id . '/pay',
            array_merge(['pay_method' => 'bank'], $payload)
        );

        // The other branch's bank is not offered...
        $this->get('/health/accounts/settlements/' . $settlement->id)
            ->assertOk()
            ->assertSee('Main Campus Current')
            ->assertDontSee('City Branch Current');

        // ...and typing its id in is refused, not silently turned into cash.
        $payWith(['paid_from_account_id' => $theirBank->id])
            ->assertRedirect()
            ->assertSessionHasErrors('paid_from_account_id');

        // An account that is not a fund account at all.
        $payWith(['paid_from_account_id' => $this->accountId(Chart::INCOME_OPD)])
            ->assertRedirect()
            ->assertSessionHasErrors('paid_from_account_id');

        // A bank account named on a cash payment: the ledger would say one
        // thing and the payout slip another.
        $payWith(['pay_method' => 'cash', 'paid_from_account_id' => $myBank->id])
            ->assertRedirect()
            ->assertSessionHasErrors('paid_from_account_id');

        $this->assertSame(
            HealthDoctorSettlement::STATUS_APPROVED,
            $settlement->fresh()->status,
            'a refused payment still closed the payout'
        );
        $this->assertSame(0.0, round(Ledger::accountBalance($this->companyId(), (int) $theirBank->id), 2));
        $this->assertSame(0.0, round(Ledger::accountBalance($this->companyId(), (int) $myBank->id), 2));
        $this->assertSame(
            0.0,
            round(Ledger::accountBalance($this->companyId(), $this->accountId(Chart::CASH)), 2),
            'the payout was quietly taken out of the cash drawer'
        );

        // Their own branch's bank, on a bank payment, goes through — and the
        // money leaves THAT account, not the generic one.
        $payWith(['paid_from_account_id' => $myBank->id, 'pay_reference' => 'CHQ-1'])->assertRedirect();

        $paid = $settlement->fresh();
        $this->assertSame(HealthDoctorSettlement::STATUS_PAID, $paid->status);
        $this->assertSame((int) $myBank->id, (int) $paid->paid_from_account_id);
        $this->assertEqualsWithDelta(
            -1000,
            Ledger::accountBalance($this->companyId(), (int) $myBank->id),
            0.005,
            'the payout did not come out of the account it named'
        );
        $this->assertSame(
            0.0,
            round(Ledger::accountBalance($this->companyId(), $this->accountId(Chart::CASH)), 2)
        );
    }
}

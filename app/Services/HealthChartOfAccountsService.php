<?php

namespace App\Services;

use App\Models\HealthAccount;
use Illuminate\Support\Facades\Schema;

/**
 * The chart of accounts, and the ONE place a system account is resolved
 * (Task 1552).
 *
 * Everything the posting engine needs is looked up by SYSTEM KEY, never by id
 * and never by name. That is what lets an owner rename "Cash in Hand" to
 * "Counter Cash", re-code it from 1000 to 1001-A, and file it under a parent —
 * without a single receipt landing in the wrong place. Resolving by name would
 * break on the first rename, and the symptom (a trial balance that quietly
 * stops balancing) surfaces weeks later with nothing to point at.
 *
 * The default chart is SEEDED, not assumed. A hospital that wants forty expense
 * accounts adds them; a clinic that wants six deletes nothing, because system
 * accounts are never deletable — they are deactivated at most, and only when
 * they carry no balance.
 */
class HealthChartOfAccountsService
{
    // ── Asset ──
    public const CASH = 'cash';
    public const BANK = 'bank';
    public const CARD_CLEARING = 'card_clearing';
    public const PATIENT_RECEIVABLE = 'patient_receivable';
    public const INSURANCE_RECEIVABLE = 'insurance_receivable';
    public const CORPORATE_RECEIVABLE = 'corporate_receivable';
    public const DOCTOR_ADVANCE = 'doctor_advance';
    public const PHARMACY_INVENTORY = 'pharmacy_inventory';
    public const SUSPENSE = 'suspense';

    // ── Liability ──
    public const SUPPLIER_PAYABLE = 'supplier_payable';
    public const EXPENSE_PAYABLE = 'expense_payable';
    public const PATIENT_ADVANCE = 'patient_advance';
    public const DOCTOR_SHARE_PAYABLE = 'doctor_share_payable';
    public const TAX_PAYABLE = 'tax_payable';

    // ── Equity ──
    public const OWNER_EQUITY = 'owner_equity';
    public const OPENING_EQUITY = 'opening_balance_equity';
    public const RETAINED_EARNINGS = 'retained_earnings';

    // ── Income ──
    public const INCOME_OPD = 'income_opd';
    public const INCOME_IPD = 'income_ipd';
    public const INCOME_OPERATION = 'income_operation';
    public const INCOME_LAB = 'income_lab';
    public const INCOME_PHARMACY = 'income_pharmacy';
    public const INCOME_OTHER = 'income_other';
    public const INCOME_CONCESSION = 'income_concession';

    // ── Expense ──
    public const COGS_PHARMACY = 'cogs_pharmacy';
    public const EXPENSE_DOCTOR_SHARE = 'expense_doctor_share';
    public const EXPENSE_SALARY = 'expense_salary';
    public const EXPENSE_RENT = 'expense_rent';
    public const EXPENSE_UTILITIES = 'expense_utilities';
    public const EXPENSE_SUPPLIES = 'expense_supplies';
    public const EXPENSE_MAINTENANCE = 'expense_maintenance';
    public const EXPENSE_WRITE_OFF = 'expense_write_off';
    public const EXPENSE_REFUND = 'expense_refund';
    public const EXPENSE_GENERAL = 'expense_general';

    /**
     * The default chart.
     *
     * code, name lang key, type, subtype, cash_flow, is_cash, is_bank.
     *
     * Names are lang KEYS, never literal English — the panel ships en / rur / ur
     * from day one, and a chart of accounts is the last place a Pakistani
     * accountant should meet untranslated labels.
     */
    public const DEFAULTS = [
        // ── Assets ──
        self::CASH                  => ['1000', 'asset', 'current_asset', 'operating', true, false],
        self::BANK                  => ['1010', 'asset', 'bank', 'operating', false, true],
        // Card and online money is COLLECTED today and ARRIVES days later. It is
        // an asset in its own right until the acquirer settles, or the day's
        // cash reconciliation is wrong by every card sale taken.
        self::CARD_CLEARING         => ['1020', 'asset', 'current_asset', 'operating', false, false],
        self::PATIENT_RECEIVABLE    => ['1100', 'asset', 'receivable', 'operating', false, false],
        self::INSURANCE_RECEIVABLE  => ['1110', 'asset', 'receivable', 'operating', false, false],
        self::CORPORATE_RECEIVABLE  => ['1120', 'asset', 'receivable', 'operating', false, false],
        // Money already in a doctor's hands — an advance drawn against future
        // work, or a shortfall being recovered. It is the hospital's ASSET until
        // a payout clears it. Netting it off the payout instead would make the
        // doctor's own share look smaller than it was and leave the advance
        // sitting in the books forever.
        self::DOCTOR_ADVANCE        => ['1130', 'asset', 'receivable', 'operating', false, false],
        self::PHARMACY_INVENTORY    => ['1200', 'asset', 'inventory', 'operating', false, false],
        // Where an unexplained reconciliation difference is parked until it is
        // understood. Never a dumping ground: the reconciliation screen shows
        // its balance so an ignored one is visible.
        self::SUSPENSE              => ['1900', 'asset', 'current_asset', 'operating', false, false],

        // ── Liabilities ──
        self::SUPPLIER_PAYABLE      => ['2000', 'liability', 'payable', 'operating', false, false],
        self::EXPENSE_PAYABLE       => ['2010', 'liability', 'payable', 'operating', false, false],
        // A patient's advance is the hospital's DEBT until treatment is billed
        // against it. Booking it as income on arrival is how a clinic reports a
        // profit it would have to refund.
        self::PATIENT_ADVANCE       => ['2100', 'liability', 'payable', 'operating', false, false],
        self::DOCTOR_SHARE_PAYABLE  => ['2200', 'liability', 'payable', 'operating', false, false],
        self::TAX_PAYABLE           => ['2300', 'liability', 'tax', 'operating', false, false],

        // ── Equity ──
        self::OWNER_EQUITY          => ['3000', 'equity', 'capital', 'financing', false, false],
        self::OPENING_EQUITY        => ['3100', 'equity', 'capital', null, false, false],
        self::RETAINED_EARNINGS     => ['3200', 'equity', 'retained', null, false, false],

        // ── Income ──
        self::INCOME_OPD            => ['4000', 'income', 'direct_income', 'operating', false, false],
        self::INCOME_IPD            => ['4010', 'income', 'direct_income', 'operating', false, false],
        self::INCOME_OPERATION      => ['4020', 'income', 'direct_income', 'operating', false, false],
        self::INCOME_LAB            => ['4030', 'income', 'direct_income', 'operating', false, false],
        self::INCOME_PHARMACY       => ['4040', 'income', 'direct_income', 'operating', false, false],
        self::INCOME_OTHER          => ['4090', 'income', 'other_income', 'operating', false, false],
        // CONTRA-INCOME. Typed as income on purpose so it nets against revenue
        // instead of inflating expenses: a hospital that gave 200,000 in
        // concessions did not spend 200,000, it earned that much less.
        self::INCOME_CONCESSION     => ['4900', 'income', 'contra_income', 'operating', false, false],

        // ── Expenses ──
        self::COGS_PHARMACY         => ['5000', 'expense', 'cost_of_sales', 'operating', false, false],
        self::EXPENSE_DOCTOR_SHARE  => ['5100', 'expense', 'direct_cost', 'operating', false, false],
        self::EXPENSE_SALARY        => ['5200', 'expense', 'operating', 'operating', false, false],
        self::EXPENSE_RENT          => ['5300', 'expense', 'operating', 'operating', false, false],
        self::EXPENSE_UTILITIES     => ['5310', 'expense', 'operating', 'operating', false, false],
        self::EXPENSE_SUPPLIES      => ['5320', 'expense', 'operating', 'operating', false, false],
        self::EXPENSE_MAINTENANCE   => ['5330', 'expense', 'operating', 'operating', false, false],
        self::EXPENSE_WRITE_OFF     => ['5900', 'expense', 'operating', 'operating', false, false],
        self::EXPENSE_REFUND        => ['5910', 'expense', 'operating', 'operating', false, false],
        self::EXPENSE_GENERAL       => ['5990', 'expense', 'operating', 'operating', false, false],
    ];

    /**
     * Which income account a charge category earns into.
     *
     * Anything unmapped lands in Other Income rather than being dropped — a
     * category we have not thought of yet must still reach the books.
     */
    public const CATEGORY_INCOME = [
        'opd'            => self::INCOME_OPD,
        'doctor'         => self::INCOME_OPD,
        'procedure'      => self::INCOME_OPD,
        'pharmacy'       => self::INCOME_PHARMACY,
        'consumable'     => self::INCOME_PHARMACY,
        'lab'            => self::INCOME_LAB,
        'investigation'  => self::INCOME_LAB,
        'room'           => self::INCOME_IPD,
        'nursing'        => self::INCOME_IPD,
        'operation'      => self::INCOME_OPERATION,
        'service'        => self::INCOME_OTHER,
        'misc'           => self::INCOME_OTHER,
    ];

    /**
     * Which asset a payment method lands in.
     *
     * A LIST rather than a match on 'cash': the retail side learned the hard way
     * that one alias missing from a bucket quietly moves money out of the day's
     * totals, and healthcare takes the same methods.
     */
    public const METHOD_ACCOUNT = [
        'cash'      => self::CASH,
        'card'      => self::CARD_CLEARING,
        'online'    => self::CARD_CLEARING,
        'cheque'    => self::BANK,
        'bank'      => self::BANK,
        'insurance' => self::INSURANCE_RECEIVABLE,
        'corporate' => self::CORPORATE_RECEIVABLE,
        'credit'    => self::PATIENT_RECEIVABLE,
        'other'     => self::CASH,
    ];

    /** Per-request memo: "companyId:systemKey" => account (or null). */
    protected static array $cache = [];

    /**
     * Make sure the organisation has its default chart. Idempotent.
     *
     * @return int how many accounts were created this call
     */
    public static function seed(int $companyId, $actor = null): int
    {
        if (!Schema::hasTable('health_accounts')) {
            return 0;
        }

        $existing = HealthAccount::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->pluck('system_key')
            ->filter()
            ->all();

        $created = 0;
        foreach (self::DEFAULTS as $key => [$code, $type, $subtype, $flow, $isCash, $isBank]) {
            if (in_array($key, $existing, true)) {
                continue;
            }

            HealthAccount::withoutGlobalScopes()->create([
                'company_id' => $companyId,
                'code' => $code,
                'name' => __('health.acc_' . $key),
                'type' => $type,
                'subtype' => $subtype,
                'cash_flow' => $flow,
                'system_key' => $key,
                'is_system' => true,
                'is_cash' => $isCash,
                'is_bank' => $isBank,
                'is_active' => true,
                'created_by' => $actor->id ?? null,
            ]);
            $created++;
        }

        if ($created > 0) {
            self::$cache = [];
        }

        return $created;
    }

    /**
     * The account behind a system key, seeding the chart if it is missing.
     *
     * Returns NULL only when the table itself is absent (a box mid-deploy). The
     * posting engine treats NULL as "cannot post yet" and refuses, rather than
     * inventing an account and burying money in it.
     */
    public static function resolve(int $companyId, string $systemKey): ?HealthAccount
    {
        if (!Schema::hasTable('health_accounts')) {
            return null;
        }

        $cacheKey = $companyId . ':' . $systemKey;
        if (array_key_exists($cacheKey, self::$cache)) {
            return self::$cache[$cacheKey];
        }

        $account = HealthAccount::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('system_key', $systemKey)
            ->first();

        if (!$account && array_key_exists($systemKey, self::DEFAULTS)) {
            self::seed($companyId);
            $account = HealthAccount::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('system_key', $systemKey)
                ->first();
        }

        return self::$cache[$cacheKey] = $account;
    }

    /** The id behind a system key, or null. */
    public static function id(int $companyId, string $systemKey): ?int
    {
        $account = self::resolve($companyId, $systemKey);

        return $account ? (int) $account->id : null;
    }

    /** Income account id for a charge category. */
    public static function incomeIdForCategory(int $companyId, ?string $category): ?int
    {
        $key = self::CATEGORY_INCOME[$category] ?? self::INCOME_OTHER;

        return self::id($companyId, $key);
    }

    /** Asset/receivable account id for a payment method. */
    public static function accountIdForMethod(int $companyId, ?string $method): ?int
    {
        $key = self::METHOD_ACCOUNT[$method] ?? self::CASH;

        return self::id($companyId, $key);
    }

    /**
     * The next free code under a type, so a hand-added account does not collide
     * with a system one and does not have to be numbered by the accountant.
     */
    public static function suggestCode(int $companyId, string $type): string
    {
        $base = [
            HealthAccount::TYPE_ASSET => 1000,
            HealthAccount::TYPE_LIABILITY => 2000,
            HealthAccount::TYPE_EQUITY => 3000,
            HealthAccount::TYPE_INCOME => 4000,
            HealthAccount::TYPE_EXPENSE => 5000,
        ][$type] ?? 6000;

        $taken = HealthAccount::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->pluck('code')
            ->map(fn ($c) => (int) preg_replace('/\D+/', '', (string) $c))
            ->filter(fn ($c) => $c >= $base && $c < $base + 1000)
            ->all();

        for ($i = $base + 500; $i < $base + 1000; $i++) {
            if (!in_array($i, $taken, true)) {
                return (string) $i;
            }
        }

        return (string) ($base + 999);
    }

    /** Cash and bank accounts — what a transfer, deposit or payout may touch. */
    public static function fundAccounts(int $companyId)
    {
        if (!Schema::hasTable('health_accounts')) {
            return collect();
        }

        self::seed($companyId);

        return HealthAccount::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where(function ($q) {
                $q->where('is_cash', true)->orWhere('is_bank', true);
            })
            ->orderBy('code')
            ->get();
    }

    /** Reset the per-request memo (tests, and after a chart edit). */
    public static function flush(): void
    {
        self::$cache = [];
    }
}

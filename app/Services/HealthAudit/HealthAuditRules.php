<?php

namespace App\Services\HealthAudit;

use App\Services\HealthAudit\Checks\AccessChecks;
use App\Services\HealthAudit\Checks\AccountsChecks;
use App\Services\HealthAudit\Checks\BillingChecks;
use App\Services\HealthAudit\Checks\ClinicalChecks;
use App\Services\HealthAudit\Checks\HrChecks;
use App\Services\HealthAudit\Checks\PaymentChecks;
use App\Services\HealthAudit\Checks\StockChecks;

/**
 * The ruleset (Task 1554).
 *
 * Every check is deterministic: the same period, the same filters and the same
 * data produce the same findings, in the same order, with the same
 * fingerprints. That is the whole point — an audit whose answer wobbles between
 * runs cannot be used to show anybody that something changed.
 *
 * THRESHOLDS ARE PART OF THE VERSION. Moving "an unusual discount starts at
 * 30%" to 20% silently would make last month's clean report and this month's
 * alarming one look like a change in the hospital rather than a change in the
 * ruler. So the constants live beside VERSION, and VERSION goes up whenever one
 * of them, or any rule's logic, moves.
 *
 * A finding is never a verdict. Each one carries the rows it came from and the
 * screen those rows live on; the label only says how hard to look.
 */
class HealthAuditRules
{
    /**
     * Bump on ANY threshold or logic change below.
     *
     * 2 — every rule honours the reader's department fence (through the bill,
     *     admission, cashier or doctor where the table has no ward column) and
     *     duplicate-patient evidence is registration numbers only, confined to
     *     the reader's branches.
     * 3 — finding params/evidence pass through the trail's redaction policy:
     *     free-text reasons, notes and descriptions persist as a length only.
     */
    public const VERSION = '3';

    // ── Thresholds, frozen into VERSION ──────────────────────────────────
    /** A concession at or above this share of the gross is worth a look. */
    public const CONCESSION_ALERT_PCT = 30.0;

    /** Ignore till differences smaller than this — they are rounding, not a gap. */
    public const CASH_VARIANCE_MIN = 1.0;

    /** A till out by this much stops being a warning and becomes critical. */
    public const CASH_VARIANCE_CRITICAL = 5000.0;

    /** Money reconciliations allow this much slack before they complain. */
    public const MONEY_TOLERANCE = 1.0;

    /** A posted charge older than this with no bill behind it is a loose end. */
    public const UNBILLED_AGE_DAYS = 3;

    /** This many failed sign-ins by one account in one day is a burst. */
    public const FAILED_LOGIN_BURST = 5;

    /** Never return more than this many rows from one rule. */
    public const PER_RULE_CAP = 300;

    /** severity weights → risk score. */
    public const WEIGHTS = ['critical' => 8.0, 'warning' => 2.5, 'info' => 0.4];

    /**
     * rule key => [category, severity, check].
     *
     * Order matters only for readability; the engine sorts findings itself so
     * two runs of the same scope produce byte-identical result hashes.
     */
    public static function all(): array
    {
        return [
            // ── Clinical activity, and the links out of it ────────────────
            'appointment_no_visit' => [
                'category' => 'clinical',
                'severity' => 'warning',
                'check' => [ClinicalChecks::class, 'appointmentNoVisit'],
            ],
            'visit_left_open' => [
                'category' => 'clinical',
                'severity' => 'info',
                'check' => [ClinicalChecks::class, 'visitLeftOpen'],
            ],
            'duplicate_patient' => [
                'category' => 'clinical',
                'severity' => 'warning',
                'check' => [ClinicalChecks::class, 'duplicatePatient'],
            ],
            'operation_no_charge' => [
                'category' => 'clinical',
                'severity' => 'warning',
                'check' => [ClinicalChecks::class, 'operationNoCharge'],
            ],

            // ── Billing: what was charged, changed, forgiven or undone ────
            'visit_fee_not_collected' => [
                'category' => 'billing',
                'severity' => 'warning',
                'check' => [BillingChecks::class, 'visitFeeNotCollected'],
            ],
            'visit_fee_waived_no_reason' => [
                'category' => 'billing',
                'severity' => 'critical',
                'check' => [BillingChecks::class, 'visitFeeWaivedNoReason'],
            ],
            'charge_reversed' => [
                'category' => 'billing',
                'severity' => 'warning',
                'check' => [BillingChecks::class, 'chargeReversed'],
            ],
            'charge_reversed_no_reason' => [
                'category' => 'billing',
                'severity' => 'critical',
                'check' => [BillingChecks::class, 'chargeReversedNoReason'],
            ],
            'charge_high_concession' => [
                'category' => 'billing',
                'severity' => 'warning',
                'check' => [BillingChecks::class, 'chargeHighConcession'],
            ],
            'charge_concession_unapproved' => [
                'category' => 'billing',
                'severity' => 'critical',
                'check' => [BillingChecks::class, 'chargeConcessionUnapproved'],
            ],
            'charge_unbilled' => [
                'category' => 'billing',
                'severity' => 'warning',
                'check' => [BillingChecks::class, 'chargeUnbilled'],
            ],
            'admission_charge_reversed' => [
                'category' => 'billing',
                'severity' => 'warning',
                'check' => [BillingChecks::class, 'admissionChargeReversed'],
            ],
            'bill_cancelled_after_payment' => [
                'category' => 'billing',
                'severity' => 'critical',
                'check' => [BillingChecks::class, 'billCancelledAfterPayment'],
            ],
            'bill_refunded' => [
                'category' => 'billing',
                'severity' => 'warning',
                'check' => [BillingChecks::class, 'billRefunded'],
            ],
            'bill_payment_mismatch' => [
                'category' => 'billing',
                'severity' => 'critical',
                'check' => [BillingChecks::class, 'billPaymentMismatch'],
            ],

            // ── The counter: cash in, cash counted ────────────────────────
            'payment_reversed' => [
                'category' => 'payment',
                'severity' => 'warning',
                'check' => [PaymentChecks::class, 'paymentReversed'],
            ],
            'payment_reversed_no_reason' => [
                'category' => 'payment',
                'severity' => 'critical',
                'check' => [PaymentChecks::class, 'paymentReversedNoReason'],
            ],
            'shift_cash_variance' => [
                'category' => 'payment',
                'severity' => 'warning',
                'check' => [PaymentChecks::class, 'shiftCashVariance'],
            ],
            'shift_left_open' => [
                'category' => 'payment',
                'severity' => 'warning',
                'check' => [PaymentChecks::class, 'shiftLeftOpen'],
            ],
            'cash_outside_shift' => [
                'category' => 'payment',
                'severity' => 'info',
                'check' => [PaymentChecks::class, 'cashOutsideShift'],
            ],

            // ── Pharmacy stock: what came in, what went out, what is left ─
            'batch_stock_variance' => [
                'category' => 'stock',
                'severity' => 'critical',
                'check' => [StockChecks::class, 'batchStockVariance'],
            ],
            'expired_stock_dispensed' => [
                'category' => 'stock',
                'severity' => 'critical',
                'check' => [StockChecks::class, 'expiredStockDispensed'],
            ],
            'expired_batch_still_sellable' => [
                'category' => 'stock',
                'severity' => 'warning',
                'check' => [StockChecks::class, 'expiredBatchStillSellable'],
            ],
            'stock_manual_adjustment' => [
                'category' => 'stock',
                'severity' => 'warning',
                'check' => [StockChecks::class, 'stockManualAdjustment'],
            ],
            'pharmacy_sale_refunded' => [
                'category' => 'stock',
                'severity' => 'warning',
                'check' => [StockChecks::class, 'pharmacySaleRefunded'],
            ],

            // ── The books, and what the doctors are owed ──────────────────
            'doctor_share_missing' => [
                'category' => 'accounts',
                'severity' => 'warning',
                'check' => [AccountsChecks::class, 'doctorShareMissing'],
            ],
            'doctor_settlement_variance' => [
                'category' => 'accounts',
                'severity' => 'critical',
                'check' => [AccountsChecks::class, 'doctorSettlementVariance'],
            ],
            'doctor_settlement_overpaid' => [
                'category' => 'accounts',
                'severity' => 'critical',
                'check' => [AccountsChecks::class, 'doctorSettlementOverpaid'],
            ],
            'doctor_share_excluded' => [
                'category' => 'accounts',
                'severity' => 'warning',
                'check' => [AccountsChecks::class, 'doctorShareExcluded'],
            ],
            'journal_reversed' => [
                'category' => 'accounts',
                'severity' => 'warning',
                'check' => [AccountsChecks::class, 'journalReversed'],
            ],
            'journal_unbalanced' => [
                'category' => 'accounts',
                'severity' => 'critical',
                'check' => [AccountsChecks::class, 'journalUnbalanced'],
            ],
            'expense_reversed' => [
                'category' => 'accounts',
                'severity' => 'warning',
                'check' => [AccountsChecks::class, 'expenseReversed'],
            ],

            // ── Attendance, and the hands that changed it ─────────────────
            'attendance_manual_day' => [
                'category' => 'hr',
                'severity' => 'warning',
                'check' => [HrChecks::class, 'attendanceManualDay'],
            ],
            'attendance_self_approved' => [
                'category' => 'hr',
                'severity' => 'critical',
                'check' => [HrChecks::class, 'attendanceSelfApproved'],
            ],
            'attendance_punch_disregarded' => [
                'category' => 'hr',
                'severity' => 'warning',
                'check' => [HrChecks::class, 'attendancePunchDisregarded'],
            ],

            // ── Who reached what, and whether the trail itself is intact ──
            'permission_changed' => [
                'category' => 'access',
                'severity' => 'warning',
                'check' => [AccessChecks::class, 'permissionChanged'],
            ],
            'sensitive_record_viewed' => [
                'category' => 'record_view',
                'severity' => 'warning',
                'check' => [AccessChecks::class, 'sensitiveRecordViewed'],
            ],
            'failed_login_burst' => [
                'category' => 'auth',
                'severity' => 'warning',
                'check' => [AccessChecks::class, 'failedLoginBurst'],
            ],
            'data_exported' => [
                'category' => 'export',
                'severity' => 'info',
                'check' => [AccessChecks::class, 'dataExported'],
            ],
            'audit_trail_break' => [
                'category' => 'audit',
                'severity' => 'critical',
                'check' => [AccessChecks::class, 'auditTrailBreak'],
            ],
        ];
    }

    public static function categories(): array
    {
        return array_values(array_unique(array_column(self::all(), 'category')));
    }

    public static function meta(string $ruleKey): ?array
    {
        return self::all()[$ruleKey] ?? null;
    }
}

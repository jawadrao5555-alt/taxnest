<?php

namespace App\Support;

/**
 * ONE human label for a stored payment_method — the single wording authority.
 *
 * Owner (26 Aug 2026, ZFC): the same prepaid delivery bill printed "QR PAYMENT"
 * on its info line, "ONLINE / QR" in its boxed banner and "PREPAID" on its
 * stamp, while the reports screen called the very same bill "Qr Payment" and an
 * ordinary card sale "Debit Card". Three names for one thing on one slip is how
 * a shop loses trust in the numbers.
 *
 * Every screen, receipt, PDF and export that SHOWS a payment method must call
 * this. Aggregation is a different question — that stays with PosPaymentBuckets
 * (cash / card / other), which deliberately keeps 'qr_payment' out of the card
 * bucket. Labels group more loosely than buckets on purpose: a customer reading
 * a slip does not care that we stored 'debit_card' rather than 'card'.
 *
 * Locked by tests/Feature/PosPaymentLabelTest.php.
 */
final class PosPaymentLabels
{
    /**
     * Stored values that mean a plain card sale. 'card' is the legacy spelling
     * of 'debit_card'. NOTE: this is deliberately NOT PosPaymentBuckets'
     * CARD_ALIASES — a credit card is offered as its own report filter, so it
     * must keep its own wording, or the filter and the rows would disagree.
     */
    public const CARD_ALIASES = ['card', 'debit_card'];

    /** Stored values that mean "money arrived online" (transfer / QR / Raast). */
    public const ONLINE_ALIASES = ['qr_payment', 'qr', 'online', 'raast'];

    /** Stored values that mean "on the customer's khata" (FBR retail udhaar). */
    public const KHATA_ALIASES = ['credit', 'khata', 'udhaar'];

    /** Human label in the CURRENT locale (receipts render per-company locale). */
    public static function label(?string $method): string
    {
        $m = strtolower(trim((string) $method));

        if ($m === '') {
            return '—';
        }
        if ($m === PosPaymentBuckets::CASH) {
            return __('pos.pm_cash');
        }
        if (in_array($m, self::CARD_ALIASES, true)) {
            return __('pos.pm_card');
        }
        if ($m === 'credit_card') {
            return __('pos.pm_credit_card');
        }
        if (in_array($m, self::ONLINE_ALIASES, true)) {
            return __('pos.pm_online');
        }
        if (in_array($m, self::KHATA_ALIASES, true)) {
            return __('pos.pm_khata');
        }
        if ($m === 'bank_transfer') {
            return __('pos.pm_bank');
        }

        // Unknown/legacy value: never hide it, just tidy it up.
        return ucwords(str_replace('_', ' ', $m));
    }

    /** Same label, upper-cased — for the boxed banner on delivery receipts. */
    public static function upper(?string $method): string
    {
        return mb_strtoupper(self::label($method), 'UTF-8');
    }
}

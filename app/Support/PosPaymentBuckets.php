<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * PRA POS cash/card/other payment buckets — the SINGLE alias-set definition.
 *
 * The universal sale screen normalizes the UI's "Card" choice to
 * payment_method='debit_card' before saving (PosTaxRule does the same for
 * rate lookups), while historical rows may still carry 'card' and some flows
 * store 'credit_card'. Any aggregation splitting cash/card/other (day-close
 * page stats, stored Z-report figures, PDFs, analytics) MUST read these
 * constants — matching ='card' reports Rs 0 card sales and dumps them into
 * "Other" (live incident, Jul 2026).
 *
 * 'qr_payment' stays in the Other bucket ON PURPOSE (owner rule).
 *
 * Locked by tests/Feature/PosPaymentBucketsTest.php — if a future report
 * hand-rolls ='card' (or forgets an alias), those tests fail.
 */
final class PosPaymentBuckets
{
    /** Cash is stored as exactly 'cash' on every write path. */
    public const CASH = 'cash';

    /** Every stored payment_method value that means "paid by card". */
    public const CARD_ALIASES = ['card', 'debit_card', 'credit_card'];

    /** cash + card aliases — anything NOT in this set is the Other bucket. */
    public static function cashOrCard(): array
    {
        return array_merge([self::CASH], self::CARD_ALIASES);
    }

    /** Bucket for a stored payment_method: 'cash' | 'card' | 'other'. */
    public static function bucket(?string $method): string
    {
        if ($method === self::CASH) {
            return 'cash';
        }

        return in_array($method, self::CARD_ALIASES, true) ? 'card' : 'other';
    }

    /**
     * Sum an already-loaded transaction collection into the three buckets.
     *
     * @return array{cash: float, card: float, other: float}
     */
    public static function split(Collection $transactions, string $column = 'total_amount'): array
    {
        $sums = ['cash' => 0.0, 'card' => 0.0, 'other' => 0.0];
        foreach ($transactions as $t) {
            $sums[self::bucket($t->payment_method)] += (float) ($t->{$column} ?? 0);
        }

        return $sums;
    }
}

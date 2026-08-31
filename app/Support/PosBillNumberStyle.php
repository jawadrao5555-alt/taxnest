<?php

namespace App\Support;

/**
 * Bill Number Style — bill par CHHAPNE wala bara number kaunsa hai.
 *
 * Aik hi jagah faisla hota hai, kyunke yeh number paanch jagah chhapta hai
 * (80mm receipt, 58mm receipt, restaurant KOT, shim KOT, public bill page).
 * Pehle har jagah wohi teen line dobara likhi hui thi — aik nayi style barhate
 * hi aadhi jaghein purane usool par reh jatin aur parchi receipt se na milti.
 *
 * Teen styles:
 *   'serial' — asal invoice_number (default).
 *   'token'  — roz ka token (1, 2, 3…), 6 baje subah reset (business day).
 *   'daily'  — wohi roz ka token, magar local bill jaisi shakal mein: L001.
 *
 * ── 'daily' kyun (ZFC, 1 Sep 2026) ────────────────────────────────────────
 * Shop ki farmaish: "L series roz L001 se shuru ho." Asal invoice_number ko roz
 * dobara L001 karna MUMKIN NAHI — (company_id, invoice_number) par unique bandhi
 * hai aur purane bill archive hote hain, mit'te nahi; us bandhish ko dhila karna
 * har shop ki P-series ki hifazat kam kar deta. Is liye number wahi hota hai jo
 * shop chahti hai — CHHAPTA L001 — aur neechay asal monotonic serial jyun ka
 * tyun rehta hai, taake khata, search, return aur PRA sab usi aik serial par
 * chalte rahein (bilkul jaise 'token' style pehle se karti hai).
 *
 * Har raasta try/catch aur Schema guard ke peechay hai: yeh mehez chhapayi ki
 * baat hai, isay kisi receipt ko tor'ne ka haq nahi.
 */
class PosBillNumberStyle
{
    /** Local bill ki shakal: L + 3 hindse (PosLocalSeries jaisi hi). */
    public const DAILY_PREFIX = 'L';
    public const DAILY_PAD = 3;

    /**
     * Is bill ke STREAM ki style. Stream ka usool receipts wala hi hai:
     * local = L-series bill YA reporting-OFF/exempt final.
     */
    public static function styleFor($company, $transaction): string
    {
        try {
            $isLocal = $transaction->isLocalBill() || $transaction->isExemptStream();
            $style = $isLocal
                ? ($company->local_number_style ?? 'serial')
                : ($company->pra_number_style ?? 'serial');
        } catch (\Throwable $e) {
            return 'serial';
        }

        return in_array($style, ['serial', 'token', 'daily'], true) ? $style : 'serial';
    }

    /** Kya is style ke liye bill ki paidaish par token allocate karna hai? */
    public static function needsToken(?string $style): bool
    {
        return $style === 'token' || $style === 'daily';
    }

    /**
     * Bill par chhapne wala bara number — ya null (yani asal serial chhapega).
     * String lautata hai: 'token' ke liye "7", 'daily' ke liye "L007".
     */
    public static function bigNumber($company, $transaction): ?string
    {
        try {
            if (!\Illuminate\Support\Facades\Schema::hasColumn('pos_transactions', 'bill_token')) {
                return null;
            }
            $token = (int) ($transaction->bill_token ?? 0);
            if ($token < 1) {
                return null;
            }
            $style = self::styleFor($company, $transaction);
            if ($style === 'token') {
                return (string) $token;
            }
            if ($style === 'daily') {
                return self::DAILY_PREFIX . str_pad((string) $token, self::DAILY_PAD, '0', STR_PAD_LEFT);
            }
        } catch (\Throwable $e) {
            return null;
        }

        return null;
    }
}

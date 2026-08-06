<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OrderTokenService
{
    /**
     * Daily Token numbering for the Order Matching feature ('token' style).
     *
     * Company-central sequence (NOT per terminal): the counter lives on the
     * companies row and is allocated under lockForUpdate, so two counters
     * billing at once can never draw the same token.
     *
     * Reset follows the POS Business Day convention (00:00–05:59 belongs to
     * yesterday) — a restaurant serving past midnight keeps one unbroken
     * token series for the whole evening instead of restarting mid-service.
     */
    public static function nextToken(int $companyId): ?int
    {
        if (!Schema::hasColumn('companies', 'pos_token_counter')) {
            return null; // PROD drift guard — feature silently off until migrated.
        }

        return DB::transaction(function () use ($companyId) {
            $company = Company::where('id', $companyId)->lockForUpdate()->first();
            if (!$company) {
                return null;
            }

            $businessDate = now()->hour < 6
                ? now()->subDay()->toDateString()
                : now()->toDateString();

            $storedDate = $company->pos_token_date
                ? (is_string($company->pos_token_date) ? substr($company->pos_token_date, 0, 10) : $company->pos_token_date->toDateString())
                : null;

            $counter = ($storedDate === $businessDate)
                ? (int) ($company->pos_token_counter ?? 0)
                : 0;

            $counter++;

            $company->pos_token_counter = $counter;
            $company->pos_token_date = $businessDate;
            $company->save();

            return $counter;
        });
    }

    /**
     * Daily BILL token for the Bill Number Style feature ('token' display style).
     * Same company-central lockForUpdate + business-day 6AM reset as order
     * tokens, but with SEPARATE counters per stream so offline and PRA series
     * each stay dense (1,2,3…) and never reveal the other stream's volume.
     *
     * @param string $stream 'pra' | 'local'
     */
    public static function nextBillToken(int $companyId, string $stream): ?int
    {
        $stream = $stream === 'pra' ? 'pra' : 'local';
        $counterCol = "bill_token_counter_{$stream}";
        $dateCol = "bill_token_date_{$stream}";

        if (!Schema::hasColumn('companies', $counterCol)) {
            return null; // PROD drift guard — feature silently off until migrated.
        }

        return DB::transaction(function () use ($companyId, $counterCol, $dateCol) {
            $company = Company::where('id', $companyId)->lockForUpdate()->first();
            if (!$company) {
                return null;
            }

            $businessDate = now()->hour < 6
                ? now()->subDay()->toDateString()
                : now()->toDateString();

            $storedDateRaw = $company->{$dateCol};
            $storedDate = $storedDateRaw
                ? (is_string($storedDateRaw) ? substr($storedDateRaw, 0, 10) : $storedDateRaw->toDateString())
                : null;

            $counter = ($storedDate === $businessDate)
                ? (int) ($company->{$counterCol} ?? 0)
                : 0;

            $counter++;

            $company->{$counterCol} = $counter;
            $company->{$dateCol} = $businessDate;
            $company->save();

            return $counter;
        });
    }

    /** Short display code for the 'code' style — last segment of ORD-yymmdd-XXXXX. */
    public static function shortCode(?string $orderNumber): ?string
    {
        if (!$orderNumber) {
            return null;
        }
        $tail = strrchr($orderNumber, '-');

        return $tail !== false ? strtoupper(substr($tail, 1)) : strtoupper($orderNumber);
    }
}

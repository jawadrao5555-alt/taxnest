<?php

namespace App\Services;

use App\Models\AiPageLedger;
use App\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * AI Reader page credits (Sep 2026 DI package restructure).
 *
 * Two pockets, spent in this order:
 *   1. MONTHLY ALLOWANCE — comes with the package (Asaan 200 / Kaarobar 400 /
 *      Unlimited 700). Resets every calendar month; leftovers do NOT carry
 *      over, otherwise a shop hoards months and dumps one giant batch.
 *   2. PURCHASED BALANCE — pages the shop paid for. These never expire while
 *      the account lives, because that is money already handed over.
 *
 * A page is only charged when the read SUCCEEDS. Reserve first, then commit or
 * refund — a failed / abandoned batch must hand the pages back.
 */
class AiPageCreditService
{
    /** Top-up packs: pages => price (PKR). Server-quoted; never user-entered. */
    public const PACKS = [
        100  => 499,
        500  => 1999,
        1000 => 3999,
    ];

    /**
     * A "strong model" page (gpt-4o) costs roughly 14x a gpt-4o-mini page at
     * the API. Charging it 1:1 would turn a full allowance into a loss, so it
     * burns 10 pages instead. Mini stays 1.
     */
    public const STRONG_MODEL_PAGE_COST = 10;

    public static function pageCostFor(?string $model): int
    {
        $model = trim((string) $model);

        if ($model === '' || $model === AiInvoiceReaderService::MODEL) {
            return 1;
        }

        return str_contains($model, 'mini') ? 1 : self::STRONG_MODEL_PAGE_COST;
    }

    /**
     * Monthly pages included with the company's effective package (-1 = unlimited).
     * One truth: AiInvoiceReaderService::monthlyQuota reads the plan column and
     * keeps the legacy fallback for plan rows that predate it.
     */
    public static function allowance(Company $company): int
    {
        return AiInvoiceReaderService::monthlyQuota($company);
    }

    /**
     * Is the page-credit schema actually present?
     *
     * The owner's production database has a history of arriving late to new
     * migrations, and a reader that 500s is far worse than one that skips its
     * bookkeeping for one deploy window.
     */
    public static function ledgerReady(): bool
    {
        static $ready = null;

        if ($ready === null) {
            $ready = \Illuminate\Support\Facades\Schema::hasTable('ai_page_ledgers')
                && \Illuminate\Support\Facades\Schema::hasColumn('companies', 'ai_page_balance');
        }

        return $ready;
    }

    /** Allowance pages already spent in the current calendar month (net of refunds). */
    public static function usedThisMonth(Company|int $company): int
    {
        if (!self::ledgerReady()) {
            return 0;
        }

        $companyId = $company instanceof Company ? $company->id : (int) $company;

        $rows = AiPageLedger::where('company_id', $companyId)
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->selectRaw("SUM(CASE WHEN kind = ? THEN from_allowance ELSE 0 END) AS spent", [AiPageLedger::KIND_CONSUME])
            ->selectRaw("SUM(CASE WHEN kind = ? THEN from_allowance ELSE 0 END) AS returned", [AiPageLedger::KIND_REFUND])
            ->first();

        return max(0, (int) ($rows->spent ?? 0) - (int) ($rows->returned ?? 0));
    }

    public static function allowanceRemaining(Company $company): int
    {
        $allowance = self::allowance($company);

        if ($allowance === -1) {
            return -1;
        }

        return max(0, $allowance - self::usedThisMonth($company));
    }

    public static function purchasedBalance(Company|int $company): int
    {
        if (!$company instanceof Company) {
            $company = Company::find((int) $company);
            if (!$company) {
                return 0;
            }
        }

        return max(0, (int) ($company->ai_page_balance ?? 0));
    }

    /** Pages the shop can spend right now (-1 = unlimited). */
    public static function totalRemaining(Company $company): int
    {
        $allowance = self::allowanceRemaining($company);

        return $allowance === -1 ? -1 : $allowance + self::purchasedBalance($company);
    }

    /**
     * Snapshot for billing / reader screens.
     */
    public static function summary(Company $company): array
    {
        $allowance = self::allowance($company);
        $unlimited = $allowance === -1;

        return [
            'unlimited'           => $unlimited,
            'allowance'           => $allowance,
            'allowance_used'      => $unlimited ? 0 : self::usedThisMonth($company),
            'allowance_remaining' => $unlimited ? -1 : self::allowanceRemaining($company),
            'purchased'           => self::purchasedBalance($company),
            'total_remaining'     => self::totalRemaining($company),
            'resets_on'           => now()->startOfMonth()->addMonth()->format('d M Y'),
            'packs'               => self::PACKS,
        ];
    }

    public static function canConsume(Company $company, int $pages): array
    {
        if ($pages <= 0) {
            return ['allowed' => true, 'remaining' => self::totalRemaining($company)];
        }

        $remaining = self::totalRemaining($company);

        if ($remaining === -1) {
            return ['allowed' => true, 'remaining' => -1];
        }

        if ($remaining < $pages) {
            return [
                'allowed'   => false,
                'remaining' => $remaining,
                'reason'    => $remaining === 0
                    ? 'AI Reader pages khatam ho gayi hain. Naye pages top-up karein ya agle mahine ka intezar karein.'
                    : "Sirf {$remaining} AI Reader pages baqi hain, is kaam ke liye {$pages} chahiyen. Top-up karein.",
            ];
        }

        return ['allowed' => true, 'remaining' => $remaining];
    }

    /**
     * Spend pages: allowance first, then the purchased balance.
     * Returns the ledger row so the caller can refund it later.
     *
     * @throws \RuntimeException when the company cannot cover the pages.
     */
    public static function consume(Company $company, int $pages, string $source, array $meta = []): ?AiPageLedger
    {
        if ($pages <= 0 || !self::ledgerReady()) {
            return null;
        }

        return DB::transaction(function () use ($company, $pages, $source, $meta) {
            /** @var Company $locked */
            $locked = Company::whereKey($company->id)->lockForUpdate()->first();

            if (!$locked) {
                throw new \RuntimeException('Company not found for AI page consumption.');
            }

            $allowanceRemaining = self::allowanceRemaining($locked);

            if ($allowanceRemaining === -1) {
                // Internal / unlimited: still write the ledger row for visibility,
                // but nothing is deducted from either pocket.
                return AiPageLedger::create([
                    'company_id'     => $locked->id,
                    'user_id'        => $meta['user_id'] ?? auth()->id(),
                    'kind'           => AiPageLedger::KIND_CONSUME,
                    'from_allowance' => 0,
                    'from_balance'   => 0,
                    'source'         => $source,
                    'ref_type'       => $meta['ref_type'] ?? null,
                    'ref_id'         => $meta['ref_id'] ?? null,
                    'note'           => $meta['note'] ?? 'unlimited account',
                ]);
            }

            $fromAllowance = min($pages, $allowanceRemaining);
            $fromBalance = $pages - $fromAllowance;

            if ($fromBalance > self::purchasedBalance($locked)) {
                throw new \RuntimeException('Insufficient AI Reader pages.');
            }

            if ($fromBalance > 0) {
                $locked->decrement('ai_page_balance', $fromBalance);
            }

            return AiPageLedger::create([
                'company_id'     => $locked->id,
                'user_id'        => $meta['user_id'] ?? auth()->id(),
                'kind'           => AiPageLedger::KIND_CONSUME,
                'from_allowance' => $fromAllowance,
                'from_balance'   => $fromBalance,
                'source'         => $source,
                'ref_type'       => $meta['ref_type'] ?? null,
                'ref_id'         => $meta['ref_id'] ?? null,
                'note'           => $meta['note'] ?? null,
            ]);
        });
    }

    /**
     * Hand pages back to the pockets they came from. Purchased pages are
     * returned FIRST — those were paid for, so they must not be the ones lost
     * to a month rollover.
     */
    public static function refund(AiPageLedger $entry, ?int $pages = null, ?string $note = null): ?AiPageLedger
    {
        if ($entry->kind !== AiPageLedger::KIND_CONSUME || !self::ledgerReady()) {
            return null;
        }

        $spent = (int) $entry->from_allowance + (int) $entry->from_balance;
        $pages = $pages === null ? $spent : min($pages, $spent);

        if ($pages <= 0) {
            return null;
        }

        return DB::transaction(function () use ($entry, $pages, $note) {
            $alreadyRefunded = (int) AiPageLedger::where('ref_type', 'ai_page_ledger')
                ->where('ref_id', $entry->id)
                ->where('kind', AiPageLedger::KIND_REFUND)
                ->sum(DB::raw('from_allowance + from_balance'));

            $spent = (int) $entry->from_allowance + (int) $entry->from_balance;
            $refundable = max(0, $spent - $alreadyRefunded);
            $pages = min($pages, $refundable);

            if ($pages <= 0) {
                return null;
            }

            $fromBalance = min($pages, (int) $entry->from_balance);
            $fromAllowance = $pages - $fromBalance;

            if ($fromBalance > 0) {
                Company::whereKey($entry->company_id)->increment('ai_page_balance', $fromBalance);
            }

            return AiPageLedger::create([
                'company_id'     => $entry->company_id,
                'user_id'        => $entry->user_id,
                'kind'           => AiPageLedger::KIND_REFUND,
                'from_allowance' => $fromAllowance,
                'from_balance'   => $fromBalance,
                'source'         => $entry->source,
                'ref_type'       => 'ai_page_ledger',
                'ref_id'         => $entry->id,
                'note'           => $note ?? 'refund',
            ]);
        });
    }

    /** Add purchased (or admin-granted) pages to the never-expiring balance. */
    public static function credit(Company $company, int $pages, string $kind = AiPageLedger::KIND_TOPUP, array $meta = []): ?AiPageLedger
    {
        if ($pages <= 0 || !self::ledgerReady()) {
            return null;
        }

        return DB::transaction(function () use ($company, $pages, $kind, $meta) {
            Company::whereKey($company->id)->increment('ai_page_balance', $pages);

            return AiPageLedger::create([
                'company_id'     => $company->id,
                'user_id'        => $meta['user_id'] ?? auth()->id(),
                'kind'           => $kind,
                'from_allowance' => 0,
                'from_balance'   => $pages,
                'source'         => $meta['source'] ?? 'topup',
                'ref_type'       => $meta['ref_type'] ?? null,
                'ref_id'         => $meta['ref_id'] ?? null,
                'note'           => $meta['note'] ?? null,
            ]);
        });
    }

    /** Price of a pack, or null when the size is not on sale. */
    public static function packPrice(int $pages): ?int
    {
        return self::PACKS[$pages] ?? null;
    }
}

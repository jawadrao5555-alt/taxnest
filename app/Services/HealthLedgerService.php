<?php

namespace App\Services;

use App\Models\HealthAccount;
use App\Exceptions\FiscalPeriodClosed;
use App\Models\HealthFiscalPeriod;
use App\Models\HealthJournal;
use App\Models\HealthJournalLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The posting engine (Task 1552).
 *
 * Everything that reaches the books reaches them through post(). There is no
 * second way in, no "just insert the lines" shortcut, because the four rules
 * this class enforces are only worth anything if nothing can go round them:
 *
 *  1. THE ENTRY BALANCES OR IT DOES NOT EXIST. Debits and credits are summed
 *     and compared before the insert; a mismatch throws and the transaction
 *     rolls back. There is no half-saved journal to find later.
 *  2. ONE SOURCE EVENT, ONE JOURNAL. A dedupe_key makes re-posting a no-op, and
 *     the uniqueness is a database index, not a caller's SELECT-then-INSERT
 *     that two concurrent requests would both pass.
 *  3. NOTHING LANDS IN A CLOSED PERIOD. The date decides the period; a closed
 *     one refuses. Automatic postings from a back-dated source do not silently
 *     vanish — they are refused with a reason the accounts screen shows.
 *  4. A POSTED JOURNAL IS NEVER EDITED. reverse() writes a mirror entry that
 *     points back at the original. Both survive.
 */
class HealthLedgerService
{
    /** Anything under a paisa is float noise, not an imbalance. */
    /** Dedupe prefix for the mirror entry of a reversal — one per journal, ever. */
    public const REVERSAL_KEY_PREFIX = 'rev:';

    public const EPSILON = 0.005;

    /**
     * Write one balanced journal.
     *
     * $payload:
     *   date              Y-m-d (defaults to today)
     *   lines             [['account' => systemKey|id, 'debit'|'credit' => amount, …dimensions]]
     *   type              auto | manual | opening | adjustment | closing
     *   memo, branch_id
     *   source_type, source_id, source_reference
     *   dedupe_key        omit for a manual entry
     *   allow_closed      only the period-close entry itself sets this
     *
     * @return array{ok:bool,reason?:string,journal?:HealthJournal,duplicate?:bool}
     */
    public static function post(int $companyId, array $payload, $actor = null): array
    {
        if (!Schema::hasTable('health_journals') || !Schema::hasTable('health_journal_lines')) {
            return ['ok' => false, 'reason' => 'not_installed'];
        }

        $date = self::normaliseDate($payload['date'] ?? null);
        $dedupe = $payload['dedupe_key'] ?? null;

        // Cheap pre-check. The unique index below is the real guarantee; this
        // just avoids burning a journal number on a re-run of the sweep.
        if ($dedupe) {
            $existing = HealthJournal::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('dedupe_key', $dedupe)
                ->first();
            if ($existing) {
                return ['ok' => true, 'journal' => $existing, 'duplicate' => true];
            }
        }

        $prepared = self::prepareLines($companyId, $payload['lines'] ?? []);
        if (!$prepared['ok']) {
            return $prepared;
        }

        $lines = $prepared['lines'];
        $debit = round(array_sum(array_column($lines, 'debit')), 2);
        $credit = round(array_sum(array_column($lines, 'credit')), 2);

        if (abs($debit - $credit) > self::EPSILON) {
            return ['ok' => false, 'reason' => 'unbalanced', 'debit' => $debit, 'credit' => $credit];
        }

        // A zero-value event (a fully-concessioned bill, a nil receipt) is not
        // an error and is not news. Posting a 0/0 journal would litter the
        // ledger with entries that mean nothing.
        if ($debit <= self::EPSILON) {
            return ['ok' => true, 'journal' => null, 'skipped' => 'zero'];
        }

        // Cheap pre-check, same as the dedupe one above: it saves the work of
        // building an entry nobody will accept. The check that actually decides
        // lives inside the write transaction, under the period's own lock.
        $period = HealthFiscalPeriodService::ensureFor($companyId, $date);
        if ($period && $period->isClosed() && empty($payload['allow_closed'])) {
            return ['ok' => false, 'reason' => 'period_closed', 'period' => $period->name];
        }

        $type = $payload['type'] ?? HealthJournal::TYPE_AUTO;
        if (!in_array($type, HealthJournal::TYPES, true)) {
            $type = HealthJournal::TYPE_AUTO;
        }

        try {
            $journal = DB::transaction(function () use ($companyId, $payload, $date, $lines, $debit, $credit, $period, $type, $dedupe, $actor) {
                /*
                 * The door is checked again here, from the database, under the
                 * lock the period close takes before it freezes its snapshot.
                 * Whoever gets the lock first wins outright: a close that is
                 * already through leaves this entry refused, and an entry that
                 * is already through is inside the snapshot the close then
                 * photographs. Trusting the pre-check instead leaves the one
                 * result nobody can reconcile — a journal sitting inside a
                 * closed month that the closed month's own statement never saw.
                 */
                if ($period && empty($payload['allow_closed'])) {
                    $live = HealthFiscalPeriod::withoutGlobalScopes()
                        ->where('id', $period->id)
                        ->lockForUpdate()
                        ->first();

                    if ($live && $live->isClosed()) {
                        throw new FiscalPeriodClosed((string) $live->name);
                    }
                }

                $journal = HealthJournal::withoutGlobalScopes()->create([
                    'company_id' => $companyId,
                    'branch_id' => $payload['branch_id'] ?? null,
                    'health_fiscal_period_id' => $period->id ?? null,
                    'journal_no' => HealthNumberService::journalNumber($companyId),
                    'journal_date' => $date,
                    'type' => $type,
                    'source_type' => $payload['source_type'] ?? null,
                    'source_id' => $payload['source_id'] ?? null,
                    'source_reference' => isset($payload['source_reference'])
                        ? mb_substr((string) $payload['source_reference'], 0, 120)
                        : null,
                    'memo' => isset($payload['memo']) ? mb_substr((string) $payload['memo'], 0, 500) : null,
                    'total_debit' => $debit,
                    'total_credit' => $credit,
                    'status' => HealthJournal::STATUS_POSTED,
                    'posted_at' => now(),
                    'posted_by' => $actor->id ?? null,
                    'reverses_journal_id' => $payload['reverses_journal_id'] ?? null,
                    'adjusts_period_id' => $payload['adjusts_period_id'] ?? null,
                    'dedupe_key' => $dedupe,
                ]);

                $no = 1;
                foreach ($lines as $line) {
                    HealthJournalLine::withoutGlobalScopes()->create([
                        'company_id' => $companyId,
                        'health_journal_id' => $journal->id,
                        'health_account_id' => $line['account_id'],
                        'line_no' => $no++,
                        'debit' => $line['debit'],
                        'credit' => $line['credit'],
                        'branch_id' => $line['branch_id'] ?? ($payload['branch_id'] ?? null),
                        'health_department_id' => $line['health_department_id'] ?? null,
                        'health_doctor_id' => $line['health_doctor_id'] ?? null,
                        'health_patient_id' => $line['health_patient_id'] ?? null,
                        'supplier_id' => $line['supplier_id'] ?? null,
                        'source_type' => $line['source_type'] ?? ($payload['source_type'] ?? null),
                        'source_id' => $line['source_id'] ?? ($payload['source_id'] ?? null),
                        'entry_date' => $date,
                        'memo' => isset($line['memo']) ? mb_substr((string) $line['memo'], 0, 300) : null,
                    ]);
                }

                return $journal;
            });
        } catch (FiscalPeriodClosed $e) {
            return ['ok' => false, 'reason' => 'period_closed', 'period' => $e->getMessage()];
        } catch (\Illuminate\Database\QueryException $e) {
            // Almost certainly the dedupe index: two workers swept the same
            // source at once. That is the index doing exactly its job.
            if ($dedupe) {
                $existing = HealthJournal::withoutGlobalScopes()
                    ->where('company_id', $companyId)
                    ->where('dedupe_key', $dedupe)
                    ->first();
                if ($existing) {
                    return ['ok' => true, 'journal' => $existing, 'duplicate' => true];
                }
            }

            return ['ok' => false, 'reason' => 'db_error', 'message' => $e->getMessage()];
        }

        return ['ok' => true, 'journal' => $journal];
    }

    /**
     * Turn caller lines into validated rows.
     *
     * An account may be given as a system key or an id. An unresolvable one is
     * a hard failure: posting the rest of a journal without it would leave the
     * entry unbalanced, and "post what we can" is how money disappears.
     */
    protected static function prepareLines(int $companyId, array $raw): array
    {
        $out = [];

        foreach ($raw as $line) {
            $ref = $line['account'] ?? $line['account_id'] ?? null;
            if ($ref === null) {
                return ['ok' => false, 'reason' => 'missing_account'];
            }

            if (is_numeric($ref)) {
                $accountId = (int) $ref;
                $exists = HealthAccount::withoutGlobalScopes()
                    ->where('company_id', $companyId)
                    ->where('id', $accountId)
                    ->exists();
                if (!$exists) {
                    return ['ok' => false, 'reason' => 'unknown_account', 'account' => $ref];
                }
            } else {
                $accountId = HealthChartOfAccountsService::id($companyId, (string) $ref);
                if (!$accountId) {
                    return ['ok' => false, 'reason' => 'unknown_account', 'account' => $ref];
                }
            }

            $debit = round((float) ($line['debit'] ?? 0), 2);
            $credit = round((float) ($line['credit'] ?? 0), 2);

            // A negative amount on one side is the same as a positive on the
            // other; normalising here means a caller can hand us a signed value
            // without inventing its own sign convention.
            if ($debit < 0) {
                $credit += -$debit;
                $debit = 0;
            }
            if ($credit < 0) {
                $debit += -$credit;
                $credit = 0;
            }
            if ($debit > 0 && $credit > 0) {
                $net = round($debit - $credit, 2);
                $debit = $net > 0 ? $net : 0;
                $credit = $net < 0 ? -$net : 0;
            }

            if ($debit <= self::EPSILON && $credit <= self::EPSILON) {
                continue; // a nothing line; not an error, just noise
            }

            $line['account_id'] = $accountId;
            $line['debit'] = $debit;
            $line['credit'] = $credit;
            $out[] = $line;
        }

        if (count($out) < 2) {
            // One line cannot balance. This is a caller bug, and letting it
            // through would put an unbalanced pair in the books.
            return ['ok' => false, 'reason' => 'too_few_lines'];
        }

        return ['ok' => true, 'lines' => $out];
    }

    /**
     * Undo a journal by writing its mirror.
     *
     * The original is marked reversed and both rows point at each other. The
     * reversal carries TODAY's date when the original's period is shut, because
     * back-dating a correction into a closed month is exactly what closing the
     * month was meant to prevent.
     *
     * @return array{ok:bool,reason?:string,journal?:HealthJournal}
     */
    public static function reverse(HealthJournal $journal, $actor = null, string $reason = '', ?string $date = null): array
    {
        if ($journal->status === HealthJournal::STATUS_REVERSED) {
            return ['ok' => false, 'reason' => 'already_reversed'];
        }

        $companyId = (int) $journal->company_id;
        $on = self::normaliseDate($date ?: $journal->journal_date->toDateString());

        $originalPeriod = HealthFiscalPeriodService::ensureFor($companyId, $on);
        $adjustsPeriod = null;
        if ($originalPeriod && $originalPeriod->isClosed()) {
            $open = HealthFiscalPeriodService::currentOpen($companyId);
            if (!$open) {
                return ['ok' => false, 'reason' => 'no_open_period'];
            }
            $adjustsPeriod = (int) $originalPeriod->id;
            $on = max($open->starts_on->toDateString(), now()->toDateString());
            if ($on > $open->ends_on->toDateString()) {
                $on = $open->ends_on->toDateString();
            }
        }

        /*
         * ONE reversal, whoever presses first.
         *
         * Two clicks half a second apart used to both read the entry as posted,
         * both write an opposite journal, and both stamp the original reversed.
         * The books then held the entry plus TWO mirrors of it — a net reversal
         * of a transaction that happened once, which is money invented out of a
         * double-click. So the row is locked and re-read inside the transaction
         * that writes the mirror, the mirror carries a dedupe key derived from
         * the entry it undoes, and the unique index on that key (and on
         * reverses_journal_id) is the backstop for the case where two workers
         * get past the lock on different connections.
         */
        return DB::transaction(function () use ($journal, $companyId, $on, $adjustsPeriod, $actor, $reason) {
            $locked = HealthJournal::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('id', $journal->id)
                ->lockForUpdate()
                ->first();

            if (!$locked) {
                return ['ok' => false, 'reason' => 'already_reversed'];
            }
            if ($locked->status === HealthJournal::STATUS_REVERSED) {
                return ['ok' => false, 'reason' => 'already_reversed'];
            }

            $locked->load('lines');

            $lines = [];
            foreach ($locked->lines as $line) {
                $lines[] = [
                    'account_id' => $line->health_account_id,
                    'debit' => (float) $line->credit,
                    'credit' => (float) $line->debit,
                    'branch_id' => $line->branch_id,
                    'health_department_id' => $line->health_department_id,
                    'health_doctor_id' => $line->health_doctor_id,
                    'health_patient_id' => $line->health_patient_id,
                    'supplier_id' => $line->supplier_id,
                    'source_type' => $line->source_type,
                    'source_id' => $line->source_id,
                    'memo' => $line->memo,
                ];
            }

            if (!$lines) {
                return ['ok' => false, 'reason' => 'no_lines'];
            }

            $result = self::post($companyId, [
                'date' => $on,
                'type' => $adjustsPeriod ? HealthJournal::TYPE_ADJUSTMENT : HealthJournal::TYPE_MANUAL,
                'branch_id' => $locked->branch_id,
                'lines' => $lines,
                'memo' => trim(__('health.jrn_reversal_of', ['no' => $locked->journal_no]) . ' ' . $reason),
                'source_type' => $locked->source_type,
                'source_id' => $locked->source_id,
                'source_reference' => $locked->source_reference,
                'reverses_journal_id' => $locked->id,
                'adjusts_period_id' => $adjustsPeriod,
                'dedupe_key' => self::REVERSAL_KEY_PREFIX . $locked->id,
            ], $actor);

            if (!($result['ok'] ?? false) || empty($result['journal'])) {
                return $result;
            }

            // The mirror already existed: somebody else reversed this entry
            // between our read and our write. Theirs stands, ours never happened.
            if (!empty($result['duplicate'])) {
                return ['ok' => false, 'reason' => 'already_reversed'];
            }

            $locked->forceFill([
                'status' => HealthJournal::STATUS_REVERSED,
                'reversed_by_journal_id' => $result['journal']->id,
                'reversed_at' => now(),
                'reversed_by' => $actor->id ?? null,
                'reversal_reason' => $reason !== '' ? mb_substr($reason, 0, 300) : null,
            ])->save();

            // Keep the caller's copy in step — it is often re-read straight after.
            $journal->setRawAttributes($locked->getAttributes(), true);

            return $result;
        });
    }

    /**
     * Reverse whatever was posted for one source event, by dedupe key.
     *
     * Used when a bill is cancelled or a payment reversed: the source record
     * knows its own key, so it can undo its own footprint without knowing a
     * journal id.
     */
    public static function reverseByDedupe(int $companyId, string $dedupeKey, $actor = null, string $reason = ''): array
    {
        $journal = HealthJournal::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('dedupe_key', $dedupeKey)
            ->where('status', HealthJournal::STATUS_POSTED)
            ->with('lines')
            ->first();

        if (!$journal) {
            return ['ok' => true, 'skipped' => 'nothing_posted'];
        }

        return self::reverse($journal, $actor, $reason);
    }

    /** TRUE when this source event already has a live journal. */
    public static function alreadyPosted(int $companyId, string $dedupeKey): bool
    {
        if (!Schema::hasTable('health_journals')) {
            return false;
        }

        return HealthJournal::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('dedupe_key', $dedupeKey)
            ->exists();
    }

    /**
     * Debit/credit totals per account over a window.
     *
     * Everything the reports need is derived from this one query. Nothing keeps
     * a running "current balance" column anywhere — a stored balance and the
     * lines behind it drift the first time one write path forgets, and then no
     * report can be trusted.
     *
     * @return array<int,array{debit:float,credit:float}> keyed by account id
     */
    public static function balances(int $companyId, ?string $from, ?string $to, array $filters = []): array
    {
        if (!Schema::hasTable('health_journal_lines')) {
            return [];
        }

        $rows = DB::table('health_journal_lines as l')
            ->join('health_journals as j', 'j.id', '=', 'l.health_journal_id')
            ->where('l.company_id', $companyId)
            ->whereIn('j.status', HealthJournal::COUNTED_STATUSES)
            ->when($from, fn ($q) => $q->whereDate('j.journal_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('j.journal_date', '<=', $to))
            ->when($filters['doctor_id'] ?? null, fn ($q, $v) => $q->where('l.health_doctor_id', $v))
            ->when($filters['supplier_id'] ?? null, fn ($q, $v) => $q->where('l.supplier_id', $v))
            ->tap(fn ($q) => self::applyBranchFilter($q, $filters, 'l.branch_id'))
            ->tap(fn ($q) => self::applyDepartmentFilter($q, $filters, 'l.health_department_id'))
            ->groupBy('l.health_account_id')
            ->select('l.health_account_id', DB::raw('SUM(l.debit) as d'), DB::raw('SUM(l.credit) as c'))
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->health_account_id] = [
                'debit' => round((float) $row->d, 2),
                'credit' => round((float) $row->c, 2),
            ];
        }

        return $out;
    }

    /**
     * The branch boundary, applied to any ledger-shaped query.
     *
     * TWO different things arrive as "branch" and they are not the same:
     *
     *   branch_id   a filter the reader CHOSE — show me only Gulberg
     *   branch_ids  the boundary the reader CANNOT cross — the branches their
     *               account is posted to, or absent for organisation-wide access
     *
     * Only the second is security. It must be applied by the caller on every
     * read, because a finance user attached to one branch typing another
     * branch's id into the query string is exactly the attempt this stops.
     *
     * A line with NO branch is organisation-wide (opening balances, a company
     * tax accrual) and stays visible to everybody, matching how the rest of the
     * healthcare panel scopes branches. An EMPTY id list therefore means "the
     * unbranched rows only", never "everything".
     */
    public static function applyBranchFilter($query, array $filters, string $column)
    {
        if (!empty($filters['branch_id'])) {
            $query->where($column, $filters['branch_id']);
        }

        $ids = $filters['branch_ids'] ?? null;
        if (is_array($ids)) {
            $query->where(function ($q) use ($column, $ids) {
                if ($ids) {
                    $q->whereIn($column, $ids);
                }
                $q->orWhereNull($column);
            });
        }

        return $query;
    }

    /**
     * The department boundary, applied to any query that carries a department.
     *
     * The same two-things-called-one-name trap as branches: `department_id` is
     * a filter the reader CHOSE, `department_ids` is the fence their account
     * cannot climb. Only the second is security, and it must ride along even on
     * the screens with no department picker — those are precisely where a
     * confined reader would otherwise see every ward's takings by simply not
     * asking. A line with NO department is organisation-wide and stays visible.
     */
    public static function applyDepartmentFilter($query, array $filters, string $column)
    {
        if (!empty($filters['department_id'])) {
            $query->where($column, $filters['department_id']);
        }

        $ids = $filters['department_ids'] ?? null;
        if (is_array($ids)) {
            $query->where(function ($q) use ($column, $ids) {
                if ($ids) {
                    $q->whereIn($column, $ids);
                }
                $q->orWhereNull($column);
            });
        }

        return $query;
    }

    /**
     * The signed balance of one account as at a date (inclusive), opening
     * balance included.
     */
    public static function accountBalance(int $companyId, int $accountId, ?string $asAt = null, array $filters = []): float
    {
        $account = HealthAccount::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('id', $accountId)
            ->first();

        if (!$account) {
            return 0.0;
        }

        $totals = self::balances($companyId, null, $asAt, $filters)[$accountId] ?? ['debit' => 0, 'credit' => 0];

        return $account->signedBalance($totals['debit'], $totals['credit']);
    }

    /** Y-m-d, defaulting to today, never a future-dated posting. */
    public static function normaliseDate($date): string
    {
        try {
            $parsed = $date ? Carbon::parse($date) : now();
        } catch (\Throwable $e) {
            $parsed = now();
        }

        // A journal dated next week would sit invisible in every report that
        // ends today and then appear from nowhere. The books record what has
        // happened.
        if ($parsed->gt(now()->endOfDay())) {
            $parsed = now();
        }

        return $parsed->toDateString();
    }

    /**
     * Post an account's opening balance as a real journal.
     *
     * The stored opening_balance and the ledger must never be able to disagree,
     * so the column is the INPUT and the journal is the truth. The other side
     * goes to Opening Balance Equity, which is what makes a part-entered
     * opening trial balance still balance.
     */
    public static function postOpeningBalance(int $companyId, HealthAccount $account, $actor = null): array
    {
        $amount = round((float) $account->opening_balance, 2);
        $date = $account->opening_balance_date
            ? Carbon::parse($account->opening_balance_date)->toDateString()
            : now()->startOfMonth()->toDateString();

        /*
         * Re-entering an opening balance REPLACES the previous one rather than
         * adding to it: an owner who typed 50,000 and meant 5,000 must be able
         * to fix it without the books holding 55,000.
         *
         * Found by source, not by dedupe key. Each restatement has to carry a
         * fresh key (the old one is spent), so looking the previous entry up by
         * the first key finds only the first one and every correction after
         * that would pile on top of the last.
         *
         * Contra entries are excluded: a reversal copies the source of what it
         * undoes, and reversing a reversal would put the old figure back.
         */
        $originals = HealthJournal::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('source_type', HealthJournal::SRC_OPENING)
            ->where('source_id', $account->id)
            ->whereNull('reverses_journal_id')
            ->orderBy('id');

        $live = (clone $originals)
            ->where('status', HealthJournal::STATUS_POSTED)
            ->with('lines')
            ->get();

        foreach ($live as $prior) {
            $undone = self::reverse($prior, $actor, __('health.acc_opening_restated'));

            // A refused reversal stops the restatement dead. Posting the new
            // figure anyway would leave both live and double the balance.
            if (!($undone['ok'] ?? false)) {
                return ['ok' => false, 'reason' => $undone['reason'] ?? 'reverse_failed'];
            }
        }

        // Version by how many opening entries this account has ever had, so two
        // corrections in the same second cannot collide on the same key (a
        // timestamp can, and the second one would silently post nothing).
        $version = (clone $originals)->count();
        $dedupe = $version === 0
            ? 'opening:' . $account->id
            : 'opening:' . $account->id . ':v' . $version;

        if (abs($amount) <= self::EPSILON) {
            return ['ok' => true, 'skipped' => 'zero'];
        }

        $equityId = HealthChartOfAccountsService::id($companyId, HealthChartOfAccountsService::OPENING_EQUITY);
        if (!$equityId) {
            return ['ok' => false, 'reason' => 'no_equity_account'];
        }

        $onDebitSide = $account->isDebitNatured() ? $amount > 0 : $amount < 0;
        $abs = abs($amount);

        return self::post($companyId, [
            'date' => $date,
            'type' => HealthJournal::TYPE_OPENING,
            'lines' => [
                [
                    'account_id' => $account->id,
                    'debit' => $onDebitSide ? $abs : 0,
                    'credit' => $onDebitSide ? 0 : $abs,
                ],
                [
                    'account_id' => $equityId,
                    'debit' => $onDebitSide ? 0 : $abs,
                    'credit' => $onDebitSide ? $abs : 0,
                ],
            ],
            'memo' => __('health.acc_opening_for', ['name' => $account->name]),
            'source_type' => HealthJournal::SRC_OPENING,
            'source_id' => $account->id,
            'source_reference' => $account->code,
            'dedupe_key' => $dedupe,
        ], $actor);
    }
}

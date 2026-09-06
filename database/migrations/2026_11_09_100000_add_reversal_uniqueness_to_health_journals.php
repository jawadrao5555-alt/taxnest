<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * One journal can be reversed ONCE — enforced by the database (Task 1552).
 *
 * The application locks the entry it is reversing, but a lock only binds the
 * connections that take it. This index is what holds when two workers, two
 * tabs, or a retried request slip past on separate connections: the second
 * mirror entry cannot be written at all. Without it, an entry can end up with
 * two opposite mirrors, and a transaction that happened once is undone twice —
 * money invented out of a double-click.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('health_journals') || !Schema::hasColumn('health_journals', 'reverses_journal_id')) {
            return;
        }

        if ($this->indexExists('health_jrn_reverses_unique')) {
            return;
        }

        /*
         * A duplicate already in the books would make the index fail and take
         * the whole deploy down with it. That is the wrong trade: the books
         * need looking at by a human, and the rest of the release must not wait
         * for it. So the situation is reported and the application-level lock
         * carries on protecting new reversals.
         */
        $duplicates = DB::table('health_journals')
            ->select('company_id', 'reverses_journal_id')
            ->whereNotNull('reverses_journal_id')
            ->groupBy('company_id', 'reverses_journal_id')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        if ($duplicates > 0) {
            Log::warning('health_journals: ' . $duplicates . ' journal(s) already carry more than one reversal; unique index not applied.');

            return;
        }

        Schema::table('health_journals', function (Blueprint $table) {
            $table->unique(['company_id', 'reverses_journal_id'], 'health_jrn_reverses_unique');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('health_journals') || !$this->indexExists('health_jrn_reverses_unique')) {
            return;
        }

        Schema::table('health_journals', function (Blueprint $table) {
            $table->dropUnique('health_jrn_reverses_unique');
        });
    }

    /** Index lookup that works on both MySQL and the SQLite test database. */
    private function indexExists(string $name): bool
    {
        try {
            return collect(Schema::getIndexes('health_journals'))
                ->contains(fn ($index) => ($index['name'] ?? '') === $name);
        } catch (\Throwable $e) {
            return false;
        }
    }
};

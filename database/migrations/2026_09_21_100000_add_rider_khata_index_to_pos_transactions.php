<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Rider khata index — dashboard "Rider settlement pending" alert (25 Aug 2026).
 *
 * Alert har dashboard load par rider ka khula cash jorhta hai, aur woh query
 * poori tareekh dekhti hai (archived bills bhi — day-close unhen archive karta
 * hai jabke cash rider ke paas hi rehta hai). pos_transactions par sab se
 * behtar mojood index sirf (company_id, payment_method) tha, is liye bare
 * purane shop par dashboard — sab se garam safha — ek mehngay scan mein ja
 * sakta tha.
 *
 * Index ki tarteeb query ke mutabiq hai:
 *   company_id        = ?      (barabari)
 *   payment_method    = 'cash' (barabari)
 *   rider_settlement_id IS NULL
 *   rider_id                   (GROUP BY / NOT NULL)
 *
 * Idempotent aur guarded: PROD schema drift (purane shops par rider columns
 * na hon) par khamoshi se nikal jata hai, aur index pehle se ho to dobara
 * nahi banata — migrate --force kabhi is par nahi rukta.
 */
return new class extends Migration
{
    private const IDX = 'pt_company_pay_settle_rider_idx';

    private function indexExists(string $table, string $name): bool
    {
        try {
            foreach (Schema::getIndexes($table) as $idx) {
                if (($idx['name'] ?? null) === $name) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            // Driver getIndexes support nahi karta — banane ki koshish karne do,
            // neeche try/catch usay sambhal lega.
            return false;
        }

        return false;
    }

    public function up(): void
    {
        if (!Schema::hasTable('pos_transactions')) {
            return;
        }
        foreach (['company_id', 'payment_method', 'rider_settlement_id', 'rider_id'] as $col) {
            if (!Schema::hasColumn('pos_transactions', $col)) {
                return;
            }
        }
        if ($this->indexExists('pos_transactions', self::IDX)) {
            return;
        }

        try {
            Schema::table('pos_transactions', function (Blueprint $table) {
                $table->index(
                    ['company_id', 'payment_method', 'rider_settlement_id', 'rider_id'],
                    self::IDX
                );
            });
        } catch (\Throwable $e) {
            // Kisi aur naam se wohi index pehle se ho sakta hai. Deploy rokna
            // is se kahin bura hai — sirf darj karo.
            Log::warning('[rider-khata-index] skipped: ' . $e->getMessage());
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('pos_transactions') || !$this->indexExists('pos_transactions', self::IDX)) {
            return;
        }
        try {
            Schema::table('pos_transactions', function (Blueprint $table) {
                $table->dropIndex(self::IDX);
            });
        } catch (\Throwable $e) {
            Log::warning('[rider-khata-index] drop skipped: ' . $e->getMessage());
        }
    }
};

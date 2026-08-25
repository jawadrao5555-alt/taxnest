<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Durable high-water mark for the PRA POS FINAL serial (short "P-NNN" series,
 * owner request 25 Aug 2026). Backfilled from BOTH the new short format and the
 * legacy "POS-YYYY-NNNNN" serials so no shop's next bill can reuse a number an
 * older (or since-deleted) bill already carried.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('pos_final_series_counters')) {
            Schema::create('pos_final_series_counters', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->unique();
                $table->unsignedBigInteger('last_number')->default(0);
                $table->timestamps();

                $table->foreign('company_id')
                    ->references('id')
                    ->on('companies')
                    ->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('pos_transactions')) {
            return;
        }

        // Parse in PHP so MySQL and sqlite follow the identical serial grammar.
        $maxByCompany = [];
        DB::table('pos_transactions')
            ->where(function ($q) {
                // "P036" (current), "P-036" (dashed, issued before the dash was
                // dropped) and the legacy "POS-YYYY-NNNNN" all reserve a number.
                $q->where('invoice_number', 'like', 'P%')
                    ->orWhere('invoice_number', 'like', 'POS-%');
            })
            ->select(['id', 'company_id', 'invoice_number'])
            ->orderBy('id')
            ->chunkById(1000, function ($rows) use (&$maxByCompany) {
                foreach ($rows as $row) {
                    $serial = (string) $row->invoice_number;
                    if (preg_match('/^P-?(\d+)$/', $serial, $match) === 1
                        || preg_match('/^POS-\d{4}-(\d+)$/', $serial, $match) === 1) {
                        $companyId = (int) $row->company_id;
                        $maxByCompany[$companyId] = max(
                            $maxByCompany[$companyId] ?? 0,
                            (int) $match[1]
                        );
                    }
                }
            });

        foreach ($maxByCompany as $companyId => $lastNumber) {
            $existing = DB::table('pos_final_series_counters')
                ->where('company_id', $companyId)
                ->value('last_number');

            if ($existing === null) {
                DB::table('pos_final_series_counters')->insert([
                    'company_id' => $companyId,
                    'last_number' => $lastNumber,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } elseif ((int) $existing < $lastNumber) {
                DB::table('pos_final_series_counters')
                    ->where('company_id', $companyId)
                    ->update(['last_number' => $lastNumber, 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_final_series_counters');
    }
};

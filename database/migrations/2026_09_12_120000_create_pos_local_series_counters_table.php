<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('pos_local_series_counters')) {
            Schema::create('pos_local_series_counters', function (Blueprint $table) {
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

        // Backfill from every exact L-NNN row, including archived bills. Do the
        // parsing in PHP so MySQL and sqlite follow the identical serial grammar.
        $maxByCompany = [];
        DB::table('pos_transactions')
            ->where('invoice_number', 'like', 'L-%')
            ->where('invoice_number', 'not like', 'LOCAL-%')
            ->select(['id', 'company_id', 'invoice_number'])
            ->orderBy('id')
            ->chunkById(1000, function ($rows) use (&$maxByCompany) {
                foreach ($rows as $row) {
                    if (!preg_match('/^L-(\d+)$/', (string) $row->invoice_number, $match)) {
                        continue;
                    }

                    $companyId = (int) $row->company_id;
                    $maxByCompany[$companyId] = max(
                        $maxByCompany[$companyId] ?? 0,
                        (int) $match[1]
                    );
                }
            });

        foreach ($maxByCompany as $companyId => $lastNumber) {
            $existing = DB::table('pos_local_series_counters')
                ->where('company_id', $companyId)
                ->value('last_number');

            if ($existing === null) {
                DB::table('pos_local_series_counters')->insert([
                    'company_id' => $companyId,
                    'last_number' => $lastNumber,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } elseif ((int) $existing < $lastNumber) {
                DB::table('pos_local_series_counters')
                    ->where('company_id', $companyId)
                    ->update(['last_number' => $lastNumber, 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_local_series_counters');
    }
};
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('fbr_pos_invoice_sequences')) {
            Schema::create('fbr_pos_invoice_sequences', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->string('prefix', 24);
                $table->unsignedBigInteger('last_number')->default(0);
                $table->timestamp('updated_at')->nullable();
                $table->unique(['company_id', 'prefix']);
            });
        }
        if (!Schema::hasTable('fbr_pos_transactions')) return;
        // Reserve existing references before a provisional/day-close deletion.
        // No invoice number, fiscal number, amount or status is rewritten.
        DB::table('fbr_pos_transactions')->select('id', 'company_id', 'invoice_number')->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    if (!preg_match('/^((?:FPOS|FLOCAL)-[0-9]{4}-|RET-[0-9]{6}-)([0-9]+)$/D', $row->invoice_number, $match)) continue;
                    $query = DB::table('fbr_pos_invoice_sequences')->where('company_id', $row->company_id)->where('prefix', $match[1]);
                    $last = (int) ($query->value('last_number') ?? 0);
                    DB::table('fbr_pos_invoice_sequences')->updateOrInsert(
                        ['company_id' => $row->company_id, 'prefix' => $match[1]],
                        ['last_number' => max($last, (int) $match[2]), 'updated_at' => now()]
                    );
                }
            });
        $indexes = Schema::getIndexes('fbr_pos_transactions');
        if (!collect($indexes)->contains(fn ($index) => $index['unique'] && $index['columns'] === ['company_id', 'invoice_number'])) {
            if (DB::table('fbr_pos_transactions')->select('company_id', 'invoice_number')
                ->groupBy('company_id', 'invoice_number')->havingRaw('COUNT(*) > 1')->exists()) {
                throw new RuntimeException('FBR POS duplicate retailer invoice references require reconciliation before adding uniqueness. No historical invoices were changed.');
            }
            Schema::table('fbr_pos_transactions', fn (Blueprint $table) => $table->unique(['company_id', 'invoice_number'], 'fbr_pos_company_invoice_unique'));
        }
    }

    public function down(): void
    {
        // Never delete durable issued-number reservations during rollback.
    }
};

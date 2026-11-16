<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach (['school_fee_receipts', 'hospital_invoices', 'hospital_payments'] as $name) {
            if (Schema::hasTable($name) && !Schema::hasColumn($name, 'fiscal_route_snapshot')) {
                Schema::table($name, fn (Blueprint $table) => $table->text('fiscal_route_snapshot')->nullable());
            }
        }
        // Deliberately no UPDATE/backfill. Null historical rows are never eligible.
    }

    public function down(): void
    {
        // Fiscal provenance is retained on rollback, like other immutable evidence.
        // An older application safely ignores this additive nullable column.
    }
};

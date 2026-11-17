<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('hospital_services', 'ims_pct_code')) {
            Schema::table('hospital_services', fn (Blueprint $table) => $table->string('ims_pct_code', 8)->nullable());
        }
        if (!Schema::hasColumn('hospital_services', 'ims_tax_rate')) {
            Schema::table('hospital_services', fn (Blueprint $table) => $table->decimal('ims_tax_rate', 5, 2)->nullable());
        }
    }

    public function down(): void
    {
        // Preserve owner-entered settings across application rollback.
    }
};

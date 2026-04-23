<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'print_paper_size')) {
                $table->string('print_paper_size', 20)->default('thermal')->after('logo_path');
            }
            if (!Schema::hasColumn('companies', 'receipt_footer_note')) {
                $table->string('receipt_footer_note', 255)->nullable()->after('print_paper_size');
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (Schema::hasColumn('companies', 'print_paper_size')) {
                $table->dropColumn('print_paper_size');
            }
            if (Schema::hasColumn('companies', 'receipt_footer_note')) {
                $table->dropColumn('receipt_footer_note');
            }
        });
    }
};

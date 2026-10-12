<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('invoices', 'di_api_request_hash')) {
            Schema::table('invoices', fn (Blueprint $t) => $t->string('di_api_request_hash', 64)->nullable());
        }
    }
    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn('di_api_request_hash'));
    }
};

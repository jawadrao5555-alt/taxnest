<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Counter/Station KOT routing (owner, Jul 2026): one order splits per prep
// counter (Kitchen / Ice Cream / Shakes...) by PRODUCT CATEGORY. Each station
// claims a set of pos_products.category strings; items whose category matches
// no active station fall to the implicit default "Kitchen". A company with
// ZERO stations behaves exactly as before (feature dormant).
// NO foreign keys — pos_* tables are shared-table (DI/POS isolation rule);
// lookups are always company-scoped in code.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('pos_stations')) {
            Schema::create('pos_stations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->string('name', 60);
                $table->json('categories')->nullable();      // array of category strings this counter prepares
                $table->string('printer_name', 255)->nullable(); // Desktop Agent printer for this counter (fallback: kot_printer)
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort')->default(0);
                $table->timestamps();
                $table->index('company_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_stations');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('fbr_hs_codes', function (Blueprint $t) {
            $t->id();
            $t->string('code', 20)->unique();
            $t->string('description', 500)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->index('code');
            $t->index('is_active');
        });

        Schema::create('fbr_sros', function (Blueprint $t) {
            $t->id();
            $t->string('sro_number', 100)->unique();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('fbr_sale_types', function (Blueprint $t) {
            $t->id();
            $t->string('name', 150)->unique();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('fbr_uoms', function (Blueprint $t) {
            $t->id();
            $t->string('name', 50)->unique();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('fbr_rates', function (Blueprint $t) {
            $t->id();
            $t->string('label', 50)->unique();
            $t->decimal('numeric_value', 8, 4)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('fbr_provinces', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100)->unique();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('fbr_buyer_types', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100)->unique();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('fbr_document_types', function (Blueprint $t) {
            $t->id();
            $t->string('name', 50)->unique();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('fbr_reasons', function (Blueprint $t) {
            $t->id();
            $t->string('name', 200)->unique();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('fbr_petroleum_levy_types', function (Blueprint $t) {
            $t->id();
            $t->string('name', 50)->unique();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('fbr_item_sr_numbers', function (Blueprint $t) {
            $t->id();
            $t->string('sr_no', 20)->unique();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'fbr_item_sr_numbers','fbr_petroleum_levy_types','fbr_reasons','fbr_document_types',
            'fbr_buyer_types','fbr_provinces','fbr_rates','fbr_uoms','fbr_sale_types','fbr_sros','fbr_hs_codes'
        ] as $tbl) {
            Schema::dropIfExists($tbl);
        }
    }
};

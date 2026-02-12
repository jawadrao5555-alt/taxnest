<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_profiles', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('company_id');
            $table->string('name', 255);
            $table->string('ntn', 50)->nullable();
            $table->string('cnic', 15)->nullable();
            $table->text('address')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('registration_type', 20)->default('Unregistered');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
        });

        \Illuminate\Support\Facades\DB::statement('CREATE UNIQUE INDEX customer_profiles_company_ntn_not_null ON customer_profiles (company_id, ntn) WHERE ntn IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_profiles');
    }
};

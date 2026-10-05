<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::create('hotel_guest_profiles', function (Blueprint $t) {
        $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('branch_id')->nullable();
        $t->string('source_key', 64); $t->unsignedBigInteger('source_stay_id');
        $t->string('guest_name', 160); $t->string('guest_phone', 40)->nullable(); $t->string('guest_cnic', 20)->nullable();
        $t->boolean('hidden')->default(false); $t->timestamps(); $t->unique(['company_id', 'source_key']);
    }); }
    public function down(): void { Schema::dropIfExists('hotel_guest_profiles'); }
};

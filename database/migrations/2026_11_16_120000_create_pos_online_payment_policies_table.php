<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pos_online_payment_policies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('user_id');
            $table->boolean('requires_approval')->default(false);
            $table->unsignedBigInteger('updated_by');
            $table->timestamps();
            $table->unique(['company_id', 'user_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('pos_online_payment_policies'); }
};

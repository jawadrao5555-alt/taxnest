<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hs_mapping_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hs_code_mapping_id');
            $table->string('action', 20);
            $table->string('field_name')->nullable();
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->json('snapshot')->nullable();
            $table->timestamps();

            $table->index('hs_code_mapping_id');
            $table->index('changed_by');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hs_mapping_audit_logs');
    }
};

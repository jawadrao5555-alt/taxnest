<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('audit_packs')) {
            return;
        }

        Schema::create('audit_packs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->date('date_from');
            $table->date('date_to');
            $table->string('status', 20)->default('pending'); // pending | processing | ready | failed
            $table->unsignedInteger('total_invoices')->default(0);
            $table->unsignedInteger('processed_invoices')->default(0);
            $table->unsignedTinyInteger('progress')->default(0);
            $table->unsignedInteger('integrity_passed')->default(0);
            $table->unsignedInteger('integrity_failed')->default(0);
            $table->unsignedInteger('integrity_missing')->default(0);
            $table->text('integrity_failed_list')->nullable();
            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_packs');
    }
};

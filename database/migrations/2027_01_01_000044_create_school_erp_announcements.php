<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_erp_announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('campus_id')->nullable();
            $table->uuid('publication_key');
            $table->string('title', 120);
            $table->text('body');
            $table->date('expires_on')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at');
            $table->foreignId('withdrawn_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('withdrawn_at')->nullable();
            $table->unique(['company_id', 'publication_key'], 'se_notice_publication_key');
            $table->foreign(['company_id', 'campus_id'], 'se_notice_campus_fk')
                ->references(['company_id', 'id'])->on('branches')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_erp_announcements');
    }
};

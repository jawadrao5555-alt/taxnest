<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hs_rejection_history', function (Blueprint $table) {
            $table->id();
            $table->string('hs_code', 20);
            $table->integer('rejection_count')->default(0);
            $table->text('last_rejection_reason')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->index('hs_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hs_rejection_history');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hs_unmapped_queue', function (Blueprint $table) {
            $table->id();
            $table->string('hs_code')->index();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->integer('usage_count')->default(1);
            $table->timestamp('first_seen_at')->nullable();
            $table->string('flagged_reason')->nullable();
            $table->timestamps();

            $table->unique(['hs_code', 'company_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hs_unmapped_queue');
    }
};

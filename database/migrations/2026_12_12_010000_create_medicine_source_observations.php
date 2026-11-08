<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('medicine_source_observations')) {
            return;
        }
        Schema::create('medicine_source_observations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('catalogue_id')->nullable()->constrained('medicine_catalogue')->nullOnDelete();
            $t->string('dedupe_key', 64)->nullable()->index();
            $t->string('source', 20);
            $t->unsignedBigInteger('sync_id')->nullable();
            $t->date('effective_date')->nullable();
            $t->decimal('mrp', 14, 2)->nullable();
            $t->string('disposition', 30)->index();
            $t->string('reason', 200)->nullable();
            $t->json('payload');
            $t->char('source_hash', 64)->unique();
            $t->dateTime('observed_at');
            $t->dateTime('applied_at')->nullable();
        });
    }

    public function down(): void
    {
        // Source evidence is deliberately retained on application rollback.
    }
};

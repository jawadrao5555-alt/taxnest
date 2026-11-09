<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('two_step_credentials', function (Blueprint $t) {
            $t->id();
            $t->string('actor_type', 10);
            $t->unsignedBigInteger('actor_id');
            $t->text('secret');
            $t->json('recovery_hashes');
            $t->unsignedBigInteger('last_step')->nullable();
            $t->unsignedSmallInteger('failed_attempts')->default(0);
            $t->dateTime('blocked_until')->nullable();
            $t->timestamps();
            $t->unique(['actor_type', 'actor_id']);
        });
        Schema::create('two_step_events', function (Blueprint $t) {
            $t->id();
            $t->string('actor_type', 10);
            $t->unsignedBigInteger('actor_id');
            $t->string('event', 40);
            $t->timestamp('created_at');
            $t->index(['actor_type', 'actor_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('two_step_events');
        Schema::dropIfExists('two_step_credentials');
    }
};

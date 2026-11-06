<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_users', function (Blueprint $table) {
            $table->uuid('recovery_session_version')->nullable();
        });
        Schema::create('admin_recovery_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('slot');
            $table->string('email')->unique();
            $table->string('code_hash', 64)->nullable()->unique();
            $table->string('link_hash', 64)->nullable()->unique();
            $table->timestamp('link_expires_at')->nullable();
            $table->unique(['admin_user_id', 'slot']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_recovery_contacts');
        Schema::table('admin_users', fn (Blueprint $table) => $table->dropColumn('recovery_session_version'));
    }
};

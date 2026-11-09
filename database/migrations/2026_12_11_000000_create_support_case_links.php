<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_case_links', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->index();
            $t->foreignId('case_id')->constrained('support_cases')->cascadeOnDelete();
            $t->foreignId('related_case_id')->constrained('support_cases')->cascadeOnDelete();
            $t->string('kind', 16);
            $t->foreignId('actor_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $t->string('note', 1000)->nullable();
            $t->dateTime('created_at');
            $t->unique(['case_id', 'related_case_id', 'kind'], 'support_case_link_identity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_case_links');
    }
};

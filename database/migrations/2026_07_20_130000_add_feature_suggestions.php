<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Feature Suggestion box (owner request 20 Jul 2026): POS users submit feature
// requests; admin reviews them at /admin/feature-suggestions ("3 customer rule").
// Idempotent (per prod-schema-drift self-heal convention) — safe to re-run.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('feature_suggestions')) {
            Schema::create('feature_suggestions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('user_id');
                // Which panel it came from: pos (NestPOS PRA) for now; di / fbrpos later.
                $table->string('product', 20)->default('pos');
                $table->string('title', 150);
                $table->text('details')->nullable();
                // pending -> planned / completed / rejected (admin-managed).
                $table->string('status', 20)->default('pending');
                $table->text('admin_note')->nullable();
                $table->timestamps();
                $table->index(['product', 'status'], 'feat_sugg_prod_status_idx');
                $table->index('company_id', 'feat_sugg_company_idx');
                $table->index('user_id', 'feat_sugg_user_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_suggestions');
    }
};

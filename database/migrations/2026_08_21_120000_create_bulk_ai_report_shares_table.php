<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 1343: emailed hand-offs of a Bulk AI Image Import review summary.
 *
 * One row per RECIPIENT (like invoice_deliveries) so the owner sees exactly
 * who the summary went to, who sent it, and which addresses bounced. The rows
 * also back the abuse cap — a durable 24h count that survives a cache flush.
 *
 * company_id cascades so a hard-deleted company leaves no orphan rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bulk_ai_report_shares')) {
            return;
        }

        Schema::create('bulk_ai_report_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('bulk_ai_image_batches')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('sent_by', 120)->nullable(); // actor name captured at send time
            $table->string('recipient', 191);
            $table->string('status', 20)->default('sent'); // sent | failed
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'created_at'], 'bulk_ai_share_company_idx');
            $table->index(['batch_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_ai_report_shares');
    }
};

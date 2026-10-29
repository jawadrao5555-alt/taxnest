<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_bill_archive_prefs', function (Blueprint $t) {
            $t->unsignedBigInteger('company_id')->primary();
            $t->boolean('enabled')->default(false);
            $t->unsignedBigInteger('grant_id')->nullable();
            $t->timestamps();
        });
        Schema::create('support_bill_archive_consents', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->index();
            $t->unsignedBigInteger('actor_id')->nullable();
            // Technical key-GC events append to the audit, never edit consent.
            $t->unsignedBigInteger('subject_grant_id')->nullable()->index();
            $t->string('action', 24);
            $t->string('policy_version', 40);
            $t->text('wording');
            $t->string('key_id', 64)->nullable()->unique();
            $t->unsignedSmallInteger('retention_days')->nullable();
            $t->dateTime('valid_until')->nullable();
            $t->timestamps();
        });
        Schema::create('support_bill_archives', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('product', 12);
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->unsignedBigInteger('source_id');
            $t->unsignedBigInteger('grant_id')->index();
            $t->string('reference', 100);
            $t->dateTime('deleted_at');
            $t->dateTime('expires_at')->index();
            $t->string('payload_hash', 64);
            $t->longText('encrypted_payload');
            $t->timestamps();
            $t->unique(['company_id', 'product', 'source_id'], 'support_bill_archive_source');
            $t->index(['company_id', 'expires_at'], 'support_bill_archive_expiry');
        });
        Schema::create('support_bill_archive_access', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->index();
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('actor_guard', 16);
            $t->string('action', 24);
            $t->unsignedBigInteger('archive_id')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_bill_archive_access');
        Schema::dropIfExists('support_bill_archives');
        Schema::dropIfExists('support_bill_archive_consents');
        Schema::dropIfExists('support_bill_archive_prefs');
    }
};

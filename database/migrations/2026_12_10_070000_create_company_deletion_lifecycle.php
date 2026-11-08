<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('company_deletion_requests')) {
            Schema::create('company_deletion_requests', function (Blueprint $table) {
                $table->id();
                // Deliberately no FK: the request is the surviving evidence that
                // a company was purged, so deleting the company must not erase it.
                $table->unsignedBigInteger('company_id')->index();
                $table->string('active_key', 80)->nullable()->unique();
                $table->string('company_name_snapshot');
                $table->string('product_type_snapshot', 80)->nullable();
                $table->string('requester_guard', 20);
                $table->unsignedBigInteger('requested_by_user_id')->nullable();
                $table->unsignedBigInteger('requested_by_admin_id')->nullable();
                $table->string('requester_email_hash', 64)->nullable();
                // Use DATETIME for required lifecycle instants. MariaDB with
                // explicit_defaults_for_timestamp disabled applies legacy
                // implicit defaults to the first TIMESTAMP and can reject a
                // later required TIMESTAMP with an invalid zero default.
                $table->dateTime('requester_verified_at');
                $table->text('reason')->nullable();
                $table->string('status', 30)->index();
                $table->json('scope_manifest')->nullable();
                $table->string('scope_manifest_sha256', 64)->nullable();
                $table->dateTime('requested_at');
                // Keep every lifecycle instant on DATETIME.  On supported
                // MariaDB with legacy implicit TIMESTAMP defaults enabled,
                // the first TIMESTAMP can acquire automatic update semantics
                // and silently move an already-recorded hold boundary.
                $table->dateTime('binned_at')->nullable();
                $table->dateTime('hold_until')->nullable();
                $table->dateTime('restored_at')->nullable();
                $table->dateTime('purged_at')->nullable();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();
            });
        }

        if (!Schema::hasTable('company_deletion_request_events')) {
            Schema::create('company_deletion_request_events', function (Blueprint $table) {
                $table->id();
                // No cascading FK for the same reason as the parent evidence.
                $table->unsignedBigInteger('request_id')->index();
                $table->unsignedBigInteger('company_id')->index();
                $table->string('event', 50)->index();
                $table->string('actor_guard', 20);
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->json('metadata')->nullable();
                $table->dateTime('created_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('company_deletion_request_events');
        Schema::dropIfExists('company_deletion_requests');
    }
};

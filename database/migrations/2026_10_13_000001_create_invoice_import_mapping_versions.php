<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_import_mapping_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            // No cascading FK: deleting a preset must retain its approval audit.
            $table->unsignedBigInteger('mapping_id');
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->string('snapshot_hash', 64);
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'mapping_id', 'version'], 'import_mapping_version_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_import_mapping_versions');
    }
};

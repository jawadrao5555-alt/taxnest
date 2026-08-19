<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DI bulk import — saved column-mapping presets for DMS day-end exports
 * (Voyage, TMX, Salesflo, etc.). One row per named preset: source column ->
 * template field mapping plus fixed default values for fields the export
 * doesn't carry. Idempotent (hasTable guard) for the owner's cPanel PROD.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('invoice_import_mappings')) {
            return;
        }

        Schema::create('invoice_import_mappings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('name', 100);
            $table->text('mapping_json')->nullable();   // {our_field: source column header}
            $table->text('defaults_json')->nullable();  // {our_field: fixed value}
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_import_mappings');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scoped_product_entitlements', function (Blueprint $table) {
            $table->id();
            $table->string('scope_type', 24);
            $table->unsignedBigInteger('scope_id');
            $table->string('product_key', 48);
            $table->string('module_key', 48);
            $table->string('source_reference', 100);
            $table->unsignedBigInteger('starts_at_epoch');
            $table->unsignedBigInteger('ends_at_epoch');
            $table->unsignedBigInteger('revoked_at_epoch')->nullable();
            $table->timestamps();

            $table->unique(
                ['scope_type', 'scope_id', 'product_key', 'module_key', 'source_reference'],
                'scoped_entitlements_source'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scoped_product_entitlements');
    }
};

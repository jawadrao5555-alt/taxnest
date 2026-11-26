<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_clients', function (Blueprint $table) {
            $table->id();
            $table->string('display_name', 120);
            $table->string('product', 20);
            $table->string('logo_mime', 30)->nullable();
            $table->longText('logo_data')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->index(['published_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_clients');
    }
};

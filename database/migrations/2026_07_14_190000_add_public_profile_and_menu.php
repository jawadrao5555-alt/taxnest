<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F8 — Public QR business profile + menu.
 * companies.public_profile_slug: random unguessable slug for the public page
 * companies.public_profile_settings: JSON (enabled + per-detail show/hide toggles)
 * pos_menu_items: which POS products appear on the public menu (live prices
 * come from pos_products at render time — NO price copy here).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('companies')) {
            if (!Schema::hasColumn('companies', 'public_profile_slug')) {
                Schema::table('companies', function (Blueprint $table) {
                    $table->string('public_profile_slug', 32)->nullable()->unique();
                });
            }
            if (!Schema::hasColumn('companies', 'public_profile_settings')) {
                Schema::table('companies', function (Blueprint $table) {
                    $table->text('public_profile_settings')->nullable();
                });
            }
        }

        if (!Schema::hasTable('pos_menu_items')) {
            Schema::create('pos_menu_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                // Deliberately NOT a foreign key constraint — pos_products is a
                // shared DI/POS surface (see inventory-mirror rule); lookups are
                // always company-scoped in code.
                $table->unsignedBigInteger('pos_product_id')->index();
                $table->integer('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->unique(['company_id', 'pos_product_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_menu_items');
        if (Schema::hasTable('companies')) {
            if (Schema::hasColumn('companies', 'public_profile_slug')) {
                Schema::table('companies', function (Blueprint $table) {
                    $table->dropColumn('public_profile_slug');
                });
            }
            if (Schema::hasColumn('companies', 'public_profile_settings')) {
                Schema::table('companies', function (Blueprint $table) {
                    $table->dropColumn('public_profile_settings');
                });
            }
        }
    }
};

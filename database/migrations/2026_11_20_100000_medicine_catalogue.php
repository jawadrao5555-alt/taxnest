<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 1579 — Pharmacy: DRAP medicine catalogue + MRP update notices.
 *
 * A GLOBAL (not per-company) medicine list seeded from DRAP's public
 * Pharmaceutical Product Price Index (https://e.dra.gov.pk/public/price),
 * refreshed on a schedule, manageable by the SaaS admin, and offered to
 * pharmacy-mode FBR shops as a one-click "add from catalogue" source.
 *
 *   • medicine_catalogue         — one row per DRAP registration × pack × maker
 *   • medicine_catalogue_prices  — append-only MRP history (old → new, when)
 *   • medicine_catalogue_syncs   — the hours-long crawl's progress row
 *                                  (survives deploys; cache does not)
 *   • medicine_price_notices     — "Price updates" a shop must act on for the
 *                                  products it linked to the catalogue
 *   • products.medicine_catalogue_id / drap_reg_no — the link + import alias
 *
 * Every column/table is hasTable/hasColumn guarded (PROD schema-drift rule).
 * Nothing here changes behaviour for a non-pharmacy shop: the new product
 * columns are nullable and unread outside pharmacy mode.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('medicine_catalogue')) {
            Schema::create('medicine_catalogue', function (Blueprint $table) {
                $table->id();
                $table->string('brand_name', 255);
                $table->text('composition')->nullable();          // raw DRAP text, never rewritten by the parser
                $table->string('generic_name', 255)->nullable();  // parsed (editable by admin)
                $table->string('strength', 120)->nullable();      // parsed
                $table->string('dosage_form', 40)->nullable();    // parsed → Product::DOSAGE_FORMS value
                $table->string('manufacturer', 255)->nullable();
                $table->string('manufacturer_licence', 120)->nullable(); // "DML: 000340" / "DSL: 030"
                $table->string('drap_reg_no', 40)->nullable();
                $table->string('category', 30)->nullable();       // essential | low_price | normal
                $table->string('pack_size', 160)->nullable();     // DRAP pack text, verbatim
                $table->decimal('mrp', 14, 2)->nullable();
                $table->date('effective_date')->nullable();
                $table->string('source', 20)->default('drap');    // drap | manual | import
                $table->boolean('is_active')->default(true);
                $table->string('dedupe_key', 64);                 // sha1(reg|pack|maker) — the idempotent upsert key
                $table->string('checksum', 64)->nullable();       // sha1 of the parsed row — unchanged row = no write
                $table->timestamp('last_seen_at')->nullable();    // last crawl that saw this row
                $table->timestamps();

                $table->unique('dedupe_key', 'mc_dedupe_unique');
                $table->index('drap_reg_no', 'mc_reg_idx');
                $table->index('brand_name', 'mc_brand_idx');
                $table->index('generic_name', 'mc_generic_idx');
                $table->index('manufacturer', 'mc_maker_idx');
                $table->index(['category', 'is_active'], 'mc_cat_active_idx');
            });
        }

        if (!Schema::hasTable('medicine_catalogue_prices')) {
            Schema::create('medicine_catalogue_prices', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('catalogue_id');
                $table->decimal('old_mrp', 14, 2)->nullable();
                $table->decimal('new_mrp', 14, 2)->nullable();
                $table->date('old_effective_date')->nullable();
                $table->date('effective_date')->nullable();
                $table->string('source', 20)->default('drap');
                $table->unsignedBigInteger('sync_id')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index(['catalogue_id', 'created_at'], 'mcp_cat_created_idx');
            });
        }

        if (!Schema::hasTable('medicine_catalogue_syncs')) {
            Schema::create('medicine_catalogue_syncs', function (Blueprint $table) {
                $table->id();
                $table->string('state', 20)->default('queued');   // queued | running | completed | failed | cancelled | stalled
                $table->string('trigger', 20)->default('manual'); // manual | schedule | cli
                $table->unsignedBigInteger('started_by')->nullable(); // admin user id
                // Crawl plan: phases are the listing filters we walk; the
                // cursor is "next page to fetch" inside the current phase.
                $table->unsignedSmallInteger('phase_index')->default(0);
                $table->unsignedInteger('next_page')->default(1);
                $table->unsignedInteger('total_pages')->nullable();  // of the current phase, once known
                $table->unsignedInteger('pages_done')->default(0);   // across phases
                $table->unsignedInteger('rows_seen')->default(0);
                $table->unsignedInteger('rows_created')->default(0);
                $table->unsignedInteger('rows_updated')->default(0);
                $table->unsignedInteger('price_changes')->default(0);
                $table->unsignedInteger('errors_count')->default(0);
                $table->text('last_error')->nullable();
                $table->boolean('cancel_requested')->default(false);
                $table->timestamp('started_at')->nullable();
                $table->timestamp('last_progress_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->index(['state', 'last_progress_at'], 'mcs_state_idx');
            });
        }

        if (!Schema::hasTable('medicine_price_notices')) {
            Schema::create('medicine_price_notices', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('catalogue_id');
                $table->unsignedBigInteger('price_id');          // medicine_catalogue_prices.id that raised it
                $table->decimal('old_mrp', 14, 2)->nullable();
                $table->decimal('new_mrp', 14, 2)->nullable();
                $table->date('effective_date')->nullable();
                $table->string('status', 20)->default('pending'); // pending | applied | dismissed | superseded
                $table->unsignedBigInteger('acted_by')->nullable();
                $table->timestamp('acted_at')->nullable();
                $table->timestamps();

                $table->unique(['product_id', 'price_id'], 'mpn_product_price_unique');
                $table->index(['company_id', 'status'], 'mpn_company_status_idx');
            });
        }

        if (Schema::hasTable('products')) {
            if (!Schema::hasColumn('products', 'medicine_catalogue_id')) {
                Schema::table('products', function (Blueprint $table) {
                    $table->unsignedBigInteger('medicine_catalogue_id')->nullable();
                });
                $this->addIndexSafely('products', ['company_id', 'medicine_catalogue_id'], 'products_company_mc_idx');
            }
            if (!Schema::hasColumn('products', 'drap_reg_no')) {
                Schema::table('products', function (Blueprint $table) {
                    $table->string('drap_reg_no', 40)->nullable();
                });
                $this->addIndexSafely('products', ['company_id', 'drap_reg_no'], 'products_company_drap_idx');
            }
        }
    }

    public function down(): void
    {
        // Catalogue data + notices are never dropped automatically: the shop's
        // product links point at these ids. Column removal only.
        if (Schema::hasTable('products')) {
            foreach (['medicine_catalogue_id', 'drap_reg_no'] as $col) {
                if (Schema::hasColumn('products', $col)) {
                    Schema::table('products', fn (Blueprint $t) => $t->dropColumn($col));
                }
            }
        }
    }

    private function addIndexSafely(string $table, array $columns, string $name): void
    {
        try {
            Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
        } catch (\Throwable $e) {
            // already there — nothing to do
        }
    }
};

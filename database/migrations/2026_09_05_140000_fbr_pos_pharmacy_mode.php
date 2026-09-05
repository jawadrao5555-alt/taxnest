<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task 1558 — FBR POS Pharmacy Mode.
 *
 * "Pharmacy / Medical" has been a signup business type on the FBR panel for a
 * long time, but the preset switched on flags nobody read: the catalogue had no
 * salt name, no schedule, no batch and no expiry, and stock was one aggregate
 * number per product per branch. A medical store could not say WHICH batch it
 * sold, what was about to expire, or what it could claim back from its
 * distributor.
 *
 * This migration lays the whole schema for the real mode:
 *   • pricing_plans.pharmacy_enabled  — the package gate (FBR Business upward)
 *   • companies.pharmacy_mode         — the shop's own master switch
 *   • products.*                      — the medicine catalogue fields
 *   • product_batches                 — batch/expiry sub-ledger UNDER branch stock
 *   • inventory_movements.batch_*     — every stock movement names its batch
 *   • purchase_order_items.batch_*    — receiving carries batch + expiry + MRP
 *   • fbr_pos_transaction_items.batch_* / loose_units — what was really sold
 *   • fbr_pos_transactions.doctor_*   — prescription capture
 *   • pharmacy_claims / _items        — distributor expiry & damage claims
 *   • pharmacy_stock_actions          — quarantine / write-off with a reason
 *                                       and a person against it
 *
 * Every single column is hasColumn/hasTable guarded (PROD schema-drift rule),
 * and NOTHING here changes behaviour for a non-pharmacy FBR shop: the gate
 * defaults to off, the mode defaults to off, and the new columns are nullable
 * with batch-less stock continuing to work exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Package gate ──────────────────────────────────────────────
        if (Schema::hasTable('pricing_plans') && !Schema::hasColumn('pricing_plans', 'pharmacy_enabled')) {
            Schema::table('pricing_plans', function (Blueprint $table) {
                $table->boolean('pharmacy_enabled')->default(false);
            });
        }
        if (Schema::hasColumn('pricing_plans', 'pharmacy_enabled')) {
            // Owner ladder: the pharmacy module is a Business-upward feature on
            // the FBR panel. Starter and Trial stay false on the COLUMN — an
            // active trial still unlocks it through planAllows()'s trial rule,
            // exactly like every other gate, so an expired trial cannot leak it.
            DB::table('pricing_plans')->where('product_type', 'fbrpos')->update(['pharmacy_enabled' => false]);
            DB::table('pricing_plans')->where('product_type', 'fbrpos')
                ->whereIn('name', ['Business', 'Unlimited'])->update(['pharmacy_enabled' => true]);
        }

        // ── 2. The shop's own switch ─────────────────────────────────────
        if (Schema::hasTable('companies') && !Schema::hasColumn('companies', 'pharmacy_mode')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->boolean('pharmacy_mode')->default(false);
            });
        }
        if (Schema::hasColumn('companies', 'pharmacy_mode')) {
            // The signup preset finally resolves into something real: every FBR
            // shop that registered as a pharmacy gets the mode ON. PRA shops and
            // every other FBR category are left untouched at false.
            $q = DB::table('companies')->where('product_type', 'fbrpos');
            if (Schema::hasColumn('companies', 'business_category')) {
                $q->where(function ($w) {
                    $w->where('business_category', 'pharmacy');
                    if (Schema::hasColumn('companies', 'pos_type')) {
                        $w->orWhere(function ($p) {
                            $p->whereNull('business_category')->where('pos_type', 'pharmacy');
                        });
                    }
                });
            } elseif (Schema::hasColumn('companies', 'pos_type')) {
                $q->where('pos_type', 'pharmacy');
            } else {
                $q->whereRaw('1 = 0');
            }
            $q->update(['pharmacy_mode' => true]);
        }

        // ── 3. Medicine catalogue fields on the shared products table ────
        if (Schema::hasTable('products')) {
            Schema::table('products', function (Blueprint $table) {
                if (!Schema::hasColumn('products', 'generic_name')) {
                    $table->string('generic_name', 190)->nullable();
                }
                if (!Schema::hasColumn('products', 'strength')) {
                    $table->string('strength', 60)->nullable();
                }
                if (!Schema::hasColumn('products', 'dosage_form')) {
                    $table->string('dosage_form', 40)->nullable();
                }
                if (!Schema::hasColumn('products', 'manufacturer')) {
                    $table->string('manufacturer', 150)->nullable();
                }
                if (!Schema::hasColumn('products', 'drug_schedule')) {
                    $table->string('drug_schedule', 20)->nullable();
                }
                if (!Schema::hasColumn('products', 'prescription_required')) {
                    $table->boolean('prescription_required')->default(false);
                }
                if (!Schema::hasColumn('products', 'shelf_location')) {
                    $table->string('shelf_location', 60)->nullable();
                }
                // Pack composition: one stocked pack (box) holds N strips, each
                // strip holds M units (tablets/capsules). Either may be null for
                // a syrup/injection that is only ever sold whole.
                if (!Schema::hasColumn('products', 'strips_per_pack')) {
                    $table->unsignedInteger('strips_per_pack')->nullable();
                }
                if (!Schema::hasColumn('products', 'units_per_strip')) {
                    $table->unsignedInteger('units_per_strip')->nullable();
                }
                if (!Schema::hasColumn('products', 'allow_loose_sale')) {
                    $table->boolean('allow_loose_sale')->default(false);
                }
            });
            if (Schema::hasColumn('products', 'generic_name')) {
                $this->addIndexSafely('products', ['company_id', 'generic_name'], 'products_company_generic_idx');
            }
        }

        // ── 4. Batch sub-ledger ──────────────────────────────────────────
        if (!Schema::hasTable('product_batches')) {
            Schema::create('product_batches', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->string('batch_number', 60);
                $table->date('expiry_date')->nullable();
                // Stocked packs, same unit as inventory_stocks.quantity. Decimal
                // so a broken strip does not have to round the pack away.
                $table->decimal('quantity', 15, 3)->default(0);
                $table->decimal('cost_price', 15, 2)->default(0);
                $table->decimal('retail_price', 15, 2)->nullable();
                $table->unsignedBigInteger('supplier_id')->nullable();
                $table->unsignedBigInteger('purchase_order_id')->nullable();
                // active | quarantined | written_off
                $table->string('status', 20)->default('active');
                $table->date('received_at')->nullable();
                $table->string('notes', 255)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'product_id', 'branch_id'], 'pbatch_scope_idx');
                $table->index(['company_id', 'expiry_date'], 'pbatch_expiry_idx');
                $table->unique(
                    ['company_id', 'product_id', 'branch_id', 'batch_number', 'expiry_date'],
                    'pbatch_identity_uq'
                );
            });
        }

        // ── 5. Movements name their batch ────────────────────────────────
        if (Schema::hasTable('inventory_movements')) {
            Schema::table('inventory_movements', function (Blueprint $table) {
                if (!Schema::hasColumn('inventory_movements', 'batch_id')) {
                    $table->unsignedBigInteger('batch_id')->nullable();
                }
                if (!Schema::hasColumn('inventory_movements', 'batch_number')) {
                    $table->string('batch_number', 60)->nullable();
                }
                if (!Schema::hasColumn('inventory_movements', 'batch_expiry')) {
                    $table->date('batch_expiry')->nullable();
                }
            });
        }

        // ── 6. Receiving carries batch/expiry/MRP ────────────────────────
        if (Schema::hasTable('purchase_order_items')) {
            Schema::table('purchase_order_items', function (Blueprint $table) {
                if (!Schema::hasColumn('purchase_order_items', 'batch_number')) {
                    $table->string('batch_number', 60)->nullable();
                }
                if (!Schema::hasColumn('purchase_order_items', 'expiry_date')) {
                    $table->date('expiry_date')->nullable();
                }
                if (!Schema::hasColumn('purchase_order_items', 'retail_price')) {
                    $table->decimal('retail_price', 15, 2)->nullable();
                }
            });
        }

        // ── 7. The sale line remembers what left the shelf ───────────────
        if (Schema::hasTable('fbr_pos_transaction_items')) {
            Schema::table('fbr_pos_transaction_items', function (Blueprint $table) {
                if (!Schema::hasColumn('fbr_pos_transaction_items', 'batch_id')) {
                    $table->unsignedBigInteger('batch_id')->nullable();
                }
                if (!Schema::hasColumn('fbr_pos_transaction_items', 'batch_number')) {
                    $table->string('batch_number', 60)->nullable();
                }
                if (!Schema::hasColumn('fbr_pos_transaction_items', 'batch_expiry')) {
                    $table->date('batch_expiry')->nullable();
                }
                // Loose sale: how many single units the customer actually took.
                // quantity stays in stocked packs so every existing report,
                // FBR payload and stock path keeps reading one unit of measure.
                if (!Schema::hasColumn('fbr_pos_transaction_items', 'loose_units')) {
                    $table->decimal('loose_units', 12, 3)->nullable();
                }
            });
        }

        // ── 8. Prescription capture on the bill ──────────────────────────
        if (Schema::hasTable('fbr_pos_transactions')) {
            Schema::table('fbr_pos_transactions', function (Blueprint $table) {
                if (!Schema::hasColumn('fbr_pos_transactions', 'doctor_name')) {
                    $table->string('doctor_name', 150)->nullable();
                }
                if (!Schema::hasColumn('fbr_pos_transactions', 'patient_name')) {
                    $table->string('patient_name', 150)->nullable();
                }
                if (!Schema::hasColumn('fbr_pos_transactions', 'prescription_image')) {
                    $table->string('prescription_image', 255)->nullable();
                }
            });
        }

        // ── 9. Distributor expiry / damage claims ────────────────────────
        if (!Schema::hasTable('pharmacy_claims')) {
            Schema::create('pharmacy_claims', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('supplier_id')->nullable();
                $table->string('supplier_name', 150)->nullable();
                $table->string('claim_number', 30);
                // draft | raised | settled | credited | rejected
                $table->string('status', 20)->default('draft');
                $table->decimal('total_amount', 15, 2)->default(0);
                $table->decimal('settled_amount', 15, 2)->nullable();
                $table->date('raised_at')->nullable();
                $table->date('settled_at')->nullable();
                $table->string('settlement_reference', 100)->nullable();
                $table->string('notes', 500)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'status'], 'pclaim_scope_idx');
                $table->unique(['company_id', 'claim_number'], 'pclaim_number_uq');
            });
        }
        if (!Schema::hasTable('pharmacy_claim_items')) {
            Schema::create('pharmacy_claim_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('claim_id');
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('batch_id')->nullable();
                $table->string('item_name', 255);
                $table->string('batch_number', 60)->nullable();
                $table->date('expiry_date')->nullable();
                $table->decimal('quantity', 15, 3)->default(0);
                $table->decimal('cost_price', 15, 2)->default(0);
                $table->decimal('total_amount', 15, 2)->default(0);
                // expired | damaged | near_expiry | other
                $table->string('reason', 20)->default('expired');
                $table->timestamps();

                $table->index(['claim_id'], 'pclaimitem_claim_idx');
                $table->index(['company_id', 'product_id'], 'pclaimitem_scope_idx');
            });
        }

        // ── 10. Quarantine / write-off with a reason and a person ────────
        if (!Schema::hasTable('pharmacy_stock_actions')) {
            Schema::create('pharmacy_stock_actions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('batch_id')->nullable();
                // quarantine | release | write_off
                $table->string('action', 20);
                $table->decimal('quantity', 15, 3)->default(0);
                $table->decimal('cost_value', 15, 2)->default(0);
                $table->string('reason', 30)->nullable();
                $table->string('responsible_name', 150)->nullable();
                $table->unsignedBigInteger('responsible_user_id')->nullable();
                $table->unsignedBigInteger('claim_id')->nullable();
                $table->string('notes', 500)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'action'], 'pact_scope_idx');
                $table->index(['company_id', 'product_id'], 'pact_product_idx');
            });
        }
    }

    public function down(): void
    {
        foreach (['pharmacy_stock_actions', 'pharmacy_claim_items', 'pharmacy_claims', 'product_batches'] as $t) {
            Schema::dropIfExists($t);
        }

        $drops = [
            'fbr_pos_transactions' => ['doctor_name', 'patient_name', 'prescription_image'],
            'fbr_pos_transaction_items' => ['batch_id', 'batch_number', 'batch_expiry', 'loose_units'],
            'purchase_order_items' => ['batch_number', 'expiry_date', 'retail_price'],
            'inventory_movements' => ['batch_id', 'batch_number', 'batch_expiry'],
            'products' => [
                'generic_name', 'strength', 'dosage_form', 'manufacturer', 'drug_schedule',
                'prescription_required', 'shelf_location', 'strips_per_pack', 'units_per_strip',
                'allow_loose_sale',
            ],
            'companies' => ['pharmacy_mode'],
            'pricing_plans' => ['pharmacy_enabled'],
        ];
        foreach ($drops as $table => $columns) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            $present = array_values(array_filter($columns, fn ($c) => Schema::hasColumn($table, $c)));
            if ($present) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn($present));
            }
        }
    }

    /** Adding an index twice throws; a missing one must not block the deploy. */
    private function addIndexSafely(string $table, array $columns, string $name): void
    {
        try {
            Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
        } catch (\Throwable $e) {
            // already there — nothing to do
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hospital Pharmacy module schema (Task 1549).
 *
 * DESIGN RULE — one stock truth, two levels of detail:
 *
 *   `inventory_stocks` (shared platform table) stays THE branch quantity truth.
 *   Every pharmacy write goes through InventoryService/BranchStockService so
 *   the rest of the platform — and the healthcare billing module that ships
 *   later — reads the same number the POS panels read.
 *
 *   The tables below add what medicine needs and the shared inventory layer
 *   deliberately does not carry: batch/lot identity, expiry dates, quarantine,
 *   and a per-batch ledger. `health_medicine_batches.quantity` summed per
 *   (medicine, branch) must always equal the matching `inventory_stocks` row.
 *
 * A medicine therefore OWNS a `products` row (health_medicines.product_id).
 * That is what lets purchase orders, inventory movements and stock levels be
 * reused verbatim instead of re-implemented for healthcare.
 *
 * Every create/add is individually guarded so the migration is idempotent on a
 * box whose migration row was marked "Ran" without the tables ever landing
 * (the owner's PROD schema-drift history).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Per-company pharmacy policy. One row per company, created lazily
        //    by HealthPharmacyService::settings() with these same defaults.
        if (!Schema::hasTable('health_pharmacy_settings')) {
            Schema::create('health_pharmacy_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                // A batch inside this window is "short dated": it still sells,
                // but the counter must be warned before it goes out.
                $table->unsignedInteger('near_expiry_days')->default(90);
                // Expired stock is refused outright unless the owner allows it.
                $table->boolean('block_expired_dispense')->default(true);
                $table->boolean('warn_short_dated')->default(true);
                // Prescription-only / controlled medicine needs a prescription
                // reference before it may leave the counter.
                $table->boolean('require_prescription_for_controlled')->default(true);
                // Pharmacy never sells air: a dispense beyond stock is refused
                // unless the owner deliberately opens it.
                $table->boolean('allow_negative_stock')->default(false);
                $table->decimal('default_tax_rate', 5, 2)->default(0);
                $table->decimal('low_stock_threshold', 12, 3)->default(10);
                $table->string('sale_prefix', 8)->default('PH');
                $table->timestamps();

                $table->unique('company_id');
            });
        }

        // ── Medicine catalogue. The healthcare-specific identity of a product.
        if (!Schema::hasTable('health_medicines')) {
            Schema::create('health_medicines', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                // The shared catalogue row this medicine bills and stocks as.
                // Nullable only so a drifted row can be healed, never by design.
                $table->unsignedBigInteger('product_id')->nullable();

                $table->string('name');
                $table->string('generic_name')->nullable();
                $table->string('strength', 64)->nullable();       // 500mg, 5mg/5ml
                $table->string('form', 24)->default('tablet');    // tablet | syrup | injection …
                $table->string('manufacturer')->nullable();
                $table->string('category', 120)->nullable();      // therapeutic class
                $table->string('code', 64)->nullable();           // internal SKU
                $table->string('barcode', 64)->nullable();

                // Pack conversion: one pack holds `pack_size` sellable units.
                $table->string('unit_uom', 24)->default('unit');  // tablet | ml | vial
                $table->string('pack_uom', 24)->nullable();       // box | strip | bottle
                $table->decimal('pack_size', 12, 3)->default(1);

                $table->decimal('purchase_price', 12, 2)->default(0);
                $table->decimal('sale_price', 12, 2)->default(0);
                $table->decimal('tax_rate', 5, 2)->nullable();
                // Carried for the healthcare billing/FBR module that ships next.
                $table->string('hs_code', 32)->nullable();
                $table->string('uom_code', 32)->nullable();

                $table->boolean('requires_prescription')->default(false);
                $table->boolean('is_controlled')->default(false);
                $table->boolean('is_narcotic')->default(false);
                $table->boolean('is_refrigerated')->default(false);

                $table->decimal('reorder_level', 12, 3)->default(0);
                $table->decimal('max_level', 12, 3)->nullable();

                $table->string('default_dosage', 190)->nullable();
                $table->string('notes', 500)->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'is_active']);
                $table->index(['company_id', 'name']);
                $table->index(['company_id', 'barcode']);
                $table->index(['company_id', 'product_id']);
            });
        }

        // ── Substitutes. Stored one row per direction so a lookup is a single
        //    indexed read; the service writes/removes both directions together.
        if (!Schema::hasTable('health_medicine_substitutes')) {
            Schema::create('health_medicine_substitutes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('medicine_id');
                $table->unsignedBigInteger('substitute_id');
                $table->timestamps();

                $table->unique(['medicine_id', 'substitute_id'], 'health_med_sub_unique');
                $table->index(['company_id', 'medicine_id']);
            });
        }

        // ── Batches. The traceability unit: what arrived, from whom, at what
        //    cost, and when it dies. Quantity here is the batch remainder.
        if (!Schema::hasTable('health_medicine_batches')) {
            Schema::create('health_medicine_batches', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('medicine_id');
                $table->unsignedBigInteger('product_id')->nullable();

                $table->string('batch_no', 64)->nullable();
                $table->date('expiry_date')->nullable();
                $table->date('manufacture_date')->nullable();

                $table->decimal('received_quantity', 14, 3)->default(0);
                $table->decimal('quantity', 14, 3)->default(0);
                $table->decimal('cost_price', 12, 2)->default(0);
                $table->decimal('sale_price', 12, 2)->default(0);

                $table->unsignedBigInteger('supplier_id')->nullable();
                $table->unsignedBigInteger('purchase_order_id')->nullable();
                $table->unsignedBigInteger('purchase_order_item_id')->nullable();

                // active | quarantined | written_off. Quarantine does NOT move
                // physical stock — it only removes the batch from the sellable
                // pool. A write-off is what actually deducts.
                $table->string('status', 16)->default('active');
                $table->string('quarantine_reason', 190)->nullable();

                $table->string('notes', 500)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'medicine_id', 'branch_id', 'status'], 'health_batch_pool_idx');
                $table->index(['company_id', 'expiry_date']);
                $table->index(['company_id', 'purchase_order_id']);
            });
        }

        // ── Per-batch ledger. Every gram that moves is attributable: who, why,
        //    against which document. Written by HealthPharmacyStockService only.
        if (!Schema::hasTable('health_batch_movements')) {
            Schema::create('health_batch_movements', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('batch_id')->nullable();
                $table->unsignedBigInteger('medicine_id');
                $table->unsignedBigInteger('product_id')->nullable();

                // purchase | dispense | sale_return | purchase_return | wastage
                // | expiry_writeoff | quarantine | release | transfer_in
                // | transfer_out | adjustment_in | adjustment_out | opening
                $table->string('type', 24);
                // in | out | none  ("none" = status-only, e.g. quarantine)
                $table->string('direction', 8)->default('out');
                $table->decimal('quantity', 14, 3)->default(0);
                $table->decimal('balance_after', 14, 3)->default(0);
                $table->decimal('unit_cost', 12, 2)->default(0);
                $table->decimal('unit_price', 12, 2)->default(0);

                $table->string('reference_type', 40)->nullable();
                $table->unsignedBigInteger('reference_id')->nullable();
                $table->string('reference_number', 64)->nullable();
                $table->string('reason', 64)->nullable();
                $table->string('notes', 500)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'medicine_id']);
                $table->index(['company_id', 'type']);
                $table->index(['company_id', 'batch_id']);
                $table->index(['reference_type', 'reference_id']);
            });
        }

        // ── Prescriptions. The OPD module owns `health_prescriptions` and
        //    `health_prescription_items`; the pharmacy does NOT create a rival
        //    pair. It extends the same rows with what dispensing needs, so a
        //    doctor's slip and a counter slip are the same record.
        //
        //    Two states coexist by design: `status` (draft|issued) stays the
        //    doctor's, `dispense_status` (pending|partial|dispensed|cancelled)
        //    is the pharmacy's. See the HealthPrescription model.
        //
        //    On a box where the pharmacy landed BEFORE the OPD module, the
        //    tables are created here in the shared shape so the two modules
        //    still meet on one table whichever order they run in.
        if (!Schema::hasTable('health_prescriptions')) {
            Schema::create('health_prescriptions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('branch_id')->nullable();
                // Nullable: a slip written outside our OPD has no visit,
                // no registered patient and no doctor of ours.
                $table->unsignedBigInteger('health_visit_id')->nullable();
                $table->unsignedBigInteger('health_patient_id')->nullable();
                $table->unsignedBigInteger('health_doctor_id')->nullable();
                $table->unsignedBigInteger('health_department_id')->nullable();

                $table->string('prescription_no', 32);
                $table->string('status', 16)->default('draft');   // draft | issued
                $table->text('general_instructions')->nullable();
                $table->date('valid_until')->nullable();
                $table->timestamp('issued_at')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->unique(['company_id', 'prescription_no'], 'health_presc_no_unique');
                $table->index(['company_id', 'health_visit_id'], 'health_presc_visit');
                $table->index(['company_id', 'health_patient_id'], 'health_presc_patient');
            });
        }

        if (!Schema::hasTable('health_prescription_items')) {
            Schema::create('health_prescription_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('health_prescription_id');
                $table->unsignedSmallInteger('line_no')->default(1);

                $table->string('medicine_name');
                $table->string('generic_name')->nullable();
                $table->string('strength', 60)->nullable();
                $table->string('form', 30)->nullable();
                $table->string('dose', 60)->nullable();
                $table->string('route', 20)->nullable();
                $table->string('frequency', 40)->nullable();
                $table->unsignedSmallInteger('duration_days')->nullable();
                $table->decimal('quantity', 10, 2)->nullable();
                $table->string('instructions', 300)->nullable();
                $table->timestamps();

                $table->index(['company_id', 'health_prescription_id'], 'health_presc_item_parent');
            });
        }

        // The OPD table requires a visit, a patient and a doctor. A prescription
        // brought in from outside has none of the three, so those columns are
        // relaxed before the pharmacy writes its first counter slip.
        foreach (['health_visit_id', 'health_patient_id', 'health_doctor_id'] as $column) {
            if (Schema::hasColumn('health_prescriptions', $column)) {
                try {
                    DB::statement("ALTER TABLE `health_prescriptions` MODIFY `{$column}` BIGINT UNSIGNED NULL");
                } catch (\Throwable $e) {
                    // sqlite has no MODIFY; it rebuilds the table instead. The
                    // relaxation must happen on BOTH engines or the test schema
                    // quietly refuses the very slip production accepts.
                    try {
                        Schema::table('health_prescriptions', function (Blueprint $table) use ($column) {
                            $table->unsignedBigInteger($column)->nullable()->change();
                        });
                    } catch (\Throwable $inner) {
                        // Already nullable, or an engine that will not say so.
                    }
                }
            }
        }

        Schema::table('health_prescriptions', function (Blueprint $table) {
            if (!Schema::hasColumn('health_prescriptions', 'health_department_id')) {
                $table->unsignedBigInteger('health_department_id')->nullable()->after('branch_id');
            }
            // Identity snapshot for a walk-in slip: there is no registered
            // patient to point at, and typing one into the registry would
            // invent a medical record the hospital never opened.
            if (!Schema::hasColumn('health_prescriptions', 'patient_name')) {
                $table->string('patient_name')->nullable();
            }
            if (!Schema::hasColumn('health_prescriptions', 'patient_mr_no')) {
                $table->string('patient_mr_no', 64)->nullable();
            }
            if (!Schema::hasColumn('health_prescriptions', 'patient_phone')) {
                $table->string('patient_phone', 32)->nullable();
            }
            if (!Schema::hasColumn('health_prescriptions', 'patient_age')) {
                $table->string('patient_age', 16)->nullable();
            }
            if (!Schema::hasColumn('health_prescriptions', 'patient_gender')) {
                $table->string('patient_gender', 10)->nullable();
            }
            if (!Schema::hasColumn('health_prescriptions', 'doctor_name')) {
                $table->string('doctor_name')->nullable();
            }
            if (!Schema::hasColumn('health_prescriptions', 'prescribed_on')) {
                $table->date('prescribed_on')->nullable();
            }
            // The pharmacy's own state. Separate from the doctor's `status`.
            if (!Schema::hasColumn('health_prescriptions', 'dispense_status')) {
                $table->string('dispense_status', 16)->default('pending');
            }
            if (!Schema::hasColumn('health_prescriptions', 'completed_at')) {
                $table->timestamp('completed_at')->nullable();
            }
        });

        Schema::table('health_prescription_items', function (Blueprint $table) {
            // Nullable: a doctor may write a medicine the pharmacy does not
            // stock. The name snapshot is what the counter reads.
            if (!Schema::hasColumn('health_prescription_items', 'medicine_id')) {
                $table->unsignedBigInteger('medicine_id')->nullable();
            }
            if (!Schema::hasColumn('health_prescription_items', 'dispensed_quantity')) {
                $table->decimal('dispensed_quantity', 12, 3)->default(0);
            }
            if (!Schema::hasColumn('health_prescription_items', 'is_cancelled')) {
                $table->boolean('is_cancelled')->default(false);
            }
            // Free-text duration for a slip written outside our OPD, which
            // fills `duration_days` instead.
            if (!Schema::hasColumn('health_prescription_items', 'duration')) {
                $table->string('duration', 64)->nullable();
            }
        });

        if (!$this->indexExists('health_prescription_items', 'health_presc_item_medicine')) {
            Schema::table('health_prescription_items', function (Blueprint $table) {
                $table->index(['company_id', 'medicine_id'], 'health_presc_item_medicine');
            });
        }

        if (!$this->indexExists('health_prescriptions', 'health_presc_dispense')) {
            Schema::table('health_prescriptions', function (Blueprint $table) {
                $table->index(['company_id', 'dispense_status'], 'health_presc_dispense');
            });
        }

        // ── Counter / patient-linked sales. FBR-ready: the tax split and the
        //    fiscal columns are persisted at sale time so the billing module
        //    files these rows without re-deriving anything.
        if (!Schema::hasTable('health_pharmacy_sales')) {
            Schema::create('health_pharmacy_sales', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('health_department_id')->nullable();

                $table->string('sale_number', 32);
                // counter | patient | prescription
                $table->string('sale_type', 16)->default('counter');
                $table->unsignedBigInteger('prescription_id')->nullable();
                $table->unsignedBigInteger('patient_id')->nullable();
                $table->string('patient_name')->nullable();
                $table->string('patient_mr_no', 64)->nullable();
                $table->string('patient_phone', 32)->nullable();

                $table->decimal('subtotal', 14, 2)->default(0);
                $table->decimal('discount_amount', 14, 2)->default(0);
                $table->decimal('tax_amount', 14, 2)->default(0);
                $table->decimal('total_amount', 14, 2)->default(0);
                $table->decimal('cost_amount', 14, 2)->default(0);
                $table->decimal('paid_amount', 14, 2)->default(0);
                $table->decimal('change_amount', 14, 2)->default(0);
                $table->decimal('refunded_amount', 14, 2)->default(0);
                $table->decimal('tax_rate', 5, 2)->default(0);

                $table->string('payment_method', 24)->default('cash');
                // completed | partially_returned | returned | void
                $table->string('status', 20)->default('completed');

                // Fiscal handoff. No submission happens here — the healthcare
                // billing module owns that; these columns are its input.
                $table->boolean('fbr_ready')->default(false);
                $table->string('fbr_status', 24)->nullable();
                $table->string('fbr_invoice_number', 64)->nullable();

                $table->date('business_date')->nullable();
                $table->string('notes', 500)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->unique(['company_id', 'sale_number'], 'health_ph_sale_no_unique');
                $table->index(['company_id', 'branch_id', 'business_date'], 'health_ph_sale_day_idx');
                $table->index(['company_id', 'status']);
                $table->index(['company_id', 'prescription_id']);
            });
        }

        if (!Schema::hasTable('health_pharmacy_sale_items')) {
            Schema::create('health_pharmacy_sale_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('sale_id');
                $table->unsignedBigInteger('medicine_id')->nullable();
                $table->unsignedBigInteger('product_id')->nullable();
                $table->unsignedBigInteger('batch_id')->nullable();
                $table->unsignedBigInteger('prescription_item_id')->nullable();

                // Snapshots: a renamed medicine or a purged batch must never
                // rewrite a printed receipt.
                $table->string('item_name');
                $table->string('batch_no', 64)->nullable();
                $table->date('expiry_date')->nullable();

                $table->decimal('quantity', 12, 3)->default(0);
                $table->decimal('returned_quantity', 12, 3)->default(0);
                $table->decimal('unit_price', 12, 2)->default(0);
                $table->decimal('unit_cost', 12, 2)->default(0);
                $table->decimal('discount_amount', 12, 2)->default(0);
                $table->decimal('tax_rate', 5, 2)->default(0);
                $table->decimal('tax_amount', 12, 2)->default(0);
                $table->decimal('line_total', 14, 2)->default(0);

                $table->boolean('is_substitute')->default(false);
                $table->unsignedBigInteger('substitute_for_medicine_id')->nullable();
                $table->unsignedBigInteger('approved_by')->nullable();
                $table->string('dosage_instructions', 500)->nullable();
                $table->timestamps();

                $table->index(['company_id', 'sale_id']);
                $table->index(['company_id', 'medicine_id']);
                $table->index(['company_id', 'batch_id']);
            });
        }

        // ── Returns / refunds. A separate document so the refund is attributable
        //    and the original sale is never rewritten beyond its returned qty.
        if (!Schema::hasTable('health_pharmacy_returns')) {
            Schema::create('health_pharmacy_returns', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('sale_id');
                $table->string('return_number', 32);
                $table->decimal('refund_amount', 14, 2)->default(0);
                // Sellable goods go back on the shelf; opened/damaged strips are
                // written off instead. One decision per return document.
                $table->boolean('restock')->default(true);
                $table->string('reason', 190)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->unique(['company_id', 'return_number'], 'health_ph_ret_no_unique');
                $table->index(['company_id', 'sale_id']);
            });
        }

        if (!Schema::hasTable('health_pharmacy_return_items')) {
            Schema::create('health_pharmacy_return_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('return_id');
                $table->unsignedBigInteger('sale_item_id');
                $table->unsignedBigInteger('medicine_id')->nullable();
                $table->unsignedBigInteger('batch_id')->nullable();
                $table->decimal('quantity', 12, 3)->default(0);
                $table->decimal('unit_price', 12, 2)->default(0);
                $table->decimal('refund_amount', 14, 2)->default(0);
                $table->boolean('restocked')->default(true);
                $table->timestamps();

                $table->index(['company_id', 'return_id']);
            });
        }

        // ── Supplier money. Purchases live in the shared purchase_orders table;
        //    what was PAID against them is healthcare-owned, so the shared table
        //    keeps its meaning for the POS panels. Balance = billed − paid.
        if (!Schema::hasTable('health_supplier_payments')) {
            Schema::create('health_supplier_payments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('supplier_id');
                $table->unsignedBigInteger('purchase_order_id')->nullable();
                $table->decimal('amount', 14, 2)->default(0);
                $table->string('method', 24)->default('cash');
                $table->date('paid_on')->nullable();
                $table->string('reference', 64)->nullable();
                $table->string('notes', 500)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'supplier_id']);
                $table->index(['company_id', 'purchase_order_id']);
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'health_supplier_payments',
            'health_pharmacy_return_items',
            'health_pharmacy_returns',
            'health_pharmacy_sale_items',
            'health_pharmacy_sales',
            'health_batch_movements',
            'health_medicine_batches',
            'health_medicine_substitutes',
            'health_medicines',
            'health_pharmacy_settings',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }

    /**
     * sqlite (tests) has no SHOW INDEX; a failed probe reports "no index",
     * which is the safe answer — the create is itself guarded by the schema.
     */
    private function indexExists(string $table, string $indexName): bool
    {
        try {
            return count(DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName])) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }
};

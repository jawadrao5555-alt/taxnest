<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Multi-Terminal: counters/cashier stations
        if (!Schema::hasTable('fbr_pos_terminals')) {
            Schema::create('fbr_pos_terminals', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                $t->string('terminal_name');
                $t->string('terminal_code')->unique();
                $t->string('location')->nullable();
                $t->boolean('is_active')->default(true);
                $t->timestamps();
            });
        }

        // 2. Add terminal_id + return/refund + split-payment to fbr_pos_transactions
        Schema::table('fbr_pos_transactions', function (Blueprint $t) {
            if (!Schema::hasColumn('fbr_pos_transactions', 'terminal_id')) {
                $t->unsignedBigInteger('terminal_id')->nullable()->after('company_id')->index();
            }
            if (!Schema::hasColumn('fbr_pos_transactions', 'shift_id')) {
                $t->unsignedBigInteger('shift_id')->nullable()->after('terminal_id')->index();
            }
            if (!Schema::hasColumn('fbr_pos_transactions', 'transaction_type')) {
                $t->string('transaction_type', 20)->default('sale')->after('invoice_mode'); // sale|return
            }
            if (!Schema::hasColumn('fbr_pos_transactions', 'parent_transaction_id')) {
                $t->unsignedBigInteger('parent_transaction_id')->nullable()->after('transaction_type')->index();
            }
            if (!Schema::hasColumn('fbr_pos_transactions', 'payment_breakdown')) {
                $t->json('payment_breakdown')->nullable()->after('payment_method');
            }
            if (!Schema::hasColumn('fbr_pos_transactions', 'cash_received')) {
                $t->decimal('cash_received', 15, 2)->default(0)->after('payment_breakdown');
            }
            if (!Schema::hasColumn('fbr_pos_transactions', 'change_due')) {
                $t->decimal('change_due', 15, 2)->default(0)->after('cash_received');
            }
            if (!Schema::hasColumn('fbr_pos_transactions', 'customer_id')) {
                $t->unsignedBigInteger('customer_id')->nullable()->after('customer_ntn')->index();
            }
            if (!Schema::hasColumn('fbr_pos_transactions', 'promotion_id')) {
                $t->unsignedBigInteger('promotion_id')->nullable()->after('discount_amount')->index();
            }
            if (!Schema::hasColumn('fbr_pos_transactions', 'promotion_code')) {
                $t->string('promotion_code', 50)->nullable()->after('promotion_id');
            }
            if (!Schema::hasColumn('fbr_pos_transactions', 'loyalty_points_earned')) {
                $t->integer('loyalty_points_earned')->default(0)->after('promotion_code');
            }
            if (!Schema::hasColumn('fbr_pos_transactions', 'loyalty_points_redeemed')) {
                $t->integer('loyalty_points_redeemed')->default(0)->after('loyalty_points_earned');
            }
            if (!Schema::hasColumn('fbr_pos_transactions', 'loyalty_redemption_amount')) {
                $t->decimal('loyalty_redemption_amount', 15, 2)->default(0)->after('loyalty_points_redeemed');
            }
        });

        // 3. Held / Parked sales
        if (!Schema::hasTable('fbr_pos_held_sales')) {
            Schema::create('fbr_pos_held_sales', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                $t->unsignedBigInteger('terminal_id')->nullable()->index();
                $t->unsignedBigInteger('user_id')->nullable()->index();
                $t->string('hold_name', 100); // e.g. "Table 5", "Customer Ahmed"
                $t->string('customer_name')->nullable();
                $t->string('customer_phone', 30)->nullable();
                $t->json('cart_data'); // items + totals + customer info
                $t->text('notes')->nullable();
                $t->timestamps();
            });
        }

        // 4. Promotions
        if (!Schema::hasTable('fbr_pos_promotions')) {
            Schema::create('fbr_pos_promotions', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                $t->string('name');
                $t->string('code', 50)->nullable()->index(); // optional promo code
                $t->string('type', 20); // percent|fixed|bogo
                $t->decimal('value', 15, 2)->default(0); // % or PKR
                $t->decimal('min_amount', 15, 2)->default(0);
                $t->decimal('max_discount', 15, 2)->nullable();
                $t->string('applies_to', 20)->default('all'); // all|category|product
                $t->json('product_ids')->nullable();
                $t->date('valid_from')->nullable();
                $t->date('valid_until')->nullable();
                $t->integer('usage_limit')->nullable();
                $t->integer('usage_count')->default(0);
                $t->boolean('is_active')->default(true);
                $t->timestamps();
            });
        }

        // 5. Loyalty: settings + customer points + ledger
        if (!Schema::hasTable('fbr_pos_loyalty_settings')) {
            Schema::create('fbr_pos_loyalty_settings', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->unique();
                $t->boolean('is_enabled')->default(false);
                $t->decimal('rs_per_point', 10, 2)->default(100); // earn 1 pt per Rs 100
                $t->decimal('point_value', 10, 2)->default(1);    // 1 pt = Rs 1
                $t->integer('min_redeem_points')->default(50);
                $t->integer('points_expiry_days')->nullable();
                $t->timestamps();
            });
        }
        if (Schema::hasTable('pos_customers') && !Schema::hasColumn('pos_customers', 'loyalty_points')) {
            Schema::table('pos_customers', function (Blueprint $t) {
                $t->integer('loyalty_points')->default(0);
                $t->string('loyalty_tier', 20)->default('bronze');
                $t->decimal('total_spent', 15, 2)->default(0);
                $t->integer('total_orders')->default(0);
            });
        }
        if (!Schema::hasTable('fbr_pos_loyalty_ledger')) {
            Schema::create('fbr_pos_loyalty_ledger', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                $t->unsignedBigInteger('customer_id')->index();
                $t->unsignedBigInteger('transaction_id')->nullable()->index();
                $t->string('type', 20); // earn|redeem|adjust|expire
                $t->integer('points'); // signed
                $t->integer('balance_after');
                $t->string('note')->nullable();
                $t->timestamps();
            });
        }

        // 6. Cash drawer / Shifts (X & Z reports)
        if (!Schema::hasTable('fbr_pos_shifts')) {
            Schema::create('fbr_pos_shifts', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                $t->unsignedBigInteger('terminal_id')->nullable()->index();
                $t->unsignedBigInteger('user_id')->index();
                $t->timestamp('opened_at');
                $t->timestamp('closed_at')->nullable();
                $t->decimal('opening_cash', 15, 2)->default(0);
                $t->decimal('expected_cash', 15, 2)->default(0);
                $t->decimal('closing_cash', 15, 2)->default(0);
                $t->decimal('variance', 15, 2)->default(0);
                $t->decimal('total_sales', 15, 2)->default(0);
                $t->decimal('total_cash', 15, 2)->default(0);
                $t->decimal('total_card', 15, 2)->default(0);
                $t->decimal('total_other', 15, 2)->default(0);
                $t->decimal('total_returns', 15, 2)->default(0);
                $t->integer('sales_count')->default(0);
                $t->integer('returns_count')->default(0);
                $t->text('notes')->nullable();
                $t->string('status', 20)->default('open'); // open|closed
                $t->timestamps();
            });
        }
        if (!Schema::hasTable('fbr_pos_cash_movements')) {
            Schema::create('fbr_pos_cash_movements', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('shift_id')->index();
                $t->unsignedBigInteger('user_id')->index();
                $t->string('type', 20); // cash_in|cash_out|drop|float
                $t->decimal('amount', 15, 2);
                $t->string('reason');
                $t->timestamps();
            });
        }

        // Item-level promotion + return tracking
        Schema::table('fbr_pos_transaction_items', function (Blueprint $t) {
            if (!Schema::hasColumn('fbr_pos_transaction_items', 'returned_quantity')) {
                $t->decimal('returned_quantity', 15, 3)->default(0);
            }
            if (!Schema::hasColumn('fbr_pos_transaction_items', 'parent_item_id')) {
                $t->unsignedBigInteger('parent_item_id')->nullable()->index();
            }
            if (!Schema::hasColumn('fbr_pos_transaction_items', 'promotion_discount')) {
                $t->decimal('promotion_discount', 15, 2)->default(0);
            }
        });

        // Backfill: create default terminal per company that has FBR POS transactions
        $companies = DB::table('fbr_pos_transactions')->select('company_id')->distinct()->pluck('company_id');
        foreach ($companies as $cid) {
            $exists = DB::table('fbr_pos_terminals')->where('company_id', $cid)->exists();
            if (!$exists) {
                $code = 'FBR-T1-C' . $cid;
                DB::table('fbr_pos_terminals')->insert([
                    'company_id' => $cid,
                    'terminal_name' => 'Counter 1',
                    'terminal_code' => $code,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $tid = DB::table('fbr_pos_terminals')->where('terminal_code', $code)->value('id');
                DB::table('fbr_pos_transactions')->where('company_id', $cid)->whereNull('terminal_id')->update(['terminal_id' => $tid]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fbr_pos_cash_movements');
        Schema::dropIfExists('fbr_pos_shifts');
        Schema::dropIfExists('fbr_pos_loyalty_ledger');
        Schema::dropIfExists('fbr_pos_loyalty_settings');
        Schema::dropIfExists('fbr_pos_promotions');
        Schema::dropIfExists('fbr_pos_held_sales');
        // Don't drop terminals or columns to avoid data loss on rollback
    }
};

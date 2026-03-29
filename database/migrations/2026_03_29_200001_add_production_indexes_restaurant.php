<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('restaurant_orders')) {
            Schema::table('restaurant_orders', function (Blueprint $table) {
                if (!$this->hasIdx('restaurant_orders', 'idx_ro_company_status')) {
                    $table->index(['company_id', 'status'], 'idx_ro_company_status');
                }
                if (!$this->hasIdx('restaurant_orders', 'idx_ro_company_created')) {
                    $table->index(['company_id', 'created_at'], 'idx_ro_company_created');
                }
                if (!$this->hasIdx('restaurant_orders', 'idx_ro_table_id')) {
                    $table->index('table_id', 'idx_ro_table_id');
                }
                if (!$this->hasIdx('restaurant_orders', 'idx_ro_customer_id')) {
                    $table->index('customer_id', 'idx_ro_customer_id');
                }
            });
        }

        if (Schema::hasTable('restaurant_order_items')) {
            Schema::table('restaurant_order_items', function (Blueprint $table) {
                if (!$this->hasIdx('restaurant_order_items', 'idx_roi_order_id')) {
                    $table->index('order_id', 'idx_roi_order_id');
                }
                if (!$this->hasIdx('restaurant_order_items', 'idx_roi_item')) {
                    $table->index(['item_id', 'item_type'], 'idx_roi_item');
                }
            });
        }

        if (Schema::hasTable('pos_transactions')) {
            Schema::table('pos_transactions', function (Blueprint $table) {
                if (!$this->hasIdx('pos_transactions', 'idx_pt_company_created')) {
                    $table->index(['company_id', 'created_at'], 'idx_pt_company_created');
                }
                if (!$this->hasIdx('pos_transactions', 'idx_pt_company_status')) {
                    $table->index(['company_id', 'status'], 'idx_pt_company_status');
                }
            });
        }

        if (Schema::hasTable('pos_transaction_items')) {
            Schema::table('pos_transaction_items', function (Blueprint $table) {
                if (!$this->hasIdx('pos_transaction_items', 'idx_pti_transaction_id')) {
                    $table->index('transaction_id', 'idx_pti_transaction_id');
                }
            });
        }

        if (Schema::hasTable('ingredients')) {
            Schema::table('ingredients', function (Blueprint $table) {
                if (!$this->hasIdx('ingredients', 'idx_ing_company_active')) {
                    $table->index(['company_id', 'is_active'], 'idx_ing_company_active');
                }
            });
        }

        if (Schema::hasTable('product_recipes')) {
            Schema::table('product_recipes', function (Blueprint $table) {
                if (!$this->hasIdx('product_recipes', 'idx_pr_company_product')) {
                    $table->index(['company_id', 'product_id'], 'idx_pr_company_product');
                }
            });
        }

        if (Schema::hasTable('restaurant_tables')) {
            Schema::table('restaurant_tables', function (Blueprint $table) {
                if (!$this->hasIdx('restaurant_tables', 'idx_rt_company_status')) {
                    $table->index(['company_id', 'status'], 'idx_rt_company_status');
                }
            });
        }
    }

    private function hasIdx(string $table, string $name): bool
    {
        try {
            $driver = Schema::getConnection()->getDriverName();
            if ($driver === 'pgsql') {
                $r = DB::select("SELECT 1 FROM pg_indexes WHERE tablename = ? AND indexname = ?", [$table, $name]);
            } else {
                $r = DB::select("SHOW INDEX FROM {$table} WHERE Key_name = ?", [$name]);
            }
            return count($r) > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function down(): void
    {
        $indexes = [
            'restaurant_orders' => ['idx_ro_company_status', 'idx_ro_company_created', 'idx_ro_table_id', 'idx_ro_customer_id'],
            'restaurant_order_items' => ['idx_roi_order_id', 'idx_roi_item'],
            'pos_transactions' => ['idx_pt_company_created', 'idx_pt_company_status'],
            'pos_transaction_items' => ['idx_pti_transaction_id'],
            'ingredients' => ['idx_ing_company_active'],
            'product_recipes' => ['idx_pr_company_product'],
            'restaurant_tables' => ['idx_rt_company_status'],
        ];
        foreach ($indexes as $table => $idxNames) {
            if (Schema::hasTable($table)) {
                Schema::table($table, function (Blueprint $t) use ($table, $idxNames) {
                    foreach ($idxNames as $idx) {
                        if ($this->hasIdx($table, $idx)) {
                            $t->dropIndex($idx);
                        }
                    }
                });
            }
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Item #1 (owner, Jul 2026): a POS customer can have MULTIPLE saved delivery
// addresses. pos_customers.address stays as-is (it is "address #1" — the default);
// extra addresses live here. NO foreign keys — pos_* tables are shared-table
// (DI/POS isolation rule), lookups are always company-scoped in code.
// The bill itself stores a SNAPSHOT (pos_transactions.delivery_address) so old
// receipts never change when a customer edits/deletes an address later.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('pos_customer_addresses')) {
            Schema::create('pos_customer_addresses', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('customer_id');
                $table->string('label', 50)->nullable();
                $table->text('address');
                $table->timestamps();
                $table->index(['company_id', 'customer_id']);
            });
        }

        if (!Schema::hasColumn('pos_transactions', 'delivery_address')) {
            Schema::table('pos_transactions', function (Blueprint $table) {
                $table->text('delivery_address')->nullable()->after('customer_phone');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_customer_addresses');
        if (Schema::hasColumn('pos_transactions', 'delivery_address')) {
            Schema::table('pos_transactions', function (Blueprint $table) {
                $table->dropColumn('delivery_address');
            });
        }
    }
};

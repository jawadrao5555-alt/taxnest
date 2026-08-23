<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner, 23 Aug 2026: "jis customer ko hum ne handle kar liya, us ko dashboard
 * se clear karna chahta hoon taake neeche wala front par aa jaye."
 *
 * The "Regular customers gone quiet" card was dismiss-free, so a called-back
 * customer kept sitting on top of the dashboard until the alert window itself
 * expired — the rest of the list never surfaced.
 *
 * last_order_at is the whole trick: it freezes WHICH silence was handled. The
 * customer orders again and later goes quiet again → their newer last-order
 * timestamp no longer matches this row, so the alert legitimately comes back.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pos_customer_alert_dismissals')) {
            return;
        }

        Schema::create('pos_customer_alert_dismissals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('customer_id');
            // The silence that was handled (the alert row's last order).
            $table->dateTime('last_order_at')->nullable();
            $table->unsignedBigInteger('dismissed_by')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'customer_id'], 'pos_cust_alert_dismiss_unique');
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_customer_alert_dismissals');
    }
};

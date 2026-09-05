<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('agents') && !Schema::hasColumn('agents', 'discount_percent')) {
            Schema::table('agents', fn (Blueprint $t) => $t->decimal('discount_percent', 5, 2)->default(0));
        }
        if (Schema::hasTable('payment_proofs')) {
            Schema::table('payment_proofs', function (Blueprint $t) {
                if (!Schema::hasColumn('payment_proofs', 'distributor_quote_snapshot')) $t->text('distributor_quote_snapshot')->nullable();
                if (!Schema::hasColumn('payment_proofs', 'distributor_net_amount')) $t->decimal('distributor_net_amount', 12, 2)->nullable();
            });
        }
        if (Schema::hasTable('agent_commissions')) {
            Schema::table('agent_commissions', function (Blueprint $t) {
                if (!Schema::hasColumn('agent_commissions', 'commission_year')) $t->unsignedTinyInteger('commission_year')->nullable();
                if (!Schema::hasColumn('agent_commissions', 'hold_until')) $t->timestamp('hold_until')->nullable();
                if (!Schema::hasColumn('agent_commissions', 'decision_key')) $t->string('decision_key', 64)->nullable()->unique();
            });
        }
        if (Schema::hasTable('subscriptions') && !Schema::hasColumn('subscriptions', 'distributor_quote_snapshot')) {
            Schema::table('subscriptions', fn (Blueprint $t) => $t->text('distributor_quote_snapshot')->nullable());
        }
        if (!Schema::hasTable('agent_incentive_awards')) {
            Schema::create('agent_incentive_awards', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agent_id')->index();
                $t->string('quarter', 7); // YYYY-QN
                $t->unsignedInteger('qualified_companies');
                $t->decimal('rate_percent', 5, 2);
                $t->decimal('base_amount', 12, 2);
                $t->decimal('amount', 12, 2);
                $t->string('status', 20)->default('pending');
                $t->timestamp('approved_at')->nullable();
                $t->unsignedBigInteger('approved_by_admin_id')->nullable();
                $t->timestamp('paid_at')->nullable();
                $t->unsignedBigInteger('paid_by_admin_id')->nullable();
                $t->text('snapshot'); // immutable qualifying commission ids/settings
                $t->timestamps();
                $t->unique(['agent_id', 'quarter']);
            });
        }
    }
    public function down(): void {}
};
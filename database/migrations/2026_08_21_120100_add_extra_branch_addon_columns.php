<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paid extra-branch add-on schema (owner-approved, 21 Aug 2026).
 *
 *  - companies.extra_branch_slots  → package ki shamil branches se OOPER
 *    khareede hue slots. Default 0, is liye purani companies par koi asar
 *    nahi (branch gate = plan branch_limit + ye counter).
 *  - payment_proofs.request_type   → 'subscription' (default, purana behaviour)
 *    ya 'extra_branch' — admin queue isi se request pehchanti hai aur approve
 *    par SIRF slots barhte hain (subscription row ko haath nahi lagta).
 *  - payment_proofs.extra_branch_qty → kitne slots maange gaye.
 *
 * Idempotent + hasColumn-guarded (prod schema-drift self-heal convention).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('companies') && !Schema::hasColumn('companies', 'extra_branch_slots')) {
            $after = Schema::hasColumn('companies', 'branch_limit_override') ? 'branch_limit_override' : null;
            Schema::table('companies', function (Blueprint $table) use ($after) {
                $col = $table->unsignedInteger('extra_branch_slots')->default(0);
                if ($after) {
                    $col->after($after);
                }
            });
        }

        if (Schema::hasTable('payment_proofs')) {
            if (!Schema::hasColumn('payment_proofs', 'request_type')) {
                $after = Schema::hasColumn('payment_proofs', 'billing_cycle') ? 'billing_cycle' : null;
                Schema::table('payment_proofs', function (Blueprint $table) use ($after) {
                    $col = $table->string('request_type', 20)->default('subscription');
                    if ($after) {
                        $col->after($after);
                    }
                });
            }

            if (!Schema::hasColumn('payment_proofs', 'extra_branch_qty')) {
                $after = Schema::hasColumn('payment_proofs', 'request_type') ? 'request_type' : null;
                Schema::table('payment_proofs', function (Blueprint $table) use ($after) {
                    $col = $table->unsignedInteger('extra_branch_qty')->nullable();
                    if ($after) {
                        $col->after($after);
                    }
                });
            }
        }
    }

    public function down(): void
    {
        // Paid slots = paisa — kabhi automatically drop nahi hote.
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * POS/FBR-POS self-registration historically set only company_status='pending'
 * and left `status` at its DB default ('approved'), so:
 *   - the saas-admin panel never showed an Approve button for them, and
 *   - the CheckCompanyApproval view-only gate (which reads `status`) never
 *     applied to POS/FBR panels.
 * Registration now sets BOTH columns to pending and admin approve/reject/
 * suspend/activate flip both. This backfill makes existing rows coherent:
 * any POS/FBR company still awaiting approval (company_status='pending')
 * gets status='pending' so it appears in the admin pending list and is
 * correctly view-only until approved.
 * Idempotent + guarded — safe to re-run on prod.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('companies', 'company_status') || !Schema::hasColumn('companies', 'status')) {
            return;
        }

        DB::table('companies')
            ->whereIn('product_type', ['pos', 'fbrpos'])
            ->where('company_status', 'pending')
            ->whereIn('status', ['approved', 'active'])
            ->update(['status' => 'pending']);
    }

    public function down(): void
    {
        // Irreversible data backfill — nothing sensible to restore.
    }
};

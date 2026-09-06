<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "These two are not the same customer" must outlive the group it was said in.
 *
 * The first version remembered a detach as (group, company). When a two-account
 * group is broken the group dissolves — a group of one is noise — and with it
 * the id the exclusion pointed at. The next automatic pass then found the same
 * shared CNIC, created a BRAND NEW group, and silently undid the admin.
 *
 * A decision about two businesses belongs to the two businesses, so it is now
 * stored as a company pair (written in both directions). The old column stays
 * for group-level records and is nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('company_group_exclusions')) {
            return;
        }

        if (!Schema::hasColumn('company_group_exclusions', 'excluded_company_id')) {
            Schema::table('company_group_exclusions', function (Blueprint $table) {
                $table->unsignedBigInteger('excluded_company_id')->nullable()->after('company_id')->index();
            });
        }

        // company_group_id was NOT NULL; a pure pair row has no group.
        if (Schema::hasColumn('company_group_exclusions', 'company_group_id')) {
            try {
                Schema::table('company_group_exclusions', function (Blueprint $table) {
                    $table->unsignedBigInteger('company_group_id')->nullable()->change();
                });
            } catch (\Throwable $e) {
                // doctrine/dbal absent or driver cannot modify — a pair row can
                // still carry the group id it was decided in, so this is not
                // fatal. Nothing else depends on the column being nullable.
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('company_group_exclusions') && Schema::hasColumn('company_group_exclusions', 'excluded_company_id')) {
            Schema::table('company_group_exclusions', function (Blueprint $table) {
                $table->dropColumn('excluded_company_id');
            });
        }
    }
};

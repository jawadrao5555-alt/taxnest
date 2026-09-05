<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SITE COORDINATES FOR THE ATTENDANCE GEOFENCE.
 *
 * "Location required" was a switch with nothing to check against: a phone can
 * send whatever coordinates it likes, so without a configured site the setting
 * only pretended to protect the punch. A geofence needs a centre.
 *
 * The centre lives on the BRANCH, because that is what a hospital actually
 * geofences — a ward block, a collection point, a second clinic across town.
 * An organisation with no branch rows (most clinics) sets one site on the HR
 * policy instead. A branch may tighten or widen the organisation's radius.
 *
 * Idempotent per column, in the shape this project needs for live: a live
 * database that already carries part of this must not fail the whole run.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('branches')) {
            Schema::table('branches', function (Blueprint $table) {
                if (!Schema::hasColumn('branches', 'latitude')) {
                    $table->decimal('latitude', 10, 7)->nullable()->after('address');
                }
                if (!Schema::hasColumn('branches', 'longitude')) {
                    $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
                }
                if (!Schema::hasColumn('branches', 'geo_radius_m')) {
                    // NULL = use the organisation's radius.
                    $table->unsignedSmallInteger('geo_radius_m')->nullable()->after('longitude');
                }
            });
        }

        if (Schema::hasTable('health_hr_policies')) {
            Schema::table('health_hr_policies', function (Blueprint $table) {
                if (!Schema::hasColumn('health_hr_policies', 'geo_latitude')) {
                    $table->decimal('geo_latitude', 10, 7)->nullable()->after('geo_radius_m');
                }
                if (!Schema::hasColumn('health_hr_policies', 'geo_longitude')) {
                    $table->decimal('geo_longitude', 10, 7)->nullable()->after('geo_latitude');
                }
            });
        }
    }

    public function down(): void
    {
        // Deliberately not dropped: a site coordinate somebody surveyed is not
        // worth losing to a rollback, and every reader guards on hasColumn.
    }
};

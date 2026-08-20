<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One confined rider login may belong to exactly one rider.
 *
 * Legacy duplicate links cannot be assigned safely to either rider without an
 * administrator's decision. Quarantine every duplicate link, retain a visible
 * repair marker, then enforce the invariant for all future writes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('pos_riders') || !Schema::hasColumn('pos_riders', 'user_id')) {
            return;
        }

        if (!Schema::hasColumn('pos_riders', 'login_link_issue')) {
            Schema::table('pos_riders', function (Blueprint $table) {
                $table->string('login_link_issue', 30)->nullable()->after('user_id');
            });
        }

        $duplicateUserIds = DB::table('pos_riders')
            ->whereNotNull('user_id')
            ->select('user_id')
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('user_id');

        foreach ($duplicateUserIds->chunk(500) as $userIds) {
            DB::table('pos_riders')
                ->whereIn('user_id', $userIds->all())
                ->update([
                    'user_id' => null,
                    'login_link_issue' => 'multiple_riders',
                    'updated_at' => now(),
                ]);
        }

        $hasUniqueUserIndex = collect(Schema::getIndexes('pos_riders'))
            ->contains(function (array $index) {
                return ($index['unique'] ?? false)
                    && array_values($index['columns'] ?? []) === ['user_id'];
            });

        if (!$hasUniqueUserIndex) {
            Schema::table('pos_riders', function (Blueprint $table) {
                $table->unique('user_id', 'pos_riders_user_id_unique');
            });
        }
    }

    public function down(): void
    {
        // Additive safety invariant. Restoring ambiguous shared links is unsafe.
    }
};
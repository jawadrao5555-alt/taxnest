<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Customer-facing What's New must not expose deployment provenance. Preserve
 * every historical row for the admin audit trail; only stop unsafe rows from
 * being rendered to POS users.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('app_updates')) {
            return;
        }

        DB::table('app_updates')
            ->where('is_published', true)
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $points = json_decode((string) ($row->points ?? '[]'), true);
                    if (!is_array($points)) {
                        $points = [(string) ($row->points ?? '')];
                    }
                    if (\App\Models\AppUpdate::containsOperationalDetails((string) ($row->title ?? ''), $points)) {
                        DB::table('app_updates')->where('id', $row->id)->update([
                            'is_published' => false,
                            'updated_at' => now(),
                        ]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Deliberately irreversible: never republish customer-unsafe content.
    }
};

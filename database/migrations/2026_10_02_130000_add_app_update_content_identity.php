<?php

use App\Models\AppUpdate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('app_updates')) {
            return;
        }
        foreach (['notification_key', 'manual_publish_key', 'announcement_revision', 'announcement_parent_id', 'archived_at'] as $column) {
            if (!Schema::hasColumn('app_updates', $column)) {
                Schema::table('app_updates', function (Blueprint $table) use ($column) {
                    if ($column === 'announcement_parent_id') {
                        $table->unsignedBigInteger($column)->nullable()->index();
                    } elseif ($column === 'archived_at') {
                        $table->timestamp($column)->nullable();
                    } elseif ($column === 'announcement_revision') {
                        $table->uuid($column)->nullable();
                    } else {
                        $field = $table->string($column, 64)->nullable();
                        $column === 'manual_publish_key' ? $field->unique() : $field->index();
                    }
                });
            }
        }
        // No deletion, re-dating, republishing or reset of customer seen state.
        AppUpdate::orderBy('id')->chunkById(200, function ($updates) {
            foreach ($updates as $update) {
                DB::table('app_updates')->where('id', $update->id)
                    ->update(['notification_key' => $update->contentKey()]);
            }
        });
    }

    public function down(): void
    {
        // Removing only the metadata restores legacy delivery behavior.
        foreach (['notification_key', 'manual_publish_key', 'announcement_revision', 'announcement_parent_id', 'archived_at'] as $column) {
            if (Schema::hasColumn('app_updates', $column)) {
                Schema::table('app_updates', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};

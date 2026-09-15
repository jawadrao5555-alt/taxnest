<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Preserve every existing saved Custom Access set when the new grant lands. */
return new class extends Migration
{
    public function up(): void
    {
        $this->rewrite(true);
    }

    public function down(): void
    {
        $this->rewrite(false);
    }

    private function rewrite(bool $add): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'pos_custom_access')) {
            return;
        }
        DB::table('users')->whereNotNull('pos_custom_access')->select('id', 'pos_custom_access')->orderBy('id')->chunk(500, function ($rows) use ($add) {
            foreach ($rows as $row) {
                $set = json_decode((string) $row->pos_custom_access, true);
                if (! is_array($set)) {
                    continue;
                }
                $has = in_array('service_jobs', $set, true);
                if ($add && ! $has) {
                    $set[] = 'service_jobs';
                }
                if (! $add && $has) {
                    $set = array_values(array_filter($set, fn ($key) => $key !== 'service_jobs'));
                }
                if ($has !== $add) {
                    DB::table('users')->where('id', $row->id)->update(['pos_custom_access' => json_encode(array_values($set))]);
                }
            }
        });
    }
};

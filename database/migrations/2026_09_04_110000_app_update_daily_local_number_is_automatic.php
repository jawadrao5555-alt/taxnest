<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TITLE = 'Daily L001 ke liye roz Reset dabana zaroori nahi';

    public function up(): void
    {
        if (!Schema::hasTable('app_updates') ||
            DB::table('app_updates')->where('title', self::TITLE)->exists()) {
            return;
        }

        $row = [
            'title' => self::TITLE,
            'points' => json_encode([
                'Customize POS → Local Billing mein receipt par dikhne wala local bill number ab seedha select aur save kiya ja sakta hai.',
                'Daily local number select ho to har karobari din subah 6 baje L001 khud dobara shuru hota hai—roz Reset numbering dabane ki zaroorat nahi.',
                'Asal bill serial unique aur mehfooz rehti hai, is liye khata, search, return aur puranay records par koi asar nahi parta.',
            ], JSON_UNESCAPED_UNICODE),
            'audience' => 'pos',
            'is_published' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('app_updates', 'type')) {
            $row['type'] = 'feature';
        }

        DB::table('app_updates')->insert($row);
    }

    public function down(): void
    {
        if (Schema::hasTable('app_updates')) {
            DB::table('app_updates')->where('title', self::TITLE)->delete();
        }
    }
};
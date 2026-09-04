<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TITLE = 'Top Selling report ab Today se khulti hai';

    public function up(): void
    {
        if (!Schema::hasTable('app_updates') ||
            DB::table('app_updates')->where('title', self::TITLE)->exists()) {
            return;
        }

        $row = [
            'title' => self::TITLE,
            'points' => json_encode([
                'Restaurant dashboard ke Top Selling Items ab current business day ki sales dikhate hain.',
                'View All kholne par report ka default range Today hoga; zaroorat par From aur To dates baad mein badli ja sakti hain.',
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
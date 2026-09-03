<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TITLE = 'F6 se product quantity seedha keyboard se badlein';

    public function up(): void
    {
        if (!Schema::hasTable('app_updates') ||
            DB::table('app_updates')->where('title', self::TITLE)->exists()) {
            return;
        }

        $row = [
            'title' => self::TITLE,
            'points' => json_encode([
                'Sale screen par cart mein product add karne ke baad F6 dabayein — aakhri product ki quantity foran select ho jayegi.',
                'Number type karke quantity seedha replace karein; mouse se quantity box par click karne ki zaroorat nahi.',
                'Arrow Up aur Arrow Down se cart ke doosre products par jayen. Plus/Minus se quantity kam-zyada aur Escape se product search par wapas jayen.',
                'Yeh shortcut Retail, Dining, Takeaway, Delivery aur FBR POS sale screens par kaam karta hai.',
            ], JSON_UNESCAPED_UNICODE),
            'audience' => 'all',
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
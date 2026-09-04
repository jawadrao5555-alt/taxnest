<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TITLE = 'Restaurant table par online payable amount';

    public function up(): void
    {
        if (!Schema::hasTable('app_updates')
            || DB::table('app_updates')->where('title', self::TITLE)->exists()) {
            return;
        }

        DB::table('app_updates')->insert([
            'title' => self::TITLE,
            'points' => json_encode([
                'Table popup mein Online Payment ke saath ab us payment method ka asal payable amount nazar aayega.',
                'Cash aur online tax ki wajah se amounts mukhtalif hon to cashier final karne se pehle sahi online amount dekh sakega.',
                'Popup, payment confirmation aur final bill ab aik hi server calculation use karte hain.',
            ], JSON_UNESCAPED_UNICODE),
            'audience' => 'pos',
            'is_published' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (Schema::hasTable('app_updates')) {
            DB::table('app_updates')->where('title', self::TITLE)->delete();
        }
    }
};
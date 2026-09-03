<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TITLE = 'Reports, deals aur table payment ab zyada asaan';

    public function up(): void
    {
        if (!Schema::hasTable('app_updates') ||
            DB::table('app_updates')->where('title', self::TITLE)->exists()) {
            return;
        }

        $row = [
            'title' => self::TITLE,
            'points' => json_encode([
                'Dashboard ke Top Selling Items se ab complete item-wise report khulti hai. Date, branch, cashier aur PRA/local billing scope usi tarah apply rehta hai.',
                'Purani Deals mein missing products ya khali choice groups ko ab Edit Deal se theek kiya ja sakta hai; Add Products option wazeh nazar aata hai.',
                'Table par Online Payment mark ho to popup se total amount ke sath direct online payment confirm/final ki ja sakti hai—bill pehle open karna zaroori nahi.',
                'Local bill delete/Day Close aur customer spent history ka farq ab settings mein saaf likha hai. Numbering khud reset nahi hoti; safe reset sirf admin khali series par karta hai.',
                'Settings ka NEW badge ab sirf usi page ke naye controls par dikhega, taa-ke shop ko foran pata chale naya option kahan hai.',
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
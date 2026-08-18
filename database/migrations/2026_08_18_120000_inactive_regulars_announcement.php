<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "What's New" elaan — Task 1161: repeat-customer inactivity alert
 * ("Purane Customer Khamosh Hain" dashboard card + customers-page chip).
 * Idempotent data migration — prod deploys run `migrate --force` (never seed).
 */
return new class extends Migration
{
    private string $title = 'Naya: purana customer khamosh ho jaye to dashboard par alert';

    public function up(): void
    {
        if (!Schema::hasTable('app_updates')) {
            return;
        }

        if (!DB::table('app_updates')->where('title', $this->title)->where('audience', 'pos')->exists()) {
            DB::table('app_updates')->insert([
                'title' => $this->title,
                'points' => json_encode([
                    'Dashboard par naya card "Purane Customer Khamosh Hain" — jo regular customer (3+ orders) 12 din se koi order na kare, uska naam yahan aa jata hai.',
                    'Har customer ke saath phone number (tap kar ke seedha call), total orders aur "aakhri order kitne din pehle" nazar aata hai — call kar ke pooch lein, shayad wapas aa jayen.',
                    'Customers page aur customer ki history par bhi "Khamosh" ka nishaan nazar aata hai, taake list dekhte hi pata chal jaye.',
                    'Card khud hi ghaib ho jata hai jab koi khamosh customer na ho — koi extra setting nahi chahiye.',
                ], JSON_UNESCAPED_UNICODE),
                'image_path' => null,
                'audience' => 'pos',
                'is_published' => true,
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('app_updates')) {
            return;
        }
        DB::table('app_updates')->where('title', $this->title)->where('audience', 'pos')->delete();
    }
};

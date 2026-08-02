<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FBR POS users ko bhi restaurant-features ka "What's New" elaan (Task: Aug 2026).
 * The PRA-only announcement (audience 'pos') never reached FBR POS admins even
 * though the same restaurant features shipped on the FBR universal sale screen.
 * Idempotent data migration — prod deploys run `migrate --force` (never seed).
 */
return new class extends Migration
{
    private string $title = 'Restaurant Features: 5 Naye Improvements!';

    public function up(): void
    {
        if (!Schema::hasTable('app_updates')) {
            return;
        }

        // Guard: skip if the FBR announcement already exists (idempotent).
        $exists = DB::table('app_updates')
            ->where('title', $this->title)
            ->where('audience', 'fbr_pos')
            ->exists();
        if ($exists) {
            return;
        }

        DB::table('app_updates')->insert([
            'title' => $this->title,
            'points' => json_encode([
                'Provisional Bills mein ab search karein — customer ka naam, phone number ya bill number likhein aur bill foran mil jaye ga.',
                'Rush order ab KOT par bara sa "URGENT" saaf kaali chhapai mein aata hai — kitchen ko foran pata chale ga.',
                'Kitchen/bill notes ab multi-line — Enter dabayen tou nayi line banti hai, aur KOT par notes number-war (1, 2, 3...) chhapte hain.',
                'Make Final par ab "Receipt print na karein" ka option — jab receipt ki zaroorat na ho tou print skip karein.',
                'Delivery orders mein ab "Payment First, Then KOT" — pehle payment lein, phir KOT kitchen jaye.',
            ], JSON_UNESCAPED_UNICODE),
            'image_path' => null,
            'audience' => 'fbr_pos',
            'is_published' => true,
            'created_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('app_updates')) {
            return;
        }
        DB::table('app_updates')
            ->where('title', $this->title)
            ->where('audience', 'fbr_pos')
            ->delete();
    }
};

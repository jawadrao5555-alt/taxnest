<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "What's New" elaan — Prepaid (Online) deliveries + logo-on-final-only setting
 * (PRA POS) aur Receipt Print Style on FBR POS (Aug 2026 batch: tasks 284-288, 291).
 * Idempotent data migration — prod deploys run `migrate --force` (never seed).
 */
return new class extends Migration
{
    private string $posTitle = 'Naye Features: Prepaid Delivery aur Logo Control';
    private string $fbrTitle = 'Receipt Print Style ab FBR POS par bhi';

    public function up(): void
    {
        if (!Schema::hasTable('app_updates')) {
            return;
        }

        if (!DB::table('app_updates')->where('title', $this->posTitle)->where('audience', 'pos')->exists()) {
            DB::table('app_updates')->insert([
                'title' => $this->posTitle,
                'points' => json_encode([
                    'Receipt Settings mein naya option "Logo sirf final (PRA) bill par" — ON karein tou local/provisional bill par aap ka logo nahi chhape ga, sirf final bill par aaye ga.',
                    'Delivery bills ke liye naya "Prepaid (Online)" button — jo customer pehle se online payment bhej chuka ho, us ka bill rider ke cash khate mein nahi phanse ga.',
                    'Deliveries board par "PAID" ka nishaan aur rider app mein bhi prepaid ka pata — rider ko saaf maloom ho ga ke customer se paise nahi lene.',
                    'Ghalti se Prepaid mark ho jaye tou admin bill wapas Cash kar sakta hai (undo) — rider ka khata khud durust ho jata hai.',
                    'Delivery receipt par ab "PREPAID — Online Paid" saaf chhapta hai — customer aur rider dono ke liye wazeh.',
                ], JSON_UNESCAPED_UNICODE),
                'image_path' => null,
                'audience' => 'pos',
                'is_published' => true,
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (!DB::table('app_updates')->where('title', $this->fbrTitle)->where('audience', 'fbr_pos')->exists()) {
            DB::table('app_updates')->insert([
                'title' => $this->fbrTitle,
                'points' => json_encode([
                    'Receipt Print Style ki settings (bold text, logo center/side) ab aap ki FBR POS receipts par bhi lagoo hoti hain — bilkul waise hi jaise PRA receipts par.',
                ], JSON_UNESCAPED_UNICODE),
                'image_path' => null,
                'audience' => 'fbr_pos',
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
        DB::table('app_updates')->where('title', $this->posTitle)->where('audience', 'pos')->delete();
        DB::table('app_updates')->where('title', $this->fbrTitle)->where('audience', 'fbr_pos')->delete();
    }
};

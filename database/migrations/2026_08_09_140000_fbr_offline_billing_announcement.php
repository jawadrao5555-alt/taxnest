<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "What's New" elaan — FBR POS Offline Billing (PRA se port, owner-approved
 * Aug 2026). Audience 'fbr_pos' ONLY: PRA POS ke paas yeh feature pehle se
 * hai aur uska apna elaan ho chuka hai.
 * Idempotent data migration — prod deploys run `migrate --force` (never seed).
 */
return new class extends Migration
{
    private string $title = 'Naya Feature: Internet ke Baghair Bhi Billing (Offline Mode)';

    public function up(): void
    {
        if (!Schema::hasTable('app_updates')) {
            return;
        }

        $points = [
            'Ab internet chala jaye to bhi billing NAHI rukegi — bill isi computer/device par mehfooz ho jata hai aur customer ko offline receipt mil jati hai.',
            'Internet wapis aate hi mehfooz bills khud-ba-khud sync ho jate hain — unhe asal invoice number milta hai aur FBR reporting (agar ON hai) apne mamool ke mutabiq chalti hai.',
            'Sale screen ke upar online/offline batti par pending bills ka number nazar aata hai — us par click kar ke aap khud bhi sync chala sakte hain.',
            'Offline receipt par sirf TOTAL chapta hai aur saaf likha hota hai ke yeh aarzi (provisional) receipt hai — asal receipt sync ke baad print ho sakti hai.',
            'Bill usi din, usi cashier aur usi branch ke naam par hi book hota hai — chahe sync agle din ho.',
            'Yeh feature package ke sath juda hai — agar aap ke package mein shamil nahi to offline hone par screen par ittila nazar aayegi.',
            'Ek aur behtari: sale screen ab ek dafa khulne ke baad device par mehfooz rehti hai — internet band hone par bhi screen khul jati hai (pehle se login hona zaroori hai).',
        ];

        foreach (['fbr_pos'] as $audience) {
            $exists = DB::table('app_updates')
                ->where('title', $this->title)
                ->where('audience', $audience)
                ->exists();
            if ($exists) {
                continue;
            }

            DB::table('app_updates')->insert([
                'title' => $this->title,
                'points' => json_encode($points, JSON_UNESCAPED_UNICODE),
                'image_path' => null,
                'audience' => $audience,
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
        DB::table('app_updates')
            ->where('title', $this->title)
            ->whereIn('audience', ['fbr_pos'])
            ->delete();
    }
};

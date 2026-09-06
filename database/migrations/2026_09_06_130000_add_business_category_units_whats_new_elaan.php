<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

// What's New elaan: product units (UoM) now follow the shop's business
// category in BOTH panels (PRA POS + FBR POS) — one server-side catalogue
// (PosUnitCatalog) behind every unit dropdown, grouped as "Aap ke business ke
// units" + "Baqi units". Audience 'all' because both layouts read it (the POS
// layout reads pos/all, the FBR layout reads fbr_pos/all).
//
// Data migration ON PURPOSE (same convention as the Offline Mode elaan): it
// must appear on live in the SAME deploy that ships the feature. Idempotent —
// skips when a row with the same title already exists.
return new class extends Migration
{
    private const TITLE = 'Units (UoM) ab aap ke business ke mutabiq: pharmacy ko STRIP, hotel ko NGT, gym ko MON';

    public function up(): void
    {
        if (!Schema::hasTable('app_updates')) {
            return; // base table migration not run yet (fresh installs run in order anyway)
        }

        if (\App\Models\AppUpdate::where('title', self::TITLE)->exists()) {
            return; // already announced (re-run / partial deploy)
        }

        // points passed as PHP ARRAY — never pre-encoded JSON (double-encode
        // incident 11 Aug 2026 500'd every pos-app page).
        \App\Models\AppUpdate::create([
            'title' => self::TITLE,
            'audience' => 'all',
            'points' => [
                'Product ka Unit (UoM) dropdown ab aap ke business type ke hisaab se khulta hai: pehla group "Aap ke business ke units" mein sirf aap ke kaam ke units, neeche "Baqi units" mein sab kuch pehle jaisa maujood.',
                'Misaal: pharmacy ko PCS/STRIP/TUBE/BTL, kiryana ko PCS/KG/GM/LTR, bakery ko PCS/KG/LB, kapray ko PCS/MTR/SUIT/PAIR, hotel ko NGT/DAY, gym ko MON/SES, catering/marquee ko HEAD, laundry ko PCS/KG, rent-a-car/cargo ko KM/TRIP, clinic/academy ko SES, general dukan ko poori goods list (U pehle).',
                'Naya product banate waqt unit khud-ba-khud aap ke business ka pehla unit hota hai (pharmacy = PCS, hotel = NGT, gym = MON) — chahein to badal lein.',
                'Purane products mein kuch nahi badla: jo unit pehle save tha (NOS, KGS waghera bhi) wohi select nazar aata hai aur waise hi save/sale hota hai.',
                'Naye units har jagah qabool hain: product form, sale screen ka quick-create aur line unit, stock quick-edit, return/edit, aur Excel import (Unit column mein NGT/TRIP/STRIP waghera likh sakte hain).',
                'Wazan/paimaish wale units (KG, GM, LB, LTR, ML, MTR, SQFT, KM, HR) mein decimal quantity aur Rs value se entry chalti hai; ginti wale units (PCS, STRIP, NGT) mein poore numbers hi lagte hain — pehle jaisa.',
                'Business type badalna admin panel se hota hai; badalte hi agli dafa page kholne par units ka group bhi badal jata hai — koi aur setting nahi.',
            ],
            'is_published' => true,
        ]);
    }

    public function down(): void
    {
        if (Schema::hasTable('app_updates')) {
            \App\Models\AppUpdate::where('title', self::TITLE)->delete();
        }
    }
};

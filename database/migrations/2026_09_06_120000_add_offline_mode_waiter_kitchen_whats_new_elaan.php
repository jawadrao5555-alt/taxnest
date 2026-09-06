<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

// What's New elaan for Offline Mode covering the waiter tablet and the kitchen
// (Sep 2026): during an internet outage the waiter page still opens from the
// shop PC, tables/orders keep showing, a new order reaches the counter's HELD
// list and the kitchen slip prints from the shop PC. Cloud-first design is
// unchanged — this elaan exists mainly to put the ONE clear sentence in front
// of every shop: "Net ho to cloud, net jaye to shop PC — rider apne mobile
// data par pehle jaisa."
//
// Data migration ON PURPOSE (same convention as the PRA-mode badge elaan): it
// must appear on live in the SAME deploy that ships the feature. Idempotent —
// skips when a row with the same title already exists.
return new class extends Migration
{
    private const POS_TITLE = 'Offline Mode: net jaye to bhi waiter aur kitchen ka kaam na ruke';

    public function up(): void
    {
        if (!Schema::hasTable('app_updates')) {
            return; // base table migration not run yet (fresh installs run in order anyway)
        }

        if (\App\Models\AppUpdate::where('title', self::POS_TITLE)->exists()) {
            return; // already announced (re-run / partial deploy)
        }

        // points passed as PHP ARRAY — never pre-encoded JSON (double-encode
        // incident 11 Aug 2026 500'd every pos-app page).
        \App\Models\AppUpdate::create([
            'title' => self::POS_TITLE,
            'audience' => 'pos',
            'points' => [
                'Ek jumla yaad rakhein: Net ho to cloud, net jaye to shop PC — rider apne mobile data par pehle jaisa.',
                'Internet chalta ho to sab kuch pehle jaisa taxnest.pk par chalta hai; shop ka PC bilkul idle rehta hai. Sirf net band hone par NestPOS Desktop (LAN Mode ON) shop WiFi par waiter tablet ka sahara banta hai.',
                'Waiter tablet: net band ho aur app dobara bhi kholi jaye to waiter page shop WiFi par khul jata hai, tables aur "meray orders" nazar aate hain (upar "shop PC se" ka nishan), aur naya order lagta hai. Pehle se khule order mein items add karna net wapas aane par hi hoga.',
                'Counter: waiter ka offline order foran HELD list mein aata hai (Shop PC ka label ke sath) — counter usay recall, settle ya delete kar sakta hai. Silent printing wali shops par kitchen slip (KOT) shop PC se usi waqt nikal jati hai.',
                'Net wapas aate hi wohi order sirf EK dafa cloud par banta hai — na duplicate order, na dobara KOT.',
                'Rider app mein kuch nahi badla: rider shop se bahar apne mobile data par cloud se hi juri rehti hai; shop PC ka LAN uske liye nahi hai.',
                'Yeh sab NestPOS Desktop ke "LAN Mode" switch ke sath chalta hai — agent window mein LAN Mode chalu karein aur waiter tablet ko ek dafa pair kar lein.',
            ],
            'is_published' => true,
        ]);
    }

    public function down(): void
    {
        if (Schema::hasTable('app_updates')) {
            \App\Models\AppUpdate::where('title', self::POS_TITLE)->delete();
        }
    }
};

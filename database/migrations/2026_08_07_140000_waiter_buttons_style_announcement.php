<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "What's New" elaan — Waiter Tablet ke liye naya 'Buttons' Style
 * (Task #340, Aug 2026). Audience 'pos' ONLY: waiter tablet restaurant
 * feature hai — FBR POS mein waiter tablets nahi hain.
 * Idempotent data migration — prod deploys run `migrate --force` (never seed).
 */
return new class extends Migration
{
    private string $title = 'Naya Waiter Style: Buttons (Bade Buttons wali Home Screen)';

    public function up(): void
    {
        if (!Schema::hasTable('app_updates')) {
            return;
        }

        $points = [
            'Waiter tablet par ab TEEN styles hain: Buttons (naya) | Saaf | Full. Har waiter apni APNI tablet ka style chunata hai — dukan ki setting nahi badalti.',
            'Buttons style kya hai: app khulte hi seedha bade bade tap karne wale buttons — har table ek alag button, uske saath running orders ki ginti aur kitni der se chal raha hai (⏱). Parcel ka alag amber button. Grid ya search nahi — sirf tap karein aur order shuru.',
            'Button rang se jaan lein: lal = table abhi chal raha hai (orders hain), hara = table khali hai, amber = Parcel/Takeaway order.',
            'Tap ka usool: occupied (lal) table tap karein to wahi purana action menu khulta hai (Items Add Karein / Table Shift). Khali table tap kare to seedha us table ka order shuru. Parcel tap kare to takeaway order.',
            'Order bhej kar screen khud button list par wapas aa jati hai — agli order ke liye dobara tap karein, scroll ya talash nahi.',
            'Style kaise badlein: waiter tablet kholen, ooper Buttons | Saaf | Full pill mein apni pasand tap karein. Sirf POS Waiter role walon ko yeh pill dikhti hai (admin/manager apna style panel se badaltey hain).',
            'Jo dukan restaurant/waiter feature use nahi karti: inka koi asar nahi — sirf waiter-role accounts ko dikhta hai.',
        ];

        foreach (['pos'] as $audience) {
            $exists = DB::table('app_updates')
                ->where('title', $this->title)
                ->where('audience', $audience)
                ->exists();
            if ($exists) {
                continue;
            }

            DB::table('app_updates')->insert([
                'title'        => $this->title,
                'points'       => json_encode($points, JSON_UNESCAPED_UNICODE),
                'image_path'   => null,
                'audience'     => $audience,
                'is_published' => true,
                'created_by'   => null,
                'created_at'   => now(),
                'updated_at'   => now(),
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
            ->whereIn('audience', ['pos'])
            ->delete();
    }
};

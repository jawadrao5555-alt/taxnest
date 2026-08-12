<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "What's New" elaan — Team page se staff Username (Task 529).
 *
 * Username login backend pehle se kaam karta tha, lekin admin kisi member ka
 * username set hi nahi kar sakta tha. Ab PRA POS (/pos/team) aur FBR POS
 * (/fbrpos/team) dono Team pages par username set/change hota hai.
 * Audience 'all': feature dono panels par ek jaisa hai.
 * Idempotent data migration — prod deploys run `migrate --force` (never seed).
 */
return new class extends Migration
{
    private string $title = 'Naya Feature: Staff ka Username — ab Team page se set karein';

    public function up(): void
    {
        if (!Schema::hasTable('app_updates')) {
            return;
        }

        $points = [
            'Ab aap apne staff (cashier, manager waghera) ke liye chhota username set kar sakte hain — login par lamba Gmail/email likhne ki zaroorat khatam.',
            'Set kaise karein: Team page → naya member banate waqt Username field bharein, ya kisi mojooda member ki edit (pencil) row se username set/tabdeel karein.',
            'Login kaise hoga: staff login page par email ki jagah sirf apna username likhe (maslan "cashier1") aur apna wohi purana password — seedha login.',
            'Team table mein har member ka username bhi nazar aata hai — jis ka set nahi, wahan dash (—) dikhta hai.',
            'Username mein space ya @ nahi ho sakta — sirf huroof, number, dot ya dash. Har username poore system mein sirf EK account ko mil sakta hai.',
            'Username set karna ikhtiyari hai — jo staff email se login karte hain, unke liye kuch tabdeel nahi hota.',
        ];

        foreach (['all'] as $audience) {
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
            ->whereIn('audience', ['all'])
            ->delete();
    }
};

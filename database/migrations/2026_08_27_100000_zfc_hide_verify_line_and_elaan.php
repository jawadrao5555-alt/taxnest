<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task 765 — PRA receipt: "Sahulat App" line optional karo, ZFC pe off.
 *
 * 1. ZFC ka invoice_display_prefs['pos']['show_verify_line'] = false karo.
 *    Company: zfclodhran@gmail.com (fallback NTN 8071541-8).
 * 2. What's New elaan (app_updates) Roman Urdu mein bataye ke yeh setting
 *    ab Receipt Settings → PRA receipt section mein milti hai.
 *
 * Idempotent: safe to run on prod via `migrate --force`.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. ZFC ka verify line off karo ───────────────────────────────────
        if (Schema::hasTable('companies')) {
            $zfc = DB::table('companies')
                ->where('email', 'zfclodhran@gmail.com')
                ->orWhere('ntn', '8071541-8')
                ->first();

            if ($zfc) {
                $prefs = [];
                if (!empty($zfc->invoice_display_prefs)) {
                    $decoded = json_decode($zfc->invoice_display_prefs, true);
                    if (is_array($decoded)) {
                        $prefs = $decoded;
                    }
                }
                // Existing PRA prefs sath rakhein, sirf show_verify_line off karein.
                if (!isset($prefs['pos']) || !is_array($prefs['pos'])) {
                    $prefs['pos'] = [];
                }
                $prefs['pos']['show_verify_line'] = false;

                DB::table('companies')
                    ->where('id', $zfc->id)
                    ->update([
                        'invoice_display_prefs' => json_encode($prefs, JSON_UNESCAPED_UNICODE),
                        'updated_at' => now(),
                    ]);
            }
        }

        // ── 2. What's New elaan ───────────────────────────────────────────────
        if (!Schema::hasTable('app_updates')) {
            return;
        }
        $title = 'Receipt par "Sahulat App" line ab optional hai';
        if (DB::table('app_updates')->where('title', $title)->exists()) {
            return;
        }
        DB::table('app_updates')->insert([
            'title' => $title,
            'points' => json_encode([
                'Ab aap PRA receipt par QR ke neeche "Scan with PRA Sahulat App to verify" wali line band kar sakte hain.',
                'Yeh setting Receipt Settings mein PRA (fiscal) receipt section mein milegi — "Show Sahulat App scan line" toggle.',
                'Default ON hai taakey baqi sab shops par koi farq na pade. Sirf wo shops band karein jo yeh line nahi chahte.',
                'QR code aur PRA Fiscal number pehle ki tarah hi chaptay rahein ge — sirf yeh ek line optional hai.',
            ], JSON_UNESCAPED_UNICODE),
            'audience' => 'pos',
            'is_published' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // ZFC ka show_verify_line wapas hata dein (revert to absent = default ON).
        if (Schema::hasTable('companies')) {
            $zfc = DB::table('companies')
                ->where('email', 'zfclodhran@gmail.com')
                ->orWhere('ntn', '8071541-8')
                ->first();

            if ($zfc) {
                $prefs = [];
                if (!empty($zfc->invoice_display_prefs)) {
                    $decoded = json_decode($zfc->invoice_display_prefs, true);
                    if (is_array($decoded)) {
                        $prefs = $decoded;
                    }
                }
                if (isset($prefs['pos']['show_verify_line'])) {
                    unset($prefs['pos']['show_verify_line']);
                }
                DB::table('companies')
                    ->where('id', $zfc->id)
                    ->update([
                        'invoice_display_prefs' => json_encode($prefs, JSON_UNESCAPED_UNICODE),
                        'updated_at' => now(),
                    ]);
            }
        }

        if (Schema::hasTable('app_updates')) {
            DB::table('app_updates')
                ->where('title', 'Receipt par "Sahulat App" line ab optional hai')
                ->delete();
        }
    }
};

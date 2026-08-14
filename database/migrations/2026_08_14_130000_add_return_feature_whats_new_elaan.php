<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

// What's New elaan for the counter-sale Return / Credit Note feature
// (Task 678 + owner's 14 Aug 2026 tabdeeliyan: qty prefill, day-close lock,
// FBR list Return button, FBR 15-din window).
//
// Data migration ON PURPOSE: the elaan must appear on live in the SAME deploy
// that ships the feature (prod runs `migrate --force` on deploy — see the
// pricing-reprice convention). Idempotent: skips rows whose title already
// exists. Screenshot assets ship in resources/whats-new/ and are copied to
// the public disk here (per-row guarded; storage symlink already exists).
return new class extends Migration
{
    private const POS_TITLE = 'Naya Feature: Bills list se seedha Return / Credit Note';
    private const FBR_TITLE = 'Naya Feature: Receipts list se seedha Return (15 din ke andar)';

    public function up(): void
    {
        if (!Schema::hasTable('app_updates')) {
            return; // base table migration not run yet (fresh installs run in order anyway)
        }

        $rows = [
            [
                'title' => self::POS_TITLE,
                'audience' => 'pos',
                'image' => 'return-elaan-pos.png',
                'points' => [
                    "Bills page par har bill ke saath naya 'Return' button — bill kholne ki zaroorat nahi, wahin se return bana dein.",
                    'Local bills bhi ab return ho sakte hain — aisa return bhi local hi rehta hai, PRA ko kuch report nahi hota.',
                    'Return form mein har item ki poori baqi miqdaar pehle se bhari aati hai — poora bill return karna ho to kuch badalne ki zaroorat nahi, partial ke liye sirf miqdaar kam kar dein.',
                    'Din band (Day Close) hone ke baad us din ke bills return nahi ho sakte — hisaab settle ho jata hai.',
                    "Team page → Custom access mein naya 'Return / Credit Note' ikhtiyar — jis staff ko tick karein wahi return bana sakta hai (owner/manager hamesha bana sakte hain).",
                ],
            ],
            [
                'title' => self::FBR_TITLE,
                'audience' => 'fbr_pos',
                'image' => 'return-elaan-fbr.png',
                'points' => [
                    "Receipts page par har bill ke saath naya 'Return' button — wahin se seedha return shuru karein.",
                    'Bill ke apne page par bhi Return ka button mil jayega.',
                    'Return sirf 15 din ke andar ho sakta hai — 15 din se purane bill par Return ka button nahi ayega.',
                ],
            ],
        ];

        $hasImageCol = Schema::hasColumn('app_updates', 'image_path');

        foreach ($rows as $row) {
            if (\App\Models\AppUpdate::where('title', $row['title'])->exists()) {
                continue; // already announced (re-run / partial deploy)
            }

            $imagePath = null;
            if ($hasImageCol) {
                $src = resource_path('whats-new/' . $row['image']);
                $destDir = storage_path('app/public/app-updates');
                if (File::exists($src)) {
                    try {
                        File::ensureDirectoryExists($destDir);
                        File::copy($src, $destDir . '/' . $row['image']);
                        $imagePath = 'app-updates/' . $row['image'];
                    } catch (\Throwable $e) {
                        $imagePath = null; // elaan without image beats no elaan
                    }
                }
            }

            // points passed as PHP ARRAY — never pre-encoded JSON (double-encode
            // incident 11 Aug 2026 500'd every pos-app page).
            \App\Models\AppUpdate::create([
                'title' => $row['title'],
                'points' => $row['points'],
                'audience' => $row['audience'],
                'is_published' => true,
            ] + ($hasImageCol ? ['image_path' => $imagePath] : []));
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('app_updates')) {
            return;
        }
        \App\Models\AppUpdate::whereIn('title', [self::POS_TITLE, self::FBR_TITLE])->delete();
    }
};

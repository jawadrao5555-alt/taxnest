<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "What's New" elaan — software update ab kabhi khud reload nahi karta (Aug 2026).
 * Applies to both PRA POS and FBR POS panels (audience 'all').
 * Idempotent data migration — prod deploys run `migrate --force` (never seed).
 */
return new class extends Migration
{
    private string $title = 'Update ab aap ki marzi se — screen khud kabhi reload nahi hogi';

    public function up(): void
    {
        if (!Schema::hasTable('app_updates')) {
            return;
        }

        if (!DB::table('app_updates')->where('title', $this->title)->where('audience', 'all')->exists()) {
            DB::table('app_updates')->insert([
                'title' => $this->title,
                'points' => json_encode([
                    'Pehle naya update aane par screen 30 second baad khud reload ho jati thi — chalti sale disturb hoti thi. Ab aisa kabhi nahi hoga.',
                    'Naya update aane par sirf upar refresh icon par "!" ka nishaan jale ga — software us waqt update ho ga jab aap khud us button ko dabayen ge, farigh waqt mein.',
                    'Update ka chhota notice agar aaye tou "Refresh" dabane se foran update lag jata hai, aur band (dismiss) karne par dobara tang nahi karta.',
                ], JSON_UNESCAPED_UNICODE),
                'image_path' => null,
                'audience' => 'all',
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
        DB::table('app_updates')->where('title', $this->title)->where('audience', 'all')->delete();
    }
};

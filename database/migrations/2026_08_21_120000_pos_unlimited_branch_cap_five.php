<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PRA POS: Unlimited package ab 5 branches (owner-approved, 21 Aug 2026).
 *
 * Har package apne card par likhi branches MUFT deta hai — Starter 1,
 * Business 1, Pro 2, Pro Max 3 — aur Unlimited, jiska branch_limit ab tak -1
 * ("unlimited") tha, ab 5. Us se ooper har branch Rs 10,000 saalana ka paid
 * add-on hai (BranchAddonService), warna branch feature live hote hi Unlimited
 * wali shop bila hisaab muft branches bana leti.
 *
 * Sirf Unlimited ki qatar chhui jati hai — baqi packages ki maujooda tadaad
 * (1/1/2/3) waisi hi rehti hai, aur koi qeemat/miyaad/subscription row nahi
 * badalti.
 *
 * Idempotent: product_type + name se keyed pure UPDATE; dobara chalne par
 * wahi natija (prod deploys `migrate --force` chalate hain, kabhi seed nahi).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('pricing_plans') || !Schema::hasColumn('pricing_plans', 'branch_limit')) {
            return;
        }

        // Schema-tolerant (pricing-ladder migration pattern): sirf wahi columns
        // likhein jo is waqt mojood hain — fresh sqlite chain / drifted prod par
        // missing column skip ho, crash na kare.
        $existingCols = array_flip(Schema::getColumnListing('pricing_plans'));

        $update = ['branch_limit' => 5];

        if (isset($existingCols['features'])) {
            // Card bullet ab branch_limit se ulat nahi kehta ("unlimited branches").
            $update['features'] = json_encode([
                'Team Custom Access — per-user permissions',
                'Unlimited bills, team accounts & products',
                '5 branches included — extra branches Rs 10,000/year each',
                'Priority support',
            ]);
        }

        $updated = DB::table('pricing_plans')
            ->where('product_type', 'pos')
            ->where('name', 'Unlimited')
            ->update($update);

        if (!$updated) {
            // Row renamed/deleted — guess mat karo, sirf loudly log karo.
            logger()->warning('Unlimited branch cap migration: pos plan "Unlimited" not found or already at 5 — skipped.');
        }
    }

    public function down(): void
    {
        // Business decision — koi automatic rollback nahi.
    }
};

<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Camera-ready state for the Physical Stock Check tutorial.
 *
 * Runs on top of VideoDemoShopSeeder's "Al-Noor General Store". Two jobs:
 *
 * 1. GIVE THE SHELF BELIEVABLE NUMBERS. The demo shop drifts into negative
 *    quantities as other recordings sell from it, and a sheet full of "-95"
 *    teaches the viewer nothing.
 *
 * 2. UNDO WHATEVER THE LAST TAKE CREATED. The scenario opens a sheet, counts
 *    on camera and posts it. Without this reset the next take starts with a
 *    finished check already in the list, and the narration lies.
 */
class VideoStockCheckSeeder extends Seeder
{
    private const COMPANY_NAME = 'Al-Noor General Store';
    private const LOGIN_EMAIL = 'videodemo@nestpos.pk';

    /**
     * Shelf quantities the camera sees. Chosen so the sheet reads like a real
     * kirana store: mostly whole cartons, a couple of slow movers.
     */
    private const STOCK = [
        'Aata 10kg' => 24,
        'Anday (Darjan)' => 18,
        'Biscuit Family Pack' => 40,
        'Chai Patti 950g' => 29,
        'Chawal Basmati 5kg' => 16,
        'Cheeni 1kg' => 35,
        'Chocolate Bar' => 60,
        'Cooking Oil 1L' => 39,
        'Daal Chana 1kg' => 45,
        'Dahi 500g' => 19,
        'Doodh 1L' => 30,
        'Lays Chips' => 72,
        'Lifebuoy Soap' => 50,
        'Mineral Water 1.5L' => 52,
        'Nimko 250g' => 26,
        'Pepsi 1.5L' => 48,
        'Shampoo Sachet (12)' => 12,
        'Surf Excel 1kg' => 33,
    ];

    public function run(): void
    {
        $companyId = $this->resolveDemoCompanyId();
        if (!$companyId) return;

        DB::transaction(function () use ($companyId) {
            $this->enableInventory($companyId);
            $this->resetShelf($companyId);
            $this->clearCameraMadeChecks($companyId);
            $this->silenceNagPopups($companyId);
        });

        $this->command?->info("Stock check demo state ready (company_id={$companyId}).");
    }

    /**
     * Fail closed. Everything below rewrites stock and deletes ledger rows, so
     * it must be impossible to point at a real shop. "Al-Noor General Store"
     * is an entirely plausible name for a genuine customer, so the name alone
     * proves nothing — the row also has to be the internal account that owns
     * the canonical demo login, and we must not be on the production database.
     */
    private function resolveDemoCompanyId(): ?int
    {
        // APP_ENV is 'production' even in this workspace, so it proves nothing.
        // Require the recording pipeline to opt in explicitly instead: a stray
        // `db:seed --class=VideoStockCheckSeeder` anywhere else does nothing.
        if (!filter_var(env('VIDEO_PIPELINE_ALLOW', false), FILTER_VALIDATE_BOOLEAN)) {
            $this->command?->error('Refusing to run: set VIDEO_PIPELINE_ALLOW=1 (recording pipeline only).');
            return null;
        }

        $company = DB::table('companies')->where('name', self::COMPANY_NAME)->first();
        if (!$company) {
            $this->command?->error('Video demo shop not found — run VideoDemoShopSeeder first.');
            return null;
        }

        if (!($company->is_internal_account ?? false)) {
            $this->command?->error("Refusing to run: company #{$company->id} is not an internal account.");
            return null;
        }

        $ownsDemoLogin = DB::table('users')
            ->where('company_id', $company->id)
            ->where('email', self::LOGIN_EMAIL)
            ->exists();

        if (!$ownsDemoLogin) {
            $this->command?->error("Refusing to run: company #{$company->id} does not own " . self::LOGIN_EMAIL . '.');
            return null;
        }

        return (int) $company->id;
    }

    /**
     * Inventory has TWO switches — the feature flag and the column the gates
     * actually read. Flipping one leaves the page 404-ing on camera.
     */
    private function enableInventory(int $companyId): void
    {
        $company = DB::table('companies')->find($companyId);
        $flags = json_decode((string) ($company->feature_flags ?? '{}'), true) ?: [];
        $flags['inventory'] = true;

        DB::table('companies')->where('id', $companyId)->update([
            'inventory_enabled' => 1,
            'feature_flags' => json_encode($flags),
            'updated_at' => now(),
        ]);
    }

    /** Put honest, positive quantities back on the shelf. */
    private function resetShelf(int $companyId): void
    {
        foreach (self::STOCK as $name => $qty) {
            $productId = (int) DB::table('pos_products')
                ->where('company_id', $companyId)->where('name', $name)->value('id');
            if (!$productId) continue;

            // The demo shop has no branches, so stock lives on the NULL row.
            $exists = DB::table('inventory_stocks')
                ->where('company_id', $companyId)->where('product_id', $productId)
                ->whereNull('branch_id')->exists();

            $exists
                ? DB::table('inventory_stocks')
                    ->where('company_id', $companyId)->where('product_id', $productId)
                    ->whereNull('branch_id')
                    ->update(['quantity' => $qty, 'updated_at' => now()])
                : DB::table('inventory_stocks')->insert([
                    'company_id' => $companyId,
                    'product_id' => $productId,
                    'branch_id' => null,
                    'quantity' => $qty,
                    'min_stock_level' => 10,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            // Any leftover branch rows would make the company total disagree
            // with what the sheet shows.
            DB::table('inventory_stocks')
                ->where('company_id', $companyId)->where('product_id', $productId)
                ->whereNotNull('branch_id')->delete();

            DB::table('pos_products')->where('id', $productId)
                ->whereNotNull('stock_quantity')
                ->update(['stock_quantity' => $qty]);
        }
    }

    /**
     * The #1 recording blocker: a full-screen modal that swallows the first
     * click of every take. Two of them can fire — "What's New" and the POS
     * survey — and the survey is chained to the What's New dismissal, so both
     * have to be marked done before the camera rolls.
     */
    private function silenceNagPopups(int $companyId): void
    {
        $userIds = DB::table('users')->where('company_id', $companyId)->pluck('id');
        if ($userIds->isEmpty()) return;

        // One-shot announcement modals stamp a "seen at" column on the user.
        foreach (['pra_elaan_seen_at'] as $seenColumn) {
            if (Schema::hasColumn('users', $seenColumn)) {
                DB::table('users')->whereIn('id', $userIds)->whereNull($seenColumn)
                    ->update([$seenColumn => now()]);
            }
        }

        foreach ($userIds as $userId) {
            if (Schema::hasTable('app_updates') && Schema::hasTable('app_update_seens')) {
                $seen = DB::table('app_update_seens')->where('user_id', $userId)->pluck('app_update_id');
                foreach (DB::table('app_updates')->whereNotIn('id', $seen)->pluck('id') as $updateId) {
                    DB::table('app_update_seens')->insert([
                        'app_update_id' => $updateId,
                        'user_id' => $userId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            if (Schema::hasTable('surveys') && Schema::hasTable('survey_responses')) {
                $open = DB::table('surveys')->where('is_published', 1)->whereNull('closed_at')->pluck('id');
                foreach ($open as $surveyId) {
                    $answered = DB::table('survey_responses')
                        ->where('survey_id', $surveyId)->where('user_id', $userId)
                        ->whereNotNull('answered_at')->exists();
                    if ($answered) continue;

                    DB::table('survey_responses')->insert([
                        'survey_id' => $surveyId,
                        'user_id' => $userId,
                        'company_id' => $companyId,
                        'answers' => json_encode([]),
                        'answered_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    /** Delete the sheets (and their corrections) the last take recorded. */
    private function clearCameraMadeChecks(int $companyId): void
    {
        if (!Schema::hasTable('stock_checks')) return;

        $ids = DB::table('stock_checks')->where('company_id', $companyId)->pluck('id');
        if ($ids->isEmpty()) return;

        DB::table('inventory_movements')
            ->where('company_id', $companyId)
            ->where('reference_type', 'stock_check')
            ->whereIn('reference_id', $ids)->delete();

        if (Schema::hasTable('ingredient_movements')) {
            DB::table('ingredient_movements')
                ->where('company_id', $companyId)
                ->where('reference_type', 'stock_check')
                ->whereIn('reference_id', $ids)->delete();
        }

        DB::table('stock_check_lines')->whereIn('stock_check_id', $ids)->delete();
        DB::table('stock_checks')->whereIn('id', $ids)->delete();
    }
}

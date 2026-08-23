<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner instruction (Sep 2026): NO Digital Invoice shop may be left sitting on
 * a package that is no longer sold.
 *
 * The restructure migration moved the shops that were on the old named plans,
 * but three cases survived it:
 *   1. an active subscription with NO plan at all (pricing_plan_id NULL)
 *   2. a shop still on the legacy Premium row
 *   3. anything else pointing at a retired (hidden) DI package
 *
 * All of them land on Kaarobar, the middle package. Dates, price paid and any
 * admin grant on the row are left exactly as they are — this repoints the
 * package, it does not sell, renew or cancel anything.
 *
 * DELIBERATELY NOT TOUCHED: shops on the free Trial. A trial is part of the
 * current structure, not a retired package, and converting one would hand out
 * a paid package for free and break its expiry.
 *
 * Idempotent: after it runs, the WHERE clause matches nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('subscriptions') || !Schema::hasTable('pricing_plans')) {
            return;
        }

        $kaarobar = DB::table('pricing_plans')
            ->where('product_type', 'di')
            ->where('name', 'Kaarobar')
            ->value('id');

        if (!$kaarobar) {
            // Restructure migration has not run on this database yet; nothing
            // to move onto a package that does not exist.
            return;
        }

        $hasIsPublic = Schema::hasColumn('pricing_plans', 'is_public');

        // Every DI package a shop may NOT keep: retired rows, and anything
        // that is not a DI package at all (a NULL plan on a DI company).
        $retiredPlanIds = DB::table('pricing_plans')
            ->where('product_type', 'di')
            ->where('is_trial', false)
            ->when($hasIsPublic, fn ($q) => $q->where('is_public', false))
            ->when(!$hasIsPublic, fn ($q) => $q->whereNotIn('name', ['Asaan', 'Kaarobar', 'Unlimited']))
            ->pluck('id')
            ->all();

        $diCompanyIds = DB::table('companies')
            ->where(function ($q) {
                $q->where('product_type', 'di')->orWhereNull('product_type');
            })
            ->pluck('id');

        if ($diCompanyIds->isEmpty()) {
            return;
        }

        $stale = fn ($q) => $q->where('active', true)
            ->whereIn('company_id', $diCompanyIds)
            ->where(function ($inner) use ($retiredPlanIds) {
                $inner->whereNull('pricing_plan_id');
                if ($retiredPlanIds) {
                    $inner->orWhereIn('pricing_plan_id', $retiredPlanIds);
                }
            });

        $stale(DB::table('subscriptions'))->update([
            'pricing_plan_id' => $kaarobar,
            'updated_at' => now(),
        ]);

        // Per-company limit overrides are LEFT ALONE on purpose. An override
        // is an admin arrangement that rides on the company, not a copy of the
        // package ceiling, so wiping it here would quietly tighten a paying
        // shop's caps mid-term. Repointing the package is the whole job.
    }

    public function down(): void
    {
        // One-way data correction: the previous package of each shop is not
        // recoverable from the row, and re-splitting them would be guesswork.
    }
};

<?php

namespace App\Services\Pharmacy;

use App\Jobs\SyncMedicineCatalogueJob;
use App\Models\MedicineCatalogueEntry;
use App\Models\MedicineCataloguePrice;
use App\Models\MedicineCatalogueSync;
use App\Models\MedicinePriceNotice;
use App\Models\Product;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * The DRAP catalogue crawl + the rules every catalogue writer shares (Task 1579).
 *
 *  • start()        — idempotent: pressing "Sync" twice never runs two crawls;
 *                     a stalled run is resumed from its own cursor, a failed
 *                     one hands its cursor to the fresh run.
 *  • runSlice()     — walks pages sequentially for a time budget (the DB queue
 *                     has retry_after 90s, so a job does ~55s and re-queues
 *                     itself); every page is committed before the next fetch.
 *  • upsertRow()    — ONE idempotent write path keyed on reg no × pack × maker;
 *                     an MRP change always leaves a history row and raises a
 *                     notice for every shop product linked to that catalogue row.
 *  • applyNotice()  — the only way a shop's MRP follows the catalogue.
 */
class MedicineCatalogueSyncService
{
    /** Job time budget (seconds) — below the DB queue's 90s retry_after with headroom. */
    public const SLICE_SECONDS = 55;

    public function __construct(
        private readonly DrapPriceIndexClient $client,
        private readonly MedicineCompositionParser $parser,
    ) {
    }

    /**
     * Crawl plan. DRAP's unfiltered index contains every category (Essential
     * 1,025 pages + Low Price 45 pages ≈ the 1,069 unfiltered pages), so one
     * phase covers the whole list; the structure stays so a filter can be
     * added without touching the cursor logic.
     *
     * @return array<int,array{label:string,filters:array<string,string>}>
     */
    public static function phases(): array
    {
        return [
            ['label' => 'All categories', 'filters' => []],
        ];
    }

    public static function tablesReady(): bool
    {
        return Schema::hasTable('medicine_catalogue')
            && Schema::hasTable('medicine_catalogue_prices')
            && Schema::hasTable('medicine_catalogue_syncs');
    }

    // ────────────────────────────────────────────────────────────────────
    //  Run lifecycle
    // ────────────────────────────────────────────────────────────────────

    /**
     * Start (or resume) a crawl. Returns the run that now owns the work.
     *
     * @param  bool  $dispatch  false = caller will drive runSlice() itself (CLI --sync)
     */
    public function start(string $trigger = 'manual', ?int $startedBy = null, bool $dispatch = true): MedicineCatalogueSync
    {
        $latest = MedicineCatalogueSync::latest('id')->first();

        if ($latest && $latest->isActive()) {
            if (!$latest->isStale()) {
                return $latest; // safe to press twice
            }
            // Worker died mid-run (deploy, OOM, restart): continue THIS run
            // from its own cursor instead of starting page 1 again.
            $latest->forceFill(['state' => 'running', 'last_progress_at' => now(), 'last_error' => 'resumed after stall'])->save();
            if ($dispatch) {
                SyncMedicineCatalogueJob::dispatch($latest->id);
            }

            return $latest;
        }

        $attrs = [
            'state' => 'queued',
            'trigger' => $trigger,
            'started_by' => $startedBy,
            'phase_index' => 0,
            'next_page' => 1,
            'started_at' => now(),
            'last_progress_at' => now(),
        ];
        // A failed run from the last day hands over its cursor — the pages it
        // already walked are upserted and need no second visit this week.
        if ($latest && $latest->state === 'failed'
            && $latest->updated_at && $latest->updated_at->gt(now()->subDay())) {
            $attrs['phase_index'] = (int) $latest->phase_index;
            $attrs['next_page'] = max(1, (int) $latest->next_page);
            $attrs['total_pages'] = $latest->total_pages;
            $attrs['pages_done'] = (int) $latest->pages_done;
            $attrs['started_at'] = $latest->started_at ?? now();
        }

        $run = MedicineCatalogueSync::create($attrs);
        if ($dispatch) {
            SyncMedicineCatalogueJob::dispatch($run->id);
        }

        return $run;
    }

    public function requestCancel(MedicineCatalogueSync $run): void
    {
        if ($run->isActive()) {
            $run->forceFill(['cancel_requested' => true])->save();
        }
    }

    /**
     * Work for up to $budgetSeconds, page by page. Returns true when the whole
     * crawl is over (completed, cancelled or failed) — false means "more to do".
     */
    public function runSlice(MedicineCatalogueSync $run, int $budgetSeconds = self::SLICE_SECONDS, ?float $pageDelay = null): bool
    {
        $pageDelay ??= DrapPriceIndexClient::PAGE_DELAY_SECONDS;
        $phases = self::phases();
        $deadline = microtime(true) + $budgetSeconds;

        $run->refresh();
        if (!$run->isActive()) {
            return true;
        }
        if ($run->state !== 'running') {
            $run->forceFill(['state' => 'running', 'started_at' => $run->started_at ?? now()])->save();
        }

        while (true) {
            $run->refresh();
            if ($run->cancel_requested) {
                $run->forceFill(['state' => 'cancelled', 'completed_at' => now(), 'last_progress_at' => now()])->save();

                return true;
            }
            if ($run->phase_index >= count($phases)) {
                $this->finish($run);

                return true;
            }

            $phase = $phases[$run->phase_index];
            $page = max(1, (int) $run->next_page);

            // Never begin a page the slice cannot finish: a slow DRAP response
            // would outlive the job's own timeout and fail the whole run.
            if (microtime(true) + 8 >= $deadline) {
                return false;
            }

            try {
                $result = $this->client->fetchPage($page, $phase['filters'], $deadline);
            } catch (\Throwable $e) {
                // One page failing repeatedly must not spin forever: count it,
                // remember the message, and fail the run after a handful so a
                // human sees an honest "failed at page N" instead of "running".
                $errors = (int) $run->errors_count + 1;
                $run->forceFill([
                    'errors_count' => $errors,
                    'last_error' => mb_substr('page ' . $page . ': ' . $e->getMessage(), 0, 1000),
                    'last_progress_at' => now(),
                ])->save();
                Log::warning('[medicine-catalogue] DRAP page fetch failed', ['page' => $page, 'error' => $e->getMessage()]);
                if ($errors >= 8) {
                    $run->forceFill(['state' => 'failed', 'completed_at' => now()])->save();

                    return true;
                }
                sleep(3);
                if (microtime(true) >= $deadline) {
                    return false;
                }
                continue;
            }

            $stats = $this->ingestPage($result['rows'], $run);

            $totalPages = $result['total_pages'] ?? $run->total_pages;
            $update = [
                'total_pages' => $totalPages,
                'pages_done' => (int) $run->pages_done + 1,
                'rows_seen' => (int) $run->rows_seen + $stats['seen'],
                'rows_created' => (int) $run->rows_created + $stats['created'],
                'rows_updated' => (int) $run->rows_updated + $stats['updated'],
                'price_changes' => (int) $run->price_changes + $stats['price_changes'],
                'last_progress_at' => now(),
            ];

            $phaseOver = ($totalPages !== null && $page >= (int) $totalPages) || empty($result['rows']);
            if ($phaseOver) {
                $update['phase_index'] = (int) $run->phase_index + 1;
                $update['next_page'] = 1;
                $update['total_pages'] = null;
            } else {
                $update['next_page'] = $page + 1;
            }
            $run->forceFill($update)->save();

            if ($phaseOver && $update['phase_index'] >= count($phases)) {
                $this->finish($run);

                return true;
            }

            if (microtime(true) + $pageDelay >= $deadline) {
                return false;
            }
            if ($pageDelay > 0) {
                usleep((int) ($pageDelay * 1_000_000));
            }
        }
    }

    private function finish(MedicineCatalogueSync $run): void
    {
        // Rows DRAP no longer lists are retired, never deleted — shop products
        // keep pointing at them and history stays readable. Only a COMPLETED
        // crawl may judge absence; a partial one has simply not looked yet.
        $retired = 0;
        try {
            if ($run->started_at && (int) $run->rows_seen > 1000) {
                $retired = MedicineCatalogueEntry::where('source', MedicineCatalogueEntry::SOURCE_DRAP)
                    ->where('is_active', true)
                    ->where(function ($q) use ($run) {
                        $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $run->started_at);
                    })->update(['is_active' => false]);
            }
        } catch (\Throwable $e) {
            Log::warning('[medicine-catalogue] retire pass failed: ' . $e->getMessage());
        }

        $run->forceFill([
            'state' => 'completed',
            'completed_at' => now(),
            'last_progress_at' => now(),
            'last_error' => $retired > 0 ? ('retired ' . $retired . ' rows no longer listed') : $run->last_error,
        ])->save();
    }

    // ────────────────────────────────────────────────────────────────────
    //  Row writes
    // ────────────────────────────────────────────────────────────────────

    /**
     * @param  array<int,array<string,mixed>>  $rows  parsed DRAP rows
     * @return array{seen:int,created:int,updated:int,price_changes:int}
     */
    public function ingestPage(array $rows, ?MedicineCatalogueSync $run = null): array
    {
        $stats = ['seen' => 0, 'created' => 0, 'updated' => 0, 'price_changes' => 0];
        foreach ($rows as $row) {
            $stats['seen']++;
            try {
                $r = DB::transaction(fn () => $this->upsertRow($row, MedicineCatalogueEntry::SOURCE_DRAP, $run?->id));
                $stats[$r['outcome']] = ($stats[$r['outcome']] ?? 0) + 1;
                if ($r['price_changed']) {
                    $stats['price_changes']++;
                }
            } catch (\Throwable $e) {
                Log::warning('[medicine-catalogue] row upsert failed', ['row' => $row, 'error' => $e->getMessage()]);
            }
        }

        return $stats;
    }

    /**
     * Idempotent upsert of one catalogue row.
     *
     * @param  array<string,mixed>  $row  brand_name, composition, drap_reg_no, manufacturer,
     *                                    manufacturer_licence, category_label|category, pack_size, mrp, effective_date
     * @param  bool  $overwriteMrp  false = an existing DRAP-sourced MRP is never touched by a
     *                              non-DRAP writer (admin import default)
     * @return array{entry:MedicineCatalogueEntry,outcome:string,price_changed:bool}
     */
    public function upsertRow(array $row, string $source = MedicineCatalogueEntry::SOURCE_DRAP, ?int $syncId = null, bool $overwriteMrp = true): array
    {
        $brand = trim((string) ($row['brand_name'] ?? ''));
        $regNo = trim((string) ($row['drap_reg_no'] ?? ''));
        $pack = trim((string) ($row['pack_size'] ?? ''));
        $maker = trim((string) ($row['manufacturer'] ?? ''));
        if ($brand === '') {
            throw new \InvalidArgumentException('brand_name required');
        }
        // A manual/import row without a registration number is keyed on the
        // brand instead — still one row per brand × pack × maker.
        $key = MedicineCatalogueEntry::dedupeKey($regNo !== '' ? $regNo : ('brand:' . mb_strtolower($brand)), $pack, $maker);

        $category = isset($row['category']) && $row['category'] !== null && $row['category'] !== ''
            ? MedicineCatalogueEntry::categoryFromLabel((string) $row['category'])
            : MedicineCatalogueEntry::categoryFromLabel($row['category_label'] ?? null);

        $mrp = $row['mrp'] ?? null;
        $mrp = ($mrp === null || $mrp === '') ? null : round((float) $mrp, 2);
        $effective = $row['effective_date'] ?? null;
        $effective = $effective ? substr((string) $effective, 0, 10) : null;

        $checksum = sha1(implode('|', [$brand, (string) ($row['composition'] ?? ''), $regNo, $maker,
            (string) ($row['manufacturer_licence'] ?? ''), $category, $pack, (string) $mrp, (string) $effective]));

        $entry = MedicineCatalogueEntry::where('dedupe_key', $key)->lockForUpdate()->first();
        $now = now();

        if (!$entry) {
            $parsed = $this->parser->parse($brand, $row['composition'] ?? null, $pack);
            $entry = MedicineCatalogueEntry::create([
                'brand_name' => mb_substr($brand, 0, 250),
                'composition' => $row['composition'] ?? null,
                'generic_name' => $row['generic_name'] ?? $parsed['generic_name'],
                'strength' => $row['strength'] ?? $parsed['strength'],
                'dosage_form' => $row['dosage_form'] ?? $parsed['dosage_form'],
                'manufacturer' => $maker !== '' ? mb_substr($maker, 0, 250) : null,
                'manufacturer_licence' => $row['manufacturer_licence'] ?? null,
                'drap_reg_no' => $regNo !== '' ? mb_substr($regNo, 0, 40) : null,
                'category' => $category,
                'pack_size' => $pack !== '' ? mb_substr($pack, 0, 160) : null,
                'mrp' => $mrp,
                'effective_date' => $effective,
                'source' => $source,
                'is_active' => true,
                'dedupe_key' => $key,
                'checksum' => $checksum,
                'last_seen_at' => $source === MedicineCatalogueEntry::SOURCE_DRAP ? $now : null,
            ]);
            if ($mrp !== null) {
                // First sighting also lands in history so "when did we first
                // see this price" is answerable — no notices (nothing linked).
                MedicineCataloguePrice::create([
                    'catalogue_id' => $entry->id, 'old_mrp' => null, 'new_mrp' => $mrp,
                    'old_effective_date' => null, 'effective_date' => $effective,
                    'source' => $source, 'sync_id' => $syncId, 'created_at' => $now,
                ]);
            }

            return ['entry' => $entry, 'outcome' => 'created', 'price_changed' => false];
        }

        $priceChanged = false;
        $update = [];
        if ($source === MedicineCatalogueEntry::SOURCE_DRAP) {
            $update['last_seen_at'] = $now;
            if (!$entry->is_active) {
                $update['is_active'] = true;
            }
        }

        if ($entry->checksum === $checksum && $source === MedicineCatalogueEntry::SOURCE_DRAP) {
            // Unchanged row — touch last_seen_at only.
            $entry->forceFill($update)->save();

            return ['entry' => $entry, 'outcome' => 'unchanged', 'price_changed' => false];
        }

        // Descriptive fields follow the source; admin-corrected parsed fields
        // are kept (only filled when still empty).
        $update['brand_name'] = mb_substr($brand, 0, 250);
        if (($row['composition'] ?? null) !== null && (string) $row['composition'] !== '') {
            $update['composition'] = $row['composition'];
        }
        if ($maker !== '') {
            $update['manufacturer'] = mb_substr($maker, 0, 250);
        }
        if (!empty($row['manufacturer_licence'])) {
            $update['manufacturer_licence'] = $row['manufacturer_licence'];
        }
        if (isset($row['category']) || isset($row['category_label'])) {
            $update['category'] = $category;
        }
        foreach (['generic_name', 'strength', 'dosage_form'] as $f) {
            if (!empty($row[$f])) {
                $update[$f] = $row[$f];
            }
        }
        if ($entry->generic_name === null && $entry->strength === null && $entry->dosage_form === null
            && !isset($update['generic_name'])) {
            $parsed = $this->parser->parse($brand, $row['composition'] ?? $entry->composition, $pack);
            $update['generic_name'] = $parsed['generic_name'];
            $update['strength'] = $parsed['strength'];
            $update['dosage_form'] = $parsed['dosage_form'];
        }

        $oldMrp = $entry->mrp !== null ? round((float) $entry->mrp, 2) : null;
        $mayWriteMrp = $source === MedicineCatalogueEntry::SOURCE_DRAP
            || $overwriteMrp
            || $entry->source !== MedicineCatalogueEntry::SOURCE_DRAP;
        if ($mrp !== null && $mayWriteMrp && ($oldMrp === null || abs($oldMrp - $mrp) >= 0.005)) {
            $update['mrp'] = $mrp;
            $update['effective_date'] = $effective ?? $entry->effective_date?->format('Y-m-d');
            $this->recordPriceChange($entry, $oldMrp, $mrp, $entry->effective_date?->format('Y-m-d'), $update['effective_date'], $source, $syncId);
            $priceChanged = true;
        } elseif ($effective !== null && $mayWriteMrp && $entry->effective_date?->format('Y-m-d') !== $effective) {
            $update['effective_date'] = $effective;
        }
        $update['checksum'] = $checksum;

        $entry->forceFill($update)->save();

        return ['entry' => $entry, 'outcome' => 'updated', 'price_changed' => $priceChanged];
    }

    /**
     * Append a history row and raise a pending notice for every shop product
     * linked to this catalogue row. Older pending notices for the same product
     * are superseded — the shop only ever sees the latest old→new pair.
     */
    public function recordPriceChange(MedicineCatalogueEntry $entry, ?float $oldMrp, float $newMrp, ?string $oldEffective, ?string $effective, string $source, ?int $syncId): MedicineCataloguePrice
    {
        $price = MedicineCataloguePrice::create([
            'catalogue_id' => $entry->id,
            'old_mrp' => $oldMrp,
            'new_mrp' => $newMrp,
            'old_effective_date' => $oldEffective,
            'effective_date' => $effective,
            'source' => $source,
            'sync_id' => $syncId,
            'created_at' => now(),
        ]);

        if (!Schema::hasTable('medicine_price_notices') || !Schema::hasColumn('products', 'medicine_catalogue_id')) {
            return $price;
        }

        $linked = Product::where('medicine_catalogue_id', $entry->id)->get(['id', 'company_id', 'mrp']);
        foreach ($linked as $product) {
            MedicinePriceNotice::where('product_id', $product->id)
                ->where('status', MedicinePriceNotice::STATUS_PENDING)
                ->update(['status' => MedicinePriceNotice::STATUS_SUPERSEDED, 'updated_at' => now()]);
            MedicinePriceNotice::updateOrCreate(
                ['product_id' => $product->id, 'price_id' => $price->id],
                [
                    'company_id' => $product->company_id,
                    'catalogue_id' => $entry->id,
                    // "old" from the shop's point of view = what ITS product says now.
                    'old_mrp' => $product->mrp !== null ? round((float) $product->mrp, 2) : $oldMrp,
                    'new_mrp' => $newMrp,
                    'effective_date' => $effective,
                    'status' => MedicinePriceNotice::STATUS_PENDING,
                ]
            );
        }

        return $price;
    }

    // ────────────────────────────────────────────────────────────────────
    //  Shop-side notice actions
    // ────────────────────────────────────────────────────────────────────

    /**
     * Apply one notice: product MRP → new; sale price follows ONLY when it
     * equalled the MRP the shop was selling at (old MRP). Audit-logged.
     */
    public function applyNotice(MedicinePriceNotice $notice, int $userId): bool
    {
        return DB::transaction(function () use ($notice, $userId) {
            $notice = MedicinePriceNotice::whereKey($notice->id)->lockForUpdate()->first();
            if (!$notice || $notice->status !== MedicinePriceNotice::STATUS_PENDING) {
                return false;
            }
            $product = Product::where('company_id', $notice->company_id)->whereKey($notice->product_id)->lockForUpdate()->first();
            if (!$product) {
                $notice->forceFill(['status' => MedicinePriceNotice::STATUS_DISMISSED, 'acted_by' => $userId, 'acted_at' => now()])->save();

                return false;
            }

            $newMrp = round((float) $notice->new_mrp, 2);
            $curMrp = $product->mrp !== null ? round((float) $product->mrp, 2) : null;
            $oldMrp = $notice->old_mrp !== null ? round((float) $notice->old_mrp, 2) : $curMrp;
            $curPrice = round((float) $product->default_price, 2);

            $before = ['mrp' => $curMrp, 'default_price' => $curPrice];
            $changes = ['mrp' => $newMrp];
            $followed = false;
            if (($oldMrp !== null && abs($curPrice - $oldMrp) < 0.005) || ($curMrp !== null && abs($curPrice - $curMrp) < 0.005)) {
                $changes['default_price'] = $newMrp;
                $followed = true;
            }
            $product->update($changes);

            $notice->forceFill(['status' => MedicinePriceNotice::STATUS_APPLIED, 'acted_by' => $userId, 'acted_at' => now()])->save();

            try {
                AuditLogService::log(
                    'medicine_mrp_notice_applied', 'product', $product->id,
                    $before,
                    $changes + ['notice_id' => $notice->id, 'catalogue_id' => $notice->catalogue_id, 'sale_price_followed' => $followed, 'effective_date' => $notice->effective_date?->format('Y-m-d')],
                    (int) $notice->company_id, $userId
                );
            } catch (\Throwable $e) {
                Log::warning('[medicine-catalogue] audit log failed: ' . $e->getMessage());
            }

            return true;
        });
    }

    public function dismissNotice(MedicinePriceNotice $notice, int $userId): bool
    {
        if ($notice->status !== MedicinePriceNotice::STATUS_PENDING) {
            return false;
        }
        $notice->forceFill(['status' => MedicinePriceNotice::STATUS_DISMISSED, 'acted_by' => $userId, 'acted_at' => now()])->save();
        try {
            AuditLogService::log('medicine_mrp_notice_dismissed', 'product', $notice->product_id, null,
                ['notice_id' => $notice->id, 'old_mrp' => $notice->old_mrp, 'new_mrp' => $notice->new_mrp],
                (int) $notice->company_id, $userId);
        } catch (\Throwable $e) {
            Log::warning('[medicine-catalogue] audit log failed: ' . $e->getMessage());
        }

        return true;
    }
}

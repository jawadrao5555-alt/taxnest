<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\PosCategoryRolloutService;
use App\Services\PosFeatureService;
use Illuminate\Console\Command;

/**
 * Task 1582 — category profiles rollout report.
 *
 *   php artisan pos:category-extras            report only (nothing written)
 *   php artisan pos:category-extras --write    record outsiders as grandfathered extras
 *   php artisan pos:category-extras --list     list shops that CARRY extras today
 *
 * Idempotent: an existing extra (admin grant or grandfathered) is never
 * overwritten. The backfill migration calls the same service.
 */
class PosCategoryExtrasAudit extends Command
{
    protected $signature = 'pos:category-extras {--write : Record outsiders as grandfathered extras} {--list : List shops that currently carry extras}';

    protected $description = 'Report (or grandfather) modules that POS shops use outside their business category.';

    public function handle(): int
    {
        if (!PosFeatureService::extrasColumnExists()) {
            $this->error('companies.pos_module_extras does not exist yet — run migrate first.');
            return self::FAILURE;
        }

        if ($this->option('list')) {
            $rows = [];
            Company::query()->whereNotNull('pos_module_extras')->orderBy('id')->chunkById(200, function ($cs) use (&$rows) {
                foreach ($cs as $c) {
                    $extras = PosFeatureService::extraModules($c);
                    if (!$extras) {
                        continue;
                    }
                    $desc = [];
                    foreach ($extras as $k => $m) {
                        $desc[] = $k . ' (' . ($m['source'] ?? 'admin') . ')';
                    }
                    $rows[] = [$c->id, $c->name, PosFeatureService::profileCategory($c), implode(', ', $desc)];
                }
            });
            $this->table(['id', 'company', 'category', 'extras'], $rows);
            $this->info(count($rows) . ' shop(s) carry extras.');
            return self::SUCCESS;
        }

        $write = (bool) $this->option('write');
        $rows = [];
        PosCategoryRolloutService::backfill($write, function (array $row) use (&$rows) {
            $desc = [];
            foreach ($row['modules'] as $k => $why) {
                $desc[] = $k . ' — ' . $why;
            }
            $rows[] = [$row['id'], $row['name'], $row['category'], implode('; ', $desc), implode(',', $row['new'])];
        });
        $this->table(['id', 'company', 'category', 'outside-category modules', 'newly recorded'], $rows);
        $this->info(count($rows) . ' shop(s) use modules outside their category' . ($write ? ' — recorded as grandfathered.' : ' (dry run; add --write to record).'));
        return self::SUCCESS;
    }
}

<?php

namespace App\Services;

use App\Models\Company;
use App\Support\ProductCatalog;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

final class CompanyProductId
{
    public const SERIES = ['D', 'P', 'F', 'S', 'E', 'EH', 'ES', 'EL', 'TN'];

    public const CLASSIFICATION_COLUMNS = ['product_type', 'pos_integration_mode', 'erps_vertical', 'health_org_type'];

    public static function available(Connection $database): bool
    {
        return $database->getSchemaBuilder()->hasColumn('companies', 'product_company_id');
    }

    public static function resolve(string $identifier): ?Company
    {
        return self::matching(Company::query(), $identifier)->first();
    }

    public static function display(?Company $company): ?string
    {
        return $company ? (($company->product_company_id ?? null) ?: ($company->account_code ?? null) ?: (string) $company->id) : null;
    }

    public static function backfill(Connection $database): void
    {
        // Include archived rows so they reserve their places in each series.
        $database->table('companies')->select('id')->chunkById(100, function ($companies) use ($database) {
            $database->transaction(function () use ($database, $companies) {
                foreach ($companies as $company) {
                    self::synchronize($database, (int) $company->id);
                }
            });
        });
    }

    /** Resolve a display code, historical alias, legacy account code or numeric key. */
    public static function matching(Builder $companies, string $identifier): Builder
    {
        $identifier = strtoupper(trim($identifier));

        if (preg_match('/^[0-9]{1,20}$/D', $identifier) === 1) {
            $numericId = filter_var(ltrim($identifier, '0'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            return $numericId === false ? $companies->whereRaw('1 = 0') : $companies->whereKey($numericId);
        }

        if (preg_match('/^[A-Z][A-Z0-9-]{1,31}$/D', $identifier) !== 1) {
            return $companies->whereRaw('1 = 0');
        }

        $database = $companies->getModel()->getConnection();
        $schema = $database->getSchemaBuilder();

        return $companies->where(function (Builder $identifiers) use ($identifier, $schema, $database) {
            if ($schema->hasColumn('companies', 'account_code')) {
                $identifiers->where('companies.account_code', $identifier);
            } else {
                $identifiers->whereRaw('1 = 0');
            }

            if (self::available($database)) {
                $identifiers->orWhereIn('companies.id', $database->table('company_product_ids')
                    ->select('company_id')->where('code', $identifier));
            }
        });
    }

    /** The caller holds the company transaction open while its serial is allocated. */
    public static function synchronize(Connection $database, int $companyId): string
    {
        if ($database->transactionLevel() < 1) {
            throw new LogicException('Assigning a product Company ID requires a company transaction.');
        }

        $company = $database->table('companies')->where('id', $companyId)->lockForUpdate()
            ->first([...self::CLASSIFICATION_COLUMNS, 'product_company_id']);
        if (!$company) {
            throw new LogicException('Cannot assign a product Company ID to a missing company.');
        }

        $series = self::seriesFor($company);
        $registry = $database->table('company_product_ids');
        $existing = (clone $registry)->where('company_id', $companyId)->where('series', $series);
        $code = (clone $existing)->value('code');
        if ($code === null) {
            $counter = $database->table('company_product_id_counters')->where('series', $series);
            $serial = (int) (clone $counter)->lockForUpdate()->value('next_serial');
            if ($serial < 1 || $serial === PHP_INT_MAX) {
                throw new LogicException('Company ID series counter is missing or exhausted.');
            }

            $code = $series.str_pad((string) $serial, 2, '0', STR_PAD_LEFT);
            $allocated = $registry->insertOrIgnore(['company_id' => $companyId, 'series' => $series, 'serial' => $serial, 'code' => $code]);
            if ($allocated) {
                $counter->update(['next_serial' => $serial + 1]);
            } else {
                // A caller's repeatable-read snapshot may predate the alias.
                // Read it under lock without advancing the counter again.
                $code = $existing->lockForUpdate()->value('code')
                    ?? throw new LogicException('Company ID counter conflicts with an existing serial.');
            }
        }

        if ($company->product_company_id !== $code) {
            $database->table('companies')->where('id', $companyId)->update(['product_company_id' => $code]);
        }

        return $code;
    }

    private static function seriesFor(object $company): string
    {
        return match (ProductCatalog::normalize($company->product_type)) {
            ProductCatalog::DI => 'D',
            ProductCatalog::POS => $company->pos_integration_mode === 'standalone' ? 'S' : 'P',
            ProductCatalog::FBRPOS => 'F',
            ProductCatalog::ERPS => match ($company->erps_vertical) {
                'school' => 'ES',
                'hospital' => 'EH',
                'lab', 'laboratory' => 'EL',
                null, '', 'health' => match ($company->health_org_type) {
                    'hospital' => 'EH',
                    'lab' => 'EL',
                    default => 'E',
                },
                default => 'E',
            },
            default => blank($company->product_type) ? 'D' : 'TN',
        };
    }
}

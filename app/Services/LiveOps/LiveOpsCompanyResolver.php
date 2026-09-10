<?php

namespace App\Services\LiveOps;

use App\Exceptions\LiveOpsCompanyResolutionException;
use App\Models\Company;
use Illuminate\Support\Facades\Schema;

/**
 * Resolve owner-supplied company names to a single NestPOS PRA company_id.
 *
 * Matching is fail-closed: unknown and ambiguous queries never operate on a tenant.
 * Owners never need to supply company IDs; IDs are an internal implementation detail.
 */
class LiveOpsCompanyResolver
{
    /**
     * @return array{company_id:int,name:string,account_code:?string,match_type:string}
     */
    public function resolve(string $query): array
    {
        $raw = trim(preg_replace('/\s+/u', ' ', $query) ?? $query);
        if ($raw === '' || mb_strlen($raw) > 255) {
            throw new LiveOpsCompanyResolutionException(
                'Company name is empty or too long',
                LiveOpsCompanyResolutionException::INVALID,
                [],
                $raw
            );
        }

        $companies = $this->posCompanies()->get(['id', 'name', 'account_code']);
        if ($companies->isEmpty()) {
            throw new LiveOpsCompanyResolutionException(
                'Company not found: '.$raw,
                LiveOpsCompanyResolutionException::NOT_FOUND,
                [],
                $raw
            );
        }

        $rows = $companies->map(fn (Company $c) => [
            'id' => (int) $c->id,
            'name' => (string) $c->name,
            'account_code' => $c->account_code !== null && $c->account_code !== '' ? (string) $c->account_code : null,
        ])->all();

        $hit = $this->firstUnique($rows, fn (array $r) => $r['name'] === $raw, 'exact_name');
        if ($hit) {
            return $hit;
        }

        $hit = $this->firstUnique($rows, fn (array $r) => ($r['account_code'] ?? null) === $raw, 'exact_account_code');
        if ($hit) {
            return $hit;
        }

        if (ctype_digit($raw)) {
            $hit = $this->firstUnique($rows, fn (array $r) => $r['id'] === (int) $raw, 'exact_id');
            if ($hit) {
                return $hit;
            }
        }

        $lower = mb_strtolower($raw);
        $hit = $this->firstUnique($rows, fn (array $r) => mb_strtolower($r['name']) === $lower, 'case_insensitive_name');
        if ($hit) {
            return $hit;
        }

        $hit = $this->firstUnique(
            $rows,
            fn (array $r) => $r['account_code'] !== null && mb_strtolower($r['account_code']) === $lower,
            'case_insensitive_account_code'
        );
        if ($hit) {
            return $hit;
        }

        $norm = $this->normalize($raw);
        $hit = $this->firstUnique($rows, fn (array $r) => $this->normalize($r['name']) === $norm, 'normalized_name');
        if ($hit) {
            return $hit;
        }

        $hit = $this->firstUnique(
            $rows,
            fn (array $r) => $r['account_code'] !== null && $this->normalize($r['account_code']) === $norm,
            'normalized_account_code'
        );
        if ($hit) {
            return $hit;
        }

        return $this->safePartial($rows, $raw, $norm);
    }

    /**
     * @param  list<array{id:int,name:string,account_code:?string}>  $rows
     * @param  callable(array{id:int,name:string,account_code:?string}):bool  $predicate
     * @return array{company_id:int,name:string,account_code:?string,match_type:string}|null
     */
    private function firstUnique(array $rows, callable $predicate, string $matchType): ?array
    {
        $matches = array_values(array_filter($rows, $predicate));
        if (count($matches) === 1) {
            return $this->ok($matches[0], $matchType);
        }
        if (count($matches) > 1) {
            throw $this->ambiguous($matches, $matchType === 'exact_id' ? (string) $matches[0]['id'] : ($matches[0]['name'] ?? ''));
        }

        return null;
    }

    /**
     * Prefix match preferred; contains only when unique and query is ≥3 normalized chars.
     *
     * @param  list<array{id:int,name:string,account_code:?string}>  $rows
     * @return array{company_id:int,name:string,account_code:?string,match_type:string}
     */
    private function safePartial(array $rows, string $raw, string $norm): array
    {
        $min = (int) config('live_ops.resolver.min_partial_chars', 3);
        if (mb_strlen($norm) < $min) {
            throw new LiveOpsCompanyResolutionException(
                'Company not found: '.$raw,
                LiveOpsCompanyResolutionException::NOT_FOUND,
                [],
                $raw
            );
        }

        $prefix = [];
        $contains = [];
        foreach ($rows as $row) {
            $nName = $this->normalize($row['name']);
            $nCode = $row['account_code'] !== null ? $this->normalize($row['account_code']) : '';
            $isPrefix = str_starts_with($nName, $norm) || ($nCode !== '' && str_starts_with($nCode, $norm));
            $isContains = str_contains($nName, $norm) || ($nCode !== '' && str_contains($nCode, $norm));
            if ($isPrefix) {
                $prefix[$row['id']] = $row;
            } elseif ($isContains) {
                $contains[$row['id']] = $row;
            }
        }

        $prefix = array_values($prefix);
        if (count($prefix) === 1) {
            return $this->ok($prefix[0], 'safe_prefix');
        }
        if (count($prefix) > 1) {
            throw $this->ambiguous($prefix, $raw);
        }

        $contains = array_values($contains);
        if (count($contains) === 1) {
            return $this->ok($contains[0], 'safe_partial');
        }
        if (count($contains) > 1) {
            throw $this->ambiguous($contains, $raw);
        }

        throw new LiveOpsCompanyResolutionException(
            'Company not found: '.$raw,
            LiveOpsCompanyResolutionException::NOT_FOUND,
            [],
            $raw
        );
    }

    /**
     * @param  array{id:int,name:string,account_code:?string}  $row
     * @return array{company_id:int,name:string,account_code:?string,match_type:string}
     */
    private function ok(array $row, string $matchType): array
    {
        return [
            'company_id' => $row['id'],
            'name' => $row['name'],
            'account_code' => $row['account_code'],
            'match_type' => $matchType,
        ];
    }

    /**
     * @param  list<array{id:int,name:string,account_code:?string}>  $matches
     */
    private function ambiguous(array $matches, string $query): LiveOpsCompanyResolutionException
    {
        $names = array_values(array_unique(array_map(fn (array $m) => $m['name'], $matches)));
        $msg = 'Ambiguous company name: '.$query.' (matches '.count($matches).' companies: '.implode(', ', $names).')';

        return new LiveOpsCompanyResolutionException(
            $msg,
            LiveOpsCompanyResolutionException::AMBIGUOUS,
            $matches,
            $query
        );
    }

    public function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    private function posCompanies()
    {
        $suffix = config('live_ops.exclude_email_suffix', '@scaletest.pk');

        return Company::query()
            ->whereIn('product_type', config('live_ops.product_types', ['pos']))
            ->where(function ($q) use ($suffix) {
                $q->whereNull('email')->orWhere('email', 'not like', '%'.$suffix);
            })
            ->when(Schema::hasColumn('companies', 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
            ->limit((int) config('live_ops.limits.max_companies_in_report', 200));
    }
}

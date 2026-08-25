<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Company;
use Illuminate\Support\Facades\Schema;

class BranchResolver
{
    /** @var array<int,array<string,int|false>> */
    private array $branchLookupCache = [];

    private ?bool $branchesTable = null;

    /** @return array<string,int|false> */
    public function branchLookup(Company $company): array
    {
        if (array_key_exists($company->id, $this->branchLookupCache)) {
            return $this->branchLookupCache[$company->id];
        }

        if (!$this->branchesTableExists()) {
            return $this->branchLookupCache[$company->id] = [];
        }

        $branches = Branch::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->get(['id', 'name', 'city']);
        $byCity = [];
        $byName = [];

        foreach ($branches as $branch) {
            foreach ([['city', &$byCity], ['name', &$byName]] as [$field, &$bucket]) {
                $key = $this->normalizeBranchKey((string) ($branch->{$field} ?? ''));
                if ($key !== '') {
                    $bucket[$key][] = (int) $branch->id;
                }
            }
            unset($bucket);
        }

        $collapse = static fn (array $ids) => count(array_unique($ids)) === 1 ? $ids[0] : false;
        $lookup = [];
        foreach ($byCity as $key => $ids) {
            $lookup[$key] = $collapse($ids);
        }
        foreach ($byName as $key => $ids) {
            $lookup[$key] = $collapse($ids);
        }

        return $this->branchLookupCache[$company->id] = $lookup;
    }

    public function headOfficeBranchId(Company $company): ?int
    {
        if (!$this->branchesTableExists()) {
            return null;
        }

        return Branch::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('is_head_office', true)
            ->orderBy('id')
            ->value('id');
    }

    public function branchChoices(Company $company): string
    {
        if (!$this->branchesTableExists()) {
            return 'none';
        }

        $choices = Branch::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->orderBy('name')
            ->get(['name', 'city'])
            ->map(fn ($branch) => $branch->city ? "{$branch->name} ({$branch->city})" : $branch->name)
            ->all();

        return $choices ? implode(', ', $choices) : 'none';
    }

    public function normalizeBranchKey(string $raw): string
    {
        return trim(preg_replace('/\s+/u', ' ', mb_strtolower($raw)));
    }

    public function branchesTableExists(): bool
    {
        return $this->branchesTable ??= Schema::hasTable('branches');
    }

    /** @return array{branch_id:?int,reason:string,candidates:array<int,int>} */
    public function resolveFromText(Company $company, string $text): array
    {
        if (!$this->branchesTableExists()) {
            return ['branch_id' => null, 'reason' => 'none', 'candidates' => []];
        }

        $haystack = $this->normalizeBranchKey($text);
        $candidates = [];
        $branches = Branch::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['id', 'city', 'name', 'code']);

        foreach ($branches as $branch) {
            foreach (['city', 'name', 'code'] as $field) {
                $needle = $this->normalizeBranchKey((string) ($branch->{$field} ?? ''));
                if ($needle !== '' && preg_match('/(?<![\pL\pN])' . preg_quote($needle, '/') . '(?![\pL\pN])/u', $haystack)) {
                    $candidates[] = (int) $branch->id;
                    break;
                }
            }
        }

        $candidates = array_values(array_unique($candidates));
        if (count($candidates) === 1) {
            return ['branch_id' => $candidates[0], 'reason' => 'matched', 'candidates' => $candidates];
        }

        return [
            'branch_id' => null,
            'reason' => count($candidates) > 1 ? 'ambiguous' : 'none',
            'candidates' => $candidates,
        ];
    }
}
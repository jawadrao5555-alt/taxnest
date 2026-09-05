<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\CompanyGroupMember;
use App\Models\CompanyIdentityKey;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Automatic business grouping (owner ruling, 5 Sep 2026).
 *
 * The products are isolated on purpose — a person signs up separately on PRA
 * POS, FBR POS and Digital Invoice, with the SAME email, phone, CNIC and NTN
 * if he likes. Nothing about that is shared with him: separate login,
 * separate subscription, separate data.
 *
 * What we owe OURSELVES is the knowledge that those accounts are one customer.
 * This service derives that automatically from the identity values already on
 * the accounts, and records WHY two accounts were tied together:
 *
 *   strong  — CNIC or NTN. Practically proof of the same taxpayer/person.
 *   weak    — email or phone. Usually right, but an accountant's email can sit
 *             on two unrelated businesses, so admin can detach in one click.
 *   manual  — an admin said so.
 *
 * A detached pair is remembered (company_group_exclusions) so the next
 * automatic run does not put it straight back.
 */
class CompanyGroupService
{
    /** Evidence types, strongest first. */
    public const TYPES = ['cnic', 'ntn', 'email', 'phone'];

    private const RANK = ['cnic' => 1, 'ntn' => 2, 'email' => 3, 'phone' => 4, 'manual' => 0, 'seed' => 5];

    private const STRENGTH = ['cnic' => 'strong', 'ntn' => 'strong', 'email' => 'weak', 'phone' => 'weak', 'manual' => 'manual', 'seed' => 'seed'];

    /** Re-entrancy guard: model events call sync, sync writes models. */
    private static bool $syncing = false;

    public static function enabled(): bool
    {
        static $ok = null;
        if ($ok === null) {
            try {
                $ok = Schema::hasTable('company_groups')
                    && Schema::hasTable('company_group_members')
                    && Schema::hasTable('company_identity_keys');
            } catch (\Throwable $e) {
                $ok = false;
            }
        }

        return $ok;
    }

    public static function strengthOf(string $matchType): string
    {
        return self::STRENGTH[$matchType] ?? 'weak';
    }

    // ------------------------------------------------------------- identity

    /**
     * Normalise one identity value, or null when it is too vague to identify
     * anybody (a 3-digit "phone", a 4-character "NTN", a CNIC that is not 13
     * digits). Vague values would glue unrelated businesses together.
     */
    public static function normalizeValue(string $type, ?string $raw): ?string
    {
        $norm = CredentialLedgerService::normalize($type, $raw);
        if ($norm === null) {
            return null;
        }

        return match ($type) {
            'cnic'  => strlen($norm) === 13 ? $norm : null,
            'ntn'   => strlen($norm) >= 6 ? $norm : null,
            'email' => str_contains($norm, '@') ? mb_substr($norm, 0, 191) : null,
            'phone' => self::normalizePhone($norm),
            default => $norm,
        };
    }

    /** 0300-1234567 / +92 300 1234567 / 00923001234567 all become 3001234567. */
    private static function normalizePhone(string $digits): ?string
    {
        if (str_starts_with($digits, '0092')) {
            $digits = substr($digits, 4);
        } elseif (str_starts_with($digits, '92') && strlen($digits) >= 12) {
            $digits = substr($digits, 2);
        }
        $digits = ltrim($digits, '0');

        return strlen($digits) >= 9 ? $digits : null;
    }

    /**
     * Every identity value this company can be recognised by: its own CNIC,
     * NTN, email and phones, plus the owner/admin user's email and phone
     * (the credentials the person actually signs up with).
     *
     * @return array<int,array{key_type:string,key_value:string}>
     */
    public static function keysFor(Company $company): array
    {
        $keys = [];
        $push = function (string $type, ?string $raw) use (&$keys) {
            $value = self::normalizeValue($type, $raw);
            if ($value !== null) {
                $keys[$type . '|' . $value] = ['key_type' => $type, 'key_value' => $value];
            }
        };

        $push('cnic', $company->cnic);
        $push('ntn', $company->ntn);
        $push('email', $company->email);
        $push('phone', $company->phone);
        $push('phone', $company->mobile ?? null);

        $owners = User::where('company_id', $company->id)
            ->whereIn('role', ['company_admin', 'admin', 'owner'])
            ->orderBy('id')
            ->limit(5)
            ->get(['email', 'phone']);
        foreach ($owners as $owner) {
            $push('email', $owner->email);
            $push('phone', $owner->phone);
        }

        return array_values($keys);
    }

    /** Rewrite this company's identity keys to exactly the current values. */
    public static function storeKeys(int $companyId, array $keys): void
    {
        $wanted = [];
        foreach ($keys as $key) {
            $wanted[$key['key_type'] . '|' . $key['key_value']] = $key;
        }

        $existing = CompanyIdentityKey::where('company_id', $companyId)->get();
        foreach ($existing as $row) {
            $signature = $row->key_type . '|' . $row->key_value;
            if (!isset($wanted[$signature])) {
                $row->delete();
                continue;
            }
            unset($wanted[$signature]);
        }

        foreach ($wanted as $key) {
            CompanyIdentityKey::create([
                'company_id' => $companyId,
                'key_type'   => $key['key_type'],
                'key_value'  => $key['key_value'],
            ]);
        }
    }

    // ---------------------------------------------------------------- group

    /**
     * Put this company in the right group (creating one if the match is with
     * an ungrouped company). Safe to call on every save.
     */
    public static function syncCompany(Company $company): ?CompanyGroup
    {
        if (!self::enabled() || !$company->exists || self::$syncing) {
            return null;
        }

        self::$syncing = true;
        try {
            $keys = self::keysFor($company);
            self::storeKeys($company->id, $keys);

            $current = CompanyGroupMember::where('company_id', $company->id)->first();
            if (!$keys) {
                return $current?->group;
            }

            $excluded = DB::table('company_group_exclusions')
                ->where('company_id', $company->id)
                ->pluck('company_group_id')
                ->all();

            $matches = CompanyIdentityKey::query()
                ->where('company_id', '!=', $company->id)
                ->where(function ($query) use ($keys) {
                    foreach ($keys as $key) {
                        $query->orWhere(function ($inner) use ($key) {
                            $inner->where('key_type', $key['key_type'])
                                  ->where('key_value', $key['key_value']);
                        });
                    }
                })
                ->get()
                ->sortBy(fn ($row) => self::RANK[$row->key_type] ?? 9)
                ->values();

            foreach ($matches as $match) {
                $other = Company::withTrashed()->find($match->company_id);
                if (!$other) {
                    // Hard-deleted company left its keys behind — clean up.
                    CompanyIdentityKey::where('company_id', $match->company_id)->delete();
                    continue;
                }

                $otherMember = CompanyGroupMember::where('company_id', $other->id)->first();
                $group = $otherMember?->group;

                if ($group && in_array($group->id, $excluded, true)) {
                    continue;   // admin said these two are not the same customer
                }

                if ($current) {
                    // Already grouped. Keep the group, but upgrade the recorded
                    // reason when a stronger one shows up (phone → CNIC).
                    if ($group && $group->id === $current->company_group_id) {
                        self::rememberReason($current, $match->key_type, $match->key_value);
                    }

                    return $current->group;
                }

                if (!$group) {
                    $group = self::createGroupAround($other);
                    if (!$group) {
                        continue;
                    }
                }

                self::attach($group, $company, $match->key_type, $match->key_value, false);

                return $group->fresh();
            }

            return $current?->group;
        } catch (\Throwable $e) {
            Log::warning('Company group sync failed', ['company_id' => $company->id, 'error' => $e->getMessage()]);

            return null;
        } finally {
            self::$syncing = false;
        }
    }

    private static function rememberReason(CompanyGroupMember $member, string $type, ?string $value): void
    {
        if ($member->is_manual) {
            return;
        }
        $now = self::RANK[$type] ?? 9;
        $was = self::RANK[$member->match_type] ?? 9;
        if ($now < $was) {
            $member->update([
                'match_type'  => $type,
                'match_value' => $value,
                'strength'    => self::strengthOf($type),
            ]);
        }
    }

    private static function createGroupAround(Company $seed): ?CompanyGroup
    {
        $group = CompanyGroup::create([
            'code' => 'GRP-TMP',
            'name' => $seed->owner_name ?: $seed->name,
        ]);
        $group->update(['code' => 'GRP-' . str_pad((string) $group->id, 5, '0', STR_PAD_LEFT)]);

        CompanyGroupMember::updateOrCreate(
            ['company_id' => $seed->id],
            [
                'company_group_id' => $group->id,
                'match_type'       => 'seed',
                'match_value'      => null,
                'strength'         => 'seed',
                'is_manual'        => false,
            ]
        );

        return $group;
    }

    /** Add a company to a group (admin link, or an automatic match). */
    public static function attach(CompanyGroup $group, Company $company, string $matchType, ?string $matchValue, bool $manual): CompanyGroupMember
    {
        DB::table('company_group_exclusions')
            ->where('company_id', $company->id)
            ->where('company_group_id', $group->id)
            ->delete();

        return CompanyGroupMember::updateOrCreate(
            ['company_id' => $company->id],
            [
                'company_group_id' => $group->id,
                'match_type'       => $matchType,
                'match_value'      => $matchValue,
                'strength'         => $manual ? 'manual' : self::strengthOf($matchType),
                'is_manual'        => $manual,
            ]
        );
    }

    /**
     * Remove a company from its group and remember the decision, so the next
     * automatic run does not silently undo the admin.
     */
    public static function detach(Company $company): void
    {
        $member = CompanyGroupMember::where('company_id', $company->id)->first();
        if (!$member) {
            return;
        }

        $groupId = $member->company_group_id;
        $member->delete();

        DB::table('company_group_exclusions')->updateOrInsert(
            ['company_group_id' => $groupId, 'company_id' => $company->id],
            ['updated_at' => now(), 'created_at' => now()]
        );

        self::dissolveIfAlone($groupId);
    }

    /** A "group" of one is noise — drop it. */
    public static function dissolveIfAlone(int $groupId): void
    {
        $group = CompanyGroup::find($groupId);
        if (!$group) {
            return;
        }
        if ($group->members()->count() <= 1) {
            $group->members()->delete();
            $group->delete();
        }
    }

    /** Forget everything about a company that no longer exists. */
    public static function forget(int $companyId): void
    {
        if (!self::enabled()) {
            return;
        }
        $member = CompanyGroupMember::where('company_id', $companyId)->first();
        $groupId = $member?->company_group_id;
        CompanyGroupMember::where('company_id', $companyId)->delete();
        CompanyIdentityKey::where('company_id', $companyId)->delete();
        DB::table('company_group_exclusions')->where('company_id', $companyId)->delete();
        if ($groupId) {
            self::dissolveIfAlone($groupId);
        }
    }

    /** Rebuild membership for every company (backfill / repair). */
    public static function rebuild(?callable $progress = null): int
    {
        if (!self::enabled()) {
            return 0;
        }

        $count = 0;
        Company::query()->orderBy('id')->chunk(200, function ($companies) use (&$count, $progress) {
            foreach ($companies as $company) {
                self::syncCompany($company);
                $count++;
                if ($progress) {
                    $progress($company, $count);
                }
            }
        });

        return $count;
    }
}

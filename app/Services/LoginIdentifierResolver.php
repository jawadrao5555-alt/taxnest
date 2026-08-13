<?php

namespace App\Services;

use App\Models\Company;
use App\Models\User;

/**
 * Username login support (owner report, 10 Aug 2026).
 *
 * The login field promises "Email / Phone / Username / NTN / CNIC", but team
 * accounts are created WITHOUT a users.username value — so staff who type the
 * short name they know ("cashier1" for cashier1@gmail.com) always failed.
 *
 * Resolution order for a username-looking input:
 *   1. users.username exact match (column has a global unique index).
 *   2. Email local-part fallback: users whose email is "<input>@<anything>",
 *      scoped to the panel's company product_type(s) so a DI account can never
 *      shadow a POS cashier (and vice versa) — per-panel guard isolation is
 *      still re-checked by the caller after the password check.
 *
 * Ambiguity is NEVER guessed: if two accounts on the SAME panel share the
 * local part, we return ambiguous=true and the caller shows a clear
 * "use your full email" error instead of logging into the wrong account.
 */
class LoginIdentifierResolver
{
    /**
     * Resolve a non-email, non-phone login input as a username.
     *
     * @param  string      $login        trimmed raw login input
     * @param  array       $productTypes allowed company product_types for this
     *                                   panel; include null to also allow
     *                                   company-less / null-product accounts (DI)
     * @return array{user: ?User, ambiguous: bool}
     */
    /**
     * Shared validation rules for every users.username WRITE path (Task 529:
     * POS/FBR Team store+update, POS/FBR self-profile). Single source of
     * truth so the Team pages can never save a username the login routers
     * would refuse to resolve.
     *
     * Digit-shape guard: BOTH login controllers divert any input whose
     * digit-stripped form is 7–13 digits into phone/NTN/CNIC resolution
     * BEFORE the username resolver runs — such a "username" could be saved
     * but would never log in. Numeric-only values of any length are also
     * reserved (they read as identifiers, not names).
     *
     * @param  int|null $exceptUserId own row to exempt from the unique check
     */
    public static function usernameRules(?int $exceptUserId = null): array
    {
        return [
            'nullable',
            'string',
            'max:100',
            // No spaces, no @ (email-looking values must stay emails).
            'regex:/^[A-Za-z0-9._-]+$/',
            function ($attribute, $value, $fail) {
                $digits = preg_replace('/\D/', '', (string) $value);
                $len = strlen($digits);
                if (ctype_digit((string) $value) || ($len >= 7 && $len <= 13)) {
                    $fail(__('pos.username_digits_reserved'));
                }
            },
            'unique:users,username' . ($exceptUserId ? ',' . $exceptUserId : ''),
        ];
    }

    /** Companion error messages for usernameRules(). */
    public static function usernameMessages(string $field = 'username'): array
    {
        return [
            $field . '.regex' => __('pos.username_format_invalid'),
            $field . '.unique' => __('pos.username_taken'),
        ];
    }

    /**
     * Shared validation rules for every owner-facing companies.cnic WRITE path
     * (Task 579: POS/FBR Business Profile + registration). Single source of
     * truth so the profile pages can never save a CNIC the login routers
     * would refuse to resolve.
     *
     * Format: 13 digits, dash/space tolerant (input like 35299-1234567-1 is
     * fine — it is normalized to plain digits before storage, matching the
     * digit-only comparison both login controllers already do).
     *
     * Uniqueness: GLOBAL across companies (any product type). Two companies
     * with the same CNIC would make the login lookup pick one of them
     * arbitrarily — dashed/spaced legacy values are compared via REPLACE so
     * an admin-set "35299-1234567-1" still blocks a plain "3529912345671".
     *
     * @param  int|null $exceptCompanyId own company row to exempt
     */
    public static function cnicRules(?int $exceptCompanyId = null): array
    {
        return [
            'nullable',
            'string',
            'max:20',
            'regex:/^[0-9\-\s]+$/',
            function ($attribute, $value, $fail) use ($exceptCompanyId) {
                $digits = preg_replace('/\D/', '', (string) $value);
                if (strlen($digits) !== 13) {
                    $fail(__('pos.cnic_format_invalid'));

                    return;
                }
                $dupe = Company::where(function ($q) use ($value, $digits) {
                    $q->where('cnic', $value)
                      ->orWhere('cnic', $digits)
                      ->orWhereRaw("REPLACE(REPLACE(cnic, '-', ''), ' ', '') = ?", [$digits]);
                });
                if ($exceptCompanyId) {
                    $dupe->where('id', '!=', $exceptCompanyId);
                }
                if ($dupe->exists()) {
                    $fail(__('pos.cnic_taken'));
                }
            },
        ];
    }

    /** Companion error messages for cnicRules(). */
    public static function cnicMessages(string $field = 'cnic'): array
    {
        return [
            $field . '.regex' => __('pos.cnic_format_invalid'),
        ];
    }

    /**
     * Normalize a CNIC input to the plain-digit form the DB stores
     * (both login controllers compare raw AND digit-only forms, so
     * digits-only storage always matches). Empty input → null (clear).
     */
    public static function normalizeCnic($value): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $value);

        return $digits === '' ? null : $digits;
    }

    public static function resolveUsername(string $login, array $productTypes): array
    {
        // Exact username match is honoured ONLY when the account belongs to
        // this panel — otherwise an FBR user named "cashier1" would block a
        // POS staff account at cashier1@… (no username) from logging in as
        // "cashier1" on the POS panel. An out-of-panel match falls through to
        // the scoped local-part fallback (and, failing that, the caller's
        // generic "Invalid credentials" — same outcome as before, no leak).
        $user = self::resolveUsernameColumn($login, $productTypes);
        if ($user) {
            return ['user' => $user, 'ambiguous' => false];
        }

        return self::resolveEmailLocalPart($login, $productTypes);
    }

    /**
     * Exact users.username match ONLY (no local-part fallback), panel-scoped.
     * DI's resolver uses this mid-chain so NTN/CNIC/FBR-reg keep their
     * existing precedence over the local-part fallback.
     */
    public static function resolveUsernameColumn(string $login, array $productTypes): ?User
    {
        $user = User::where('username', $login)->first();

        return ($user && self::inScope($user, $productTypes)) ? $user : null;
    }

    /**
     * Email local-part fallback ONLY (no username-column lookup).
     * DI's resolver calls this LAST so NTN/CNIC/FBR-reg matching keeps
     * exactly its existing precedence.
     *
     * @return array{user: ?User, ambiguous: bool}
     */
    public static function resolveEmailLocalPart(string $login, array $productTypes): array
    {
        // An input containing '@' is a (possibly malformed) email — never
        // treat it as a local part. Same for empty strings.
        if ($login === '' || str_contains($login, '@')) {
            return ['user' => null, 'ambiguous' => false];
        }

        $escaped = addcslashes($login, '\\%_');

        $candidates = User::where('email', 'like', $escaped . '@%')->get();

        $inScope = [];
        foreach ($candidates as $candidate) {
            if (self::inScope($candidate, $productTypes)) {
                $inScope[] = $candidate;
            }
        }

        if (count($inScope) === 1) {
            return ['user' => $inScope[0], 'ambiguous' => false];
        }
        if (count($inScope) > 1) {
            return ['user' => null, 'ambiguous' => true];
        }

        return ['user' => null, 'ambiguous' => false];
    }

    /**
     * Does this user's company product_type fall inside the panel scope?
     * Explicit Company::find (NOT lazy relation access — prod runs with
     * strict lazy-loading). Soft-deleted companies drop out automatically
     * via the model query. Include null in $productTypes to also allow
     * company-less / null-product accounts (DI).
     */
    private static function inScope(User $user, array $productTypes): bool
    {
        $allowNull = in_array(null, $productTypes, true);

        if (!$user->company_id) {
            return $allowNull;
        }

        $company = Company::find($user->company_id);
        $productType = $company ? $company->product_type : null;

        return in_array($productType, $productTypes, true)
            || ($productType === null && $allowNull);
    }
}

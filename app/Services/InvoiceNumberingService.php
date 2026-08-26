<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

class InvoiceNumberingService
{
    /** Short prefix for every NEW Digital Invoice number. */
    public const PREFIX = 'D';

    /** Zero-pad width; longer numbers simply grow past it (D10000). */
    public const PAD = 4;

    /** Pad widths this system has ever issued — all read as the same number. */
    public const KNOWN_PADS = [4, 3];

    /**
     * Per-company sequential invoice numbering.
     *
     * Format: D{NNN} — "D001", "D002", … growing past the pad on its own.
     *
     * Owner rule (25 Aug 2026): FBR's own invoice number is already very long,
     * so OUR number has to be short enough to read out, type and search. The
     * previous "{13-digit registration}DI{NNNNN}" spelling made the shop scan a
     * 20-character string to find one invoice. The registration prefix is not
     * needed for uniqueness — the unique index is (company_id, invoice_number)
     * and every query is company-scoped — so the sequence alone carries it.
     *
     * Nothing is renumbered: legacy "{identifier}DI{NNNNN}" and the even older
     * timestamp-suffix numbers keep their value and stay searchable. The
     * sequence counter continues where it stood, so a company on DI00250 gets
     * D251 next and the two shapes can never collide.
     *
     * What FBR receives is UNCHANGED: FbrService rebuilds the regulator-facing
     * reference as {identifier}DI{NNNNN} from this sequence.
     */
    public static function generateNextNumber(int $companyId): string
    {
        return DB::transaction(function () use ($companyId) {
            $company = Company::where('id', $companyId)->lockForUpdate()->first();

            if (!$company) {
                throw new \RuntimeException("Company not found: {$companyId}");
            }

            $seq = max(1, (int) ($company->next_invoice_number ?? 1));

            // Company-scoped: the number is unique per company (that is what
            // the index enforces), so another company's D001 is irrelevant.
            // Bump past anything an import or a legacy row already occupies.
            $like = \App\Helpers\DbCompat::like();

            do {
                $candidate = self::format($seq);
                // Every spelling of the SAME sequence counts as taken — the
                // narrower pad we used to issue ("D036") and the legacy
                // "{identifier}DI00036" one. FbrService rebuilds exactly that
                // last string as the regulator-facing reference, so reusing the
                // sequence would send FBR a reference it has already filed.
                $spellings = self::spellingsOf($seq);
                $legacy = '%DI' . str_pad((string) $seq, 5, '0', STR_PAD_LEFT);

                $taken = Invoice::withoutGlobalScopes()
                    ->where('company_id', $companyId)
                    ->where(function ($q) use ($spellings, $legacy, $like) {
                        foreach ($spellings as $spelling) {
                            $q->orWhere('invoice_number', $spelling)
                              ->orWhere('internal_invoice_number', $spelling);
                        }
                        $q->orWhere('invoice_number', $like, $legacy)
                          ->orWhere('internal_invoice_number', $like, $legacy);
                    })
                    ->exists();
                if ($taken) {
                    $seq++;
                }
            } while ($taken);

            $company->next_invoice_number = $seq + 1;
            $company->save();

            return $candidate;
        });
    }

    public static function peekNextNumber(int $companyId): string
    {
        $company = Company::find($companyId);
        if (!$company) {
            return self::format(1);
        }

        return self::format(max(1, (int) ($company->next_invoice_number ?? 1)));
    }

    /** Render a sequence number in the short format (D0001, D10000). */
    public static function format(int $sequence): string
    {
        return self::PREFIX . str_pad((string) $sequence, self::PAD, '0', STR_PAD_LEFT);
    }

    /**
     * Every short spelling this system has ever written for one sequence.
     *
     * The pad widened from 3 to 4 (owner, 26 Aug 2026), so "D036" and "D0036"
     * are the same invoice. Anything that has to decide "is this number already
     * used" or "which row does the shop mean" must consider all of them.
     *
     * @return list<string>
     */
    public static function spellingsOf(int $sequence): array
    {
        $out = [];
        foreach (self::KNOWN_PADS as $pad) {
            $out[] = self::PREFIX . str_pad((string) $sequence, $pad, '0', STR_PAD_LEFT);
        }
        $out[] = self::PREFIX . $sequence;

        return array_values(array_unique($out));
    }

    /**
     * The short form of a stored invoice number, for anything a human reads.
     *
     * Numbers issued before the short format still sit in the database as
     * "{identifier}DI00036" — 20 characters of registration prefix in front of
     * the only part that identifies the invoice. They are NOT rewritten (the
     * regulator-facing reference and every debit-note link are rebuilt from the
     * stored value), but a shop should never have to read that on its own
     * screen or on a buyer's document, so the same sequence is shown as D0036.
     *
     * Anything that carries no sequence we can recognise — the oldest
     * timestamp-suffix numbers, hand-typed imports — is handed back untouched
     * rather than renamed into something that was never issued.
     */
    public static function shortNumber(?string $stored): ?string
    {
        $value = trim((string) $stored);

        if ($value === '') {
            return null;
        }

        $sequence = self::sequenceOf($value);

        return $sequence !== null ? self::format($sequence) : $value;
    }

    /**
     * The sequence a number carries, for both the short "D036" format and the
     * legacy "{identifier}DI00036" one — null when it is neither (the oldest
     * timestamp-suffix numbers included, which reserve nothing).
     */
    public static function sequenceOf(?string $invoiceNumber): ?int
    {
        $number = strtoupper(trim((string) $invoiceNumber));

        if (preg_match('/^' . self::PREFIX . '(\d{1,9})$/', $number, $m)) {
            return (int) $m[1];
        }

        if (preg_match('/^\d{7,13}DI(\d{5})$/', $number, $m)) {
            return (int) $m[1];
        }

        return null;
    }

}

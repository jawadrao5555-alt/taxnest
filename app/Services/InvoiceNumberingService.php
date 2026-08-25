<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

class InvoiceNumberingService
{
    /** Short prefix for every NEW Digital Invoice number. */
    public const PREFIX = 'D';

    /** Zero-pad width; longer numbers simply grow past it (D1000). */
    public const PAD = 3;

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
                // The legacy "{identifier}DI00036" spelling of the SAME sequence
                // counts as taken too: FbrService rebuilds exactly that string as
                // the regulator-facing reference, so reusing the sequence would
                // send FBR a reference it has already filed.
                $legacy = '%DI' . str_pad((string) $seq, 5, '0', STR_PAD_LEFT);

                $taken = Invoice::withoutGlobalScopes()
                    ->where('company_id', $companyId)
                    ->where(function ($q) use ($candidate, $legacy, $like) {
                        $q->where('invoice_number', $candidate)
                          ->orWhere('internal_invoice_number', $candidate)
                          ->orWhere('invoice_number', $like, $legacy)
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

    /** Render a sequence number in the short format (D001, D1000). */
    public static function format(int $sequence): string
    {
        return self::PREFIX . str_pad((string) $sequence, self::PAD, '0', STR_PAD_LEFT);
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

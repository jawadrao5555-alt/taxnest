<?php

namespace App\Support;

use App\Models\Invoice;

/**
 * Who the buyer bought from, as printed on a Digital Invoice.
 *
 * One NTN can trade under a different name at each address, so the branch
 * attached to a sale — head office included — is the business the buyer dealt
 * with. Everything the buyer receives (PDF, public share page, delivery email)
 * must name that one business and nothing else: the shop owner rejected the
 * registered company riding along beside it, because a bill from one shop that
 * also names another shop reads like the wrong document.
 *
 * The registered identity is NOT lost — the NTN printed on the invoice belongs
 * to it, and the FBR payload always carries it (see InvoiceSubmitService).
 */
class InvoiceSellerIdentity
{
    /**
     * @return array{name:string,legal_name:string,address:?string,city:?string}
     */
    public static function for(?Invoice $invoice, string $fallbackName = 'TaxNest'): array
    {
        // Explicit load: production disables lazy loading, and a caller that
        // never eager-loaded the branch must still get the right name rather
        // than silently falling back to the company.
        if ($invoice && $invoice->exists) {
            $invoice->loadMissing('company', 'branch');
        }

        $company = $invoice?->company;
        $branch = $invoice?->branch;

        $clean = static fn ($v) => trim((string) ($v ?? ''));

        $legalName = $clean($company->name ?? null) ?: $fallbackName;
        $branchName = $clean($branch->name ?? null);

        $branchAddress = $clean($branch->address ?? null);
        $branchCity = $clean($branch->city ?? null);

        // The location is taken as a UNIT. A branch row with a blank address
        // must not borrow the head office street and pair it with its own
        // city — that invents an address nobody trades from.
        if ($branch && ($branchAddress !== '' || $branchCity !== '')) {
            $address = $branchAddress;
            $city = $branchCity;
        } else {
            $address = $clean($company->address ?? null);
            $city = $clean($company->city ?? null);
        }

        return [
            'name' => $branchName !== '' ? $branchName : $legalName,
            'legal_name' => $legalName,
            'address' => $address ?: null,
            'city' => $city ?: null,
        ];
    }
}

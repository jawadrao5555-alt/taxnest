<?php

namespace App\Services;

/**
 * CCI-style packaging/deposit movements must not be silently treated as
 * taxable beverages. Classification is conservative: unknowns require review.
 * Product labels alone are not proof of tax treatment.
 */
final class InvoiceImportLineClassifier
{
    public function classify(string $description, ?string $sourceCode = null): string
    {
        $name = strtolower(trim($description));
        if ($name === '') {
            return 'review';
        }

        // Dedicated packaging descriptions only. A beverage in a returnable
        // bottle (e.g. Coca-Cola RB 250ml) remains a beverage sale.
        if (preg_match('/\b(empty (bottle|crate|shell|glass)|empties|bottle deposit|crate deposit|security deposit|return of empties)\b/i', $name)) {
            return 'packaging_review';
        }

        // Negative sales lines may be returns, credits or damaged stock.
        if (preg_match('/\b(damag(ed|e)|breakage|shortage|expired stock|sales return)\b/i', $name)) {
            return 'adjustment_review';
        }

        return 'product_review';
    }
}

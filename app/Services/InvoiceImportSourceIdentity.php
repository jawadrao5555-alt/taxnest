<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * Stable source-invoice identity for cross-batch deduplication.
 * This class builds identity only; a database UNIQUE constraint and atomic
 * insertion must guard actual imports against racing workers.
 */
final class InvoiceImportSourceIdentity
{
    public function fingerprint(int $companyId, string $sourceSystem, string $sourceInvoiceNo, string $supplyDate): string
    {
        $sourceSystem = strtolower(trim($sourceSystem));
        $sourceInvoiceNo = strtoupper(trim($sourceInvoiceNo));
        $supplyDate = trim($supplyDate);

        if ($companyId < 1 || $sourceSystem === '' || $sourceInvoiceNo === ''
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $supplyDate)
            || !checkdate((int) substr($supplyDate, 5, 2), (int) substr($supplyDate, 8, 2), (int) substr($supplyDate, 0, 4))) {
            throw new InvalidArgumentException('A company, source system, original invoice number and valid supply date are required.');
        }

        // Length-prefix encoding avoids separator collisions with arbitrary DMS IDs.
        $parts = [(string) $companyId, $sourceSystem, $sourceInvoiceNo, $supplyDate];
        $encoded = implode('', array_map(static fn (string $v) => strlen($v) . ':' . $v, $parts));

        return hash('sha256', $encoded);
    }

    public function fileFingerprint(string $filePath): string
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new InvalidArgumentException('Import source file is unavailable.');
        }

        $hash = hash_file('sha256', $filePath);
        if ($hash === false) {
            throw new InvalidArgumentException('Unable to fingerprint import file.');
        }

        return $hash;
    }
}

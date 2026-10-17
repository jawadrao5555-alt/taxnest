<?php

namespace App\Services;

use App\Models\Company;
use App\Models\InvoiceImportSourceReceipt;
use App\Models\InvoiceImportVerifiedMapping;

/** Pure local review. This service never calls an external tax authority. */
final class SmartInvoiceImportReview
{
    public function review(array $parsedRows, Company $company, string $system, array $decisions = []): array
    {
        $mappings = InvoiceImportVerifiedMapping::where('company_id', $company->id)
            ->where('source_system', $system)->orderBy('version')->get();
        $catalogue = [];
        foreach ($mappings as $mapping) {
            $catalogue[$mapping->kind][$mapping->source_code] = $mapping;
        }
        $rows = [];
        $groups = [];
        foreach ($parsedRows as $entry) {
            $raw = $entry['original_data'] ?? $entry['data'];
            $data = $raw;
            $errors = $entry['parse_errors'] ?? $entry['errors'] ?? [];
            $class = (new InvoiceImportLineClassifier())->classify((string) ($raw['description'] ?? ''));
            $amounts = [];
            foreach (['gross', 'discount', 'vat', 'net'] as $field) {
                try {
                    $amounts[$field] = InvoiceImportMoney::cents($raw['source_' . $field] ?? '');
                } catch (\InvalidArgumentException $e) {
                    $errors[] = 'source_' . $field . ': ' . $e->getMessage();
                }
            }
            if (count($amounts) === 4 && $amounts['gross'] - $amounts['discount'] + $amounts['vat'] !== $amounts['net']) {
                $errors[] = 'Source gross - discount + VAT does not equal net.';
            }
            // Explicit empty/deposit descriptions remain in packaging review even for returns.
            // Every such row still requires its own exclusion reason and exact reconciliation.
            if ($class !== 'packaging_review' && ((InvoiceImportService::cleanNumber($raw['quantity'] ?? '') ?? 0) < 0 || (count($amounts) === 4 && min($amounts) < 0))) {
                $class = 'adjustment_review';
            }
            $excluded = false;
            if ($class === 'packaging_review') {
                $reason = trim((string) ($decisions[(string) $entry['row']] ?? ''));
                $excluded = $reason !== '' && mb_strlen($reason) <= 500;
                if (!$excluded) {
                    $errors[] = 'Packaging requires explicit exclusion review and a reason.';
                }
            } elseif ($class === 'adjustment_review') {
                $errors[] = 'Negative/damage movement requires separate adjustment or credit-note review; no sale draft will be created.';
            } else {
                $sku = $catalogue['sku'][trim((string) ($raw['source_sku'] ?? ''))] ?? null;
                if (!$sku) {
                    $errors[] = 'Unknown SKU: verified company mapping required.';
                } else {
                    $d = $sku->details;
                    foreach (['description', 'hs_code', 'uom', 'litres_per_case', 'schedule_type', 'tax_rate'] as $required) {
                        if (!isset($d[$required]) || trim((string) $d[$required]) === '') {
                            $errors[] = 'Verified SKU mapping missing ' . $required . '.';
                        }
                    }
                    $rules = ScheduleEngine::resolveValidationRules($d['schedule_type'] ?? 'standard', isset($d['tax_rate']) ? (float) $d['tax_rate'] : null, $company->getStandardTaxRateValue() ?? 18.0);
                    foreach (['requires_mrp' => 'mrp', 'requires_sro' => 'sro_schedule_no', 'requires_serial' => 'sro_serial_no'] as $rule => $field) {
                        if (!empty($rules[$rule]) && empty($d[$field])) {
                            $errors[] = 'Verified SKU ' . $field . ' required; no automatic fallback permitted.';
                        }
                    }
                    foreach (['description', 'hs_code', 'schedule_type', 'tax_rate', 'mrp', 'sro_schedule_no', 'sro_serial_no'] as $key) {
                        $data[$key] = (string) ($d[$key] ?? '');
                    }
                    $data['_default_uom'] = $d['uom'] ?? '';
                    $data['_sku_mapping_id'] = $sku->id;
                    $cases = InvoiceImportService::cleanNumber($raw['quantity'] ?? '');
                    $quantity = $cases === null ? 0 : $cases * (float) ($d['litres_per_case'] ?? 0);
                    if ($quantity <= 0 || abs(round($quantity, 4) - $quantity) > 0.0000001) {
                        $errors[] = 'Case-to-litre conversion is invalid or exceeds supported quantity precision.';
                    } else {
                        $data['quantity'] = number_format($quantity, 4, '.', '');
                        if (count($amounts) === 4) {
                            $taxable = $amounts['gross'] - $amounts['discount'];
                            $data['price'] = number_format($taxable / 100 / $quantity, 2, '.', '');
                            // Existing item storage has two-decimal prices. Reject lossy conversions.
                            if ((int) round((float) $data['price'] * $quantity * 100) !== $taxable) {
                                $errors[] = 'Converted unit price cannot preserve source taxable value at supported price precision.';
                            }
                            $data['tax'] = InvoiceImportMoney::decimal($amounts['vat']);
                        }
                    }
                }
            }
            $buyer = $catalogue['buyer'][trim((string) ($raw['source_outlet'] ?? ''))] ?? null;
            if (!$buyer) {
                $errors[] = 'Unknown outlet: buyer identity and registration verification required.';
            } else {
                foreach (['buyer_name', 'buyer_ntn', 'buyer_cnic', 'buyer_address', 'destination_province'] as $key) {
                    $data[$key] = (string) ($buyer->details[$key] ?? '');
                }
                $data['_buyer_registration_type'] = $buyer->details['registration_type'];
                $data['_buyer_mapping_id'] = $buyer->id;
            }
            $fingerprint = null;
            try {
                $fingerprint = (new InvoiceImportSourceIdentity())->fingerprint((int) $company->id, $system,
                    (string) ($raw['source_invoice_number'] ?? ''), (string) ($raw['invoice_date'] ?? ''));
            } catch (\InvalidArgumentException $e) {
                $errors[] = $e->getMessage() . ' Missing source identity requires review; no guessed fallback.';
            }
            $index = count($rows);
            $rows[] = ['row' => $entry['row'], 'data' => $data, 'original_data' => $raw,
                'parse_errors' => $entry['parse_errors'] ?? $entry['errors'] ?? [],
                'valid' => false, 'errors' => $errors, 'excluded' => $excluded,
                'classification' => $class, 'exclusion_reason' => $excluded ? $reason : null,
                'source_amounts' => $amounts, 'source_fingerprint' => $fingerprint];
            $groups[$fingerprint ?? ('missing:' . $index)][] = $index;
        }
        $summaries = [];
        foreach ($groups as $fingerprint => $indexes) {
            $errors = [];
            $totals = ['gross' => 0, 'discount' => 0, 'vat' => 0, 'net' => 0];
            $excludedTotals = $totals;
            $headers = [];
            $buyers = [];
            $draftKeys = [];
            foreach ($indexes as $i) {
                $errors = array_merge($errors, $rows[$i]['errors']);
                $buyers[(string) ($rows[$i]['original_data']['source_outlet'] ?? '')] = true;
                $headerData = $rows[$i]['data'];
                unset($headerData['_source_fingerprint']);
                $draftKeys[(new InvoiceImportService())->groupKey($headerData)] = true;
                foreach ($totals as $field => $_) {
                    $totals[$field] += $rows[$i]['source_amounts'][$field] ?? 0;
                    if ($rows[$i]['excluded']) {
                        $excludedTotals[$field] += $rows[$i]['source_amounts'][$field] ?? 0;
                    }
                    try {
                        $headers[$field][] = InvoiceImportMoney::cents($rows[$i]['original_data']['invoice_' . $field] ?? '');
                    } catch (\InvalidArgumentException $e) {
                        $errors[] = 'Original invoice ' . $field . ' total required for reconciliation.';
                    }
                }
            }
            foreach ($totals as $field => $value) {
                $values = array_unique($headers[$field] ?? []);
                if (count($values) !== 1 || reset($values) !== $value) {
                    $errors[] = 'Invoice ' . $field . ' total does not reconcile to all source lines.';
                }
            }
            if (count($buyers) !== 1 || count($draftKeys) !== 1) {
                $errors[] = 'Conflicting buyer/date/branch/document headers within one original invoice.';
            }
            $receipt = InvoiceImportSourceReceipt::where('company_id', $company->id)->where('fingerprint', $fingerprint)->first();
            if ($receipt) {
                $errors[] = 'Original invoice already imported (draft/receipt #' . $receipt->invoice_id . '). Use existing invoice; never re-upload to submit again.';
            }
            $included = array_filter($indexes, fn ($i) => !$rows[$i]['excluded']);
            if (!$included) {
                $errors[] = 'No taxable product lines remain after exclusions.';
            }
            $errors = array_values(array_unique($errors));
            foreach ($indexes as $i) {
                $rows[$i]['errors'] = $errors;
                $rows[$i]['valid'] = !$errors && !$rows[$i]['excluded'];
                $rows[$i]['data']['_smart_source_system'] = $system;
                $rows[$i]['data']['_source_fingerprint'] = $rows[$i]['source_fingerprint'];
            }
            $summaries[] = ['source_invoice_number' => $rows[$indexes[0]]['original_data']['source_invoice_number'] ?? '',
                'invoice_date' => $rows[$indexes[0]]['original_data']['invoice_date'] ?? '',
                'rows' => array_map(fn ($i) => $rows[$i]['row'], $indexes),
                'source_totals' => $totals, 'excluded_totals' => $excludedTotals,
                'draft_totals' => array_combine(array_keys($totals), array_map(fn ($value, $field) => $value - $excludedTotals[$field], $totals, array_keys($totals))),
                'errors' => $errors];
        }
        $legacy = (new InvoiceImportService())->validateRows(array_values(array_filter($rows, fn ($row) => $row['valid'])), $company);
        $validated = [];
        foreach ($legacy['rows'] as $row) {
            $validated[$row['row']] = $row;
        }
        foreach ($rows as &$row) {
            if (isset($validated[$row['row']])) {
                $uom = $row['data']['_default_uom'] ?? null;
                $row['data'] = $validated[$row['row']]['data'];
                $row['data']['_default_uom'] = $uom;
                $row['errors'] = $validated[$row['row']]['errors'];
            }
        }
        unset($row);
        $byRow = [];
        foreach ($rows as $index => $row) {
            $byRow[$row['row']] = $index;
        }
        foreach ($summaries as &$summary) {
            foreach ($summary['rows'] as $number) {
                $summary['errors'] = array_values(array_unique(array_merge($summary['errors'], $rows[$byRow[$number]]['errors'])));
            }
            if (!$summary['errors']) {
                $includedRows = array_values(array_filter(array_map(fn ($number) => $rows[$byRow[$number]], $summary['rows']), fn ($row) => !$row['excluded']));
                $first = $includedRows[0]['data'];
                $preview = new \App\Models\Invoice(array_merge($first, ['company_id' => $company->id, 'internal_invoice_number' => 'IMPORT-PREVIEW', 'buyer_registration_type' => $first['_buyer_registration_type']]));
                $preview->setRelation('company', $company);
                $preview->setRelation('items', collect($includedRows)->map(fn ($row) => new \App\Models\InvoiceItem(array_merge($row['data'], [
                    'default_uom' => $row['data']['_default_uom'], 'sale_type' => $row['data']['_sale_type'], 'serial_no' => $row['data']['sro_serial_no'],
                ]))));
                try {
                    $payload = (new FbrService())->buildPayload($preview, true);
                    foreach ($includedRows as $n => $row) {
                        $item = $payload['items'][$n] ?? [];
                        $expected = ['valueSalesExcludingST' => $row['source_amounts']['gross'] - $row['source_amounts']['discount'],
                            'salesTaxApplicable' => $row['source_amounts']['vat'], 'totalValues' => $row['source_amounts']['net']];
                        foreach ($expected as $field => $value) {
                            if (InvoiceImportMoney::cents(number_format((float) ($item[$field] ?? -1), 2, '.', '')) !== $value) {
                                $summary['errors'][] = 'Existing DI payload ' . $field . ' differs from reviewed source row ' . $row['row'] . '; review tax/MRP treatment. Source amounts were not changed.';
                            }
                        }
                        if (($item['uoM'] ?? '') !== $row['data']['_default_uom']) {
                            $summary['errors'][] = 'Existing DI payload UoM differs from verified SKU mapping.';
                        }
                    }
                } catch (\Throwable $e) {
                    $summary['errors'][] = 'Local DI payload preview failed: ' . $e->getMessage();
                }
            }
            foreach ($summary['rows'] as $number) {
                $index = $byRow[$number];
                $rows[$index]['errors'] = $summary['errors'];
                $rows[$index]['valid'] = !$rows[$index]['excluded'] && !$summary['errors'];
            }
        }
        unset($summary);
        return ['rows' => $rows, 'invoices' => $summaries];
    }
}

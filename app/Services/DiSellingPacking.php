<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Optional selling units; the persisted invoice remains expressed in its validated fiscal UOM. */
final class DiSellingPacking
{
    public static function definitions(array $rows, string $uom, array $existing = []): array
    {
        if (count($rows) > 30) {
            self::reject('selling_packings', 'At most 30 packings are supported per product.');
        }
        $result = [];
        $labels = [];
        foreach ($rows as $index => $row) {
            if (! is_array($row) || ! is_string($row['label'] ?? null)
                || (isset($row['id']) && ! is_string($row['id']))
                || (isset($row['barcode']) && ! is_string($row['barcode']))) {
                self::reject("selling_packings.$index", 'Enter a label, decimal factor and optional text barcode.');
            }
            $label = trim((string) ($row['label'] ?? ''));
            if ($label === '' || mb_strlen($label) > 80) {
                self::reject("selling_packings.$index.label", 'Enter a packing label of at most 80 characters.');
            }
            if (in_array(mb_strtolower($label), $labels, true)) {
                self::reject("selling_packings.$index.label", 'Packing labels must be unique for this product.');
            }
            $labels[] = mb_strtolower($label);
            $factor = self::decimal($row['factor'] ?? null, "selling_packings.$index.factor", 6);
            $id = (string) ($row['id'] ?? '');
            if ($id !== '' && ! collect($existing)->contains('id', $id)) {
                self::reject("selling_packings.$index.id", 'Reload the product before editing its packings.');
            }
            if ($id !== '' && collect($result)->contains('id', $id)) {
                self::reject("selling_packings.$index.id", 'Each packing can occur only once.');
            }
            $price = isset($row['price']) && $row['price'] !== '' ? self::decimal($row['price'], "selling_packings.$index.price", 2) : null;
            $barcode = trim((string) ($row['barcode'] ?? ''));
            if (mb_strlen($barcode) > 80) {
                self::reject("selling_packings.$index.barcode", 'Barcode is too long.');
            }
            $result[] = ['id' => $id ?: (string) Str::uuid(), 'label' => $label,
                'factor' => (string) $factor, 'base_uom' => $uom, 'price' => $price === null ? null : (string) $price,
                'barcode' => $barcode ?: null];
        }

        return $result;
    }

    /** Normalize only explicitly selected packing lines. Legacy base-unit lines are byte-for-byte unchanged. */
    public static function normalize(array $items, int $companyId, ?Invoice $editing = null, ?Invoice $returnSource = null): array
    {
        foreach ($items as $index => &$item) {
            // Callers cannot supply their own historical evidence or conversion factor.
            unset($item['selling_snapshot']);
            if (empty($item['selling_packing_id'])) {
                if ($editing && ! empty($item['invoice_item_id'])) {
                    $old = $editing->items()->whereKey($item['invoice_item_id'])->first();
                    if (! $old) {
                        self::reject("items.$index.invoice_item_id", 'Invoice line is not part of this draft.');
                    }
                    $same = true;
                    foreach (['quantity', 'price', 'tax', 'mrp', 'tax_rate'] as $field) {
                        if ((float) ($item[$field] ?? 0) !== (float) ($old->$field ?? 0)) {
                            $same = false;
                        }
                    }
                    foreach (['hs_code', 'default_uom', 'schedule_type'] as $field) {
                        if ((string) ($item[$field] ?? '') !== (string) ($old->$field ?? '')) {
                            $same = false;
                        }
                    }
                    if ($same && $old->selling_snapshot) {
                        $item['selling_snapshot'] = $old->selling_snapshot;
                    }
                }

                continue;
            }
            if (! Schema::hasColumn('invoice_items', 'selling_snapshot')) {
                self::reject("items.$index.selling_packing_id", 'Packing support is not installed yet.');
            }
            $product = Product::withoutGlobalScopes()->where('company_id', $companyId)->whereKey($item['product_id'] ?? 0)->first();
            $pack = $product ? collect($product->selling_packings ?? [])->firstWhere('id', $item['selling_packing_id']) : null;
            if (! $returnSource && (! $product || ! $product->is_active || ! $pack)) {
                self::reject("items.$index.selling_packing_id", 'Select a current packing belonging to this company and product.');
            }
            $sourceItem = null;
            if ($returnSource) {
                $sourceItem = $returnSource->items()->whereKey($item['source_invoice_item_id'] ?? 0)->first();
                $original = $sourceItem?->selling_snapshot;
                if (! $original || ($original['packing_id'] ?? null) !== $item['selling_packing_id']
                    || (int) ($original['company_id'] ?? 0) !== $companyId) {
                    self::reject("items.$index.source_invoice_item_id", 'Select the original packing line from this invoice.');
                }
                $product = new Product(['company_id' => $companyId, 'uom' => $original['base_uom'],
                    'hs_code' => $original['hs_code'], 'is_active' => true]);
                $product->id = $original['product_id'];
                $pack = ['id' => $original['packing_id'], 'label' => $original['label'],
                    'base_uom' => $original['base_uom'], 'factor' => $original['factor']];
            }
            $fiscal = app(FbrService::class);
            $baseUom = $fiscal->resolveUomForHsCode(null, $product->uom);
            if (($pack['base_uom'] ?? null) !== $product->uom
                || self::comparableUom($fiscal->resolveUomForHsCode(null, $item['default_uom'] ?? $product->uom)) !== self::comparableUom($baseUom)
                || preg_replace('/[^0-9]/', '', (string) ($item['hs_code'] ?? '')) !== preg_replace('/[^0-9]/', '', $product->hs_code)) {
                self::reject("items.$index.selling_packing_id", 'Product HS/UOM changed. Re-select the product and confirm its packing.');
            }
            $quantity = self::decimal($item['quantity'] ?? null, "items.$index.quantity", 4);
            $price = self::decimal($item['price'] ?? null, "items.$index.price", 6);
            $factor = self::decimal($pack['factor'], "items.$index.selling_packing_id", 6);
            $enteredPrice = $price;
            $inclusive = filter_var($item['price_includes_tax'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if ($sourceItem) {
                $prior = $sourceItem->selling_snapshot;
                if ($inclusive !== (bool) ($prior['price_includes_tax'] ?? false)
                    || ! $enteredPrice->isEqualTo((string) $prior['selling_price'])
                    || ! BigDecimal::of((string) ($item['mrp'] ?? 0))->isEqualTo((string) ($prior['selling_mrp'] ?? 0))
                    || (float) ($item['tax_rate'] ?? 0) !== (float) $sourceItem->tax_rate
                    || (string) ($item['schedule_type'] ?? 'standard') !== (string) $sourceItem->schedule_type) {
                    self::reject("items.$index.price", 'A packing return must retain the original selling rate, tax treatment and basis.');
                }
            }
            if ($inclusive) {
                $rate = BigDecimal::of((string) ($item['tax_rate'] ?? 0))->dividedBy('100', 8, RoundingMode::HALF_UP);
                $schedule = $item['schedule_type'] ?? 'standard';
                if ($schedule === '3rd_schedule') {
                    $mrp = self::decimal($item['mrp'] ?? null, "items.$index.mrp", 6);
                    $price = $price->minus($mrp->multipliedBy($rate))->toScale(6, RoundingMode::HALF_UP);
                } elseif (! in_array($schedule, ['exempt', 'zero_rated'], true)) {
                    $price = $price->dividedBy(BigDecimal::of('1')->plus($rate), 6, RoundingMode::HALF_UP);
                }
                if (! $price->isPositive()) {
                    self::reject("items.$index.price", 'Tax-inclusive rate leaves no positive sale consideration.');
                }
            }
            try {
                $baseQuantity = $quantity->multipliedBy($factor)->toScale(4, RoundingMode::UNNECESSARY);
            } catch (\Brick\Math\Exception\RoundingNecessaryException) {
                self::reject("items.$index.quantity", 'This packing quantity exceeds fiscal precision (four decimal places).');
            }
            if ($baseQuantity->isGreaterThan('999999999999.9999')) {
                self::reject("items.$index.quantity", 'Converted quantity is too large.');
            }
            $basePrice = $price->dividedBy($factor, 6, RoundingMode::HALF_UP);
            $sellingTotal = $quantity->multipliedBy($price)->toScale(2, RoundingMode::HALF_UP);
            $baseTotal = $baseQuantity->multipliedBy($basePrice)->toScale(2, RoundingMode::HALF_UP);
            if (! $baseTotal->isEqualTo($sellingTotal)) {
                self::reject("items.$index.price", 'Rate cannot be represented exactly at fiscal precision. Adjust the rate or use the base unit.');
            }
            $snapshot = ['version' => 1, 'company_id' => $companyId, 'product_id' => (int) $product->id,
                'packing_id' => $pack['id'], 'label' => $pack['label'], 'hs_code' => $product->hs_code,
                'base_uom' => (string) ($item['default_uom'] ?? $product->uom), 'factor' => (string) $factor, 'selling_quantity' => (string) $quantity,
                'selling_price' => (string) $enteredPrice, 'selling_net_price' => (string) $price, 'price_includes_tax' => $inclusive, 'base_quantity' => (string) $baseQuantity,
                'base_price' => (string) $basePrice, 'selling_total' => (string) $sellingTotal];
            if ($sourceItem) {
                $snapshot['source_invoice_id'] = (int) $returnSource->id;
                $snapshot['source_invoice_item_id'] = (int) $sourceItem->id;
            }
            // MRP on the form is expressed in the same selected selling unit as its rate.
            if (isset($item['mrp']) && (string) $item['mrp'] !== '' && (float) $item['mrp'] > 0) {
                $mrp = self::decimal($item['mrp'], "items.$index.mrp", 6);
                $baseMrp = $mrp->dividedBy($factor, 6, RoundingMode::HALF_UP);
                if (! $mrp->multipliedBy($quantity)->toScale(2, RoundingMode::HALF_UP)
                    ->isEqualTo($baseMrp->multipliedBy($baseQuantity)->toScale(2, RoundingMode::HALF_UP))) {
                    self::reject("items.$index.mrp", 'MRP conversion exceeds fiscal precision. Use the base unit.');
                }
                $snapshot['selling_mrp'] = (string) $mrp;
                $item['mrp'] = (string) $baseMrp;
            }
            if (! BigDecimal::of(number_format((float) (string) $baseQuantity, 4, '.', ''))->isEqualTo($baseQuantity)
                || ! BigDecimal::of(number_format((float) (string) $basePrice, 6, '.', ''))->isEqualTo($basePrice)
                || $sellingTotal->isGreaterThan('9999999999999.99')) {
                self::reject("items.$index.price", 'Converted values exceed supported invoice precision or amount.');
            }
            $item['quantity'] = (string) $baseQuantity;
            $item['price'] = (string) $basePrice;
            $item['default_uom'] = $snapshot['base_uom'];
            $item['selling_snapshot'] = $snapshot;
            $money = DiInvoiceMath::line($item);
            $item['tax'] = $money['salesTaxApplicable'];
            if (! $sellingTotal->isEqualTo(number_format($money['valueSalesExcludingST'], 2, '.', ''))
                || $money['totalValues'] > 9999999999999.99) {
                self::reject("items.$index.price", 'Converted invoice amount exceeds supported fiscal precision.');
            }
            if ($inclusive && ! $enteredPrice->multipliedBy($quantity)->toScale(2, RoundingMode::HALF_UP)
                ->isEqualTo(number_format($money['valueSalesExcludingST'] + $money['salesTaxApplicable'], 2, '.', ''))) {
                self::reject("items.$index.price", 'Tax-inclusive rate cannot reconcile at fiscal precision. Use the base unit or adjust the rate.');
            }
        }
        unset($item);

        return $items;
    }

    public static function normalizeRequest(\Illuminate\Http\Request $request, int $companyId, ?Invoice $editing = null): array
    {
        $source = null;
        $items = $request->input('items', []);
        if ($request->input('document_type') === 'Credit Note' && collect($items)->contains(fn ($item) => ! empty($item['selling_packing_id']))) {
            $reference = (string) $request->input('reference_invoice_number');
            $matches = Invoice::withoutGlobalScopes()->where('company_id', $companyId)
                ->where('document_type', 'Sale Invoice')->where(fn ($q) => $q->where('invoice_number', $reference)
                ->orWhere('internal_invoice_number', $reference)->orWhere('fbr_invoice_number', $reference))->limit(2)->get();
            if ($matches->count() !== 1 || ! $matches->first()->isLocked()) {
                self::reject('reference_invoice_number', 'Choose one completed original invoice for a packing return.');
            }
            $source = $matches->first();
        }

        return self::normalize($items, $companyId, $editing, $source);
    }

    /** Called inside the invoice write transaction; original invoice locks serialize partial returns. */
    public static function validateReturns(array $items, int $companyId, ?int $editingId = null): void
    {
        $returnLines = collect($items)->filter(fn ($item) => ! empty($item['selling_snapshot']['source_invoice_item_id']));
        $sourceIds = $returnLines->pluck('selling_snapshot.source_invoice_id')->unique()->sort()->values();
        foreach ($sourceIds as $id) {
            Invoice::withoutGlobalScopes()->where('company_id', $companyId)->whereKey($id)->lockForUpdate()->firstOrFail();
        }
        foreach ($returnLines->groupBy('selling_snapshot.source_invoice_item_id') as $id => $lines) {
            $original = InvoiceItem::whereKey($id)->whereIn('invoice_id', $sourceIds)->firstOrFail();
            $used = InvoiceItem::where('selling_snapshot->source_invoice_item_id', (int) $id)
                ->whereHas('invoice', fn ($q) => $q->where('company_id', $companyId)->where('document_type', 'Credit Note')
                    ->when($editingId, fn ($q) => $q->where('id', '!=', $editingId)))->sum('quantity');
            $requested = BigDecimal::of((string) $used);
            foreach ($lines as $line) {
                $requested = $requested->plus((string) $line['quantity']);
            }
            if ($requested->isGreaterThan((string) $original->quantity)) {
                self::reject('items', 'Packing return quantity exceeds the original invoice after earlier returns.');
            }
        }
    }

    private static function comparableUom(string $uom): string
    {
        return $uom === 'Pcs' ? 'Numbers, pieces, units' : $uom;
    }

    private static function decimal(mixed $value, string $field, int $scale): BigDecimal
    {
        if (! is_scalar($value) || ! preg_match('/^\d{1,12}(?:\.\d{1,'.$scale.'})?$/D', (string) $value)) {
            self::reject($field, 'Enter a positive decimal with at most '.$scale.' decimal places.');
        }
        $decimal = BigDecimal::of((string) $value);
        if (! $decimal->isPositive()) {
            self::reject($field, 'Value must be greater than zero.');
        }

        return $decimal;
    }

    private static function reject(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}

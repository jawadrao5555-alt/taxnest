<?php

namespace App\Services;

use App\Models\Company;
use App\Models\PosDiSandboxSubmission;
use App\Models\PosDiSandboxProfile;
use App\Models\PosTransaction;
use App\Support\PraDiSandboxGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Explicit sandbox copy. No checkout observer or FBR production switch. */
class PraDiSandboxService
{
    public function capture(Company $company, int $transactionId, int $actorId): PosDiSandboxSubmission
    {
        PraDiSandboxGate::enforce($company->id);
        abort_unless($company->product_type === 'pos', 403);
        return DB::transaction(function () use ($company, $transactionId, $actorId) {
            $sale = PosTransaction::where('company_id', $company->id)->whereKey($transactionId)
                ->lockForUpdate()->firstOrFail();
            abort_unless($sale->status === 'completed', 422, 'Only completed test sales or returns can be rehearsed.');
            abort_unless(in_array($sale->transaction_type ?? 'sale', ['sale', 'return'], true), 422);
            $existing = PosDiSandboxSubmission::where('company_id', $company->id)
                ->where('source_transaction_id', $sale->id)->first();
            if ($existing) return $existing;

            $lines = $sale->items()->orderBy('id')->get()->map(function ($line) {
                if ($line->taxable_base === null || $line->exempt_base === null || $line->tax_amount === null) {
                    throw ValidationException::withMessages(['transaction_id' => 'Stored line tax snapshots are missing. Rehearse a new fictional sale; legacy amounts will not be guessed.']);
                }
                return [
                    'name' => $line->item_name, 'quantity' => (float) $line->quantity,
                    'base' => round((float) $line->taxable_base + (float) $line->exempt_base, 2),
                    'pra_tax' => (float) $line->tax_amount,
                ];
            })->values()->all();
            abort_if(!$lines, 422, 'The test sale has no lines.');

            return PosDiSandboxSubmission::create([
                'company_id' => $company->id, 'source_transaction_id' => $sale->id,
                'branch_id' => $sale->branch_id, 'created_by' => $actorId,
                'state' => 'draft', 'attempts' => [],
                'snapshot' => [
                    'company_name' => $company->name, 'seller_ntn' => $company->ntn,
                    'seller_fbr_id' => $this->sellerIdentity($company->fbr_registration_no ?: $company->ntn),
                    'seller_address' => $company->address, 'seller_province' => $company->province,
                    'invoice_number' => $sale->invoice_number, 'date' => $sale->created_at->format('Y-m-d'),
                    'transaction_type' => $sale->transaction_type ?? 'sale',
                    'parent_transaction_id' => $sale->parent_transaction_id,
                    'subtotal' => (float) $sale->subtotal, 'discount' => (float) $sale->discount_amount,
                    'pra_tax' => (float) $sale->tax_amount, 'total' => (float) $sale->total_amount,
                    'payment_method' => $sale->payment_method,
                    'pra_status' => $sale->pra_status, 'pra_number' => $sale->pra_invoice_number,
                    'lines' => $lines,
                ],
            ]);
        });
    }

    public function template(PosDiSandboxSubmission $record): array
    {
        $s = $record->snapshot;
        return [
            'invoiceType' => $s['transaction_type'] === 'return' ? 'Debit Note' : 'Sale Invoice',
            'invoiceDate' => $s['date'], 'sellerNTNCNIC' => $s['seller_fbr_id'] ?? $this->sellerIdentity($s['seller_ntn'] ?? ''),
            'sellerBusinessName' => $s['company_name'], 'sellerProvince' => $s['seller_province'] ?? '',
            'sellerAddress' => $s['seller_address'] ?? '', 'buyerNTNCNIC' => '',
            'buyerBusinessName' => 'Fictional walk-in customer', 'buyerProvince' => '',
            'buyerAddress' => 'Fictional test address', 'buyerRegistrationType' => 'Unregistered',
            'invoiceRefNo' => '', 'scenarioId' => '',
            'items' => array_map(fn ($line) => [
                'hsCode' => '', 'productDescription' => $line['name'], 'rate' => '',
                'uoM' => '', 'quantity' => $line['quantity'], 'totalValues' => null,
                'valueSalesExcludingST' => $line['base'], 'fixedNotifiedValueOrRetailPrice' => 0,
                'salesTaxApplicable' => null, 'salesTaxWithheldAtSource' => 0, 'extraTax' => 0,
                'furtherTax' => 0, 'sroScheduleNo' => '', 'fedPayable' => 0,
                'discount' => 0, 'saleType' => '', 'sroItemSerialNo' => '',
            ], $s['lines']),
        ];
    }

    public function prepare(int $companyId, int $id, array $payload): PosDiSandboxSubmission
    {
        PraDiSandboxGate::enforce($companyId);
        return DB::transaction(function () use ($companyId, $id, $payload) {
            $record = $this->locked($companyId, $id);
            abort_unless(in_array($record->state, ['draft', 'rejected'], true), 409, 'This outcome must be preserved; it cannot be edited or replayed.');
            $this->validatePayload($record, $payload);
            $record->payload = $payload;
            // Hash the encrypted-cast round trip used for submission. Whole-number
            // decimals (100.0) may normalize to integers (100) when stored as JSON.
            $record->payload_hash = hash('sha256', json_encode($record->payload, JSON_THROW_ON_ERROR));
            $record->state = 'draft';
            $record->save();
            return $record;
        });
    }

    public function submit(int $companyId, int $id, int $actorId): PosDiSandboxSubmission
    {
        PraDiSandboxGate::enforce($companyId);
        $company = Company::findOrFail($companyId);
        abort_unless($company->product_type === 'pos', 403);
        // Explicit environment: never read a production token, URL override or demo flag.
        $profile = PosDiSandboxProfile::where('company_id', $companyId)->first();
        // Hosted pilots must use the separate POS sandbox credential. Legacy
        // local rehearsals alone may use the pre-existing local DI credential.
        $token = $profile ? (string) $profile->sandbox_token
            : (PraDiSandboxGate::hostedAvailable() ? '' : app(FbrService::class)->resolveDiToken($company, 'sandbox'));
        if ($token === '') throw ValidationException::withMessages(['sandbox' => 'Configure this company\'s DI sandbox token in Sandbox setup first.']);

        $record = DB::transaction(function () use ($companyId, $id, $actorId) {
            Company::whereKey($companyId)->lockForUpdate()->firstOrFail();
            PraDiSandboxGate::enforce($companyId);
            $record = $this->locked($companyId, $id);
            abort_unless($record->state === 'draft' && $record->payload, 409, 'Prepare a reviewed payload first. Accepted or unknown submissions cannot be replayed.');
            $this->validatePayload($record, $record->payload);
            $attempts = $record->attempts ?? [];
            $attempts[] = ['actor_id' => $actorId, 'at' => now()->toIso8601String(),
                'hash' => $record->payload_hash, 'payload' => $record->payload, 'state' => 'submitting'];
            $record->fill(['state' => 'submitting', 'attempted_at' => now(), 'attempts' => $attempts])->save();
            return $record;
        });

        // External I/O outside the DB lock; never retry an uncertain POST.
        $result = app(FbrService::class)->submitPosSandboxPayload($record->payload, $token, $companyId);
        return DB::transaction(function () use ($companyId, $id, $result) {
            $record = $this->locked($companyId, $id);
            abort_unless($record->state === 'submitting', 409);
            $attempts = $record->attempts;
            $attempts[array_key_last($attempts)]['state'] = $result['state'];
            $attempts[array_key_last($attempts)]['error_codes'] = $result['error_codes'];
            $record->fill(['state' => $result['state'], 'sandbox_invoice_number' => $result['reference'], 'attempts' => $attempts])->save();
            return $record;
        });
    }

    private function locked(int $companyId, int $id): PosDiSandboxSubmission
    {
        return PosDiSandboxSubmission::where('company_id', $companyId)->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    private function sellerIdentity(?string $value): string
    {
        $value = trim($value ?? '');
        // Recognize explicit display formats only. Never truncate an unknown
        // identifier into a different seller; validation rejects malformed IDs.
        if (preg_match('/^([0-9]{7})-[0-9]$/', $value, $match)) return $match[1];
        if (preg_match('/^[0-9]{5}-[0-9]{7}-[0-9]$/', $value)) return str_replace('-', '', $value);
        return $value;
    }

    private function validatePayload(PosDiSandboxSubmission $record, array $p): void
    {
        $template = $this->template($record);
        $rules = [
            'invoiceType' => 'required|in:Sale Invoice,Debit Note', 'invoiceDate' => 'required|date_format:Y-m-d',
            'sellerNTNCNIC' => ['required', 'regex:/^(?:[0-9]{7}|[0-9]{13})$/'],
            'sellerBusinessName' => 'required|string|max:200', 'sellerProvince' => 'required|string|max:80',
            'sellerAddress' => 'required|string|max:500', 'buyerNTNCNIC' => 'present|nullable|string|max:13',
            'buyerBusinessName' => 'required|string|max:200', 'buyerProvince' => 'required|string|max:80',
            'buyerAddress' => 'required|string|max:500', 'buyerRegistrationType' => 'required|in:Registered,Unregistered',
            'invoiceRefNo' => 'present|nullable|string|max:100', 'scenarioId' => ['required', 'regex:/^SN[0-9]{3}$/'],
            'items' => 'required|array|list|size:'.count($template['items']),
            'items.*.hsCode' => ['required', 'regex:/^[0-9]{4}\.[0-9]{4}$/'],
            'items.*.productDescription' => 'required|string|max:500',
            'items.*.rate' => 'required|string|max:100', 'items.*.uoM' => 'required|string|max:100',
            'items.*.saleType' => 'required|string|max:150', 'items.*.quantity' => 'required|numeric|gt:0',
            'items.*.sroScheduleNo' => 'present|nullable|string|max:100',
            'items.*.sroItemSerialNo' => 'present|nullable|string|max:100',
        ];
        foreach (['totalValues', 'valueSalesExcludingST', 'fixedNotifiedValueOrRetailPrice', 'salesTaxApplicable', 'salesTaxWithheldAtSource', 'extraTax', 'furtherTax', 'fedPayable', 'discount'] as $field) {
            $rules['items.*.'.$field] = 'required|numeric|min:0|max:999999999999';
        }
        Validator::make($p, $rules)->validate();
        $errors = [];
        if (array_diff(array_keys($p), array_keys($template))) $errors[] = 'Unexpected invoice fields.';
        foreach (['invoiceType', 'invoiceDate', 'sellerNTNCNIC', 'sellerBusinessName'] as $field) {
            if ((string) $p[$field] !== (string) $template[$field]) $errors[] = "The source {$field} must stay unchanged.";
        }
        foreach ($p['items'] as $i => $line) {
            if (array_diff(array_keys($line), array_keys($template['items'][$i]))) $errors[] = 'Unexpected item fields.';
            if (abs((float) $line['quantity'] - $template['items'][$i]['quantity']) > 0.0001
                || abs((float) $line['valueSalesExcludingST'] - $template['items'][$i]['valueSalesExcludingST']) > 0.005
                || (float) $line['discount'] !== 0.0) {
                $errors[] = 'Keep original quantities and net bases; stored discounts are already included.';
            }
        }
        $parentId = $record->snapshot['parent_transaction_id'] ?? null;
        if ($p['invoiceType'] === 'Debit Note') {
            $parent = PosDiSandboxSubmission::where('company_id', $record->company_id)
                ->where('source_transaction_id', $parentId)->where('state', 'accepted')->first();
            if (!$parent || !$parent->sandbox_invoice_number || $p['invoiceRefNo'] !== $parent->sandbox_invoice_number) {
                $errors[] = 'A return requires the accepted FBR SANDBOX reference of its original sale.';
            }
        } elseif (!empty($p['invoiceRefNo'])) $errors[] = 'A sale cannot reference another invoice.';
        if ($errors) throw ValidationException::withMessages(['payload_json' => $errors]);
    }
}

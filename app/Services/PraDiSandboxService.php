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

/** One source sale, separate environment-bound fiscal evidence. Manual submission. */
class PraDiSandboxService
{
    public function capture(Company $company, int $transactionId, int $actorId, ?string $expectedEnvironment = null): PosDiSandboxSubmission
    {
        PraDiSandboxGate::enforce($company->id);
        abort_unless($company->product_type === 'pos', 403);
        return DB::transaction(function () use ($company, $transactionId, $actorId, $expectedEnvironment) {
            Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
            $environment = PraDiSandboxGate::environment($company->id);
            PraDiSandboxGate::enforce($company->id, $environment);
            abort_if(($expectedEnvironment !== null && $expectedEnvironment !== $environment)
                || ($environment === 'production' && $expectedEnvironment !== 'production'), 409,
                'DI environment changed. Refresh and explicitly confirm the intended environment.');
            if (\Illuminate\Support\Facades\Schema::hasTable('pos_ims_submissions')) PosImsReportingService::preventOtherFederalRecord($company->id, $transactionId, $environment, 'di');
            $sale = PosTransaction::where('company_id', $company->id)->whereKey($transactionId)
                ->lockForUpdate()->firstOrFail();
            abort_unless($sale->status === 'completed', 422, 'Only completed sales or returns can be prepared.');
            abort_unless(in_array($sale->transaction_type ?? 'sale', ['sale', 'return'], true), 422);
            $existing = PosDiSandboxSubmission::where('company_id', $company->id)
                ->where('source_transaction_id', $sale->id)->where('environment', $environment)->first();
            if ($existing) return $existing;

            return PosDiSandboxSubmission::create([
                'company_id' => $company->id, 'source_transaction_id' => $sale->id,
                'branch_id' => $sale->branch_id, 'created_by' => $actorId,
                'state' => 'draft', 'attempts' => [], 'environment' => $environment,
                'snapshot' => PosFiscalSnapshot::capture($company, $sale),
            ]);
        });
    }

    public function template(PosDiSandboxSubmission $record): array
    {
        $s = $record->snapshot;
        $sandbox = $record->reportingEnvironment() === 'sandbox';
        return [
            'invoiceType' => $s['transaction_type'] === 'return' ? 'Debit Note' : 'Sale Invoice',
            'invoiceDate' => $s['date'], 'sellerNTNCNIC' => $s['seller_fbr_id'] ?? $this->sellerIdentity($s['seller_ntn'] ?? ''),
            'sellerBusinessName' => $s['seller_fbr_name'] ?? $s['company_name'], 'sellerProvince' => $s['seller_province'] ?? '',
            'sellerAddress' => $s['seller_address'] ?? '', 'buyerNTNCNIC' => '',
            'buyerBusinessName' => $sandbox ? 'Fictional walk-in customer' : '', 'buyerProvince' => '',
            'buyerAddress' => $sandbox ? 'Fictional test address' : '', 'buyerRegistrationType' => 'Unregistered',
            'invoiceRefNo' => '', ...($sandbox ? ['scenarioId' => ''] : []),
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
            PraDiSandboxGate::enforce($companyId, $record->reportingEnvironment());
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
        [$record, $token] = DB::transaction(function () use ($company, $companyId, $id, $actorId) {
            Company::whereKey($companyId)->lockForUpdate()->firstOrFail();
            $record = $this->locked($companyId, $id);
            $environment = $record->reportingEnvironment();
            PraDiSandboxGate::enforce($companyId, $environment);
            if (\Illuminate\Support\Facades\Schema::hasTable('pos_ims_submissions')) PosImsReportingService::preventOtherFederalRecord($companyId, $record->source_transaction_id, $environment, 'di');
            $profile = PosDiSandboxProfile::where('company_id', $companyId)->first();
            $token = $profile ? (string) $profile->{$environment.'_token'}
                : ($environment === 'sandbox' && !PraDiSandboxGate::hostedAvailable()
                    ? app(FbrService::class)->resolveDiToken($company, 'sandbox') : '');
            if ($token === '') throw ValidationException::withMessages([$environment => 'Configure the selected environment\'s DI token first.']);
            abort_unless($record->state === 'draft' && $record->payload, 409, 'Prepare a reviewed payload first. Accepted or unknown submissions cannot be replayed.');
            $this->validatePayload($record, $record->payload);
            abort_unless(hash_equals((string) $record->payload_hash, hash('sha256', json_encode($record->payload, JSON_THROW_ON_ERROR))), 409, 'Stored payload integrity check failed.');
            $attempts = $record->attempts ?? [];
            $attempts[] = ['environment' => $environment, 'actor_id' => $actorId, 'at' => now()->toIso8601String(),
                'hash' => $record->payload_hash, 'payload' => $record->payload, 'state' => 'submitting'];
            $record->fill(['state' => 'submitting', 'attempted_at' => now(), 'attempts' => $attempts])->save();
            return [$record, $token];
        });

        // External I/O outside the DB lock; never retry an uncertain POST.
        $result = app(FbrService::class)->submitPosDiPayload($record->payload, $token, $record->reportingEnvironment(), $companyId);
        return DB::transaction(function () use ($companyId, $id, $result) {
            $record = $this->locked($companyId, $id);
            abort_unless($record->state === 'submitting', 409);
            $attempts = $record->attempts;
            $attempts[array_key_last($attempts)]['state'] = $result['state'];
            $attempts[array_key_last($attempts)]['error_codes'] = $result['error_codes'];
            $referenceField = $record->reportingEnvironment() === 'production' ? 'production_invoice_number' : 'sandbox_invoice_number';
            $record->fill(['state' => $result['state'], $referenceField => $result['reference'], 'attempts' => $attempts])->save();
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
            'invoiceRefNo' => 'present|nullable|string|max:100',
            'scenarioId' => $record->reportingEnvironment() === 'sandbox' ? ['required', 'regex:/^SN[0-9]{3}$/'] : ['prohibited'],
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
                ->where('source_transaction_id', $parentId)->where('environment', $record->reportingEnvironment())->where('state', 'accepted')->first();
            if (!$parent || !$parent->fiscalReference() || $p['invoiceRefNo'] !== $parent->fiscalReference()) {
                $errors[] = 'A return requires the accepted FBR '.strtoupper($record->reportingEnvironment()).' reference of its original sale.';
            }
        } elseif (!empty($p['invoiceRefNo'])) $errors[] = 'A sale cannot reference another invoice.';
        if ($errors) throw ValidationException::withMessages(['payload_json' => $errors]);
    }
}

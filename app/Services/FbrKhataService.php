<?php

namespace App\Services;

use App\Models\FbrCustomerLedger;
use App\Models\FbrKhataSettlement;
use App\Models\PosCustomer;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * The single write path for FBR POS customer credit.
 *
 * Ledger rows are authoritative and customer.khata_balance is a locked cache.
 * Every mutation keeps both in one transaction. Wasooli and return adjustments
 * settle the oldest unpaid credit lots first so the customer statement, aging,
 * receipts and reports all describe the same debt.
 */
class FbrKhataService
{
    public function recordCreditSale(
        int $companyId,
        int $customerId,
        float $amount,
        int $transactionId,
        string $invoiceNumber,
        ?User $actor,
        bool $limitOverrideRequested = false,
        ?PosCustomer $lockedCustomer = null,
    ): FbrCustomerLedger {
        return DB::transaction(function () use (
            $companyId, $customerId, $amount, $transactionId, $invoiceNumber,
            $actor, $limitOverrideRequested
        ) {
            // Always own the lock here. A caller's lock may be a useful early
            // guard, but the public service contract cannot depend on it.
            $customer = PosCustomer::lockForUpdate()
                ->where('company_id', $companyId)
                ->find($customerId);

            if (!$customer) {
                throw ValidationException::withMessages([
                    'customer_id' => 'Customer nahi mila — udhaar bill cancel.',
                ]);
            }

            $amount = round($amount, 2);
            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Udhaar amount zero se zyada hona chahiye.',
                ]);
            }
            $currentBalance = round((float) $customer->khata_balance, 2);
            $newBalance = round($currentBalance + $amount, 2);
            $limitOverridden = false;

            // A missing newly-added column is treated as an unavailable setting,
            // not as a reason to interrupt billing on a drifted production DB.
            $limit = PosCustomer::khataColumnExists('khata_limit')
                ? $customer->khata_limit
                : null;
            if ($limit !== null && $newBalance > (float) $limit + 0.001) {
                $mayOverride = $limitOverrideRequested && $this->mayOverrideLimit($actor);
                if (!$mayOverride) {
                    throw ValidationException::withMessages([
                        'payment_method' => __('pos.khata_limit_exceeded', [
                            'name' => $customer->name,
                            'limit' => number_format((float) $limit, 0),
                            'balance' => number_format($currentBalance, 0),
                            'bill' => number_format($amount, 0),
                        ]),
                    ]);
                }
                $limitOverridden = true;
            }

            $note = "Udhaar bill {$invoiceNumber}";
            if ($limitOverridden) {
                $note .= ' ' . __('pos.khata_limit_override_note', [
                    'user' => (string) ($actor?->name ?? '—'),
                ]);
            }

            $entry = FbrCustomerLedger::create([
                'company_id' => $companyId,
                'customer_id' => $customer->id,
                'entry_type' => 'udhaar',
                'amount' => $amount,
                'balance_after' => $newBalance,
                'transaction_id' => $transactionId,
                'note' => $note,
                'created_by' => $actor?->id,
            ]);

            $customer->update(['khata_balance' => $newBalance]);

            return $entry;
        });
    }

    /**
     * Record a received payment once. A client-generated request UUID makes a
     * lost response/retry return the original entry instead of reducing balance
     * for a second time.
     *
     * @return array{entry:FbrCustomerLedger,replayed:bool,previous_balance:float}
     */
    public function recordWasooli(
        int $companyId,
        int $customerId,
        float $amount,
        ?string $note,
        ?User $actor,
        ?string $requestUuid = null,
    ): array {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Wasooli amount zero se zyada hona chahiye.']);
        }
        $hasRequestUuid = $requestUuid !== null
            && $requestUuid !== ''
            && Schema::hasColumn('fbr_customer_ledgers', 'request_uuid');

        try {
            return DB::transaction(function () use (
                $companyId, $customerId, $amount, $note, $actor, $requestUuid, $hasRequestUuid
            ) {
            if ($hasRequestUuid) {
                $existing = FbrCustomerLedger::where('company_id', $companyId)
                    ->where('request_uuid', $requestUuid)
                    ->first();
                if ($existing) {
                    return $this->wasooliReplay($existing, $customerId, $amount, $actor);
                }
            }

            $customer = PosCustomer::lockForUpdate()
                ->where('company_id', $companyId)
                ->findOrFail($customerId);

            // Check again after the customer lock, so two concurrent retry
            // requests cannot both pass the pre-lock replay lookup.
            if ($hasRequestUuid) {
                $existing = FbrCustomerLedger::where('company_id', $companyId)
                    ->where('request_uuid', $requestUuid)
                    ->first();
                if ($existing) {
                    return $this->wasooliReplay($existing, $customerId, $amount, $actor);
                }
            }

            $previousBalance = round((float) $customer->khata_balance, 2);
            if ($previousBalance <= 0) {
                throw ValidationException::withMessages([
                    'amount' => "{$customer->name} par koi udhaar baqi nahi.",
                ]);
            }
            if ($amount > $previousBalance + 0.001) {
                throw ValidationException::withMessages([
                    'amount' => 'Wasooli Rs ' . number_format($amount, 2)
                        . ' outstanding Rs ' . number_format($previousBalance, 2)
                        . ' se zyada hai — pehle amount theek karein.',
                ]);
            }

            $newBalance = round($previousBalance - $amount, 2);
            $attributes = [
                'company_id' => $companyId,
                'customer_id' => $customer->id,
                'entry_type' => 'wasooli',
                'amount' => -1 * $amount,
                'balance_after' => $newBalance,
                'transaction_id' => null,
                'note' => $note ?: 'Wasooli received',
                'created_by' => $actor?->id,
            ];
            if ($hasRequestUuid) {
                $attributes['request_uuid'] = $requestUuid;
            }

            $entry = FbrCustomerLedger::create($attributes);
            $this->allocateOldestCreditLots($companyId, $customer->id, $entry, $amount);
            $customer->update(['khata_balance' => $newBalance]);

            return [
                'entry' => $entry,
                'replayed' => false,
                'previous_balance' => $previousBalance,
            ];
            });
        } catch (QueryException $e) {
            // A UUID collision can happen when two different customer rows are
            // submitted at exactly once. The unique index is the final arbiter;
            // re-read only its matching row and validate the original payload.
            if (!$hasRequestUuid) {
                throw $e;
            }
            $existing = FbrCustomerLedger::where('company_id', $companyId)
                ->where('request_uuid', $requestUuid)
                ->first();
            if (!$existing) {
                throw $e;
            }
            return $this->wasooliReplay($existing, $customerId, $amount, $actor);
        }
    }

    /**
     * Reduce a credit customer's balance for a return atomically.
     */
    public function recordReturnAdjustment(
        int $companyId,
        int $customerId,
        float $amount,
        int $returnTransactionId,
        string $returnInvoiceNumber,
        ?User $actor,
    ): FbrCustomerLedger {
        return DB::transaction(function () use (
            $companyId, $customerId, $amount, $returnTransactionId, $returnInvoiceNumber, $actor
        ) {
            $customer = PosCustomer::lockForUpdate()
                ->where('company_id', $companyId)
                ->findOrFail($customerId);

            $amount = round($amount, 2);
            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Khata return amount zero se zyada hona chahiye.',
                ]);
            }
            $previousBalance = round((float) $customer->khata_balance, 2);
            if ($amount > $previousBalance + 0.001) {
                throw ValidationException::withMessages([
                    'refund_method' => 'Khata adjustment customer ke current baqaya se zyada nahi ho sakta.',
                ]);
            }

            $newBalance = round($previousBalance - $amount, 2);
            $entry = FbrCustomerLedger::create([
                'company_id' => $companyId,
                'customer_id' => $customer->id,
                'entry_type' => 'return_adjust',
                'amount' => -1 * $amount,
                'balance_after' => $newBalance,
                'transaction_id' => $returnTransactionId,
                'note' => "Khata return adjustment {$returnInvoiceNumber}",
                'created_by' => $actor?->id,
            ]);

            $this->allocateOldestCreditLots($companyId, $customer->id, $entry, $amount);
            $customer->update(['khata_balance' => $newBalance]);

            return $entry;
        });
    }

    /** Reject a UUID copied into a different customer, amount or staff request. */
    private function wasooliReplay(
        FbrCustomerLedger $entry,
        int $customerId,
        float $amount,
        ?User $actor,
    ): array {
        if ($entry->entry_type !== 'wasooli'
            || (int) $entry->customer_id !== $customerId
            || abs(round((float) $entry->amount, 2)) !== round($amount, 2)
            || (int) ($entry->created_by ?? 0) !== (int) ($actor?->id ?? 0)) {
            throw ValidationException::withMessages([
                'request_uuid' => 'Yeh Wasooli request kisi doosri payment ke liye use ho chuki hai.',
            ]);
        }

        return [
            'entry' => $entry,
            'replayed' => true,
            'previous_balance' => round((float) $entry->balance_after - (float) $entry->amount, 2),
        ];
    }

    private function mayOverrideLimit(?User $actor): bool
    {
        return $actor && in_array($actor->pos_role ?? $actor->role, [
            'company_admin', 'pos_admin', 'pos_manager',
        ], true);
    }

    /**
     * Materialize the FIFO choice for this settlement. Historical ledger entries
     * without allocation rows are replayed in date/id order, which keeps the
     * migration safe for shops that already have credit history.
     */
    private function allocateOldestCreditLots(
        int $companyId,
        int $customerId,
        FbrCustomerLedger $settlement,
        float $amount,
    ): void {
        if (!Schema::hasTable('fbr_khata_settlements')) {
            return;
        }

        $entries = FbrCustomerLedger::where('company_id', $companyId)
            ->where('customer_id', $customerId)
            ->where('id', '!=', $settlement->id)
            ->whereIn('entry_type', ['udhaar', 'wasooli', 'return_adjust'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'entry_type', 'amount']);

        $lots = [];
        foreach ($entries as $entry) {
            if ($entry->entry_type === 'udhaar') {
                $lots[] = ['id' => $entry->id, 'remaining' => round((float) $entry->amount, 2)];
                continue;
            }

            $remainingSettlement = abs(round((float) $entry->amount, 2));
            foreach ($lots as &$lot) {
                if ($remainingSettlement <= 0) {
                    break;
                }
                $taken = min($lot['remaining'], $remainingSettlement);
                $lot['remaining'] = round($lot['remaining'] - $taken, 2);
                $remainingSettlement = round($remainingSettlement - $taken, 2);
            }
            unset($lot);
        }

        $remaining = round($amount, 2);
        foreach ($lots as $lot) {
            if ($remaining <= 0) {
                break;
            }
            if ($lot['remaining'] <= 0.001) {
                continue;
            }
            $allocated = min($lot['remaining'], $remaining);
            FbrKhataSettlement::create([
                'company_id' => $companyId,
                'customer_id' => $customerId,
                'settlement_ledger_id' => $settlement->id,
                'credit_ledger_id' => $lot['id'],
                'amount' => round($allocated, 2),
            ]);
            $remaining = round($remaining - $allocated, 2);
        }

        if ($remaining > 0.001) {
            throw new \LogicException('Khata ledger balance does not cover this settlement.');
        }
    }
}
<?php

namespace App\Services;

use App\Exceptions\HotelStayException;
use App\Models\HotelCreditNote;
use App\Models\HotelFolioEntry;
use App\Models\HotelStay;
use App\Models\PosTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HotelCreditNoteService
{
    public function issue(HotelStay $stay, User $actor, int $billId, ?array $quantities, string $reason, string $key, string $fingerprint): HotelCreditNote
    {
        abort_unless((int) $actor->company_id === (int) $stay->company_id && $actor->isPosAdmin(), 403);
        if (!HotelCreditNoteActivation::issuance((int) $stay->company_id)) {
            throw new HotelStayException('Enable credit-note issuance for this company first.');
        }
        if (mb_strlen(trim($reason)) < 5 || mb_strlen($reason) > 255 || !preg_match('/^[a-zA-Z0-9-]{16,64}$/', $key)) {
            throw new HotelStayException('A reason and valid unique request key are required.');
        }
        if (!\App\Models\Company::findOrFail($stay->company_id)->praReportingActive()) {
            throw new HotelStayException('Enable PRA reporting before issuing a fiscal credit note.');
        }
        return DB::transaction(function () use ($stay, $actor, $billId, $quantities, $reason, $key, $fingerprint) {
            $stay = HotelStay::where('company_id', $actor->company_id)->lockForUpdate()->findOrFail($stay->id);
            $existing = HotelCreditNote::where('company_id', $stay->company_id)->where('request_key', $key)->first();
            if ($existing) {
                if ((int) $existing->stay_id !== (int) $stay->id || (int) $existing->original_transaction_id !== $billId
                    || $this->canonicalSelection($existing->selection) !== $this->canonicalSelection($quantities) || $existing->reason !== trim($reason)) {
                    throw new HotelStayException('Request key already belongs to a different adjustment.');
                }
                return $existing;
            }
            $bill = PosTransaction::withoutGlobalScope('hide_archived')->where('company_id', $stay->company_id)
                ->lockForUpdate()->findOrFail($billId);
            $bill->items()->lockForUpdate()->get();
            $charges = HotelFolioEntry::where('company_id', $stay->company_id)->where('stay_id', $stay->id)
                ->where('entry_type', HotelFolioEntry::TYPE_CHARGE)->where('pos_transaction_id', $billId)->lockForUpdate()->get();
            $plan = app(HotelCreditNotePolicy::class)->review($stay, $actor, $billId, $quantities);
            if (!hash_equals($plan['fingerprint'], $fingerprint)) {
                throw new HotelStayException('The bill changed. Review the adjustment again.');
            }
            if (!$plan['issuance_enabled'] || (float) $bill->discount_amount !== 0.0) {
                throw new HotelStayException('Original item linkage or bill-level discount requires manual review.');
            }
            $items = array_map(fn ($line) => ['item_id' => $line['item_id'], 'return_qty' => $line['quantity']], $plan['lines']);
            $result = PosReturnService::createReturn((int) $stay->company_id, $billId, $items, 'cash', (int) $actor->id,
                ['hotel_credit_note' => true]);
            if (!empty($result['error']) || empty($result['return'])) {
                throw new HotelStayException($result['error'] ?? 'Credit note could not be created.');
            }
            $credit = $result['return'];
            $already = (float) PosTransaction::withoutGlobalScope('hide_archived')->where('company_id', $stay->company_id)
                ->where('parent_transaction_id', $billId)->where('transaction_type', 'return')->where('id', '!=', $credit->id)->sum('total_amount');
            if ((float) $credit->total_amount + $already > (float) $bill->total_amount + 0.009) {
                throw new HotelStayException('Cumulative adjustment exceeds the original invoice.');
            }
            // Guest account credit, not a cash/card refund. PRA uses original payment snapshot below.
            $data = ['payment_method' => 'hotel_credit_note', 'notes' => trim($reason), 'cash_received' => 0, 'change_due' => 0];
            if (Schema::hasColumn('pos_transactions', 'branch_id')) {
                $data['branch_id'] = $bill->branch_id;
            }
            $credit->update($data);
            $note = HotelCreditNote::create([
                'company_id' => $stay->company_id, 'stay_id' => $stay->id,
                'original_transaction_id' => $billId, 'credit_transaction_id' => $credit->id,
                'created_by' => $actor->id, 'request_key' => $key, 'reason' => trim($reason), 'selection' => $quantities,
            ]);
            foreach ($plan['lines'] as $line) {
                $item = $bill->items->firstWhere('id', $line['item_id']);
                $source = $charges->firstWhere('id', $item->hotel_folio_entry_id);
                $ratio = $line['quantity'] / (float) $item->quantity;
                $previous = HotelFolioEntry::where('company_id', $stay->company_id)->where('stay_id', $stay->id)
                    ->where('entry_type', HotelFolioEntry::TYPE_ADJUSTMENT)->where('reverses_entry_id', $source->id)->get();
                $final = abs($line['quantity'] - $line['remaining_quantity']) < 0.0000001;
                $shares = [];
                foreach (['amount', 'gross_amount', 'discount_amount'] as $column) {
                    $remaining = round((float) $source->{$column} + (float) $previous->sum($column), 2);
                    if ($remaining < -0.009) {
                        throw new HotelStayException('Original folio adjustment needs reconciliation.');
                    }
                    $shares[$column] = -max(0, $final ? $remaining : min($remaining, round((float) $source->{$column} * $ratio, 2)));
                }
                HotelFolioEntry::create([
                    'company_id' => $stay->company_id, 'stay_id' => $stay->id,
                    'entry_type' => HotelFolioEntry::TYPE_ADJUSTMENT, 'category' => $source->category,
                    'description' => 'Credit note '.$credit->invoice_number.': '.trim($reason),
                    'quantity' => $line['quantity'], 'uom' => $source->uom,
                    'unit_amount' => -(float) $source->unit_amount,
                    'amount' => $shares['amount'],
                    'gross_amount' => $shares['gross_amount'],
                    'discount_amount' => $shares['discount_amount'],
                    'reverses_entry_id' => $source->id, 'pos_transaction_id' => $credit->id,
                    'idempotency_key' => 'hotel-credit-'.$note->id.'-'.$source->id, 'created_by' => $actor->id,
                ]);
            }
            AuditLogService::log('hotel_credit_note_created', 'hotel_stay', $stay->id, null,
                ['note_id' => $note->id, 'credit_transaction_id' => $credit->id, 'reason' => trim($reason)],
                (int) $stay->company_id, (int) $actor->id);
            // Existing agent/cloud retry contract, with all money and stay actions separate.
            DB::afterCommit(fn () => PosReturnService::submitToPraPostCommit($result));
            return $note;
        });
    }
    public function refund(HotelStay $stay, User $actor, int $noteId, float $amount, string $method, string $key, int $terminalId = 0): HotelFolioEntry
    {
        abort_unless((int) $actor->company_id === (int) $stay->company_id && $actor->isPosAdmin(), 403);
        if (!HotelCreditNoteActivation::refunds((int) $stay->company_id)) {
            throw new HotelStayException('Enable money refunds for this company first.');
        }
        abort_unless(abs($amount - round($amount, 2)) < 0.0000001 && in_array($method, ['cash', 'card'], true) && is_finite($amount) && $amount > 0
            && preg_match('/^[a-zA-Z0-9-]{16,64}$/', $key), 422);
        return DB::transaction(function () use ($stay, $actor, $noteId, $amount, $method, $key, $terminalId) {
            $stay = HotelStay::where('company_id', $actor->company_id)->lockForUpdate()->findOrFail($stay->id);
            $note = HotelCreditNote::where('company_id', $stay->company_id)->where('stay_id', $stay->id)->findOrFail($noteId);
            $credit = PosTransaction::withoutGlobalScope('hide_archived')->where('company_id', $stay->company_id)
                ->findOrFail($note->credit_transaction_id);
            if ($credit->pra_status !== 'submitted' || !$credit->pra_invoice_number) {
                throw new HotelStayException('Resolve fiscal submission before refunding this credit note.');
            }
            $refundKey = 'hcn-refund-'.substr(hash('sha256', $key), 0, 52);
            $existing = HotelFolioEntry::where('company_id', $stay->company_id)->where('idempotency_key', $refundKey)->first();
            if ($existing) {
                if ((int) $existing->stay_id !== (int) $stay->id || (int) $existing->pos_transaction_id !== (int) $credit->id
                    || abs((float) $existing->amount - $amount) > 0.009 || $existing->payment_method !== $method
                    || (int) $existing->refund_terminal_id !== $terminalId) {
                    throw new HotelStayException('Request key already belongs to another refund.');
                }
                return $existing;
            }
            if (!Schema::hasColumn('hotel_folio_entries', 'refund_business_date')) {
                throw new HotelStayException('Refund settlement migration is required.');
            }
            abort_unless($terminalId >= 0 && ($terminalId === 0 || \App\Models\PosTerminal::where('company_id', $stay->company_id)
                ->where('is_active', true)->whereKey($terminalId)->exists()), 422);
            $date = PosBusinessDay::forMoment((int) $stay->company_id, now());
            $closed = \App\Models\PosDayCloseReport::where('company_id', $stay->company_id)
                ->whereDate('report_date', $date)->whereIn('branch_id', [0, (int) ($stay->branch_id ?? 0)])->exists();
            if ($closed || ($method === 'cash' && PosCounterDrawer::isClosed((int) $stay->company_id, $terminalId, $date))) {
                throw new HotelStayException('This refund day or drawer is closed.');
            }
            $already = (float) HotelFolioEntry::where('company_id', $stay->company_id)->where('stay_id', $stay->id)
                ->where('entry_type', HotelFolioEntry::TYPE_REFUND)->where('pos_transaction_id', $credit->id)->sum('amount');
            $available = app(HotelFolioInvoiceService::class)->availableTowardFiscal($stay);
            if ($amount > (float) $credit->total_amount - $already + 0.009 || $amount > $available + 0.009) {
                throw new HotelStayException('Refund exceeds the remaining guest credit.');
            }
            $refund = app(HotelFolioService::class)->refundPayment($stay, $amount, (int) $actor->id, $method, $refundKey, $terminalId);
            $refund->update(['pos_transaction_id' => $credit->id, 'refund_business_date' => $date,
                'refund_branch_id' => $stay->branch_id, 'refund_terminal_id' => $terminalId]);
            AuditLogService::log('hotel_credit_note_refunded', 'hotel_stay', $stay->id, null,
                ['note_id' => $note->id, 'refund_entry_id' => $refund->id, 'amount' => $amount, 'method' => $method],
                (int) $stay->company_id, (int) $actor->id);
            return $refund;
        });
    }

    private function canonicalSelection(?array $selection): ?array
    {
        if ($selection === null) {
            return null;
        }
        $normalized = [];
        foreach ($selection as $id => $quantity) {
            $normalized[(int) $id] = number_format((float) $quantity, 3, '.', '');
        }
        ksort($normalized);
        return $normalized;
    }
}

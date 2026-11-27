<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ErpUpgradeSettlement extends Model
{
    public const PAYMENT_METHOD = 'bank_transfer';

    protected $casts = [
        'quoted_on' => 'immutable_date',
        'quote_snapshot' => 'array',
        'target_price_paisa' => 'integer',
        'credit_paisa' => 'integer',
        'difference_paisa' => 'integer',
        'verified_received_paisa' => 'integer',
        'settled_at' => 'immutable_datetime',
        'branch_map' => 'array',
        'activated_at' => 'immutable_datetime',
    ];

    protected $hidden = ['owner_authorization_reference', 'payment_reference', 'payment_reference_key',
        'quote_key', 'quote_snapshot', 'branch_map', 'activation_reference'];

    protected static function booted(): void
    {
        static::updating(function (self $settlement): void {
            $changed = array_keys($settlement->getDirty());
            $activationFields = ['destination_subscription_id', 'branch_map', 'activated_by', 'activated_at',
                'activation_reference', 'updated_at'];
            if ($settlement->getRawOriginal('settled_at') !== null
                && $settlement->getRawOriginal('activated_at') === null
                && !array_diff($changed, $activationFields)
                && $settlement->activated_at && $settlement->destination_subscription_id && $settlement->activated_by
                && is_array($settlement->branch_map) && trim((string) $settlement->activation_reference) !== '') {
                return;
            }

            $reviewFields = ['allocated_source_invoice_id', 'verified_received_paisa', 'payment_method', 'payment_reference',
                'payment_reference_key', 'settled_by', 'settled_at', 'updated_at'];
            if ($settlement->getRawOriginal('settled_at') !== null
                || array_diff($changed, $reviewFields)
                || !$settlement->settled_at
                || !$settlement->settled_by
                || (int) $settlement->allocated_source_invoice_id !== (int) $settlement->source_invoice_id
                || $settlement->verified_received_paisa !== $settlement->difference_paisa
                || !$settlement->hasSettlementPaymentEvidence()) {
                throw new \LogicException('ERP upgrade quotes are immutable and may only be settled once at the quoted difference.');
            }
        });
    }

    private function hasSettlementPaymentEvidence(): bool
    {
        if ($this->difference_paisa === 0) {
            return $this->payment_method === null && $this->payment_reference === null && $this->payment_reference_key === null;
        }

        return $this->payment_method === self::PAYMENT_METHOD && $this->payment_reference !== null
            && $this->payment_reference_key !== null;
    }
}

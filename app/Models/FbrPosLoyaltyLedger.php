<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FbrPosLoyaltyLedger extends Model
{
    protected $table = 'fbr_pos_loyalty_ledger';
    protected $fillable = ['company_id', 'customer_id', 'transaction_id', 'type', 'points', 'balance_after', 'note'];
}

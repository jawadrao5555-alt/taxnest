<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PosFederalOutbox extends Model
{
    protected $table = 'pos_federal_outbox';
    protected $guarded = ['id'];
    protected $casts = ['rule_snapshot'=>'encrypted:array','started_at'=>'datetime'];
}

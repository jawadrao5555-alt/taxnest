<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class AgentIncentiveAward extends Model {
    protected $fillable = ['agent_id','quarter','qualified_companies','rate_percent','base_amount','amount','status','approved_at','approved_by_admin_id','paid_at','paid_by_admin_id','snapshot'];
    protected $casts = ['base_amount'=>'decimal:2','rate_percent'=>'decimal:2','amount'=>'decimal:2','snapshot'=>'array','approved_at'=>'datetime','paid_at'=>'datetime'];
    public function agent() { return $this->belongsTo(Agent::class); }
}
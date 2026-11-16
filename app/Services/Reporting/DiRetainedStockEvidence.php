<?php

namespace App\Services\Reporting;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** SELECT-only exact retained links, never an inventory reconciliation or repair. */
final class DiRetainedStockEvidence
{
    public static function available(): bool
    {
        foreach ([
            'di_invoice_inventory_postings' => ['id','company_id','invoice_id','invoice_item_id','movement_id','product_id','branch_id','created_at'],
            'invoices' => ['id','company_id','branch_id'],
            'invoice_items' => ['id','invoice_id','inventory_snapshot'],
            'inventory_movements' => ['id','company_id','product_id','branch_id','reference_type','reference_id'],
            'branches' => ['id','company_id'],
        ] as $table => $columns) {
            if (!Schema::hasTable($table) || array_diff($columns, Schema::getColumnListing($table))) return false;
        }
        return true;
    }

    /** IDs only. No amounts, patient/customer data or current catalogue inference. */
    public static function validIds(int $companyId): Builder
    {
        return DB::table('di_invoice_inventory_postings as p')
            ->join('invoices as i', 'i.id', '=', 'p.invoice_id')
            ->join('invoice_items as line', function ($join) {
                $join->on('line.id','=','p.invoice_item_id')->on('line.invoice_id','=','i.id');
            })
            ->join('inventory_movements as m', function ($join) {
                $join->on('m.id','=','p.movement_id')->on('m.company_id','=','p.company_id')
                    ->on('m.product_id','=','p.product_id')->on('m.reference_id','=','p.invoice_item_id')
                    ->where('m.reference_type','=','di_invoice_item');
            })
            ->where('p.company_id',$companyId)->where('i.company_id',$companyId)
            ->where('line.inventory_snapshot->version',1)
            ->whereJsonContainsKey('line.inventory_snapshot->branch_id')
            ->whereColumn('line.inventory_snapshot->company_id','p.company_id')
            ->whereColumn('line.inventory_snapshot->product_id','p.product_id')
            ->where(function ($q) {
                $q->whereColumn('line.inventory_snapshot->branch_id','p.branch_id')
                    ->orWhere(function ($n) { $n->whereNull('p.branch_id')->whereNull('line.inventory_snapshot->branch_id'); });
            })
            ->whereRaw('(m.branch_id = p.branch_id OR (m.branch_id IS NULL AND p.branch_id IS NULL))')
            ->whereRaw('(i.branch_id = p.branch_id OR (i.branch_id IS NULL AND p.branch_id IS NULL))')
            ->where(function ($q) use ($companyId) {
                $q->whereNull('p.branch_id')->orWhereExists(function ($b) use ($companyId) {
                    $b->selectRaw('1')->from('branches as b')->whereColumn('b.id','p.branch_id')->where('b.company_id',$companyId);
                });
            })->select('p.id');
    }

    public static function bill(int $companyId, int $invoiceId, int $after, int $limit): array
    {
        $empty=['rows'=>[], 'has_more'=>false, 'next_after_id'=>null];
        if (!self::available()) return ['status'=>'UNAVAILABLE']+$empty;
        $q=DB::table('di_invoice_inventory_postings')->where('company_id',$companyId)->where('invoice_id',$invoiceId);
        if ((clone $q)->whereNotIn('id',self::validIds($companyId))->exists()) return ['status'=>'INCONSISTENT_RETAINED_LINKS']+$empty;
        $rows=$q->where('id','>',$after)->orderBy('id')->limit($limit+1)->get(['id','movement_id']);
        $page=$rows->take($limit);$more=$rows->count()>$limit;
        return ['status'=>$rows->isEmpty()?'NO_RETAINED_LINKS':'PARTIAL',
            'rows'=>$page->map(fn($r)=>['id'=>(int)$r->id,'movement_id'=>(int)$r->movement_id])->values()->all(),
            'has_more'=>$more,'next_after_id'=>$more?(int)$page->last()->id:null];
    }
}

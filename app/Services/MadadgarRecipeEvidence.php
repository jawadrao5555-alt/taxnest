<?php
namespace App\Services;
use Illuminate\Support\Facades\{DB,Schema};
/** SELECT-only bounded estimates. Never invokes stock consumption/healing/preparation. */
final class MadadgarRecipeEvidence
{
    public static function read(int $company,?int $branch):array
    {
        $out=['values'=>[],'labels'=>[],'sources'=>['product_recipes','ingredients','pos_products', $branch===null?'ingredients.current_stock':'ingredient_stocks'],
            'note'=>'Current recipe estimates per product, independently (not simultaneous menu capacity). Recorded stock, not physical availability. Prepared batches, substitutes and labor excluded. Zero/unset cost remains unknown. Maximum 25 products; no arbitrary product input.'];
        foreach(['product_recipes'=>['company_id','product_id','ingredient_id','quantity_needed','waste_percent','is_active'],'ingredients'=>['id','company_id','unit','is_active','cost_per_unit','current_stock'],'pos_products'=>['id','company_id','is_active']] as$t=>$cols) {
            if(!Schema::hasColumns($t,$cols)){ $out['note']='Recipe cost/stock source unavailable; estimates unknown.';return $out; }
        }
        $products=DB::table('pos_products')->where('company_id',$company)->where('is_active',true)->whereExists(fn($q)=>$q->selectRaw('1')->from('product_recipes')->whereColumn('product_id','pos_products.id')->where('company_id',$company)->where('is_active',true))->orderBy('id')->limit(26)->pluck('id');
        $out['values']['has_more']=$products->count()>25?1:0;$out['labels']['has_more']='More products beyond bounded page';
        foreach($products->take(25) as$id){
            $lines=DB::table('product_recipes')->where('company_id',$company)->where('product_id',$id)->where('is_active',true)->limit(501)->get();
            $ingredients=DB::table('ingredients')->where('company_id',$company)->whereIn('id',$lines->pluck('ingredient_id'))->get()->keyBy('id');
            $valid=$lines->count()<=500 && $lines->every(fn($r)=>isset($ingredients[$r->ingredient_id]) && $ingredients[$r->ingredient_id]->is_active && is_numeric($r->quantity_needed) && $r->quantity_needed>0 && is_numeric($r->waste_percent) && $r->waste_percent>=0 && $r->waste_percent<=100 && trim((string)$ingredients[$r->ingredient_id]->unit)!=='');
            $cost=null;$capacity=null;
            if($valid){
                // Reuse sale engine's per-unit/waste/duplicate-ingredient requirement arithmetic.
                $requirements=RecipeInventoryService::requirementsForItems($company,[['type'=>'product','item_id'=>$id,'quantity'=>1]]);
                $cost=$requirements?0:null;$capacity=$requirements?PHP_INT_MAX:null;
                foreach($requirements as$r){
                    $i=$ingredients[$r['ingredient_id']];$qty=$r['quantity'];
                    if($qty<=0){$cost=null;$capacity=null;break;}
                    if($cost!==null)$cost=is_numeric($i->cost_per_unit)&&$i->cost_per_unit>0?$cost+$qty*(float)$i->cost_per_unit:null;
                    $stock=$i->current_stock;
                    if($branch!==null){
                        $stock=null;
                        if(Schema::hasColumns('ingredient_stocks',['company_id','ingredient_id','branch_id','quantity'])){
                            $rows=DB::table('ingredient_stocks')->where('company_id',$company)->where('ingredient_id',$i->id)->where('branch_id',$branch)->limit(2)->get(['quantity']);
                            if($rows->count()===1)$stock=$rows[0]->quantity;
                        }
                    }
                    if($capacity!==null)$capacity=is_numeric($stock)?min($capacity,floor(max(0,(float)$stock)/$qty)):null;
                }
            }
            $out['values']['product_'.$id.'_cost']=$cost===null?null:round($cost,2);
            $out['labels']['product_'.$id.'_cost']='Product #'.$id.' estimated ingredient cost PKR';
            $out['values']['product_'.$id.'_servings']=$capacity;
            $out['labels']['product_'.$id.'_servings']='Product #'.$id.' recorded-stock servings';
        }
        return $out;
    }
}

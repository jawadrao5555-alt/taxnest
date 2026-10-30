<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\PosProduct;
use App\Models\PosPromotion;
use App\Services\PosFeatureService;
use App\Services\PosPromotions;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PosPromotionController extends Controller
{
    private function admin(): Company
    {
        $company = Company::findOrFail(app('currentCompanyId')); $user = auth('pos')->user();
        abort_unless($user && (int) $user->company_id === (int) $company->id && $user->isPosAdmin()
            && $user->posCustomAllows('customize') !== false && !session()->has('impersonation'),403);
        abort_unless(PosFeatureService::moduleRelevant($company,'deals_enabled') && PosFeatureService::planAllows($company,'deals_enabled'),403,__('pos.plan_locked_feature'));
        abort_unless(Schema::hasTable('pos_promotions'),503,__('pos_promotions.unavailable'));
        return $company;
    }

    public function index()
    {
        $company=$this->admin();
        return view('pos.promotions', ['company'=>$company,
            'promotions'=>PosPromotion::where('company_id',$company->id)->orderByDesc('priority')->orderByDesc('id')->get(),
            'products'=>PosProduct::where('company_id',$company->id)->where('is_active',true)->orderBy('name')->get(),
            'timezone'=>config('app.timezone','Asia/Karachi')]);
    }

    private function attributes(Request $request, Company $company): array
    {
        $data=$request->validate([
            'title'=>['required','string','max:120'],'percent'=>['required','numeric','min:0.01','max:100'],
            'priority'=>['required','integer','min:0','max:9999'],'timezone'=>['required','timezone'],
            'starts_at'=>['required','date_format:Y-m-d\TH:i'],'ends_at'=>['required','date_format:Y-m-d\TH:i','after:starts_at'],
            'applies_to'=>['required','in:all,selected'],'product_ids'=>['required_if:applies_to,selected','array','max:2000'],
            'product_ids.*'=>['integer','distinct',Rule::exists('pos_products','id')->where('company_id',$company->id)->where('is_active',true)],
            'is_active'=>['sometimes','boolean'],
        ]);
        if ($data['applies_to']==='selected' && empty($data['product_ids'])) throw ValidationException::withMessages(['product_ids'=>__('pos_promotions.select_products')]);
        foreach(['starts_at','ends_at'] as $field) {
            $time=CarbonImmutable::createFromFormat('!Y-m-d\TH:i',$data[$field],$data['timezone']);
            if ($time->format('Y-m-d\TH:i')!==$data[$field]) throw ValidationException::withMessages([$field=>__('pos_promotions.invalid_time')]);
            $data[$field]=$time->utc()->format('Y-m-d H:i:s');
        }
        $data['product_ids']=$data['applies_to']==='all' ? [] : array_values(array_map('intval',$data['product_ids']));
        $data['is_active']=$request->boolean('is_active');
        return $data;
    }

    public function store(Request $request)
    {
        $company=$this->admin();$data=$this->attributes($request,$company);
        DB::transaction(function() use($company,$data) {
            Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
            PosPromotion::create(['company_id'=>$company->id]+$data);
        });
        return redirect()->route('pos.promotions')->with('success',__('pos_promotions.saved'));
    }

    public function update(Request $request,int $id)
    {
        $company=$this->admin();$data=$this->attributes($request,$company);
        DB::transaction(function() use($company,$id,$data) {
            Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
            PosPromotion::where('company_id',$company->id)->whereKey($id)->lockForUpdate()->firstOrFail()->update($data);
        });
        return redirect()->route('pos.promotions')->with('success',__('pos_promotions.saved'));
    }

    public function quotes(Request $request)
    {
        $request->validate(['product_ids'=>['required','array','max:200'],'product_ids.*'=>['integer']]);
        $company=Company::findOrFail(app('currentCompanyId'));
        $products=PosProduct::where('company_id',$company->id)->whereIn('id',$request->product_ids)->where('is_active',true)->get();
        return response()->json(['success'=>true,'quotes'=>PosPromotions::quotes($company,$products), 'prices'=>$products->mapWithKeys(fn($p)=>[$p->id=>(float)$p->price])])
            ->header('Cache-Control','no-store, no-cache, must-revalidate, private');
    }
}

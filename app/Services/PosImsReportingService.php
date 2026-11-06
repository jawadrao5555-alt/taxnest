<?php
namespace App\Services;

use App\Models\{Company, PosTransaction, PosImsProfile, PosImsSubmission, PosDiSandboxSubmission};
use App\Support\PraDiSandboxGate;
use Illuminate\Support\Facades\{DB, Http, Validator};
use Illuminate\Validation\ValidationException;

/** PRA operational sale + separate IMS fiscal evidence; never an FbrPosTransaction. */
class PosImsReportingService
{
    public function profile(int $companyId, ?string $environment = null): PosImsProfile
    {
        abort_unless(PraDiSandboxGate::setupAvailable(),404);
        abort_unless(Company::whereKey($companyId)->where('product_type','pos')->where('status','approved')->where('company_status','active')->exists(),403);
        $p=PosImsProfile::where('company_id',$companyId)->first();
        abort_unless($p && $p->enabled && in_array($p->environment,['sandbox','production'],true),409,'Enable FBR POS in Tax Integrations first.');
        abort_if($environment !== null && $p->environment !== $environment,409,'Environment changed; refresh before continuing.');
        abort_if($p->environment === 'production' && (!$p->production_confirmed_at || !config('pra_di_sandbox.production_enabled')),422,'Production reporting is not activated.');
        return $p;
    }

    public static function preventOtherFederalRecord(int $companyId,int $sourceId,string $environment,string $kind): void
    {
        $model=$kind === 'ims' ? PosDiSandboxSubmission::class : PosImsSubmission::class;
        abort_if($model::where('company_id',$companyId)->where('source_transaction_id',$sourceId)
            ->where('environment',$environment)->whereIn('state',['queued_agent','submitting','verification_required','accepted','local_accepted'])->exists(),409,
            'This bill already has an accepted or unresolved submission through the other FBR integration. Reconcile it before changing reporting channels.');
    }

    public function capture(Company $company,int $sourceId,int $actor,string $environment): PosImsSubmission
    {
        return DB::transaction(function() use($company,$sourceId,$actor,$environment){
            $company=Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
            $p=$this->profile($company->id,$environment);
            self::preventOtherFederalRecord($company->id,$sourceId,$environment,'ims');
            $sale=PosTransaction::where('company_id',$company->id)->lockForUpdate()->findOrFail($sourceId);
            if ($old=PosImsSubmission::where('company_id',$company->id)->where('source_transaction_id',$sourceId)->where('environment',$environment)->first()) return $old;
            $s=PosFiscalSnapshot::capture($company,$sale,true);
            $s=PosImsOriginalAmounts::snapshot($company,$sale,$s);
            $s['pos_id']=(int)$p->{$environment.'_pos_id'};
            $s['device_uid']=$p->device_uid;
            $s['local_ims_url']=$p->local_ims_url;
            return PosImsSubmission::create(['company_id'=>$company->id,'source_transaction_id'=>$sourceId,
                'created_by'=>$actor,'environment'=>$environment,'connection_mode'=>$p->connection_mode,'state'=>'draft','snapshot'=>$s,'attempts'=>[]]);
        });
    }

    public function template(PosImsSubmission $r): array
    {
        $s=$r->snapshot; $type=$s['transaction_type']==='return' ? 3 : 1;
        $items=[];
        foreach ($s['lines'] as $i=>$line) $items[]=['ItemCode'=>(string)($i+1),'ItemName'=>$line['name'],
            'Quantity'=>$line['quantity'],'PCTCode'=>'00000000','TaxRate'=>null,'SaleValue'=>$line['base'],
            'TotalAmount'=>null,'TaxCharged'=>null,'Discount'=>0,'FurtherTax'=>0,'InvoiceType'=>$type,'RefUSIN'=>null];
        return ['InvoiceNumber'=>'','POSID'=>$s['pos_id'],'USIN'=>$s['invoice_number'],
            'DateTime'=>$s['date_time'],'BuyerNTN'=>'','BuyerCNIC'=>'','BuyerName'=>'','BuyerPhoneNumber'=>'',
            'TotalSaleValue'=>round(array_sum(array_column($items,'SaleValue')),2),'TotalTaxCharged'=>null,
            'TotalQuantity'=>round(array_sum(array_column($items,'Quantity')),4),'Discount'=>0,'FurtherTax'=>0,
            'TotalBillAmount'=>null,'PaymentMode'=>match($s['payment_method']){'cash','credit','udhaar','khata','store_credit'=>1,'card','bank_transfer','online'=>2,'mixed'=>5,'cheque','check'=>6,default=>null},
            'RefUSIN'=>null,'InvoiceType'=>$type,'Items'=>$items];
    }

    private function validatePayload(PosImsSubmission $r,array $p): void
    {
        $t=$this->template($r);
        Validator::make($p,[
            'POSID'=>'required|integer|min:1','InvoiceNumber'=>'present|nullable|string|max:100','USIN'=>'required|string|max:100',
            'DateTime'=>'required|date_format:Y-m-d H:i:s','BuyerNTN'=>'present|nullable|string|max:13',
            'BuyerCNIC'=>['present','nullable','regex:/^[0-9]{13}$/'],'BuyerName'=>'present|nullable|string|max:200',
            'BuyerPhoneNumber'=>'present|nullable|string|max:30','PaymentMode'=>'required|integer|between:1,6',
            'InvoiceType'=>'required|in:1,3','RefUSIN'=>'present|nullable|string|max:100',
            'TotalSaleValue'=>'required|numeric|min:0','TotalTaxCharged'=>'required|numeric|min:0',
            'TotalQuantity'=>'required|numeric|gt:0','Discount'=>'required|numeric|in:0','FurtherTax'=>'required|numeric|in:0',
            'TotalBillAmount'=>'required|numeric|min:0', 'Items'=>'required|array|list|size:'.count($t['Items']),
            'Items.*.ItemCode'=>'required|string|max:50','Items.*.ItemName'=>'required|string|max:200',
            'Items.*.PCTCode'=>['required','regex:/^[0-9]{8}$/'],'Items.*.Quantity'=>'required|numeric|gt:0',
            'Items.*.TaxRate'=>'required|numeric|between:0,100','Items.*.SaleValue'=>'required|numeric|min:0',
            'Items.*.TotalAmount'=>'required|numeric|min:0','Items.*.TaxCharged'=>'required|numeric|min:0',
            'Items.*.Discount'=>'required|numeric|in:0','Items.*.FurtherTax'=>'required|numeric|in:0',
            'Items.*.InvoiceType'=>'required|in:1,3','Items.*.RefUSIN'=>'present|nullable|string|max:100',
        ])->validate();
        $errors=[];
        if (array_diff(array_keys($p),array_keys($t))) $errors[]='Unexpected IMS header fields.';
        foreach (['InvoiceNumber','POSID','USIN','DateTime','InvoiceType','TotalSaleValue','TotalQuantity'] as $key) {
            if ((string)$p[$key] !== (string)$t[$key]) $errors[]='Keep the frozen IMS '.$key.'.';
        }
        if ($t['PaymentMode'] !== null && (int)$p['PaymentMode'] !== $t['PaymentMode']) $errors[]='Keep the original payment method.';
        foreach ($p['Items'] as $i=>$line) {
            if (array_diff(array_keys($line),array_keys($t['Items'][$i]))) $errors[]='Unexpected IMS item fields.';
            foreach (['ItemCode','ItemName','Quantity','SaleValue','InvoiceType'] as $key) {
                if ((string)$line[$key] !== (string)$t['Items'][$i][$key]) $errors[]='Keep original IMS line values.';
            }
            $original=$r->snapshot['lines'][$i];
            $originalTax=isset($original['tax_rate'])
                && abs((float)$line['TaxRate']-(float)$original['tax_rate'])<0.00001
                && abs((float)$line['TaxCharged']-(float)$original['pra_tax'])<0.00001;
            if ((!$originalTax && abs((float)$line['TaxCharged']-round((float)$line['SaleValue']*(float)$line['TaxRate']/100,2))>0.01)
                || abs((float)$line['TotalAmount']-(float)$line['SaleValue']-(float)$line['TaxCharged'])>0.01) $errors[]='IMS line totals/rates do not balance.';
            if (($line['RefUSIN'] ?? '') !== ($p['RefUSIN'] ?? '')) $errors[]='Item RefUSIN must match the header.';
        }
        if (abs((float)$p['TotalTaxCharged']-array_sum(array_column($p['Items'],'TaxCharged')))>0.01
            || abs((float)$p['TotalBillAmount']-(float)$p['TotalSaleValue']-(float)$p['TotalTaxCharged'])>0.01) $errors[]='IMS header totals do not balance.';
        if ((int)$p['InvoiceType'] === 3) {
            $parent=PosImsSubmission::where('company_id',$r->company_id)->where('source_transaction_id',$r->snapshot['parent_transaction_id'])
                ->where('environment',$r->environment)->whereIn('state',['accepted','local_accepted'])->first();
            if (!$parent || !$parent->fiscal_number || (int)$parent->snapshot['pos_id'] !== (int)$r->snapshot['pos_id'] || $p['RefUSIN'] !== $parent->fiscal_number) $errors[]='Use the accepted original IMS reference from the same company and environment.';
        } elseif (!empty($p['RefUSIN'])) $errors[]='A sale cannot reference another invoice.';
        if ($errors) throw ValidationException::withMessages(['payload_json'=>$errors]);
    }

    private function activeRecord(PosImsSubmission $r): PosImsProfile
    {
        $p=$this->profile($r->company_id,$r->environment);
        abort_if($p->connection_mode !== $r->connection_mode || (int)$p->{$r->environment.'_pos_id'} !== (int)$r->snapshot['pos_id']
            || ($r->connection_mode === 'fiscal_device' && ($p->device_uid !== ($r->snapshot['device_uid'] ?? null) || $p->local_ims_url !== ($r->snapshot['local_ims_url'] ?? null))),409,
            'IMS connection changed after capture; this record cannot be submitted with another identity or device.');
        return $p;
    }
    /** Explicit recovery only before dispatch or after an unambiguous rejection. */
    public function refreshConnection(int $companyId,int $id,int $actor): PosImsSubmission
    {
        return DB::transaction(function() use($companyId,$id,$actor){
            Company::whereKey($companyId)->lockForUpdate()->firstOrFail();
            $r=$this->locked($companyId,$id);$p=$this->profile($companyId,$r->environment);
            abort_unless(in_array($r->state,['draft','rejected','queued_agent'],true) && !$r->fiscal_number,409,
                'A dispatched, accepted or uncertain invoice cannot change connection or be replayed.');
            self::preventOtherFederalRecord($companyId,$r->source_transaction_id,$r->environment,'ims');
            $s=$r->snapshot;$a=$r->attempts ?? [];
            $a[]=['actor_id'=>$actor,'at'=>now()->toIso8601String(),'state'=>'connection_refreshed',
                'previous_state'=>$r->state,'previous_mode'=>$r->connection_mode,'previous_pos_id'=>$s['pos_id'],
                'previous_payload_hash'=>$r->payload_hash,'environment'=>$r->environment];
            $s['pos_id']=(int)$p->{$r->environment.'_pos_id'};$s['device_uid']=$p->device_uid;$s['local_ims_url']=$p->local_ims_url;
            $r->fill(['connection_mode'=>$p->connection_mode,'snapshot'=>$s,'payload'=>null,'payload_hash'=>null,
                'state'=>'draft','attempts'=>$a,'agent_claim'=>null,'device_uid'=>null])->save();return $r;
        });
    }

    public function prepare(int $companyId,int $id,array $payload): PosImsSubmission
    {
        return DB::transaction(function() use($companyId,$id,$payload){
            Company::whereKey($companyId)->lockForUpdate()->firstOrFail();
            $r=$this->locked($companyId,$id); $this->activeRecord($r);
            abort_unless(in_array($r->state,['draft','rejected'],true),409);
            $this->validatePayload($r,$payload); $r->payload=$payload;
            $r->payload_hash=hash('sha256',json_encode($r->payload,JSON_THROW_ON_ERROR)); $r->state='draft';$r->save();return $r;
        });
    }
    public function submit(int $companyId,int $id,int $actor): PosImsSubmission
    {
        [$r,$token]=DB::transaction(function() use($companyId,$id,$actor){
            Company::whereKey($companyId)->lockForUpdate()->firstOrFail();
            $r=$this->locked($companyId,$id);$p=$this->activeRecord($r);
            self::preventOtherFederalRecord($companyId,$r->source_transaction_id,$r->environment,'ims');
            abort_unless($r->state==='draft' && $r->payload,409,'Prepare a reviewed payload. Accepted and uncertain invoices cannot be replayed.');
            $this->validatePayload($r,$r->payload);
            abort_unless(hash_equals($r->payload_hash,hash('sha256',json_encode($r->payload,JSON_THROW_ON_ERROR))),409);
            $token=(string)$p->{$r->environment.'_token'};
            if ($r->connection_mode==='cloud' && $token==='') throw ValidationException::withMessages(['token'=>'Configure the selected IMS token.']);
            $state=$r->connection_mode==='fiscal_device' ? 'queued_agent':'submitting';
            $attempts=$r->attempts ?? []; $attempts[]=['actor_id'=>$actor,'at'=>now()->toIso8601String(),'state'=>$state,'environment'=>$r->environment,'hash'=>$r->payload_hash,'payload'=>$r->payload];
            $r->fill(['state'=>$state,'attempted_at'=>now(),'attempts'=>$attempts])->save();return [$r,$token];
        });
        if ($r->state==='queued_agent') return $r;
        $unknown=['state'=>'verification_required','reference'=>null,'error_codes'=>[]];
        try {
            $p=PosImsProfile::where('company_id',$companyId)->firstOrFail();
            $url=PosTaxIntegrationEndpoints::checked('ims',$r->environment,$p->{$r->environment.'_url'});
            $res=Http::withToken($token)->acceptJson()->connectTimeout(10)->timeout(45)->withoutRedirecting()->post($url,$r->payload);
            $result=$res->successful() ? self::result($res->json()) : $unknown;
        } catch (\Throwable $e) { $result=$unknown; }
        return $this->complete($companyId,$id,$result);
    }
    public static function result($data,bool $local=false): array
    {
        $unknown=['state'=>'verification_required','reference'=>null,'error_codes'=>[]];
        if (!is_array($data)) return $unknown;
        $number=FbrPosFiscalResult::fromResponse($data);
        $codes=[];
        foreach (['Code','code'] as $key) {
            if (!isset($data[$key])) continue;
            if (!is_string($data[$key]) && !is_int($data[$key]) && !(is_float($data[$key]) && is_finite($data[$key]))) return $unknown;
            $codes[]=trim((string)$data[$key]);
        }
        if (count(array_unique($codes))>1) return $unknown;
        $code=$codes[0] ?? '';
        if ($code==='100' && $number) return ['state'=>$local?'local_accepted':'accepted','reference'=>$number,'error_codes'=>[]];
        // Any acknowledgement evidence makes a conflicting rejection uncertain.
        if ($number || !empty($data['InvoiceNumber']) || !empty($data['FBRInvoiceNumber']) || !empty($data['invoiceNumber'])) return $unknown;
        if (preg_match('/^[0-9]{3}$/',$code) && $code !== '100') return ['state'=>'rejected','reference'=>null,'error_codes'=>[$code]];
        return $unknown;
    }
    private function complete(int $companyId,int $id,array $result,?string $claim=null,?string $diagnostic=null): PosImsSubmission
    {
        return DB::transaction(function() use($companyId,$id,$result,$claim,$diagnostic){
            $r=$this->locked($companyId,$id);
            if ($claim !== null) abort_unless(is_string($r->agent_claim) && hash_equals($r->agent_claim,$claim),409);
            // Replayed callbacks are idempotent and never overwrite terminal evidence.
            if ($r->state !== 'submitting') return $r;
            $a=$r->attempts;$a[array_key_last($a)]['state']=$result['state'];$a[array_key_last($a)]['error_codes']=$result['error_codes'];
            if ($diagnostic !== null && in_array($diagnostic,['invalid_endpoint','response_received','unrecognized_response','transport_timeout','http_error','invalid_json_result','unconfirmed_soap_or_transport'],true)) $a[array_key_last($a)]['diagnostic']=$diagnostic;
            if (!empty($result['response_fields'])) $a[array_key_last($a)]['response_fields']=$result['response_fields'];
            if (isset($result['acknowledgement'])) $a[array_key_last($a)]['acknowledgement']=$result['acknowledgement'];
            $r->fill(['state'=>$result['state'],'fiscal_number'=>$result['reference'],'attempts'=>$a])->save();return $r;
        });
    }
    public function claim(int $companyId,string $deviceUid,bool $supportsSoap=false): ?array
    {
        return DB::transaction(function() use($companyId,$deviceUid,$supportsSoap){
            Company::whereKey($companyId)->lockForUpdate()->firstOrFail();$p=$this->profile($companyId);
            abort_unless($p->connection_mode==='fiscal_device' && $p->device_uid === $deviceUid,409);
            $r=PosImsSubmission::where('company_id',$companyId)->where('environment',$p->environment)
                ->where('state','queued_agent')->orderBy('id')->lockForUpdate()->first();
            if (!$r) return null;
            if (str_ends_with($r->snapshot['local_ims_url'] ?? '', '/TcpService/IMSHttp') && !$supportsSoap) return null;
            $this->activeRecord($r); self::preventOtherFederalRecord($companyId,$r->source_transaction_id,$r->environment,'ims');
            $this->validatePayload($r,$r->payload);
            abort_unless(hash_equals($r->payload_hash,hash('sha256',json_encode($r->payload,JSON_THROW_ON_ERROR))),409);
            $a=$r->attempts;$a[array_key_last($a)]['state']='submitting';
            $r->fill(['state'=>'submitting','agent_claim'=>bin2hex(random_bytes(32)),'device_uid'=>$deviceUid,'attempted_at'=>now(),'attempts'=>$a])->save();
            return ['id'=>$r->id,'claim'=>$r->agent_claim,'payload'=>$r->payload,'endpoint'=>$r->snapshot['local_ims_url'],'environment'=>$r->environment];
        });
    }
    public function callback(int $companyId,int $id,string $deviceUid,string $claim,$response,?string $diagnostic=null,array $responseFields=[]): PosImsSubmission
    {
        $r=PosImsSubmission::where('company_id',$companyId)->findOrFail($id);
        abort_unless($r->connection_mode==='fiscal_device' && $r->device_uid===$deviceUid,403);
        $result=self::result($response,true);
        // The live IMS component can acknowledge with only FBRInvoiceNumber.
        // Bind that reply shape to this claimed POS and keep central verification separate.
        if ($result['state'] === 'verification_required'
            && $r->environment === 'production'
            && $diagnostic === 'response_received'
            && $responseFields === ['FBRInvoiceNumber:string']
            && is_array($response)
            && in_array(array_keys($response), [['InvoiceNumber'], ['FBRInvoiceNumber']], true)) {
            $number = FbrPosFiscalResult::fromResponse($response);
            $posId = str_pad((string) $r->payload['POSID'], 6, '0', STR_PAD_LEFT);
            if ($number && strlen($posId) === 6 && str_starts_with($number, $posId)
                && preg_match('/[0-9]/', substr($number, 6))) {
                $result = ['state'=>'local_accepted','reference'=>$number,'error_codes'=>[]];
            }
        }

        // Retain only bounded, validated fiscal fields in encrypted evidence.
        // The original code used to discard all callback evidence after classification.
        $evidence=[];
        foreach (['Code','code'] as $key) {
            $value=is_array($response) ? ($response[$key] ?? null) : null;
            if ((is_string($value) || is_int($value) || (is_float($value) && is_finite($value))) && preg_match('/^[A-Za-z0-9_-]{1,12}$/D',trim((string)$value))) $evidence[$key]=trim((string)$value);
        }
        foreach (['InvoiceNumber','FBRInvoiceNumber','invoiceNumber','Response'] as $key) {
            $value=is_array($response) ? ($response[$key] ?? null) : null;
            if ($key==='Response' && (!is_string($value) || !preg_match('/^(?:[0-9]{6}|[A-Z][A-Z-]*-[0-9]{3,}$)/',trim($value)))) continue;
            if ($number=FbrPosFiscalResult::number($value)) $evidence[$key]=$number;
        }
        $result['acknowledgement']=$evidence;
        $result['response_fields']=array_values(array_filter(array_slice($responseFields,0,16),fn($field)=>is_string($field) && strlen($field)<=100 && preg_match('/^[A-Za-z0-9_.]+:(string|number|boolean|object|array|null)$/D',$field)));
        return $this->complete($companyId,$id,$result,$claim,$diagnostic);
    }
    /** Record independently checked portal evidence; NEVER dispatch an invoice. */
    public function reconcilePortal(int $companyId,int $id,int $actor,array $evidence): PosImsSubmission
    {
        return DB::transaction(function() use($companyId,$id,$actor,$evidence){
            Company::whereKey($companyId)->lockForUpdate()->firstOrFail();
            $r=$this->locked($companyId,$id);
            abort_unless(in_array($r->state,['verification_required','local_accepted'],true) && $r->payload,409,
                'Only an unresolved or locally acknowledged invoice can be reconciled. Never resend it.');
            $evidence=Validator::make($evidence,[
                'environment'=>'required|in:sandbox,production','pos_id'=>'required|integer|min:1',
                'usin'=>'required|string|max:100','invoice_date'=>'required|date_format:Y-m-d',
                'invoice_type'=>'required|integer|in:1,3','sale_value'=>'required|numeric|min:0|decimal:0,2',
                'tax_charged'=>'required|numeric|min:0|decimal:0,2','total_amount'=>'required|numeric|min:0|decimal:0,2',
                'quantity'=>'required|numeric|min:0|decimal:0,3','fiscal_number'=>'required|string|max:100',
                'evidence_note'=>'required|string|min:20|max:1000','confirm_portal'=>'accepted',
            ])->validate();
            $number=FbrPosFiscalResult::number($evidence['fiscal_number']);
            if (!$number) throw ValidationException::withMessages(['fiscal_number'=>'Enter the exact FBR invoice number shown in the portal.']);
            $p=$r->payload;
            $identity=$evidence['environment']===$r->environment
                && (int)$evidence['pos_id']===(int)$p['POSID'] && $evidence['usin']===$p['USIN']
                && $evidence['invoice_date']===substr($p['DateTime'],0,10)
                && (int)$evidence['invoice_type']===(int)$p['InvoiceType'];
            foreach(['sale_value'=>'TotalSaleValue','tax_charged'=>'TotalTaxCharged',
                'total_amount'=>'TotalBillAmount','quantity'=>'TotalQuantity'] as $input=>$field) {
                $identity=$identity && round((float)$evidence[$input],$field==='TotalQuantity'?3:2)
                    ===round((float)$p[$field],$field==='TotalQuantity'?3:2);
            }
            if (!$identity) throw ValidationException::withMessages(['confirm_portal'=>'Portal identity and amounts must match this exact frozen IMS payload.']);
            abort_if($r->fiscal_number && $r->fiscal_number!==$number,409,'Existing acknowledgement conflicts with this reference.');
            abort_if(PosImsSubmission::where('company_id',$companyId)->where('environment',$r->environment)
                ->where('id','!=',$id)->where('fiscal_number',$number)->exists(),409,'This reference already belongs to another invoice.');
            self::preventOtherFederalRecord($companyId,$r->source_transaction_id,$r->environment,'ims');
            $attempts=$r->attempts ?? [];
            $attempts[]=['at'=>now()->toIso8601String(),'state'=>'accepted','error_codes'=>[],
                'source'=>'owner_verified_fbr_portal','actor_id'=>$actor,'previous_state'=>$r->state,
                'fiscal_number'=>$number,'evidence'=>$evidence];
            $r->fill(['state'=>'accepted','fiscal_number'=>$number,'attempts'=>$attempts])->save();
            return $r;
        });
    }

    private function locked(int $companyId,int $id): PosImsSubmission
    { return PosImsSubmission::where('company_id',$companyId)->lockForUpdate()->findOrFail($id); }
}

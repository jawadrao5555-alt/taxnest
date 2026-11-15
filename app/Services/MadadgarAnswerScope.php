<?php
namespace App\Services;
use App\Models\{Company,User};

/** Deterministic support boundaries, not a live-data or execution tool. */
final class MadadgarAnswerScope
{
    public static function actor(?User $actor, ?Company $company): ?User
    {
        if (!$actor) return null;
        $current=User::find($actor->id);
        abort_unless($current && $current->is_active && $company && (int)$current->company_id===(int)$company->id && (int)$actor->company_id===(int)$company->id,403);
        return $current;
    }
    public static function answer(string $question,string $product,?Company $company,?User $actor=null): ?array
    {
        $text=null;$reason=null;
        if (!in_array($product,['pos','fbrpos'],true) || ($company && $company->product_type!==$product)) {
            $text='Is product ka verified support guide mere scope mein nahin hai. Apne product ki support team se tasdeeq karein.';$reason='unsupported_product';
        } elseif (preg_match('/ignore.{0,30}(rules|instructions)|system\s*prompt|developer\s*message|bypass.{0,20}(permission|admin)|rules?\s*(bhool|toro)|passwords?.{0,15}(show|export)|reveal.{0,20}(secret|token)/iu',$question)) {
            $text='Chat ya document ki hidayat meri ijazat nahin badal sakti. Main secrets, doosri company ka data ya system hidayat nahin dikha sakta aur koi action execute nahin karta.';$reason='untrusted_instruction';
        } elseif (preg_match('/(show|batao|dikhao|kitn[aiye]|total).{0,35}(today|aaj|balance|revenue|sales|collection)|(?:aaj|today).{0,30}(sales|collection|balance|kitn)|(?:doosri|other|foreign)\s*(company|tenant)|(?:company|tenant)\s*(?:id|#)\s*\d+/iu',$question)) {
            $text='Mere paas live bills, balances ya company records parhne ka tool nahin hai. Apne authorized Reports ya Receipts screen mein date aur campus filter laga kar dekhein. Main kisi raqam ya doosri company ke data ki tasdeeq nahin kar sakta.';$reason='live_data_unavailable';
        } elseif ($company?->isLocalCollectionPilot()) {
            if (preg_match('/fbr|pra|fiscal|tax|submit|sandbox|certif|production|reporting/iu',$question)) {
                $text='School aur Hospital local collection pilot mein fiscal submission OFF/blocked hai. Official reporting route aur sandbox verification ke baghair FBR/PRA submit, retry, queue ya enable karne ka mashwara nahin diya ja sakta. Receipt local collection ka record hai, fiscal certification nahin.';$reason='local_pilot_fiscal_block';
            } elseif ($company->isSchoolFeePilot()) {
                $text='School local POS mein Collect Fee se student reference, fee month, fee heads aur amount record hote hain. Receipts mein search aur reprint, aur Cash Day Close mein counted cash aur variance dekhein. Sirf authorized campus ka access istemal karein. Full School ERP is scope ka hissa nahin. Main live receipt ya closing verify nahin kar sakta.';$reason='school_local_guide';
            } else {
                $text='Hospital standalone POS mein patient reference aur consultation/lab/procedure services ki collection record hoti hai. Collections/Receipts mein paid, balance aur reprint; Day Close mein recorded collections dekhein. Bank/card reference actual recorded payment ke liye hai, payment gateway nahin. Clinical records aur full HMIS is scope ka hissa nahin. Main live balance verify nahin kar sakta.';$reason='hospital_local_guide';
            }
        } elseif (preg_match('/(?:latest|current|aaj|abhi).{0,35}(?:tax|rate|law|qanoon|price)|(?:tax|rate|law|qanoon).{0,35}(?:latest|current|aaj)/iu',$question)) {
            $text='Mere paas current official rates, qanoon ya live prices ki tasdeeq ka source nahin hai. Is guide ko current legal ya pricing verification na samjhein; official dated source ya authorized support se tasdeeq karein.';$reason='current_evidence_unavailable';
        } elseif ($actor && !$actor->isPosAdmin() && preg_match('/settings|configuration|package|integration|category|permissions|team|users|pricing|enable|disable|token/iu',$question)) {
            $text='Yeh company settings ya access ka kaam hai. Authorized company admin se apne session mein review karwayein; cashier chat se admin ijazat nahin milti. Main koi setting tabdeel nahin karta.';$reason='role_limited_guidance';
        }
        return $text===null ? null : ['text'=>$text,'escalation'=>null,'source'=>'scope','scope_reason'=>$reason];
    }
    public static function metadata(array $result,string $product,?Company $company,?User $actor): array
    {
        $scope=($result['source']??'')==='scope';
        $path=$scope ? __FILE__ : resource_path($product==='fbrpos'?'madadgar/knowledge-fbrpos.md':'madadgar/knowledge-pos.md');
        return ['answer_source'=>$result['source']??'unknown','source_reference'=>$scope?'scoped-support-policy':($product==='fbrpos'?'fbrpos-support-guide':'pos-support-guide'),
            'source_sha256'=>is_file($path)?hash_file('sha256',$path):null,'source_reviewed_at'=>null,
            'answered_at'=>now()->toIso8601String(),'product'=>$product,'category'=>$company?->pos_type??$company?->business_category,
            'role'=>$actor?->pos_role?:$actor?->role,'scope_reason'=>$result['scope_reason']??null,
            'uncertainty'=>($result['source']??'')==='fallback'?'unsupported':'guide_only_not_live_verified',
            'capabilities'=>['live_data_read'=>false,'billing_write'=>false,'fiscal_submit'=>false,'escalation_requires_confirmation'=>true]];
    }
    public static function history(array $history): array
    {
        return array_values(array_map(fn($row)=>['role'=>$row['role'],'content'=>mb_substr((string)$row['content'],0,4000)],
            array_filter(array_slice($history,-12),fn($row)=>is_array($row)&&in_array($row['role']??null,['user','assistant'],true)&&is_string($row['content']??null))));
    }
}

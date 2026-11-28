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
        } elseif (preg_match('/(?:doosri|other|foreign)\s*(company|tenant)|(?:company|tenant)\s*(?:id|#)\s*\d+/iu',$question)) {
            $text='Doosri company ya prompt se diye gaye identifiers is read scope mein allowed nahin.';$reason='cross_company_denied';
        } elseif ($company?->isLocalCollectionPilot() && preg_match('/fbr|pra|fiscal|tax|submit|sandbox|certif|production|reporting/iu',$question)) {
            $text='School aur Hospital local pilot mein fiscal submission OFF/blocked hai. Official route aur sandbox verification tak submit, retry, queue ya enable nahin hota.';$reason='local_pilot_fiscal_block';
        } elseif (($read=MadadgarCompanyReadTools::answer($question,$product,$company,$actor))!==null) {
            return $read;
        } elseif (preg_match('/(show|batao|dikhao|kitn[aiye]|total).{0,35}(today|aaj|balance|revenue|sales|collection)|(?:aaj|today).{0,30}(sales|collection|balance|kitn)|(?:doosri|other|foreign)\s*(company|tenant)|(?:company|tenant)\s*(?:id|#)\s*\d+/iu',$question)) {
            $text='Yeh sawal bounded read tools mein supported nahin. Owner exact commands today billing, today collections, today tax breakdown, stock summary, recipe summary ya today day close use kar sakta hai. Doosri company, arbitrary dates aur individual records ka access is chat se nahin milta.';$reason='live_data_unavailable';
        } elseif (($roadmap = MadadgarUpcomingProductGuide::answer($question)) !== null) {
            $text = $roadmap;
            $reason = 'upcoming_products_guide';
        } elseif (($guide = self::productGuide($question, $product, $company, $actor)) !== null) {
            [$text, $reason] = $guide;
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

    private static function productGuide(string $question, string $product, ?Company $company, ?User $actor): ?array
    {
        if (preg_match('/\b(?:erps?|hmis)\b/iu', $question)) {
            return ['School, Lab aur Hospital ERP ke workflows aur demo ka rasta /product-guide#erps par dekhein. ERP package, access aur onboarding TaxNest ERP ke apne panel/support se confirm hote hain.', 'erp_guide'];
        }

        $digitalInvoice = preg_match('/\b(?:digital[\s-]+invoic(?:e|ing)|fbr[\s-]+di)\b/iu', $question);
        $fbrPos = preg_match('/\b(?:fbr[\s-]+pos|ims)\b/iu', $question);
        $pra = preg_match('/\bpra\b/iu', $question);
        $addOn = preg_match('/\badd[\s-]*ons?\b/iu', $question);
        $federal = $digitalInvoice || $fbrPos || preg_match('/\b(?:federal|fbr)\b/iu', $question);

        if ($product === 'pos' && $company?->pos_integration_mode === 'standalone'
            && ($pra || $federal || preg_match('/\b(?:fiscal|packages?|plans?|upgrade)\b/iu', $question))) {
            return ['Standalone POS mein sales aur receipts local rehti hain. PRA/FBR reporting aur federal add-ons is edition mein available nahin. Apne current package ke liye /pos/billing ya product options ke liye /product-guide#pra dekhein.', 'standalone_pos_guide'];
        }

        if ($product === 'pos' && $federal && ($addOn || ($pra && $digitalInvoice))) {
            if ($actor && !$actor->isCompanyAdmin()) {
                return ['Federal add-on ki availability aur setup company owner verify kar sakta hai. Owner apne PRA POS Billing aur Tax Integrations mein current status dekhein.', 'role_limited_guidance'];
            }
            return ['PRA POS owner /pos/billing par Federal add-ons mein FBR Digital Invoice ya FBR POS (IMS) ka option check karein. Ek eligible paid package term par aik federal channel select hota hai. Price ya purchase option account eligibility aur configured rate par depend karta hai. Add-on active hona aur fiscal connection setup alag hain. Setup ke liye /pos/tax-integrations dekhein.', 'pra_federal_addon_guide'];
        }

        if ($product === 'fbrpos' && $fbrPos && $addOn) {
            return ['Is FBR POS panel ka apna package /fbr-pos/billing par hai. Iske IMS connection ka status authorized admin /fbr-pos/settings mein dekhein. Optional federal add-on ka purchase eligible PRA POS account ki Billing mein hota hai. Dono products ka guide /product-guide par dekhein.', 'fbr_pos_ims_guide'];
        }

        if ($pra && $fbrPos && preg_match('/\b(?:compare|difference|farq|muqabla|versus|vs)\b/iu', $question)) {
            return ['PRA POS Punjab Revenue Authority ki reporting ke liye apna POS product hai. FBR POS ka alag panel FBR IMS ki reporting ke liye hai. Product selection aur setup ka muqabla /product-guide par dekhein.', 'pos_product_comparison'];
        }

        if ($digitalInvoice && ($product === 'fbrpos' || !$pra)) {
            $text = 'TaxNest Digital Invoice ka apna workflow /product-guide#di par hai. Digital Invoice company owner ka read-only Madadgar console /madadgar/di-read par milta hai.';
            if ($product === 'pos') {
                $text .= ' PRA POS ke optional federal connection ka status PRA POS Billing mein dekhein.';
            }
            return [$text, 'digital_invoice_guide'];
        }

        if ($fbrPos && $product === 'pos' && !$pra) {
            return ['TaxNest FBR POS ke alag product aur login ka guide /product-guide#fbr par hai. Agar sawal PRA POS ke optional IMS add-on ka hai, apne PRA POS Billing mein availability check karein.', 'fbr_pos_guide'];
        }
        if ($pra && $product === 'fbrpos') {
            return ['PRA POS ka alag login aur setup hai. Uski billing aur reporting ke steps /product-guide#pra par dekhein.', 'pra_pos_guide'];
        }

        return null;
    }

    public static function metadata(array $result,string $product,?Company $company,?User $actor): array
    {
        if (isset($result['read_evidence'])) {
            $e=$result['read_evidence'];
            return ['answer_source'=>'company_read','source_reference'=>implode(', ',$e['sources']),
                'source_sha256'=>hash_file('sha256',__DIR__.'/MadadgarCompanyReadTools.php'),'source_reviewed_at'=>null,
                'answered_at'=>$e['read_at'],'product'=>$product,'category'=>$company?->pos_type??$company?->business_category,
                'role'=>$actor?->pos_role?:$actor?->role,'scope_reason'=>'bounded_company_read','uncertainty'=>'retained_records_not_independently_verified',
                'read_audit_id'=>$e['audit_id'],'report_date'=>$e['report_date'],'timezone'=>$e['timezone'],'branch_id'=>$e['branch_id'],
                'result_sha256'=>$e['result_sha256'],'outcome'=>$e['outcome'],
                'capabilities'=>['live_data_read'=>in_array($e['outcome'],['recorded','empty'],true),'billing_write'=>false,'fiscal_submit'=>false,'escalation_requires_confirmation'=>true]];
        }
        if (($result['scope_reason'] ?? null) === 'upcoming_products_guide') {
            return [
                'answer_source' => 'public_product_roadmap',
                'source_reference' => '/upcoming-products',
                'source_sha256' => null,
                'source_reviewed_at' => null,
                'answered_at' => now()->toIso8601String(),
                'product' => $product,
                'category' => $company?->pos_type ?? $company?->business_category,
                'role' => $actor?->pos_role ?: $actor?->role,
                'scope_reason' => 'upcoming_products_guide',
                'uncertainty' => 'published_status_only',
                'capabilities' => ['live_data_read' => false, 'billing_write' => false, 'fiscal_submit' => false, 'escalation_requires_confirmation' => true],
            ];
        }
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

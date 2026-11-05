<?php
namespace App\Services;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/** Separate service-fee snapshot; never a second sale or an item-tax line. */
final class PosImsFee
{
    public static function quote(int $companyId): float
    {
        if (!Schema::hasColumn('pos_transactions','fbr_service_charge')
            || !Schema::hasTable('pos_ims_profiles') || !Schema::hasTable('pos_di_sandbox_profiles')) return 0;
        $selected=app(PosFederalAutomation::class)->selected($companyId);
        if (!$selected || $selected[0]!=='ims') return 0;
        $p=$selected[1]; $env=$p->environment;
        if ($env!=='production' || !$p->production_confirmed_at || !config('pra_di_sandbox.production_enabled')
            || (int)$p->{$env.'_pos_id'}<1) return 0;
        if ($p->connection_mode==='fiscal_device') {
            $port=(int)parse_url((string)$p->local_ims_url,PHP_URL_PORT);
            return Schema::hasColumn('companies','agent_enabled')
                && Company::whereKey($companyId)->where('agent_enabled',true)->exists()
                && $p->device_uid && $port>=1024 && $port<=65535
                && preg_match('~^http://(?:127\.0\.0\.1|localhost):[0-9]{4,5}/(?:api/IMSFiscal/GetInvoiceNumberByModel|TcpService/IMSHttp)$~',(string)$p->local_ims_url)
                ? 1.0 : 0.0;
        }
        try { return trim((string)$p->{$env.'_token'})!=='' ? 1.0 : 0.0; }
        catch(\Illuminate\Contracts\Encryption\DecryptException) { return 0.0; }
    }

    public static function forCheckout(Request $request,int $companyId,bool $fiscal,?array $coreTotals=null): float
    {
        if (!Schema::hasColumn('pos_transactions','fbr_service_charge')) return 0;
        // Preserve already-collected offline money, including older clients
        // that have no fee field. Never re-price an old sale during replay.
        if ($coreTotals!==null) {
            $fee=(float)($coreTotals['fbr_service_charge'] ?? 0);
            abort_unless(in_array($fee,[0.0,1.0],true),422,'Review the original signed fee snapshot.');
            return $fee;
        }
        if ($request->filled('offline_queued_at')) {
            $request->validate(['fbr_service_charge'=>'nullable|numeric|in:0,1']);
            return (float)$request->input('fbr_service_charge',0);
        }
        $fee=$fiscal ? self::quote($companyId) : 0.0;
        if ($request->has('fbr_service_charge')) {
            $request->validate(['fbr_service_charge'=>'required|numeric|in:0,1']);
            if ((float)$request->input('fbr_service_charge')!==$fee) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'fbr_service_charge'=>'FBR POS fee settings changed. Refresh the sale screen and review the payment total.']);
            }
        }
        return $fee;
    }

    public static function fields(float $fee): array
    {
        return Schema::hasColumn('pos_transactions','fbr_service_charge') ? ['fbr_service_charge'=>$fee] : [];
    }
}

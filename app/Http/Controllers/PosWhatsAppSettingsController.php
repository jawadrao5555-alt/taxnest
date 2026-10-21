<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\PosFeatureService;
use App\Services\PosWhatsAppReadiness;
use App\Services\WhatsAppBusinessApi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PosWhatsAppSettingsController extends Controller
{
    private function company(): Company
    {
        abort_unless(auth('pos')->user()?->isPosAdmin(), 403, __('pos.only_admin_change_setting'));
        $company = Company::findOrFail(app('currentCompanyId'));
        abort_unless(($company->product_type ?? 'pos') === 'pos', 403);
        return $company;
    }

    public function show()
    {
        $company = $this->company();
        $deliveries = Schema::hasTable('pos_whatsapp_outbox')
            ? DB::table('pos_whatsapp_outbox')->where('company_id', $company->id)->latest('id')->limit(50)->get()
            : collect();
        return view('pos.whatsapp-delivery', [
            'company' => $company, 'allowed' => PosFeatureService::planAllows($company, 'whatsapp_enabled'),
            'configured' => WhatsAppBusinessApi::configuredFor($company), 'deliveries' => $deliveries,
            'readiness' => PosWhatsAppReadiness::inspect($company),
            'confirmedDelivery' => Schema::hasTable('pos_whatsapp_outbox')
                ? DB::table('pos_whatsapp_outbox')->where('company_id', $company->id)
                    ->whereIn('status', ['delivered', 'read'])->latest('updated_at')->first(['transaction_id', 'status', 'updated_at'])
                : null,
        ]);
    }

    public function save(Request $request)
    {
        $company = $this->company();
        abort_unless(Schema::hasColumn('companies', 'pos_whatsapp_auto_send') && Schema::hasTable('pos_whatsapp_outbox'), 503);
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'auto_send' => 'required|boolean', 'wa_api_enabled' => 'sometimes|boolean',
            'wa_phone_number_id' => ['sometimes', 'nullable', 'string', 'max:100', 'regex:/^[0-9]+$/'],
            'wa_api_token' => 'sometimes|nullable|string|max:2000',
            'wa_app_secret' => 'sometimes|nullable|string|max:255',
            'wa_template_name' => 'sometimes|nullable|string|max:100|regex:/^[a-z0-9_]+$/',
            'wa_attach_pdf' => 'sometimes|boolean',
            'wa_webhook_verify_token' => 'sometimes|nullable|string|max:100',
        ]);
        if ($validator->fails()) return back()->withErrors($validator);
        $data = ['pos_whatsapp_auto_send' => $request->boolean('auto_send')];
        foreach (['wa_phone_number_id', 'wa_template_name', 'wa_webhook_verify_token'] as $key) {
            if ($request->has($key)) $data[$key] = trim((string) $request->input($key)) ?: null;
        }
        foreach (['wa_api_enabled', 'wa_attach_pdf'] as $key) if ($request->has($key)) $data[$key] = $request->boolean($key);
        if (trim((string) $request->input('wa_api_token')) !== '') $data['wa_api_token'] = Crypt::encryptString(trim($request->input('wa_api_token')));
        if (trim((string) $request->input('wa_app_secret')) !== '') $data['wa_app_secret'] = trim($request->input('wa_app_secret'));
        $company->forceFill($data);
        if ($data['pos_whatsapp_auto_send']) {
            if (!PosFeatureService::planAllows($company, 'whatsapp_enabled')) {
                return back()->withErrors(['auto_send' => __('pos.wa_bill_plan_locked_api')]);
            }
            if (!PosWhatsAppReadiness::inspect($company)['setup_ready']) {
                // Never flash secrets back through validation old-input/session.
                return back()->withErrors(['auto_send' => 'WhatsApp Bill aur readable API credentials/App Secret configure karein. Neeche setup checklist dekhein.']);
            }
        }
        $company->save();
        return redirect()->route('pos.whatsapp-delivery')->with('success', 'WhatsApp settings save ho gayi hain.');
    }
}

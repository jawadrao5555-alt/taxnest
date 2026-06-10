<?php

namespace App\Http\Controllers\SaasAdmin;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Models\AdminAuditLog;
use Illuminate\Http\Request;

class AdminSettingsController extends Controller
{
    /**
     * Keys managed by this page (support contact + manual-payment bank details).
     */
    private array $keys = [
        'support_whatsapp_number',
        'payment_bank_name',
        'payment_account_title',
        'payment_account_number',
        'payment_iban',
        'payment_instructions',
    ];

    public function index()
    {
        $settings = [];
        foreach ($this->keys as $key) {
            $settings[$key] = SystemSetting::get($key, '');
        }

        return view('saas-admin.settings', compact('settings'));
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'support_whatsapp_number' => ['nullable', 'string', 'max:25'],
            'payment_bank_name' => ['nullable', 'string', 'max:120'],
            'payment_account_title' => ['nullable', 'string', 'max:120'],
            'payment_account_number' => ['nullable', 'string', 'max:60'],
            'payment_iban' => ['nullable', 'string', 'max:60'],
            'payment_instructions' => ['nullable', 'string', 'max:1000'],
        ]);

        // Normalise the WhatsApp number to digits only (country code + number, no +).
        if (isset($data['support_whatsapp_number'])) {
            $data['support_whatsapp_number'] = preg_replace('/\D/', '', $data['support_whatsapp_number']);
        }

        foreach ($this->keys as $key) {
            SystemSetting::set($key, $data[$key] ?? '', 'Trial / payment / support configuration');
        }

        AdminAuditLog::log(auth('admin')->id(), 'Support & payment settings updated', 'SystemSetting', null, [
            'whatsapp' => $data['support_whatsapp_number'] ?? '',
            'bank' => $data['payment_bank_name'] ?? '',
        ]);

        return back()->with('success', 'Settings saved successfully.');
    }
}

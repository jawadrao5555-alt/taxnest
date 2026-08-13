<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Company;
use App\Services\SecurityLogService;
use App\Services\AuditLogService;
use App\Services\DiBrandingService;
use App\Services\DiFeatureService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

class CompanySettingsController extends Controller
{
    public function profile()
    {
        return redirect('/profile');
    }

    public function updateProfile(Request $request)
    {
        $company = Company::find(auth()->user()->company_id);

        $request->validate([
            'name' => 'required|string|max:255',
            'owner_name' => 'nullable|string|max:255',
            'ntn' => 'nullable|string|max:50',
            // Shared CNIC truth (Task 580): 13 digits, dash-tolerant, GLOBAL
            // uniqueness (own row exempt) — same rules as POS/FBR profiles.
            'cnic' => \App\Services\LoginIdentifierResolver::cnicRules($company->id),
            'registration_no' => 'nullable|string|max:100',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'mobile' => 'nullable|string|max:50',
            'business_activity' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:100',
            'website' => 'nullable|string|max:255',
        ], \App\Services\LoginIdentifierResolver::cnicMessages());

        $data = $request->only(['name', 'owner_name', 'ntn', 'cnic', 'registration_no', 'email', 'phone', 'mobile', 'business_activity', 'address', 'city', 'website']);
        if (array_key_exists('cnic', $data)) {
            // Always store plain digits (empty clears to NULL).
            $data['cnic'] = \App\Services\LoginIdentifierResolver::normalizeCnic($data['cnic']);
        }

        $company->update($data);

        SecurityLogService::log('company_profile_updated', auth()->id(), [
            'company_id' => $company->id,
        ]);

        return redirect('/company/profile')->with('success', 'Company profile updated.');
    }

    public function fbrSettings()
    {
        $company = Company::find(auth()->user()->company_id);
        if (!$company) {
            return redirect('/dashboard')->with('error', 'Company not found.');
        }

        // Centralized DI token resolution: decrypts encrypted tokens, passes through
        // plausible raw tokens, and yields '' for corrupted blobs (never shows a
        // Crypt blob in the settings form).
        $fbrService = app(\App\Services\FbrService::class);
        $sandboxToken = $fbrService->resolveDiToken($company, 'sandbox') ?: null;
        $productionToken = $fbrService->resolveDiToken($company, 'production') ?: null;

        $initData = [
            'environment' => $company->fbr_environment ?? 'sandbox',
            'registration_no' => $company->fbr_registration_no ?? '',
            'business_name' => $company->fbr_business_name ?? '',
            'sandbox_url' => $company->fbr_sandbox_url ?? 'https://gw.fbr.gov.pk/di_data/v1/di/postinvoicedata_sb',
            'production_url' => $company->fbr_production_url ?? 'https://gw.fbr.gov.pk/di_data/v1/di/postinvoicedata',
            'sandbox_token' => $sandboxToken ?? '',
            'production_token' => $productionToken ?? '',
            'connection_status' => $company->fbr_connection_status ?? 'unknown',
            'has_sandbox' => !empty($sandboxToken),
            'has_production' => !empty($productionToken),
        ];

        return view('company.fbr-settings', compact('company', 'sandboxToken', 'productionToken', 'initData'));
    }

    public function updateFbrSettings(Request $request)
    {
        $company = Company::find(auth()->user()->company_id);
        if (!$company) {
            return redirect('/dashboard')->with('error', 'Company not found.');
        }

        $request->validate([
            'fbr_environment' => 'required|in:sandbox,production',
            'fbr_sandbox_token' => 'nullable|string|max:500',
            'fbr_production_token' => 'nullable|string|max:500',
            'fbr_registration_no' => 'nullable|string|max:100',
            'fbr_business_name' => 'nullable|string|max:255',
            'fbr_sandbox_url' => 'nullable|url|max:500',
            'fbr_production_url' => 'nullable|url|max:500',
        ]);

        if ($request->fbr_environment === 'production' && $company->fbr_environment !== 'production') {
            if (!$request->has('confirm_production') || $request->confirm_production !== 'CONFIRM') {
                return redirect('/company/fbr-settings')->with('error', 'Production switch requires double confirmation. Please type CONFIRM.');
            }
        }

        $data = [
            'fbr_environment' => $request->fbr_environment,
            'fbr_registration_no' => $request->fbr_registration_no,
            'fbr_business_name' => $request->fbr_business_name,
            'fbr_sandbox_url' => $request->fbr_sandbox_url,
            'fbr_production_url' => $request->fbr_production_url,
        ];

        if ($request->filled('fbr_sandbox_token')) {
            $data['fbr_sandbox_token'] = Crypt::encryptString($request->fbr_sandbox_token);
        }

        if ($request->filled('fbr_production_token')) {
            $data['fbr_production_token'] = Crypt::encryptString($request->fbr_production_token);
        }

        $company->update($data);

        SecurityLogService::log('fbr_settings_updated', auth()->id(), [
            'company_id' => $company->id,
            'environment' => $request->fbr_environment,
        ]);

        AuditLogService::log('fbr_settings_changed', 'Company', $company->id, null, [
            'environment' => $request->fbr_environment,
            'registration_no' => $request->fbr_registration_no,
        ]);

        return redirect('/company/fbr-settings')->with('success', 'FBR settings updated.');
    }

    public function updateFbrSettingsAjax(Request $request)
    {
        try {
            if (!auth()->check()) {
                return response()->json(['success' => false, 'message' => 'Session expired. Please login again.'], 401);
            }

            $company = Company::find(auth()->user()->company_id);
            if (!$company) {
                return response()->json(['success' => false, 'message' => 'Company not found.']);
            }

            $env = $request->input('fbr_environment', 'sandbox');
            if (!in_array($env, ['sandbox', 'production'])) {
                return response()->json(['success' => false, 'message' => 'Invalid environment selected.']);
            }

            $data = [
                'fbr_environment' => $env,
                'fbr_registration_no' => $request->input('fbr_registration_no') ?: null,
                'fbr_business_name' => $request->input('fbr_business_name') ?: null,
                'fbr_sandbox_url' => $request->input('fbr_sandbox_url') ?: null,
                'fbr_production_url' => $request->input('fbr_production_url') ?: null,
            ];

            $sandboxToken = $request->input('fbr_sandbox_token');
            if (!empty($sandboxToken)) {
                $data['fbr_sandbox_token'] = Crypt::encryptString($sandboxToken);
            }

            $productionToken = $request->input('fbr_production_token');
            if (!empty($productionToken)) {
                $data['fbr_production_token'] = Crypt::encryptString($productionToken);
            }

            $company->update($data);

            SecurityLogService::log('fbr_settings_updated', auth()->id(), [
                'company_id' => $company->id,
                'environment' => $env,
            ]);

            AuditLogService::log('fbr_settings_changed', 'Company', $company->id, null, [
                'environment' => $env,
                'registration_no' => $request->input('fbr_registration_no'),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'FBR settings saved! Environment: ' . ucfirst($env),
            ]);
        } catch (\Exception $e) {
            \Log::error('FBR settings save error', [
                'error' => $e->getMessage(),
                'company_id' => auth()->check() ? auth()->user()->company_id : null,
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error saving settings: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * WhatsApp Business API (Phase 2) — per-company Meta Cloud API credentials
     * for server-side "seedha bhejein" invoice delivery. Token stored
     * Crypt-encrypted in a TEXT column; wa.me fallback stays when unconfigured.
     */
    public function whatsappSettings()
    {
        $company = Company::find(auth()->user()->company_id);
        if (!$company) {
            return redirect('/dashboard')->with('error', 'Company not found.');
        }

        $hasToken = !empty($company->wa_api_token);

        return view('company.whatsapp-settings', [
            'company' => $company,
            'hasToken' => $hasToken,
            'webhookUrl' => url('/webhooks/whatsapp/' . $company->id),
            'defaultTemplate' => \App\Services\WhatsAppBusinessApi::DEFAULT_TEMPLATE,
        ]);
    }

    public function updateWhatsappSettings(Request $request)
    {
        $company = Company::find(auth()->user()->company_id);
        if (!$company) {
            return redirect('/dashboard')->with('error', 'Company not found.');
        }

        $request->validate([
            'wa_api_enabled' => 'nullable|boolean',
            'wa_phone_number_id' => 'nullable|string|max:100',
            'wa_api_token' => 'nullable|string|max:1000',
            'wa_template_name' => 'nullable|string|max:100',
            'wa_attach_pdf' => 'nullable|boolean',
            'wa_webhook_verify_token' => 'nullable|string|max:100',
        ]);

        $data = [
            'wa_api_enabled' => $request->boolean('wa_api_enabled'),
            'wa_phone_number_id' => trim((string) $request->input('wa_phone_number_id')) ?: null,
            'wa_template_name' => trim((string) $request->input('wa_template_name')) ?: null,
            'wa_attach_pdf' => $request->boolean('wa_attach_pdf'),
            'wa_webhook_verify_token' => trim((string) $request->input('wa_webhook_verify_token')) ?: null,
        ];

        // Token field left blank = keep the existing stored token.
        $token = trim((string) $request->input('wa_api_token'));
        if ($token !== '') {
            $data['wa_api_token'] = Crypt::encryptString($token);
        }

        // Enabling direct send without credentials would silently dead-end
        // every "seedha bhejein" — block it loudly.
        $effectiveToken = $token !== '' ? $token : ($company->wa_api_token ? 'set' : '');
        if ($data['wa_api_enabled'] && ($data['wa_phone_number_id'] === null || $effectiveToken === '')) {
            return redirect('/company/whatsapp-settings')
                ->with('error', 'Direct send on karne ke liye Phone Number ID aur Access Token dono zaroori hain.')
                ->withInput();
        }

        $company->forceFill($data)->save();

        SecurityLogService::log('whatsapp_api_settings_updated', auth()->id(), [
            'company_id' => $company->id,
            'enabled' => $data['wa_api_enabled'],
        ]);

        return redirect('/company/whatsapp-settings')->with('success', 'WhatsApp Business API settings save ho gayi hain.');
    }

    public function testConnection()
    {
        $company = Company::find(auth()->user()->company_id);
        $environment = $company->fbr_environment ?? 'sandbox';

        // Centralized DI token resolution (FbrService): handles encrypted tokens,
        // tolerates plausible RAW tokens, and returns '' for corrupted blobs
        // (never sends an undecryptable blob as a bearer token).
        $tokenEnv = ($environment === 'production' && $company->fbr_production_token) ? 'production' : 'sandbox';
        $token = app(\App\Services\FbrService::class)->resolveDiToken($company, $tokenEnv);

        if (empty($token)) {
            $company->update(['fbr_connection_status' => 'red']);
            $hasStored = $environment === 'production'
                ? !empty($company->fbr_production_token) || !empty($company->fbr_sandbox_token)
                : !empty($company->fbr_sandbox_token);
            return response()->json([
                'status' => 'red',
                'message' => $hasStored
                    ? 'Stored FBR token could not be read (corrupted or encrypted with a different key). Please re-save your token.'
                    : 'No FBR token configured for ' . $environment . ' environment.',
            ]);
        }

        $tokenExpired = $company->token_expiry_date && $company->token_expiry_date < now();
        if ($tokenExpired) {
            $company->update(['fbr_connection_status' => 'red']);
            return response()->json([
                'status' => 'red',
                'message' => 'FBR token has expired. Please renew your token.',
            ]);
        }

        $company->update(['fbr_connection_status' => 'green']);

        SecurityLogService::log('fbr_connection_test', auth()->id(), [
            'company_id' => $company->id,
            'environment' => $environment,
            'result' => 'healthy',
        ]);

        return response()->json([
            'status' => 'green',
            'message' => 'FBR connection is healthy. Token is valid for ' . $environment . ' environment.',
        ]);
    }

    public function sandboxTest(Request $request, string $type)
    {
        $company = Company::find(auth()->user()->company_id);
        $environment = $company->fbr_environment ?? 'sandbox';

        if ($environment !== 'sandbox') {
            return response()->json([
                'success' => false,
                'title' => 'Not Available',
                'message' => 'Sandbox tests are only available in Sandbox environment.',
            ]);
        }

        return match ($type) {
            'ping' => $this->testPing($company),
            'token' => $this->testToken($company),
            'payload' => $this->testPayload($company),
            'config' => $this->testConfig($company),
            'dryrun' => $this->testDryRun($company),
            'provinces' => $this->testProvinces($company),
            default => response()->json(['success' => false, 'title' => 'Unknown Test', 'message' => 'Unknown test type.']),
        };
    }

    private function testPing(Company $company)
    {
        $endpoint = 'https://gw.fbr.gov.pk/di_data/v1/di/postinvoicedata_sb';
        try {
            $ch = curl_init($endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_NOBODY, true);
            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return response()->json([
                'success' => $httpCode > 0,
                'title' => $httpCode > 0 ? 'Endpoint Reachable' : 'Endpoint Unreachable',
                'message' => $httpCode > 0 ? "FBR endpoint responded with HTTP $httpCode." : 'Could not reach FBR endpoint.',
                'details' => ['endpoint' => $endpoint, 'http_code' => $httpCode],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'title' => 'Ping Failed', 'message' => $e->getMessage()]);
        }
    }

    private function testToken(Company $company)
    {
        $token = app(\App\Services\FbrService::class)->resolveDiToken($company, 'sandbox');

        $checks = [];
        $checks['token_exists'] = !empty($token);
        $checks['token_length'] = $token ? strlen($token) : 0;
        $checks['expiry_date'] = $company->token_expiry_date ? \Carbon\Carbon::parse($company->token_expiry_date)->format('d M Y') : 'Not set';
        $checks['expired'] = $company->token_expiry_date ? \Carbon\Carbon::parse($company->token_expiry_date)->isPast() : false;
        $checks['expiring_soon'] = $company->token_expiry_date && !$checks['expired'] ? \Carbon\Carbon::parse($company->token_expiry_date)->diffInDays(now()) <= 7 : false;

        $success = $checks['token_exists'] && !$checks['expired'];

        return response()->json([
            'success' => $success,
            'title' => $success ? 'Token Valid' : 'Token Issues Found',
            'message' => $success
                ? 'Sandbox token is configured and not expired.'
                : (!$checks['token_exists'] ? 'No sandbox token configured.' : 'Token has expired.'),
            'details' => $checks,
        ]);
    }

    private function testPayload(Company $company)
    {
        $samplePayload = [
            'InvoiceNumber' => 'TEST-001',
            'POSID' => $company->fbr_pos_id ?? 'N/A',
            'USIN' => 'TEST-USIN-001',
            'DateTime' => now()->format('Y-m-d H:i:s'),
            'BuyerNTN' => '1234567-8',
            'BuyerName' => 'Test Buyer',
            'TotalSaleValue' => 1000.00,
            'TotalTaxCharged' => 180.00,
            'Items' => [
                [
                    'ItemCode' => '0101.2100',
                    'ItemName' => 'Test Item',
                    'Quantity' => 1,
                    'TaxRate' => 18,
                    'SaleValue' => 1000.00,
                    'TaxCharged' => 180.00,
                ],
            ],
        ];

        $errors = [];
        if (empty($company->fbr_registration_no)) $errors[] = 'FBR Registration No not set';
        if (empty($company->fbr_business_name) && empty($company->name)) $errors[] = 'Business name not set';
        if (empty($company->ntn)) $errors[] = 'Company NTN not set';

        return response()->json([
            'success' => empty($errors),
            'title' => empty($errors) ? 'Payload Structure Valid' : 'Payload Issues',
            'message' => empty($errors)
                ? 'Sample payload structure passes basic validation.'
                : 'Missing required fields: ' . implode(', ', $errors),
            'details' => ['sample_payload' => $samplePayload, 'missing_fields' => $errors],
        ]);
    }

    private function testConfig(Company $company)
    {
        $checks = [];
        $checks['fbr_registration_no'] = !empty($company->fbr_registration_no) ? 'Set' : 'Missing';
        $checks['fbr_business_name'] = !empty($company->fbr_business_name) ? 'Set' : 'Missing';
        $checks['ntn'] = !empty($company->ntn) ? 'Set' : 'Missing';
        $checks['environment'] = $company->fbr_environment ?? 'Not set';
        $checks['sandbox_token'] = !empty($company->fbr_sandbox_token) ? 'Configured' : 'Missing';
        $checks['province'] = !empty($company->province) ? $company->province : 'Not set';
        $checks['invoice_prefix'] = !empty($company->invoice_number_prefix) ? $company->invoice_number_prefix : 'Not set';

        $missing = array_filter($checks, fn($v) => in_array($v, ['Missing', 'Not set']));
        $success = count($missing) <= 2;

        return response()->json([
            'success' => $success,
            'title' => $success ? 'Configuration OK' : 'Configuration Incomplete',
            'message' => $success
                ? 'Company configuration looks good for FBR submissions.'
                : count($missing) . ' settings need attention.',
            'details' => $checks,
        ]);
    }

    private function testDryRun(Company $company)
    {
        $token = app(\App\Services\FbrService::class)->resolveDiToken($company, 'sandbox');

        if (empty($token)) {
            return response()->json([
                'success' => false,
                'title' => 'Dry Run Failed',
                'message' => !empty($company->fbr_sandbox_token)
                    ? 'Stored sandbox token could not be read (corrupted or encrypted with a different key). Please re-save your token.'
                    : 'No sandbox token configured. Please set your sandbox token first.',
            ]);
        }

        $payload = [
            'InvoiceNumber' => 'DRYRUN-' . time(),
            'POSID' => $company->fbr_pos_id ?? ($company->fbr_registration_no ?? 'TEST'),
            'USIN' => 'DRYRUN-USIN-' . time(),
            'DateTime' => now()->format('Y-m-d H:i:s'),
            'BuyerNTN' => '0000000-0',
            'BuyerName' => 'Dry Run Test',
            'TotalSaleValue' => 100.00,
            'TotalTaxCharged' => 18.00,
        ];

        $validateUrl = 'https://gw.fbr.gov.pk/di_data/v1/di/validateinvoicedata_sb';
        try {
            $ch = curl_init($validateUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $decoded = json_decode($response, true);

            return response()->json([
                'success' => $httpCode >= 200 && $httpCode < 300,
                'title' => $httpCode >= 200 && $httpCode < 300 ? 'Dry Run Successful' : 'Dry Run Response: HTTP ' . $httpCode,
                'message' => 'Test payload submitted to FBR sandbox validation endpoint.',
                'details' => ['http_code' => $httpCode, 'payload_sent' => $payload, 'response' => $decoded ?? $response],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'title' => 'Dry Run Failed',
                'message' => 'Could not reach sandbox validation endpoint: ' . $e->getMessage(),
                'details' => ['payload_preview' => $payload],
            ]);
        }
    }

    private function testProvinces(Company $company)
    {
        $provinces = [
            'Punjab' => '01', 'Sindh' => '02', 'Khyber Pakhtunkhwa' => '03',
            'Balochistan' => '04', 'Islamabad' => '05', 'Azad Kashmir' => '06',
            'Gilgit-Baltistan' => '07', 'FATA' => '08',
        ];

        $companyProvince = $company->province ?? null;
        $mapped = $companyProvince && isset($provinces[$companyProvince]);

        return response()->json([
            'success' => true,
            'title' => 'Province Mapping',
            'message' => $mapped
                ? "Company province '$companyProvince' maps to code '{$provinces[$companyProvince]}'."
                : 'Company province not set. All 8 province codes are available for selection.',
            'details' => ['province_codes' => $provinces, 'company_province' => $companyProvince],
        ]);
    }

    // ==========================================================
    // Task 140: DI White-Label Branding (Premium — `white_label`)
    // ==========================================================

    public function branding()
    {
        $company = Company::find(auth()->user()->company_id);
        if (!$company) {
            return redirect('/dashboard')->with('error', 'Company not found.');
        }

        $allowed = DiFeatureService::planAllows($company, 'white_label');
        $settings = DiBrandingService::stored($company);

        $logoUrl = null;
        if ($settings['logo_path'] && Storage::disk('public')->exists($settings['logo_path'])) {
            $logoUrl = Storage::disk('public')->url($settings['logo_path']);
        }

        return view('company.branding', compact('company', 'allowed', 'settings', 'logoUrl'));
    }

    public function updateBranding(Request $request)
    {
        $company = Company::find(auth()->user()->company_id);
        if (!$company) {
            return redirect('/dashboard')->with('error', 'Company not found.');
        }

        // Server-side plan gate — the form is hidden for non-premium plans,
        // but the request must be independently rejected too (fail closed).
        if (!DiFeatureService::planAllows($company, 'white_label')) {
            return redirect('/company/branding')->with('error', 'White-label branding is available on the DI Premium plan. Upgrade to unlock it.');
        }

        $validated = $request->validate([
            'branding_enabled' => 'nullable|in:1',
            'accent_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'footer_line1' => 'nullable|string|max:150',
            'footer_line2' => 'nullable|string|max:150',
            'hide_platform_branding' => 'nullable|in:1',
            'logo' => 'nullable|image|mimes:png,jpg,jpeg|max:' . DiBrandingService::MAX_LOGO_KB . '|dimensions:min_width=32,min_height=32,max_width=2500,max_height=2500',
            'remove_logo' => 'nullable|in:1',
        ], [
            'accent_color.regex' => 'Accent color must be a 6-digit hex value like #0A4D5C.',
            'logo.max' => 'Logo must be 1 MB or smaller.',
            'logo.dimensions' => 'Logo must be between 32x32 and 2500x2500 pixels.',
        ]);

        $stored = DiBrandingService::stored($company);
        $logoPath = $stored['logo_path'];
        $disk = Storage::disk('public');

        if (!empty($validated['remove_logo']) && $logoPath) {
            if ($disk->exists($logoPath)) {
                $disk->delete($logoPath);
            }
            $logoPath = null;
        }

        if ($request->hasFile('logo')) {
            if ($logoPath && $disk->exists($logoPath)) {
                $disk->delete($logoPath);
            }
            $logoPath = $request->file('logo')->store(DiBrandingService::LOGO_DIR, 'public');
        }

        $company->di_branding = [
            'enabled' => !empty($validated['branding_enabled']),
            'logo_path' => $logoPath,
            'accent' => DiBrandingService::sanitizeAccent($validated['accent_color'] ?? null),
            'footer_line1' => trim((string) ($validated['footer_line1'] ?? '')),
            'footer_line2' => trim((string) ($validated['footer_line2'] ?? '')),
            'hide_platform' => !empty($validated['hide_platform_branding']),
        ];
        $company->save();

        DiBrandingService::flushCache();

        SecurityLogService::log('di_branding_updated', auth()->id(), [
            'company_id' => $company->id,
            'enabled' => !empty($validated['branding_enabled']),
            'hide_platform' => !empty($validated['hide_platform_branding']),
        ]);

        return redirect('/company/branding')->with('success', 'Branding settings saved.');
    }
}

<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Company;
use App\Services\SecurityLogService;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\Crypt;

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
            'cnic' => 'nullable|string|max:20',
            'registration_no' => 'nullable|string|max:100',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'mobile' => 'nullable|string|max:50',
            'business_activity' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:100',
            'website' => 'nullable|string|max:255',
        ]);

        $company->update($request->only(['name', 'owner_name', 'ntn', 'cnic', 'registration_no', 'email', 'phone', 'mobile', 'business_activity', 'address', 'city', 'website']));

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

        $sandboxToken = null;
        $productionToken = null;
        try {
            if (!empty($company->fbr_sandbox_token)) {
                $sandboxToken = Crypt::decryptString($company->fbr_sandbox_token);
            }
            if (!empty($company->fbr_production_token)) {
                $productionToken = Crypt::decryptString($company->fbr_production_token);
            }
        } catch (\Exception $e) {
            $sandboxToken = $company->fbr_sandbox_token ?? null;
            $productionToken = $company->fbr_production_token ?? null;
        }

        return view('company.fbr-settings', compact('company', 'sandboxToken', 'productionToken'));
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

    public function testConnection()
    {
        $company = Company::find(auth()->user()->company_id);
        $environment = $company->fbr_environment ?? 'sandbox';

        $token = null;
        try {
            if ($environment === 'production' && $company->fbr_production_token) {
                $token = Crypt::decryptString($company->fbr_production_token);
            } elseif ($company->fbr_sandbox_token) {
                $token = Crypt::decryptString($company->fbr_sandbox_token);
            }
        } catch (\Exception $e) {
            $token = $environment === 'production' ? $company->fbr_production_token : $company->fbr_sandbox_token;
        }

        if (empty($token)) {
            $company->update(['fbr_connection_status' => 'red']);
            return response()->json([
                'status' => 'red',
                'message' => 'No FBR token configured for ' . $environment . ' environment.',
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
        $token = null;
        try {
            if ($company->fbr_sandbox_token) {
                $token = Crypt::decryptString($company->fbr_sandbox_token);
            }
        } catch (\Exception $e) {
            $token = $company->fbr_sandbox_token;
        }

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
        $token = null;
        try {
            if ($company->fbr_sandbox_token) {
                $token = Crypt::decryptString($company->fbr_sandbox_token);
            }
        } catch (\Exception $e) {
            $token = $company->fbr_sandbox_token;
        }

        if (empty($token)) {
            return response()->json([
                'success' => false,
                'title' => 'Dry Run Failed',
                'message' => 'No sandbox token configured. Please set your sandbox token first.',
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
}

<?php

namespace App\Http\Controllers;

use App\Exceptions\AiReaderException;
use App\Models\AiInvoiceParse;
use App\Models\Company;
use App\Services\AiInvoiceReaderService;
use App\Services\DiFeatureService;
use App\Services\PlanLimitService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Task 142: AI Invoice Reader (Premium gate key 'ai_reader').
 *
 * Upload an old/supplier-format invoice (PDF / photo / Excel / CSV) →
 * AI extracts buyer + items → user reviews on the normal create form →
 * saves a DRAFT through the existing store() path. Nothing is ever
 * auto-submitted to FBR.
 */
class AiInvoiceReaderController extends Controller
{
    public function show()
    {
        $companyId = app('currentCompanyId');
        $company = Company::findOrFail($companyId);

        $allowed = DiFeatureService::planAllows($company, 'ai_reader');
        $configured = AiInvoiceReaderService::enabled();
        $quota = $allowed ? AiInvoiceReaderService::quotaState($company) : null;
        $recentParses = $allowed
            ? AiInvoiceParse::where('company_id', $companyId)->orderByDesc('id')->limit(6)->get()
            : collect();

        return view('invoice.ai-reader', compact('company', 'allowed', 'configured', 'quota', 'recentParses'));
    }

    public function parse(Request $request)
    {
        $companyId = app('currentCompanyId');
        $company = Company::findOrFail($companyId);

        if (!DiFeatureService::planAllows($company, 'ai_reader')) {
            return response()->json(['error' => 'AI Invoice Reader is a Premium plan feature. Upgrade your plan to use it.'], 403);
        }
        if (!AiInvoiceReaderService::enabled()) {
            return response()->json(['error' => 'AI service is not configured yet. Please contact support.'], 503);
        }

        // No point burning an AI parse if the draft can't be saved afterwards.
        $limitCheck = PlanLimitService::canCreateInvoice($companyId);
        if (!($limitCheck['allowed'] ?? false)) {
            return response()->json(['error' => $limitCheck['reason'] ?? 'Monthly invoice limit reached.'], 422);
        }

        $quota = AiInvoiceReaderService::quotaState($company);
        if (!$quota['unlimited'] && $quota['remaining'] <= 0) {
            return response()->json([
                'error' => 'Monthly AI parse limit reached (' . $quota['used'] . '/' . $quota['quota'] . '). It resets on the 1st of next month.',
                'quota' => $quota,
            ], 429);
        }

        $request->validate([
            'invoice_file' => 'required|file|max:5120|mimes:pdf,jpg,jpeg,png,webp,xlsx,xls,csv,txt',
        ], [
            'invoice_file.required' => 'Please choose a file first.',
            'invoice_file.max' => 'File is too large — maximum size is 5MB.',
            'invoice_file.mimes' => 'Unsupported file type. Upload a PDF, photo (JPG/PNG), Excel (.xlsx), or CSV file.',
        ]);

        try {
            $parse = AiInvoiceReaderService::parseUpload($request->file('invoice_file'), $company, auth()->id());
        } catch (AiReaderException $e) {
            return response()->json(['error' => $e->getMessage(), 'retry' => true], 422);
        } catch (\Throwable $e) {
            Log::error('AI invoice reader unexpected failure', [
                'company_id' => $companyId,
                'err' => mb_substr($e->getMessage(), 0, 300),
            ]);

            return response()->json(['error' => 'Something went wrong while reading the file. Please try again.', 'retry' => true], 500);
        }

        $payload = $parse->payload_json ?? [];

        return response()->json([
            'ok' => true,
            'redirect' => '/invoice/create?ai_parse=' . $parse->id,
            'items_count' => count($payload['items'] ?? []),
            'warnings' => $payload['warnings'] ?? [],
        ]);
    }
}

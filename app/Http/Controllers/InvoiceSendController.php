<?php

namespace App\Http\Controllers;

use App\Mail\InvoiceShareMail;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\InvoiceDelivery;
use App\Services\InvoiceActivityService;
use App\Services\MailHealth;
use App\Services\PkPhone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * One-click "invoice buyer ko bhejein" over Email / WhatsApp.
 * Table-stakes DI feature — ALL plans, no premium gate.
 *
 *  - Email: branded English mail + B/W PDF attached + public share link, sent
 *    synchronously through the existing noreply SMTP; MailHealth records
 *    success/failure exactly like every other sender.
 *  - WhatsApp: wa.me deep link on the buyer's NORMALIZED PK number with a
 *    prefilled English message. The browser opens WhatsApp — we only build
 *    the link and log the hand-off (no Business API; that is Phase 2).
 *  - Every send lands in invoice_deliveries (channel/recipient/user/status)
 *    and shows as "Delivery History" on the invoice page.
 *  - Missing buyer contact is captured inline in the send modal and saved
 *    back to the matching CustomerProfile (NTN → CNIC → exact name; created
 *    from the invoice's buyer fields when nothing matches).
 */
class InvoiceSendController extends Controller
{
    /**
     * GET /invoice/{invoice}/send-info — prefill data for the send modal.
     */
    public function info(Invoice $invoice)
    {
        $this->authorizeCompany($invoice);
        $this->ensureShareUuid($invoice);

        $profile = $this->matchProfile($invoice);

        return response()->json([
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->display_invoice_number,
            'buyer_name' => $invoice->buyer_name,
            'share_url' => url('/share/invoice/' . $invoice->share_uuid),
            'email' => $profile->email ?? null,
            'phone' => $profile->phone ?? null,
            'has_profile' => (bool) $profile,
        ]);
    }

    /**
     * POST /invoice/{invoice}/send-email
     */
    public function sendEmail(Request $request, Invoice $invoice)
    {
        $this->authorizeCompany($invoice);

        $data = $request->validate([
            'email' => 'required|email:rfc',
            'save_to_profile' => 'nullable|boolean',
        ], [
            'email.required' => 'Buyer ka email address likhein.',
            'email.email' => 'Email address sahi format mein nahi hai.',
        ]);

        $invoice->loadMissing('items', 'company');
        $this->ensureShareUuid($invoice);
        $shareUrl = url('/share/invoice/' . $invoice->share_uuid);
        $email = strtolower(trim($data['email']));

        try {
            Mail::to($email)->send(new InvoiceShareMail($invoice, $shareUrl));
            MailHealth::recordSuccess();
        } catch (\Throwable $e) {
            MailHealth::recordFailure('Buyer invoice email (#' . $invoice->id . ')', $e);
            Log::warning("Invoice #{$invoice->id} buyer email to {$email} failed: " . $e->getMessage());
            $delivery = $this->logDelivery($invoice, 'email', $email, 'failed', $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Email send nahi ho saka — ' . Str::limit($e->getMessage(), 140) . ' Thori dair baad dobara koshish karein.',
                'delivery' => $this->deliveryJson($delivery),
            ], 500);
        }

        $delivery = $this->logDelivery($invoice, 'email', $email, 'sent');
        InvoiceActivityService::log($invoice->id, $invoice->company_id, 'sent_email', ['to' => $email]);
        $profileSaved = $this->maybeSaveContact($invoice, $request->boolean('save_to_profile'), 'email', $email);

        return response()->json([
            'status' => 'ok',
            'message' => 'Invoice email bhej di gayi: ' . $email,
            'delivery' => $this->deliveryJson($delivery),
            'profile_saved' => $profileSaved,
        ]);
    }

    /**
     * POST /invoice/{invoice}/send-whatsapp — validates + logs, returns the
     * wa.me URL; the browser actually opens WhatsApp.
     */
    public function sendWhatsApp(Request $request, Invoice $invoice)
    {
        $this->authorizeCompany($invoice);

        $data = $request->validate([
            'phone' => 'required|string|max:30',
            'save_to_profile' => 'nullable|boolean',
        ], [
            'phone.required' => 'Buyer ka WhatsApp number likhein.',
        ]);

        $normalized = PkPhone::normalize($data['phone']);
        if (!$normalized) {
            return response()->json([
                'status' => 'error',
                'message' => 'Number samajh nahi aaya — PK format likhein, maslan 0300-1234567.',
            ], 422);
        }

        $invoice->loadMissing('company');
        $this->ensureShareUuid($invoice);
        $shareUrl = url('/share/invoice/' . $invoice->share_uuid);

        // Buyer-facing message stays ENGLISH (owner rule).
        $message = 'Invoice ' . $invoice->display_invoice_number
            . ' from ' . ($invoice->company->name ?? 'our company') . "\n"
            . 'Amount: PKR ' . number_format((float) ($invoice->total_amount ?? 0), 2) . "\n"
            . 'View / download PDF: ' . $shareUrl;

        $delivery = $this->logDelivery($invoice, 'whatsapp', $normalized, 'sent');
        InvoiceActivityService::log($invoice->id, $invoice->company_id, 'sent_whatsapp', ['to' => $normalized]);
        $profileSaved = $this->maybeSaveContact($invoice, $request->boolean('save_to_profile'), 'phone', trim($data['phone']));

        return response()->json([
            'status' => 'ok',
            'wa_url' => PkPhone::waUrl($normalized, $message),
            'message' => 'WhatsApp khul raha hai: +' . $normalized,
            'delivery' => $this->deliveryJson($delivery),
            'profile_saved' => $profileSaved,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────

    private function authorizeCompany(Invoice $invoice): void
    {
        // Routes are role-gated (company_admin/employee); this is the same
        // tenant check InvoiceController uses. CompanyScope already 404s
        // cross-tenant route-model binding — belt & braces.
        if ($invoice->company_id !== app('currentCompanyId')) {
            abort(403);
        }
    }

    /** Legacy rows created before share_uuid existed get one on first send. */
    private function ensureShareUuid(Invoice $invoice): void
    {
        if (!$invoice->share_uuid) {
            $invoice->share_uuid = (string) Str::uuid();
            $invoice->saveQuietly();
        }
    }

    /** Match the invoice's buyer to a CustomerProfile: NTN → CNIC → exact name. */
    private function matchProfile(Invoice $invoice): ?CustomerProfile
    {
        $base = fn() => CustomerProfile::where('company_id', $invoice->company_id);

        if (!empty($invoice->buyer_ntn)) {
            $p = $base()->where('ntn', $invoice->buyer_ntn)->first();
            if ($p) return $p;
        }
        if (!empty($invoice->buyer_cnic)) {
            $p = $base()->where('cnic', $invoice->buyer_cnic)->first();
            if ($p) return $p;
        }
        if (!empty($invoice->buyer_name)) {
            return $base()->where('name', $invoice->buyer_name)->first();
        }
        return null;
    }

    /**
     * Save the captured contact back to the buyer's CustomerProfile (creating
     * one from the invoice's buyer fields when none matches). Never fails the
     * send itself — logs and reports profile_saved=false instead.
     */
    private function maybeSaveContact(Invoice $invoice, bool $save, string $field, string $value): bool
    {
        if (!$save || $value === '') {
            return false;
        }

        try {
            $profile = $this->matchProfile($invoice);
            if ($profile) {
                if (trim((string) $profile->{$field}) === $value) {
                    return false; // unchanged — no-op
                }
                $profile->{$field} = $value;
                $profile->save();
                return true;
            }

            if (empty($invoice->buyer_name)) {
                return false;
            }

            CustomerProfile::create([
                'company_id' => $invoice->company_id,
                'name' => $invoice->buyer_name,
                'ntn' => $invoice->buyer_ntn,
                'cnic' => $invoice->buyer_cnic,
                'address' => $invoice->buyer_address,
                'province' => $invoice->destination_province,
                'registration_type' => $invoice->buyer_registration_type ?: 'Unregistered',
                'is_active' => true,
                $field => $value,
            ]);
            return true;
        } catch (\Throwable $e) {
            Log::warning("Invoice #{$invoice->id} buyer contact save-back failed: " . $e->getMessage());
            return false;
        }
    }

    private function logDelivery(Invoice $invoice, string $channel, string $recipient, string $status, ?string $error = null): InvoiceDelivery
    {
        return InvoiceDelivery::create([
            'invoice_id' => $invoice->id,
            'company_id' => $invoice->company_id,
            'user_id' => auth()->id(),
            'channel' => $channel,
            'recipient' => $recipient,
            'status' => $status,
            'error' => $error ? mb_substr($error, 0, 500) : null,
        ]);
    }

    private function deliveryJson(InvoiceDelivery $d): array
    {
        return [
            'id' => $d->id,
            'channel' => $d->channel,
            'recipient' => $d->recipient,
            'status' => $d->status,
            'user' => auth()->user()->name ?? '',
            'at' => $d->created_at ? $d->created_at->format('d M Y, h:i A') : '',
        ];
    }
}

<?php

namespace App\Services;

use App\Mail\ConsultantNotificationMail;
use App\Models\Company;
use App\Models\ConsultantClientLink;
use App\Models\ConsultantCommission;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/**
 * Consultant-program email notifications (queued on the database queue).
 *
 * Every method is fire-and-forget: dispatching only inserts a jobs row and
 * even THAT is wrapped in try/catch, so no email can ever block the business
 * action (link request, approval, payment recording, payout marking).
 * Actual SMTP failures are handled by ConsultantNotificationMail::failed()
 * → MailHealth banner.
 */
class ConsultantMailer
{
    /** New pending link request → notify the client company admin. */
    public static function linkRequested(ConsultantClientLink $link, User $consultant, Company $company): void
    {
        try {
            $admin = $company->users()->where('role', 'company_admin')->orderBy('id')->first();
            if (!$admin || !$admin->email) {
                return;
            }

            self::queue($admin->email, new ConsultantNotificationMail(
                'Tax consultant ne aapke account tak access maanga hai — TaxNest',
                $company->name,
                'Consultant link request aayi hai',
                [
                    "Tax consultant \"{$consultant->name}\" ({$consultant->email}) ne aapki company \"{$company->name}\" ke TaxNest account ko apne Consultant Console se dekhne ki ijazat maangi hai.",
                    'Aap is request ko apne panel se approve ya reject kar sakte hain. Approve karne par consultant sirf aapke invoices ki sehat (counts, plan, expiry) dekh sakega — aap kisi bhi waqt access wapas le sakte hain.',
                ],
                url('/company/consultants'),
                'Request Dekhein',
                'Digital Invoicing',
            ));
        } catch (\Throwable $e) {
            \Log::warning('Consultant link-request email not queued: ' . $e->getMessage(), ['link_id' => $link->id]);
        }
    }

    /** Client approved the pending request → notify the consultant. */
    public static function linkApproved(ConsultantClientLink $link): void
    {
        try {
            $consultant = User::find($link->consultant_user_id);
            $company = Company::find($link->company_id);
            if (!$consultant || !$consultant->email || !$company) {
                return;
            }

            self::queue($consultant->email, new ConsultantNotificationMail(
                "Client ne aapki access request approve kar di — TaxNest",
                $company->name,
                "\"{$company->name}\" ne aapko approve kar diya",
                [
                    "Mubarak ho! \"{$company->name}\" ne aapki consultant link request approve kar di hai.",
                    'Ab aap apne Consultant Console mein is client ki compliance health dekh sakte hain.',
                ],
                url('/consultant'),
                'Console Kholen',
                'Consultant Console',
            ));
        } catch (\Throwable $e) {
            \Log::warning('Consultant approval email not queued: ' . $e->getMessage(), ['link_id' => $link->id]);
        }
    }

    /**
     * Pending request rejected, or active link revoked (by client or SaaS
     * admin) → notify the consultant. Not called when the consultant
     * cancelled/revoked the link themself.
     */
    public static function linkRejectedOrRevoked(ConsultantClientLink $link, bool $wasPending): void
    {
        try {
            $consultant = User::find($link->consultant_user_id);
            $company = Company::find($link->company_id);
            if (!$consultant || !$consultant->email || !$company) {
                return;
            }

            $subject = $wasPending
                ? 'Aapki consultant link request reject ho gayi — TaxNest'
                : 'Client access revoke ho gaya — TaxNest';
            $headline = $wasPending
                ? "\"{$company->name}\" ne request reject kar di"
                : "\"{$company->name}\" tak access revoke ho gaya";
            $body = $wasPending
                ? "\"{$company->name}\" ne aapki consultant link request is waqt approve nahi ki. Aap chahein to client se raabta kar ke dobara request ya invite code le sakte hain."
                : "Aapka \"{$company->name}\" ke TaxNest account tak consultant access khatam kar diya gaya hai. Agar yeh ghalti se hua hai to client se raabta karein — wo aapko dobara invite/approve kar sakte hain.";

            self::queue($consultant->email, new ConsultantNotificationMail(
                $subject,
                $company->name,
                $headline,
                [$body],
                url('/consultant'),
                'Console Kholen',
                'Consultant Console',
            ));
        } catch (\Throwable $e) {
            \Log::warning('Consultant revoke email not queued: ' . $e->getMessage(), ['link_id' => $link->id]);
        }
    }

    /** New commission ledger entry → notify the consultant. */
    public static function commissionRecorded(ConsultantCommission $commission): void
    {
        try {
            $consultant = User::find($commission->consultant_user_id);
            if (!$consultant || !$consultant->email) {
                return;
            }

            $amount = number_format((float) $commission->amount, 2);

            self::queue($consultant->email, new ConsultantNotificationMail(
                "Nayi commission Rs {$amount} aapke ledger mein — TaxNest",
                $commission->company_name ?: 'TaxNest',
                "Rs {$amount} ki nayi commission ban gayi",
                [
                    "Aapke referred client \"{$commission->company_name}\" ki payment record hui hai ({$commission->description}).",
                    "Aapki commission: Rs {$amount} ({$commission->rate_percent}% of Rs " . number_format((float) $commission->base_amount, 2) . '). Status abhi pending hai — payout hone par aapko alag email milega.',
                ],
                url('/consultant/earnings'),
                'Earnings Dekhein',
                'Consultant Console',
            ));
        } catch (\Throwable $e) {
            \Log::warning('Consultant commission email not queued: ' . $e->getMessage(), ['commission_id' => $commission->id]);
        }
    }

    /** Admin marked a commission paid → notify the consultant. */
    public static function commissionPaid(ConsultantCommission $commission): void
    {
        try {
            $consultant = User::find($commission->consultant_user_id);
            if (!$consultant || !$consultant->email) {
                return;
            }

            $amount = number_format((float) $commission->amount, 2);
            $paragraphs = [
                "Aapki Rs {$amount} ki commission (\"{$commission->company_name}\" — {$commission->description}) paid mark kar di gayi hai.",
            ];
            if ($commission->payout_reference) {
                $paragraphs[] = "Payout reference: {$commission->payout_reference}";
            }

            self::queue($consultant->email, new ConsultantNotificationMail(
                "Commission Rs {$amount} pay kar di gayi — TaxNest",
                $commission->company_name ?: 'TaxNest',
                "Rs {$amount} ka payout ho gaya",
                $paragraphs,
                url('/consultant/earnings'),
                'Earnings Dekhein',
                'Consultant Console',
            ));
        } catch (\Throwable $e) {
            \Log::warning('Consultant payout email not queued: ' . $e->getMessage(), ['commission_id' => $commission->id]);
        }
    }

    private static function queue(string $to, ConsultantNotificationMail $mail): void
    {
        Mail::to($to)->queue($mail);
    }
}

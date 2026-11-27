<?php

namespace App\Services;

use App\Models\Company;
use App\Models\User;
use App\Support\SchoolErpPanel;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SchoolErpFeeLedger
{
    public function __construct(private SchoolErpWorkspace $workspace) {}

    public function receivedPayments(int $companyId): Builder
    {
        return DB::table('school_erp_fee_payments as payment')
            ->where('payment.company_id', $companyId)
            ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('school_erp_fee_reversals as reversal')
                ->whereColumn('reversal.company_id', 'payment.company_id')
                ->whereColumn('reversal.payment_id', 'payment.id'));
    }

    public function schedule(User $user, array $data): void
    {
        $this->write($user, function (int $companyId) use ($data) {
            $term = DB::table('school_erp_terms')->where('company_id', $companyId)->where('id', $data['term_id'])->first();
            if (!$term || $data['due_on'] < $term->starts_on || $data['due_on'] > $term->ends_on) {
                throw ValidationException::withMessages(['due_on' => __('school_erp_fees.term_date')]);
            }

            $identity = ['company_id' => $companyId, 'term_id' => $term->id, 'label' => $data['label']];
            $amount = $this->paisa($data['amount']);
            $existing = DB::table('school_erp_fee_schedules')->where($identity)->first();
            if ($existing) {
                if ((int) $existing->amount_paisa !== $amount || $existing->due_on !== $data['due_on']) {
                    throw ValidationException::withMessages(['label' => __('school_erp_fees.schedule_conflict')]);
                }

                return;
            }

            DB::table('school_erp_fee_schedules')->insert($identity + [
                'amount_paisa' => $amount, 'due_on' => $data['due_on'],
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    public function issue(User $user, int $studentId, int $scheduleId): int
    {
        return $this->write($user, function (int $companyId, int $actorId) use ($studentId, $scheduleId) {
            $schedule = DB::table('school_erp_fee_schedules')->where('company_id', $companyId)->where('id', $scheduleId)->first();
            $enrollment = $schedule ? DB::table('school_erp_enrollments')->where('company_id', $companyId)
                ->where('term_id', $schedule->term_id)->where('student_id', $studentId)->first() : null;
            if (!$enrollment) {
                throw ValidationException::withMessages(['student_id' => __('school_erp_fees.enrollment_required')]);
            }

            $identity = ['company_id' => $companyId, 'student_id' => $studentId, 'schedule_id' => $scheduleId];
            $existing = DB::table('school_erp_fee_invoices')->where($identity)->first();
            if ($existing) {
                return (int) $existing->id;
            }

            return DB::table('school_erp_fee_invoices')->insertGetId($identity + [
                'campus_id' => $enrollment->campus_id, 'amount_paisa' => $schedule->amount_paisa, 'issued_by' => $actorId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    public function receive(User $user, int $invoiceId, array $data): int
    {
        return $this->write($user, function (int $companyId, int $actorId) use ($invoiceId, $data) {
            $invoice = $this->invoice($companyId, $invoiceId);
            $amount = $this->paisa($data['amount']);
            $reference = $data['reference'] ?? null;
            $key = strtolower($data['request_key']);
            $prior = DB::table('school_erp_fee_payments')->where('company_id', $companyId)->where('request_key', $key)->first();
            if ($prior) {
                if ((int) $prior->invoice_id !== $invoiceId || (int) $prior->amount_paisa !== $amount
                    || $prior->method !== $data['method'] || $prior->reference !== $reference) {
                    throw ValidationException::withMessages(['request_key' => __('school_erp_fees.receipt_conflict')]);
                }

                return (int) $prior->id;
            }

            $paid = (int) $this->receivedPayments($companyId)->where('payment.invoice_id', $invoiceId)->sum('amount_paisa');
            if ($paid + $amount > (int) $invoice->amount_paisa) {
                throw ValidationException::withMessages(['amount' => __('school_erp_fees.amount_above_balance')]);
            }

            return DB::table('school_erp_fee_payments')->insertGetId([
                'company_id' => $companyId, 'invoice_id' => $invoiceId, 'request_key' => $key,
                'amount_paisa' => $amount, 'method' => $data['method'], 'reference' => $reference, 'received_by' => $actorId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    public function reverse(User $user, int $invoiceId, int $receiptId, string $reason): void
    {
        $this->write($user, function (int $companyId, int $actorId) use ($invoiceId, $receiptId, $reason) {
            $this->invoice($companyId, $invoiceId);
            abort_unless(DB::table('school_erp_fee_payments')->where('company_id', $companyId)
                ->where('invoice_id', $invoiceId)->where('id', $receiptId)->exists(), 404);
            $identity = ['company_id' => $companyId, 'payment_id' => $receiptId];
            $previous = DB::table('school_erp_fee_reversals')->where($identity)->first();
            if ($previous) {
                if ($previous->reason !== $reason) {
                    throw ValidationException::withMessages(['reason' => __('school_erp_fees.reversal_exists')]);
                }

                return;
            }

            DB::table('school_erp_fee_reversals')->insert($identity + [
                'reversed_by' => $actorId, 'reason' => $reason, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    private function invoice(int $companyId, int $invoiceId): object
    {
        $invoice = DB::table('school_erp_fee_invoices')->where('company_id', $companyId)
            ->where('id', $invoiceId)->lockForUpdate()->first();
        abort_unless($invoice, 404);

        return $invoice;
    }

    private function write(User $actor, Closure $work): mixed
    {
        return DB::transaction(function () use ($actor, $work) {
            $company = Company::whereKey($actor->company_id)->lockForUpdate()->first();
            $user = User::where('company_id', $actor->company_id)->whereKey($actor->id)->lockForUpdate()->first();
            abort_unless(SchoolErpPanel::ownsCompany($company) && $user?->is_active && $user->isCompanyAdmin()
                && $this->workspace->mayWrite($company), 403);

            return $work((int) $company->id, (int) $user->id);
        }, 3);
    }

    private function paisa(string $amount): int
    {
        if (!preg_match('/^\d{1,9}(?:\.\d{1,2})?$/D', $amount)) {
            throw ValidationException::withMessages(['amount' => __('school_erp_fees.invalid_amount')]);
        }
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        $paisa = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
        if ($paisa < 1) {
            throw ValidationException::withMessages(['amount' => __('school_erp_fees.invalid_amount')]);
        }

        return $paisa;
    }
}

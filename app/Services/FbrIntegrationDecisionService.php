<?php

namespace App\Services;

use App\Models\Company;
use App\Models\FbrPosTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Optional FBR integration (Sep 2026) — the ONE place that records a shop's
 * FBR choice, flips the reporting switch honestly, and cleans up bills that
 * failed ONLY because the integration was never set up.
 *
 * Rules (mirror the task brief):
 *  - Reporting may turn ON only when Company::fbrPosIntegrationConfigured().
 *  - "Without FBR" converts CONFIG-ONLY failures to plain bills: fbr_status
 *    config_error, or failed with no fiscal number AND no real FBR submission
 *    attempt logged. A log row counts as a real attempt unless it is one of
 *    the config-validation rows FbrService writes before any HTTP call
 *    (status failed, no response code / payload, "not configured" message).
 *    Anything that ever reached FBR — submitted, offline, pending, any row
 *    with a response — is never touched.
 *  - Every mutation is audited and idempotent (a second run converts 0).
 */
class FbrIntegrationDecisionService
{
    public const CHOICE_CONNECT = Company::FBR_DECISION_CONNECT;
    public const CHOICE_WITHOUT = Company::FBR_DECISION_WITHOUT;

    /** Session flag set by the card's X ("Baad mein") — snoozes it for the session only. */
    public const SESSION_LATER = 'fbr_integration_card_later';

    /**
     * Decision-card audience (What's New rules + owner-only): company_admin,
     * company not pending, not read-only impersonation, not snoozed this
     * session, on the dashboard / sale screen, and the shop still undecided.
     * Confined roles (cashier/waiter/kitchen/rider) never qualify because
     * their role is never company_admin. Never throws — a layout must not die.
     */
    public function shouldShowDecisionCard(?\App\Models\User $user, ?Company $company, bool $onEligibleRoute): bool
    {
        try {
            if (!$user || !$company || !$onEligibleRoute) {
                return false;
            }
            if ($user->role !== 'company_admin') {
                return false;
            }
            if (($company->status ?? null) === 'pending') {
                return false;
            }
            $imp = session('impersonation');
            if (is_array($imp) && !empty($imp['readonly'])) {
                return false;
            }
            if (session(self::SESSION_LATER)) {
                return false;
            }
            return $company->fbrIntegrationUndecided();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Record "Haan — FBR se jorein". Reporting turns ON right away when the
     * shop is already configured; otherwise it stays/goes OFF until the
     * settings save completes the setup (maybeAutoEnableReporting).
     */
    public function chooseConnect(Company $company, ?int $userId): array
    {
        return DB::transaction(function () use ($company, $userId) {
            $before = (bool) $company->fbr_reporting_enabled;
            $configured = $company->fbrPosIntegrationConfigured();

            $this->stampDecision($company, self::CHOICE_CONNECT, $userId);
            $company->fbr_reporting_enabled = $configured;
            $company->save();

            AuditLogService::log('fbr_integration_decision', 'Company', $company->id,
                ['fbr_reporting_enabled' => $before],
                ['choice' => self::CHOICE_CONNECT, 'configured' => $configured, 'fbr_reporting_enabled' => $configured],
                $company->id, $userId);

            return ['enabled' => $configured, 'configured' => $configured];
        });
    }

    /**
     * Record "Abhi nahi — bills FBR ke baghair". Reporting goes OFF and the
     * config-only failures become plain bills (simple QR) in the same txn.
     */
    public function chooseWithoutFbr(Company $company, ?int $userId): array
    {
        return DB::transaction(function () use ($company, $userId) {
            $before = (bool) $company->fbr_reporting_enabled;

            $this->stampDecision($company, self::CHOICE_WITHOUT, $userId);
            $company->fbr_reporting_enabled = false;
            $company->save();

            $converted = $this->convertConfigOnlyFailures($company, $userId, 'decision_without_fbr');

            AuditLogService::log('fbr_integration_decision', 'Company', $company->id,
                ['fbr_reporting_enabled' => $before],
                ['choice' => self::CHOICE_WITHOUT, 'fbr_reporting_enabled' => false, 'converted_bills' => $converted],
                $company->id, $userId);

            return ['enabled' => false, 'converted' => $converted];
        });
    }

    /** Settings "show the choice card again" — forgets the recorded choice only. */
    public function resetDecision(Company $company, ?int $userId): void
    {
        if (!Schema::hasColumn('companies', 'fbr_integration_decision')) {
            return;
        }
        $old = $company->fbrIntegrationDecision();
        $company->fbr_integration_decision = null;
        $company->fbr_integration_decided_at = null;
        $company->fbr_integration_decided_by = null;
        $company->save();
        AuditLogService::log('fbr_integration_decision_reset', 'Company', $company->id,
            ['choice' => $old], ['choice' => null], $company->id, $userId);
    }

    /**
     * Explicit ON/OFF from the toggle or the settings section.
     * Returns ['ok' => bool, 'enabled' => bool, 'missing' => [...]].
     * Refuses ON while unconfigured — the caller shows what is missing.
     */
    public function setReporting(Company $company, bool $on, ?int $userId): array
    {
        if ($on && !$company->fbrPosIntegrationConfigured()) {
            return ['ok' => false, 'enabled' => (bool) $company->fbr_reporting_enabled, 'missing' => $company->fbrIntegrationMissing()];
        }

        $before = (bool) $company->fbr_reporting_enabled;
        $company->fbr_reporting_enabled = $on;
        // Turning ON is itself the "connect" decision; OFF keeps whatever was chosen
        // (a configured shop pausing FBR is not the same as "without FBR").
        if ($on && $company->fbrIntegrationDecision() === null) {
            $this->stampDecision($company, self::CHOICE_CONNECT, $userId);
        }
        $company->save();

        if ($before !== $on) {
            AuditLogService::log('fbr_reporting_changed', 'Company', $company->id,
                ['fbr_reporting_enabled' => $before], ['fbr_reporting_enabled' => $on],
                $company->id, $userId);
        }

        return ['ok' => true, 'enabled' => $on, 'missing' => []];
    }

    /**
     * Called after a settings save: a shop that chose "connect" and has just
     * completed its setup gets reporting switched ON without a second click.
     * Returns true when it flipped ON in this call.
     */
    public function maybeAutoEnableReporting(Company $company, ?int $userId): bool
    {
        $company->refresh();
        if ($company->fbr_reporting_enabled) {
            return false;
        }
        if ($company->fbrIntegrationDecision() !== self::CHOICE_CONNECT) {
            return false;
        }
        if (!$company->fbrPosIntegrationConfigured()) {
            return false;
        }
        $company->fbr_reporting_enabled = true;
        $company->save();
        AuditLogService::log('fbr_reporting_changed', 'Company', $company->id,
            ['fbr_reporting_enabled' => false],
            ['fbr_reporting_enabled' => true, 'reason' => 'auto_on_after_setup'],
            $company->id, $userId);
        return true;
    }

    /**
     * Query of this company's CONFIG-ONLY failed bills (strict, see class doc).
     */
    public function configOnlyFailureQuery(int $companyId)
    {
        $q = FbrPosTransaction::query()
            ->where('company_id', $companyId)
            ->whereNull('fbr_invoice_number')
            ->whereIn('fbr_status', ['config_error', 'failed'])
            ->where(function ($m) {
                $m->whereNull('invoice_mode')->orWhere('invoice_mode', '!=', 'local');
            });

        if (Schema::hasTable('fbr_pos_logs')) {
            // A "real" attempt = any log row that is NOT a pre-HTTP config-validation row.
            $q->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('fbr_pos_logs')
                    ->whereColumn('fbr_pos_logs.transaction_id', 'fbr_pos_transactions.id')
                    ->where(function ($w) {
                        $w->whereNotNull('response_code')
                          ->orWhereNotNull('response_payload')
                          ->orWhere('status', '!=', 'failed')
                          ->orWhereNull('status')
                          ->orWhereNull('error_message')
                          ->orWhere('error_message', 'not like', '%not configured%');
                    });
            });
        }

        return $q;
    }

    /**
     * Convert config-only failures to plain bills (fbr_status NULL) inside one
     * transaction, audited. Idempotent: returns the number converted (0 on rerun).
     */
    public function convertConfigOnlyFailures(Company $company, ?int $userId, string $reason = 'manual'): int
    {
        return DB::transaction(function () use ($company, $userId, $reason) {
            $rows = $this->configOnlyFailureQuery($company->id)
                ->lockForUpdate()
                ->get(['id', 'invoice_number', 'fbr_status']);

            if ($rows->isEmpty()) {
                return 0;
            }

            $patch = ['fbr_status' => null, 'fbr_submission_hash' => null, 'updated_at' => now()];
            foreach (['fbr_response_code', 'fbr_error_message', 'fbr_response'] as $col) {
                if (Schema::hasColumn('fbr_pos_transactions', $col)) {
                    $patch[$col] = null;
                }
            }
            if (Schema::hasColumn('fbr_pos_transactions', 'fbr_auto_retry_count')) {
                $patch['fbr_auto_retry_count'] = 0;
            }

            $ids = $rows->pluck('id')->all();
            FbrPosTransaction::whereIn('id', $ids)->update($patch);

            AuditLogService::log('fbr_config_only_failures_converted', 'Company', $company->id,
                ['bills' => $rows->map(fn ($r) => ['id' => (int) $r->id, 'invoice_number' => $r->invoice_number, 'fbr_status' => $r->fbr_status])->values()->all()],
                ['count' => count($ids), 'fbr_status' => null, 'reason' => $reason],
                $company->id, $userId);

            return count($ids);
        });
    }

    private function stampDecision(Company $company, string $choice, ?int $userId): void
    {
        if (!Schema::hasColumn('companies', 'fbr_integration_decision')) {
            return;
        }
        $company->fbr_integration_decision = $choice;
        if (Schema::hasColumn('companies', 'fbr_integration_decided_at')) {
            $company->fbr_integration_decided_at = now();
        }
        if (Schema::hasColumn('companies', 'fbr_integration_decided_by')) {
            $company->fbr_integration_decided_by = $userId;
        }
    }
}

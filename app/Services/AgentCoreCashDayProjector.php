<?php

namespace App\Services;

use App\Http\Controllers\PosController;
use App\Models\AgentCoreEvent;
use App\Models\Company;
use App\Models\PosDayCloseReport;
use App\Models\PosDayOpening;
use App\Models\PosUserSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Projects the small Local Core cash-day boundary without going through an
 * HTTP controller action. Day close deliberately delegates to
 * PosController::performDayClose(), the shared manual/automatic close engine.
 */
final class AgentCoreCashDayProjector
{
    public function __construct(private PosController $dayCloser)
    {
    }

    public function project(Company $company, AgentCoreEvent $event, array $wire): AgentCoreProjectionOutcome
    {
        $payload = (array) ($wire['payload'] ?? []);
        $data = $payload['data'] ?? null;
        $scope = $wire['scope'] ?? null;

        if (!is_array($data) || !is_array($scope)) {
            return $this->rejected($event, 'Canonical Local Core payload.data and scope are required.');
        }
        if ((string) ($scope['company_id'] ?? '') !== (string) $company->id
            || (int) $event->company_id !== (int) $company->id
            || (string) ($scope['device_id'] ?? '') !== (string) $event->device_uid) {
            return $this->rejected($event, 'Company/device scope mismatch.');
        }

        $command = (string) ($payload['command_type'] ?? '');
        $allowed = [
            'cash.opened' => 'cash.open',
            'expense.recorded' => 'cash.expense',
            'expense.created' => 'cash.expense',
            'day.closed' => 'cash.close',
            'day-close.created' => 'cash.close',
            'staff.session.started' => 'staff.start',
            'staff.session.ended' => 'staff.end',
            'staff.shift.recorded' => $command,
        ];
        if (!isset($allowed[$event->event_type])
            || !in_array($command, (array) $allowed[$event->event_type], true)) {
            return $this->rejected($event, 'Unsupported cash-day event/command combination.');
        }

        $scopeResult = $this->scope($company, $event, $wire, $data);
        if ($scopeResult instanceof AgentCoreProjectionOutcome) {
            return $scopeResult;
        }

        try {
            return match ($command) {
                'cash.open' => $this->open($company, $event, $payload, $data, $scopeResult),
                'cash.expense' => $this->expense($company, $event, $payload, $data, $scopeResult),
                'cash.close' => $this->close($company, $event, $data, $scopeResult),
                'staff.start' => $this->staffStart($company, $event, $payload, $data, $scopeResult),
                'staff.end' => $this->staffEnd($company, $event, $payload, $data, $scopeResult),
            };
        } catch (\Throwable $e) {
            report($e);
            return $this->retryable($event, 'Cash-day projection failed transiently.');
        }
    }

    /**
     * @return array{branch_id:?int,branch_key:int,terminal_id:int,user_id:int}|AgentCoreProjectionOutcome
     */
    private function scope(Company $company, AgentCoreEvent $event, array $wire, array $data): array|AgentCoreProjectionOutcome
    {
        $scope = $wire['scope'];
        $branchId = (int) ($scope['branch_id'] ?? 0);
        $userId = (int) ($scope['user_id'] ?? 0);
        if ($userId < 1 || !Schema::hasTable('users')) {
            return $this->retryable($event, 'Staff scope schema is unavailable.', 'users');
        }
        if (!DB::table('users')->where('company_id', $company->id)->where('id', $userId)->exists()) {
            return $this->rejected($event, 'Staff scope does not belong to the company.');
        }
        if ($branchId > 0) {
            if (!Schema::hasTable('branches')) {
                return $this->retryable($event, 'Branch scope schema is unavailable.', 'branches');
            }
            if (!DB::table('branches')->where('company_id', $company->id)->where('id', $branchId)->exists()) {
                return $this->rejected($event, 'Branch scope does not belong to the company.');
            }
        }

        $terminalId = (int) ($data['terminal_id'] ?? $data['counter_id'] ?? 0);
        if ($terminalId > 0) {
            if (!Schema::hasTable('pos_terminals')) {
                return $this->retryable($event, 'Counter scope schema is unavailable.', 'pos_terminals');
            }
            if (!DB::table('pos_terminals')->where('company_id', $company->id)->where('id', $terminalId)->exists()) {
                return $this->rejected($event, 'Counter scope does not belong to the company.');
            }
            if (Schema::hasTable('pos_agent_devices') && Schema::hasColumn('pos_agent_devices', 'terminal_id')) {
                $bound = DB::table('pos_agent_devices')
                    ->where('company_id', $company->id)
                    ->where('device_uid', $event->device_uid)
                    ->value('terminal_id');
                if ($bound !== null && (int) $bound > 0 && (int) $bound !== $terminalId) {
                    return $this->rejected($event, 'Counter scope does not match the device.');
                }
            }
        }

        return [
            'branch_id' => $branchId ?: null,
            'branch_key' => PosDayCloseReport::branchKey($branchId ?: null),
            'terminal_id' => $terminalId,
            'user_id' => $userId,
        ];
    }

    private function open(
        Company $company,
        AgentCoreEvent $event,
        array $payload,
        array $data,
        array $scope
    ): AgentCoreProjectionOutcome {
        if (!Schema::hasTable('pos_day_openings')) {
            return $this->retryable($event, 'Opening-cash schema is unavailable.', 'pos_day_openings');
        }
        $date = $this->date($data);
        if ($date === null || $date !== PosBusinessDay::current((int) $company->id)) {
            return $this->rejected($event, 'Opening cash may only be recorded for the current business day.');
        }
        $amount = $this->money($data, 'opening_cents', 'opening_cash');
        if ($amount === null || $amount < 0 || $amount > 99999999) {
            return $this->rejected($event, 'Opening cash amount is invalid.');
        }
        if ($this->closed((int) $company->id, $scope['branch_id'], $date)) {
            return $this->rejected($event, 'The business day is closed and immutable.');
        }
        if ($scope['terminal_id'] > 0 && Schema::hasTable('pos_counter_closes')
            && DB::table('pos_counter_closes')->where('company_id', $company->id)
                ->where('terminal_id', $scope['terminal_id'])->where('business_date', $date)->exists()) {
            return $this->rejected($event, 'The counter drawer is already closed.');
        }

        $key = ['company_id' => $company->id, 'business_date' => $date];
        if (Schema::hasColumn('pos_day_openings', 'branch_id')) {
            $key['branch_id'] = $scope['branch_key'];
        }
        if (Schema::hasColumn('pos_day_openings', 'terminal_id')) {
            $key['terminal_id'] = $scope['terminal_id'];
        }
        $values = [
            'opening_cash' => $amount,
            'entered_by' => $scope['user_id'],
            'notes' => $this->note($data, $payload),
        ];
        // PosDayOpening casts business_date to a date object. A direct
        // updateOrCreate key is reliable on MySQL DATE, but SQLite stores that
        // cast as midnight text; whereDate keeps retries portable on both.
        $openingQuery = PosDayOpening::where('company_id', $company->id)
            ->whereDate('business_date', $date);
        if (array_key_exists('branch_id', $key)) {
            $openingQuery->where('branch_id', $key['branch_id']);
        }
        if (array_key_exists('terminal_id', $key)) {
            $openingQuery->where('terminal_id', $key['terminal_id']);
        }
        $opening = $openingQuery->first();
        if ($opening) {
            $opening->fill($values)->save();
        } else {
            $opening = PosDayOpening::create($key + $values);
        }

        return $this->projected($event, [
            'opening_id' => $opening->id,
            'business_date' => $date,
            'opening_cash' => $amount,
        ]);
    }

    private function expense(
        Company $company,
        AgentCoreEvent $event,
        array $payload,
        array $data,
        array $scope
    ): AgentCoreProjectionOutcome {
        $table = Schema::hasTable('pos_cash_expenses') ? 'pos_cash_expenses'
            : (Schema::hasTable('pos_expenses') ? 'pos_expenses' : null);
        if ($table === null) {
            return $this->retryable($event, 'Expense schema is unavailable.', 'pos_cash_expenses');
        }
        $date = $this->date($data);
        $amount = $this->money($data, 'amount_cents', 'amount');
        if ($date === null || $amount === null || $amount <= 0) {
            return $this->rejected($event, 'Expense business date or amount is invalid.');
        }
        if ($this->closed((int) $company->id, $scope['branch_id'], $date)) {
            return $this->rejected($event, 'The business day is closed and immutable.');
        }

        $idColumn = Schema::hasColumn($table, 'idempotency_key') ? 'idempotency_key'
            : (Schema::hasColumn($table, 'aggregate_id') ? 'aggregate_id' : null);
        if ($idColumn === null) {
            return $this->retryable($event, 'Expense idempotency schema is unavailable.', $table . '.idempotency_key');
        }
        $identity = $idColumn === 'idempotency_key'
            ? (string) $event->idempotency_key
            : (string) ($payload['aggregate_id'] ?? '');
        if ($identity === '') {
            return $this->rejected($event, 'Expense identity is required.');
        }

        $existing = DB::table($table)->where('company_id', $company->id)->where($idColumn, $identity)->first();
        if ($existing) {
            return $this->projected($event, ['expense_id' => $existing->id, 'replayed' => true]);
        }
        $row = [
            'company_id' => $company->id,
            $idColumn => $identity,
            'business_date' => $date,
            'amount' => $amount,
        ];
        foreach ([
            'branch_id' => $scope['branch_key'],
            'terminal_id' => $scope['terminal_id'],
            'recorded_by' => $scope['user_id'],
            'notes' => $this->note($data, $payload),
            'occurred_at' => $event->occurred_at ?? now(),
            'created_at' => now(),
            'updated_at' => now(),
        ] as $column => $value) {
            if (Schema::hasColumn($table, $column)) {
                $row[$column] = $value;
            }
        }
        $id = DB::table($table)->insertGetId($row);

        return $this->projected($event, [
            'expense_id' => $id,
            'business_date' => $date,
            'amount' => $amount,
            'replayed' => false,
        ]);
    }

    private function close(Company $company, AgentCoreEvent $event, array $data, array $scope): AgentCoreProjectionOutcome
    {
        if (!Schema::hasTable('pos_day_close_reports')) {
            return $this->retryable($event, 'Day-close schema is unavailable.', 'pos_day_close_reports');
        }
        $date = $this->date($data);
        if ($date === null || $date > PosBusinessDay::current((int) $company->id)) {
            return $this->rejected($event, 'Business date is invalid.');
        }
        $existing = PosDayCloseReport::where('company_id', $company->id)
            ->forBranch($scope['branch_id'])->where('report_date', $date)->first();
        if ($existing) {
            return $this->projected($event, [
                'day_close_id' => $existing->id,
                'report_number' => $existing->report_number,
                'business_date' => $date,
                'replayed' => true,
            ]);
        }

        $counted = $this->nullableMoney($data, 'counted_cents', 'counted_cash');
        if ($counted === false || (is_float($counted) && $counted < 0)) {
            return $this->rejected($event, 'Counted cash amount is invalid.');
        }
        $recon = ['counted_cash' => $counted];
        $result = $this->dayCloser->performDayClose(
            (int) $company->id,
            $date,
            $scope['user_id'],
            $data['note'] ?? $data['notes'] ?? null,
            $recon,
            false,
            null,
            $scope['branch_id'],
        );
        if (!in_array($result['status'] ?? null, ['created', 'exists'], true) || empty($result['report'])) {
            return $this->rejected($event, 'The business day could not be closed.');
        }
        $report = $result['report'];

        return $this->projected($event, [
            'day_close_id' => $report->id,
            'report_number' => $result['report_number'] ?? $report->report_number,
            'business_date' => $date,
            'replayed' => ($result['status'] ?? null) === 'exists',
        ]);
    }

    private function staffStart(
        Company $company,
        AgentCoreEvent $event,
        array $payload,
        array $data,
        array $scope
    ): AgentCoreProjectionOutcome {
        if (!Schema::hasTable('pos_user_sessions')) {
            return $this->retryable($event, 'Staff-session schema is unavailable.', 'pos_user_sessions');
        }
        $userId = (int) ($data['user_id'] ?? $scope['user_id']);
        if (!$this->ownedUser((int) $company->id, $userId)) {
            return $this->rejected($event, 'Session staff does not belong to the company.');
        }
        $token = $this->sessionToken($payload);
        $existing = PosUserSession::where('company_id', $company->id)
            ->where('user_id', $userId)->where('ip', $token)->first();
        if ($existing) {
            return $this->projected($event, ['session_id' => $existing->id, 'replayed' => true]);
        }
        $at = $event->occurred_at ?? now();
        $session = PosUserSession::create([
            'company_id' => $company->id,
            'user_id' => $userId,
            'login_at' => $at,
            'last_activity_at' => $at,
            'ip' => $token,
        ]);

        return $this->projected($event, ['session_id' => $session->id, 'replayed' => false]);
    }

    private function staffEnd(
        Company $company,
        AgentCoreEvent $event,
        array $payload,
        array $data,
        array $scope
    ): AgentCoreProjectionOutcome {
        if (!Schema::hasTable('pos_user_sessions')) {
            return $this->retryable($event, 'Staff-session schema is unavailable.', 'pos_user_sessions');
        }
        $userId = (int) ($data['user_id'] ?? $scope['user_id']);
        if (!$this->ownedUser((int) $company->id, $userId)) {
            return $this->rejected($event, 'Session staff does not belong to the company.');
        }
        $session = PosUserSession::where('company_id', $company->id)
            ->where('user_id', $userId)
            ->where('ip', $this->sessionToken($payload))
            ->first();
        if (!$session) {
            return $this->rejected($event, 'Active staff session does not exist.');
        }
        $replayed = $session->logout_at !== null;
        if ($session->logout_at === null) {
            $at = $event->occurred_at ?? now();
            $session->forceFill(['logout_at' => $at, 'last_activity_at' => $at])->save();
        }

        return $this->projected($event, ['session_id' => $session->id, 'replayed' => $replayed]);
    }

    private function closed(int $companyId, ?int $branchId, string $date): bool
    {
        return Schema::hasTable('pos_day_close_reports')
            && PosDayCloseReport::where('company_id', $companyId)
                ->forBranch($branchId)->where('report_date', $date)->exists();
    }

    private function date(array $data): ?string
    {
        $date = $data['business_date'] ?? null;
        if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }
        try {
            $parsed = \Carbon\Carbon::createFromFormat('!Y-m-d', $date);
            return $parsed !== false && $parsed->format('Y-m-d') === $date ? $date : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function money(array $data, string $centsKey, string $decimalKey): ?float
    {
        if (array_key_exists($centsKey, $data) && is_int($data[$centsKey])) {
            return round($data[$centsKey] / 100, 2);
        }
        return array_key_exists($decimalKey, $data) && is_numeric($data[$decimalKey])
            ? round((float) $data[$decimalKey], 2) : null;
    }

    /** @return float|null|false false denotes malformed, null denotes not counted */
    private function nullableMoney(array $data, string $centsKey, string $decimalKey): float|null|false
    {
        if (array_key_exists($centsKey, $data)) {
            return $data[$centsKey] === null ? null
                : (is_int($data[$centsKey]) ? round($data[$centsKey] / 100, 2) : false);
        }
        if (array_key_exists($decimalKey, $data)) {
            return $data[$decimalKey] === null ? null
                : (is_numeric($data[$decimalKey]) ? round((float) $data[$decimalKey], 2) : false);
        }
        return null;
    }

    private function ownedUser(int $companyId, int $userId): bool
    {
        return $userId > 0 && Schema::hasTable('users')
            && DB::table('users')->where('company_id', $companyId)->where('id', $userId)->exists();
    }

    private function sessionToken(array $payload): string
    {
        return 'ac:' . substr(hash('sha256', (string) ($payload['aggregate_id'] ?? '')), 0, 32);
    }

    private function note(array $data, array $payload): ?string
    {
        $note = $data['note'] ?? $data['notes'] ?? null;
        return is_string($note) ? mb_substr($note, 0, 500) : null;
    }

    private function projected(AgentCoreEvent $event, array $data): AgentCoreProjectionOutcome
    {
        return new AgentCoreProjectionOutcome('projected', [
            'event_id' => $event->event_id,
            'status' => 'projected',
        ] + $data);
    }

    private function rejected(AgentCoreEvent $event, string $error): AgentCoreProjectionOutcome
    {
        return new AgentCoreProjectionOutcome(
            'rejected',
            ['event_id' => $event->event_id, 'status' => 'rejected'],
            $error,
        );
    }

    private function retryable(AgentCoreEvent $event, string $error, ?string $dependency = null): AgentCoreProjectionOutcome
    {
        return new AgentCoreProjectionOutcome(
            'retryable',
            ['event_id' => $event->event_id, 'status' => 'retryable']
                + ($dependency ? ['dependency' => $dependency] : []),
            $error,
            $dependency,
        );
    }
}
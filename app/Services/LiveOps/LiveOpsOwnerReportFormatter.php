<?php

namespace App\Services\LiveOps;

/**
 * Turn Live Ops diagnostic envelopes into a nontechnical owner report.
 * Does not invent root causes; UNKNOWN stays UNKNOWN.
 */
class LiveOpsOwnerReportFormatter
{
    /**
     * @param  array<string, mixed>  $envelope
     * @return array{title:string,overall:string,text:string,companies:list<array<string,mixed>>,platform:array<string,mixed>,unresolved:list<string>,actions_taken:list<string>}
     */
    public function format(array $envelope, array $context = []): array
    {
        $operation = strtoupper((string) ($envelope['operation'] ?? ''));
        $data = is_array($envelope['data'] ?? null) ? $envelope['data'] : [];

        $formatted = match ($operation) {
            'DAILY_OPS' => $this->formatDaily($envelope, $data, $context),
            'COMPANY_DIAGNOSTIC', 'COMPANY_HEALTH', 'PRINTER_HEALTH' => $this->formatCompany($envelope, $data, $context),
            'BILLING_BY_COMPANY', 'BILLING_SUMMARY' => $this->formatBilling($envelope, $data),
            'PROBLEMATIC_COMPANIES' => $this->formatProblematic($envelope, $data),
            default => $this->formatGeneric($envelope, $data),
        };

        $formatted['report_id'] = $envelope['report_id'] ?? null;
        $formatted['operation'] = $operation;
        $formatted['timestamp'] = $envelope['timestamp'] ?? null;

        return $formatted;
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function formatDaily(array $envelope, array $data, array $context): array
    {
        $overall = $data['overall']['status'] ?? 'UNKNOWN';
        $cards = $data['owner_company_cards'] ?? $this->cardsFromDaily($data);
        $platform = [
            'server' => $this->passFail($data['server_health'] ?? []),
            'queue' => $this->queueLine($data['queue_worker_health'] ?? []),
            'scheduler' => $this->heartbeatLine($data['scheduler_health'] ?? [], 'Scheduler'),
            'websocket' => $this->wsLine($data['websocket_agent_health']['websocket'] ?? []),
            'application_errors' => $this->appErrorLine($data['application_health'] ?? []),
            'pra_fleet' => $this->praFleetLine($data['pra_transaction_health'] ?? []),
        ];
        $unresolved = [];
        foreach ($cards as $card) {
            if (($card['severity'] ?? 'none') !== 'none') {
                $unresolved[] = ($card['name'] ?? 'Company').': '.($card['issues_line'] ?? 'attention');
            }
        }
        foreach ($data['unknown_observability_gaps'] ?? [] as $gap) {
            $unresolved[] = 'Not measured: '.$gap;
        }

        $lines = [];
        $lines[] = 'TaxNest daily report';
        $lines[] = 'Overall: '.$overall;
        $lines[] = '';
        foreach ($cards as $card) {
            $lines[] = $this->renderCard($card);
            $lines[] = '';
        }
        $lines[] = 'Server: '.$platform['server'];
        $lines[] = 'Queue: '.$platform['queue'];
        $lines[] = 'Scheduler: '.$platform['scheduler'];
        $lines[] = 'Realtime: '.$platform['websocket'];
        $lines[] = 'Application errors: '.$platform['application_errors'];
        $lines[] = 'PRA (all shops): '.$platform['pra_fleet'];
        if ($unresolved) {
            $lines[] = '';
            $lines[] = 'Unresolved:';
            foreach ($unresolved as $item) {
                $lines[] = '- '.$item;
            }
        }
        $actions = $context['actions_taken'] ?? [];
        if ($actions) {
            $lines[] = '';
            $lines[] = 'Actions already taken:';
            foreach ($actions as $action) {
                $lines[] = '- '.$action;
            }
        }

        return [
            'title' => 'Daily operations report',
            'overall' => (string) $overall,
            'text' => trim(implode("\n", $lines)),
            'companies' => $cards,
            'platform' => $platform,
            'unresolved' => $unresolved,
            'actions_taken' => $actions,
        ];
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function formatCompany(array $envelope, array $data, array $context): array
    {
        $card = $this->cardFromCompanyDiagnostic($data);
        $actions = $context['actions_taken'] ?? [];
        $card['actions_taken'] = $actions;
        if (!empty($context['status'])) {
            $card['status'] = $context['status'];
        }
        $text = $this->renderCard($card);
        if (!empty($card['root_cause']) && $card['root_cause'] !== 'no_obvious_fault_from_available_signals') {
            $text .= "\nRoot cause: ".$this->humanCause((string) $card['root_cause']);
        } elseif (($card['severity'] ?? 'none') === 'none') {
            $text .= "\nRoot cause: none determined from available signals.";
        } else {
            $text .= "\nRoot cause: not proven from available signals (not guessed).";
        }
        if ($actions) {
            $text .= "\nAction: ".implode('; ', $actions);
        }
        if (!empty($card['status'])) {
            $text .= "\nStatus: ".$card['status'];
        }

        return [
            'title' => ($card['name'] ?? 'Company').' report',
            'overall' => $card['severity'] === 'none' ? 'GREEN' : strtoupper((string) $card['severity']),
            'text' => $text,
            'companies' => [$card],
            'platform' => [],
            'unresolved' => $card['unresolved'] ?? [],
            'actions_taken' => $actions,
        ];
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function formatBilling(array $envelope, array $data): array
    {
        $lines = ['Billing'];
        foreach ($data['companies'] ?? [] as $row) {
            $lines[] = sprintf(
                '%s — Rs. %s — %d bills',
                $row['company_name'] ?? 'Company',
                $this->money($row['gross_total'] ?? 0),
                (int) ($row['invoice_count'] ?? 0)
            );
        }
        $tot = $data['totals'] ?? [];
        $lines[] = sprintf(
            'Total: Rs. %s across %d shops (%d bills)',
            $this->money($tot['gross_total'] ?? 0),
            (int) ($tot['company_count'] ?? 0),
            (int) ($tot['invoice_count'] ?? 0)
        );

        return [
            'title' => 'Billing',
            'overall' => 'GREEN',
            'text' => implode("\n", $lines),
            'companies' => [],
            'platform' => [],
            'unresolved' => [],
            'actions_taken' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function formatProblematic(array $envelope, array $data): array
    {
        $lines = ['Shops needing attention'];
        foreach (['offline_agents' => 'Agent offline', 'pra_failures' => 'PRA failures', 'print_failures' => 'Print failures', 'zero_billing' => 'No billing today'] as $key => $label) {
            foreach ($data[$key] ?? [] as $row) {
                $lines[] = ($row['name'] ?? 'Company').' — '.$label;
            }
        }

        return [
            'title' => 'Problematic companies',
            'overall' => count($lines) > 1 ? 'ATTENTION' : 'GREEN',
            'text' => implode("\n", $lines),
            'companies' => [],
            'platform' => [],
            'unresolved' => array_slice($lines, 1),
            'actions_taken' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function formatGeneric(array $envelope, array $data): array
    {
        $summary = (string) ($envelope['summary_text'] ?? 'Diagnostic completed.');

        return [
            'title' => (string) ($envelope['operation'] ?? 'Report'),
            'overall' => 'UNKNOWN',
            'text' => $summary,
            'companies' => [],
            'platform' => [],
            'unresolved' => [],
            'actions_taken' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function cardsFromDaily(array $data): array
    {
        $billing = [];
        foreach ($data['billing_by_company']['companies'] ?? [] as $row) {
            $billing[(int) $row['company_id']] = $row;
        }
        $agents = [];
        foreach ($data['websocket_agent_health']['agents']['agents'] ?? [] as $row) {
            $agents[(int) $row['company_id']] = $row;
        }
        $printFails = [];
        foreach ($data['problematic_companies']['print_failures'] ?? [] as $row) {
            $printFails[(int) $row['company_id']] = (int) ($row['fail_count'] ?? 0);
        }
        $praFails = [];
        foreach ($data['problematic_companies']['pra_failures'] ?? [] as $row) {
            $praFails[(int) $row['company_id']] = (int) ($row['fail_count'] ?? 0);
        }
        $offline = [];
        foreach ($data['problematic_companies']['offline_agents'] ?? [] as $row) {
            $offline[(int) $row['company_id']] = $row;
        }

        $ids = array_unique(array_merge(array_keys($billing), array_keys($agents), array_keys($printFails), array_keys($praFails), array_keys($offline)));
        $cards = [];
        foreach ($ids as $id) {
            $b = $billing[$id] ?? [];
            $a = $agents[$id] ?? [];
            $name = $b['company_name'] ?? $a['name'] ?? ($offline[$id]['name'] ?? 'Company');
            $printFailed = $printFails[$id] ?? 0;
            $praFailed = $praFails[$id] ?? (int) ($b['pra_failed'] ?? 0);
            $agentLine = !empty($a['online']) ? 'ONLINE' : (!empty($offline[$id]) || (isset($a['online']) && $a['online'] === false) ? 'OFFLINE' : 'UNKNOWN');
            $issues = [];
            if ($printFailed > 0) {
                $issues[] = $printFailed.' failed print job(s)';
            }
            if ($praFailed > 0) {
                $issues[] = $praFailed.' PRA error(s)';
            }
            if ($agentLine === 'OFFLINE') {
                $issues[] = 'Desktop Agent offline';
            }
            $severity = $issues ? 'attention' : 'none';
            $cards[] = [
                'company_id' => $id,
                'name' => $name,
                'billing_amount' => (float) ($b['gross_total'] ?? 0),
                'bill_count' => (int) ($b['invoice_count'] ?? 0),
                'successful_bills' => (int) ($b['pra_submitted'] ?? 0),
                'failed_abnormal' => $praFailed,
                'print_jobs' => null,
                'failed_print_jobs' => $printFailed,
                'stuck_print_jobs' => null,
                'agent' => $agentLine,
                'printer' => $printFailed > 0 ? 'FAIL' : 'PASS',
                'pra' => $praFailed > 0 ? 'FAIL' : 'PASS',
                'issues' => $issues,
                'issues_line' => $issues ? implode('; ', $issues) : 'None',
                'severity' => $severity,
                'root_cause' => null,
                'actions_taken' => [],
                'unresolved' => $issues,
            ];
        }
        usort($cards, fn ($x, $y) => ($y['billing_amount'] <=> $x['billing_amount']));

        return $cards;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function cardFromCompanyDiagnostic(array $data): array
    {
        $company = $data['company'] ?? [];
        $billing = $data['billing']['totals'] ?? ($data['billing'] ?? []);
        if (isset($data['billing']['companies'][0]) && empty($billing['invoice_count'])) {
            $billing = $data['billing']['companies'][0];
        }
        $printer = $data['printer'] ?? [];
        $pra = $data['pra']['counts'] ?? [];
        $printFailed = (int) ($printer['failed_job_count'] ?? 0);
        $stuck = (int) ($printer['stuck_job_count'] ?? 0);
        $printTotal = (int) ($printer['job_counts']['total'] ?? count($printer['recent_jobs'] ?? []));
        $praFailed = (int) ($pra['failed'] ?? 0);
        $agentOnline = !empty($data['agent']['online']) || !empty($company['agent_online']);
        $issues = [];
        if ($printFailed > 0) {
            $issues[] = $printFailed.' failed print job(s)';
        }
        if ($stuck > 0) {
            $issues[] = $stuck.' stuck print job(s)';
        }
        if ($praFailed > 0) {
            $issues[] = $praFailed.' PRA error(s)';
        }
        if (!$agentOnline && !empty($company['agent_enabled'])) {
            $issues[] = 'Desktop Agent offline';
        }
        $printerLine = 'PASS';
        if ($printFailed > 0 || $stuck > 0) {
            $printerLine = 'FAIL';
        } elseif (empty($printer) && empty($company)) {
            $printerLine = 'UNKNOWN';
        }
        $cause = $data['likely_root_cause'] ?? null;

        return [
            'company_id' => $company['id'] ?? $data['scope']['company_id'] ?? null,
            'name' => $company['name'] ?? 'Company',
            'billing_amount' => (float) ($billing['gross_total'] ?? 0),
            'bill_count' => (int) ($billing['invoice_count'] ?? 0),
            'successful_bills' => (int) ($billing['pra_submitted'] ?? $pra['submitted'] ?? 0),
            'failed_abnormal' => $praFailed,
            'print_jobs' => $printTotal,
            'failed_print_jobs' => $printFailed,
            'stuck_print_jobs' => $stuck,
            'agent' => $agentOnline ? 'ONLINE' : 'OFFLINE',
            'printer' => $printerLine,
            'pra' => $praFailed > 0 ? 'FAIL' : 'PASS',
            'issues' => $issues,
            'issues_line' => $issues ? implode('; ', $issues) : 'None',
            'severity' => $issues ? 'attention' : 'none',
            'root_cause' => $cause,
            'actions_taken' => [],
            'unresolved' => $issues,
        ];
    }

    /**
     * @param  array<string, mixed>  $card
     */
    public function renderCard(array $card): string
    {
        $name = (string) ($card['name'] ?? 'Company');
        $billing = 'Rs. '.$this->money($card['billing_amount'] ?? 0);
        $bills = (string) (int) ($card['bill_count'] ?? 0);
        $printer = (string) ($card['printer'] ?? 'UNKNOWN');
        if ($printer === 'FAIL' && !empty($card['failed_print_jobs'])) {
            $printer = ((int) $card['failed_print_jobs']).' failed jobs';
            if (!empty($card['stuck_print_jobs'])) {
                $printer .= ', '.(int) $card['stuck_print_jobs'].' stuck';
            }
        }
        $agent = (string) ($card['agent'] ?? 'UNKNOWN');
        $pra = (string) ($card['pra'] ?? 'UNKNOWN');
        $issues = (string) ($card['issues_line'] ?? 'None');

        return implode("\n", [
            $name,
            'Billing: '.$billing,
            'Bills: '.$bills,
            'Printing: '.$printer,
            'Agent: '.$agent,
            'PRA: '.$pra,
            'Issues: '.$issues,
        ]);
    }

    public function money(float|int|string $amount): string
    {
        return number_format((float) $amount, 0);
    }

    public function humanCause(string $cause): string
    {
        return match ($cause) {
            'agent_offline' => 'Desktop Agent is offline, so printing/PRA sync cannot finish.',
            'printer_or_print_pipeline' => 'Receipt print jobs are failing while the agent is online (printer or print pipeline).',
            'pra_submission_errors' => 'PRA invoice submission is returning errors.',
            'pra_backlog_pending_agent_sync' => 'PRA invoices are waiting for the Desktop Agent to sync.',
            'printer_not_configured' => 'Silent printing is on but no receipt printer is configured.',
            'no_obvious_fault_from_available_signals' => 'No proven fault from available signals.',
            default => $cause,
        };
    }

    private function passFail(array $server): string
    {
        $disk = $server['disk']['used_pct'] ?? null;
        if (is_numeric($disk) && (float) $disk >= 95) {
            return 'CRITICAL (disk '.$disk.'% full)';
        }

        return 'Checked';
    }

    private function queueLine(array $queue): string
    {
        $failed = (int) ($queue['failed_jobs_count'] ?? 0);
        if (!empty($queue['heartbeat_stale'])) {
            return 'Worker heartbeat stale'.($failed ? "; {$failed} failed job(s)" : '');
        }

        return $failed > 0 ? "{$failed} failed job(s) in queue" : 'PASS';
    }

    private function heartbeatLine(array $row, string $label): string
    {
        if (($row['heartbeat_availability'] ?? '') === 'unknown') {
            return $label.' not measured';
        }

        return !empty($row['heartbeat_stale']) ? $label.' stale' : 'PASS';
    }

    private function wsLine(array $ws): string
    {
        if (($ws['availability'] ?? '') !== 'measured') {
            return 'Not measured';
        }

        return (!empty($ws['ok'])) ? 'PASS' : 'Gateway not healthy';
    }

    private function appErrorLine(array $app): string
    {
        $log = $app['laravel_log'] ?? [];
        $crit = (int) ($log['critical_count'] ?? 0);
        $err = (int) ($log['error_count'] ?? 0);
        if ($crit > 0) {
            return $crit.' critical log line(s)';
        }
        if ($err > 0) {
            return $err.' error log line(s)';
        }

        return 'None measured';
    }

    private function praFleetLine(array $pra): string
    {
        $failed = (int) ($pra['counts']['failed'] ?? 0);
        $submitted = (int) ($pra['counts']['submitted'] ?? 0);

        return $failed > 0 ? "{$failed} failed / {$submitted} submitted" : 'PASS ('.$submitted.' submitted)';
    }
}

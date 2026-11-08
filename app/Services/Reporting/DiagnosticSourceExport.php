<?php

namespace App\Services\Reporting;

use Illuminate\Validation\ValidationException;

/**
 * Bounded JSON export of the existing SELECT-only source evidence pages.
 *
 * This does not widen source, tenant, branch or date scope.  Exhausting the
 * retained rows is not evidence that collection itself was complete.
 */
final class DiagnosticSourceExport
{
    public const MAX_ROWS = 250;

    public function read(array $args, ?array $allowed): array
    {
        $supported = ['company_name', 'company_id', 'branch_id', 'date_from', 'date_to', 'source', 'after_id', 'max_rows'];
        if (array_diff(array_keys($args), $supported)) {
            throw ValidationException::withMessages(['arguments' => 'Unsupported source export filter.']);
        }

        $validated = validator($args, [
            'company_name' => 'required|string|min:2|max:150',
            'company_id' => 'nullable|integer|min:1',
            'branch_id' => 'nullable|integer|min:1',
            'date_from' => 'required|date_format:Y-m-d',
            'date_to' => 'nullable|date_format:Y-m-d',
            'source' => 'required|in:'.implode(',', array_keys(DiagnosticSourceEvidence::SOURCES)),
            'after_id' => 'nullable|integer|min:0',
            'max_rows' => 'nullable|integer|min:1|max:'.self::MAX_ROWS,
        ])->validate();

        $maxRows = (int) ($validated['max_rows'] ?? self::MAX_ROWS);
        $startAfter = (int) ($validated['after_id'] ?? 0);
        $after = $startAfter;
        $rows = [];
        $first = null;
        $last = null;
        $terminalFailure = null;
        $failureStatuses = ['SOURCE_QUERY_FAILED', 'UNAVAILABLE', 'BRANCH_SCOPE_UNAVAILABLE'];

        while (count($rows) < $maxRows) {
            $remaining = $maxRows - count($rows);
            $pageArgs = array_diff_key($validated, ['max_rows' => true]);
            $pageArgs['after_id'] = $after;
            $pageArgs['limit'] = min(50, $remaining);
            $page = app(DiagnosticSourceEvidence::class)->read($pageArgs, $allowed);
            $first ??= $page;
            $last = $page;

            if (in_array($page['status'], $failureStatuses, true)) {
                $terminalFailure = $page['status'];
                break;
            }

            foreach ($page['rows'] as $row) {
                $rows[] = $row;
                $after = (int) $row['id'];
            }

            if (! ($page['pagination']['has_more'] ?? false) || $page['rows'] === []) {
                break;
            }
            $after = (int) $page['pagination']['next_after_id'];
        }

        $truncated = count($rows) >= $maxRows && (bool) ($last['pagination']['has_more'] ?? false);
        $sourceStatus = $first['status'];
        $readFailed = $terminalFailure !== null;

        return [
            'company' => $first['company'],
            'branch' => $first['branch'],
            'period' => $first['period'],
            'as_of' => $first['as_of'],
            'registry_version' => $first['registry_version'],
            'source' => $first['source'],
            'status' => $terminalFailure ?? $sourceStatus,
            'coverage' => $first['coverage'],
            'collection_observation' => $first['collection_observation'],
            'export' => [
                'version' => 1,
                'format' => 'application/json',
                'row_limit' => $maxRows,
                'exported_rows' => count($rows),
                'read_status' => $terminalFailure ?? $sourceStatus,
                'started_after_id' => $startAfter,
                'truncated' => $truncated,
                'next_after_id' => $truncated && $rows !== [] ? (int) end($rows)['id'] : null,
                'retained_window_rows_exhausted' => ! $truncated && ! $readFailed,
                'source_collection_complete' => false,
                'note' => 'This bounded export covers only retained matching rows. It does not prove complete collection, physical output, fiscal acceptance or product health.',
            ],
            'rows' => $rows,
        ];
    }
}

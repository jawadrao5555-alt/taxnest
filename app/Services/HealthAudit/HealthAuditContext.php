<?php

namespace App\Services\HealthAudit;

/**
 * The scope one audit run is asking about (Task 1554).
 *
 * A plain, frozen value object rather than an array, because every check reads
 * it and a typo in an array key is a silently unfiltered check — which is the
 * one failure mode an audit engine must never have.
 *
 * Two different kinds of limit live here and must not be confused:
 *
 *   the FILTER   — what the owner chose to look at (this branch, this doctor)
 *   the BOUNDARY — what this account is allowed to see at all
 *
 * The boundary is applied on top of the filter, never instead of it. An
 * auditor posted to one branch who clears the branch filter must still get one
 * branch's findings, not the whole organisation's.
 */
class HealthAuditContext
{
    public function __construct(
        public readonly int $companyId,
        public readonly string $from,           // Y-m-d, inclusive
        public readonly string $to,             // Y-m-d, inclusive
        public readonly ?int $branchId = null,
        public readonly ?int $departmentId = null,
        public readonly ?int $doctorId = null,
        public readonly ?int $subjectUserId = null,
        public readonly ?array $branchBoundary = null,      // null = every branch
        public readonly ?array $departmentBoundary = null,  // null = every department
        public readonly ?array $doctorBoundary = null,      // null = every doctor
        public readonly string $preset = 'custom',
    ) {
    }

    public function fromStart(): string
    {
        return $this->from . ' 00:00:00';
    }

    public function toEnd(): string
    {
        return $this->to . ' 23:59:59';
    }

    /**
     * Apply the branch filter AND the branch boundary to a query.
     *
     * Rows with a NULL branch are organisation-wide and stay visible under a
     * boundary — the same rule the rest of the panel already follows — but they
     * are excluded by an explicit branch FILTER, because somebody who asked for
     * one branch's findings did not ask for the head office's too.
     */
    public function applyBranch($query, string $column = 'branch_id')
    {
        if ($this->branchId) {
            $query->where($column, $this->branchId);
        } elseif (is_array($this->branchBoundary)) {
            $ids = $this->branchBoundary;
            $query->where(function ($q) use ($column, $ids) {
                $q->whereIn($column, $ids ?: [0])->orWhereNull($column);
            });
        }

        return $query;
    }

    public function applyDepartment($query, string $column = 'health_department_id')
    {
        if ($this->departmentId) {
            $query->where($column, $this->departmentId);
        } elseif (is_array($this->departmentBoundary)) {
            $ids = $this->departmentBoundary;
            $query->where(function ($q) use ($column, $ids) {
                $q->whereIn($column, $ids ?: [0])->orWhereNull($column);
            });
        }

        return $query;
    }

    /**
     * The department fence for a table that has no department column of its
     * own, applied THROUGH the record it hangs off.
     *
     * A cash receipt, an admission charge, a cashier shift, a doctor's
     * settlement: none of them is filed under a ward, but the bill, the
     * admission, the cashier and the doctor behind them are. Rows with no
     * parent at all (an advance with no bill yet) are organisation-wide and
     * stay in — the NULL-means-everyone rule every list screen applies.
     *
     * Only the department boundary travels this way; the branch fence is on the
     * child table itself and applied directly.
     */
    public function applyDepartmentVia($query, string $parentTable, string $localKey, string $parentKey = 'id', string $parentDepartmentColumn = 'health_department_id')
    {
        if (!$this->departmentId && !is_array($this->departmentBoundary)) {
            return $query;
        }

        $ctx = $this;
        // An explicit department FILTER excludes parentless rows, exactly as
        // applyDepartment() excludes NULL rows; a BOUNDARY keeps them.
        $keepParentless = !$this->departmentId;

        return $query->where(function ($outer) use ($ctx, $parentTable, $localKey, $parentKey, $parentDepartmentColumn, $keepParentless) {
            if ($keepParentless) {
                $outer->whereNull($localKey);
            }
            $outer->orWhereExists(function ($sub) use ($ctx, $parentTable, $localKey, $parentKey, $parentDepartmentColumn) {
                $sub->selectRaw('1')
                    ->from($parentTable . ' as hav_parent')
                    ->whereColumn('hav_parent.' . $parentKey, $localKey);
                $ctx->applyDepartment($sub, 'hav_parent.' . $parentDepartmentColumn);
            });
        });
    }

    public function applyDoctor($query, string $column = 'health_doctor_id')
    {
        if ($this->doctorId) {
            $query->where($column, $this->doctorId);
        } elseif (is_array($this->doctorBoundary)) {
            $query->whereIn($column, $this->doctorBoundary ?: [0]);
        }

        return $query;
    }

    /**
     * Narrow to one staff member, when the owner picked one.
     *
     * Takes several columns because "the person behind this row" is spelled
     * differently on every table — created_by, received_by, reversed_by. ANY of
     * them matching counts: a cashier who took the payment and a cashier who
     * reversed it are both that cashier's activity.
     */
    public function applySubject($query, array $columns)
    {
        if (!$this->subjectUserId || empty($columns)) {
            return $query;
        }

        $id = $this->subjectUserId;

        return $query->where(function ($q) use ($columns, $id) {
            foreach ($columns as $column) {
                $q->orWhere($column, $id);
            }
        });
    }

    /** Everything that makes two runs comparable, in one hash. */
    public function fingerprint(string $rulesetVersion): string
    {
        return hash('sha256', implode('|', [
            $this->companyId,
            $this->from,
            $this->to,
            $this->branchId ?? '',
            $this->departmentId ?? '',
            $this->doctorId ?? '',
            $this->subjectUserId ?? '',
            // The boundary is part of the question. An owner's "last 30 days"
            // and a branch accountant's "last 30 days" are different audits and
            // must never be compared as a trend of one another.
            $this->branchBoundary === null ? '*' : implode(',', self::sortedIds($this->branchBoundary)),
            $this->departmentBoundary === null ? '*' : implode(',', self::sortedIds($this->departmentBoundary)),
            $rulesetVersion,
        ]));
    }

    /** Ids as a stable, de-duplicated, ascending list — the storable form of a boundary. */
    public static function sortedIds(?array $ids): ?array
    {
        if ($ids === null) {
            return null;
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        return $ids;
    }

    public function days(): int
    {
        return max(1, (int) \Illuminate\Support\Carbon::parse($this->from)
            ->diffInDays(\Illuminate\Support\Carbon::parse($this->to)) + 1);
    }
}

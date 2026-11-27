<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Company;
use App\Models\HealthCharge;
use App\Models\HealthDepartment;
use App\Models\HealthLabOrder;
use App\Models\HealthLabOrderItem;
use App\Models\HealthLabResult;
use App\Models\HealthLabSpecimen;
use App\Models\HealthLabTest;
use App\Models\HealthPatient;
use App\Models\HealthVisit;
use App\Models\User;
use App\Services\HealthAudit\HealthAuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HealthLabWorkflow
{
    public function intake(User $user, array $data): HealthLabOrder
    {
        return DB::transaction(function () use ($user, $data): HealthLabOrder {
            // Lock the tenant first so two retries can never create separate orders.
            $company = Company::whereKey($user->company_id)->lockForUpdate()->firstOrFail();
            abort_unless(HealthAccessService::can($user, 'lab.orders.manage', $company), 403);

            $patientId = (int) $data['patient_id'];
            $visit = isset($data['visit_id']) ? $this->visitForIntake($user, (int) $data['visit_id'], true) : null;
            if ($visit && ($patientId !== $visit->health_patient_id
                || (isset($data['branch_id']) && (int) $data['branch_id'] !== $visit->branch_id)
                || (isset($data['department_id']) && (int) $data['department_id'] !== $visit->health_department_id))) {
                $this->invalid('visit_id', 'visit_mismatch');
            }
            $branchId = $visit ? $visit->branch_id : $this->postingBranch($user, $company, $data['branch_id'] ?? null);
            $departmentId = $visit ? $visit->health_department_id
                : $this->postingDepartment($user, $branchId, $data['department_id'] ?? null);
            $testIds = array_map('intval', $data['test_ids']);
            sort($testIds, SORT_NUMERIC);
            $fingerprintData = [$patientId, $branchId, $departmentId, $testIds];
            if ($visit) {
                $fingerprintData[] = $visit->id;
            }
            $fingerprint = hash('sha256', json_encode($fingerprintData, JSON_THROW_ON_ERROR));
            $previous = HealthLabOrder::query()
                ->where('company_id', $user->company_id)
                ->where('request_key', $data['request_key'])
                ->first();

            if ($previous) {
                $visible = HealthLabOrder::visibleTo($user)->findOrFail($previous->id);
                if ($visible->request_fingerprint !== $fingerprint) {
                    $this->invalid('request_key', 'request_changed');
                }

                return $visible;
            }

            $patient = HealthScopeService::applyBranchScope(HealthPatient::query(), $user)
                ->where('company_id', $user->company_id)->where('is_active', true)
                ->lockForUpdate()->findOrFail($patientId);
            abort_if($patient->is_confidential && !HealthAccessService::isAdministrative($user)
                && !HealthRecordAccessService::isTreatingClinician($user, $patient), 404);

            $tests = HealthLabTest::query()->where('company_id', $user->company_id)
                ->where('is_active', true)->whereKey($testIds)->orderBy('id')->lockForUpdate()->get();
            if ($tests->count() !== count($testIds)) {
                $this->invalid('test_ids', 'unavailable_test');
            }

            $order = HealthLabOrder::create([
                'company_id' => $user->company_id,
                'branch_id' => $branchId,
                'health_department_id' => $departmentId,
                'health_patient_id' => $patient->id,
                'health_visit_id' => $visit?->id,
                'order_no' => 'LAB-'.Str::ulid(),
                'request_key' => $data['request_key'],
                'request_fingerprint' => $fingerprint,
                'ordered_by' => $user->id,
            ]);
            foreach ($tests as $test) {
                $item = $order->items()->create([
                    'company_id' => $user->company_id,
                    'health_lab_test_id' => $test->id,
                    'test_code' => $test->code,
                    'test_name' => $test->name,
                    'price_paisa' => $test->price_paisa,
                    'unit_cost_paisa' => $test->unit_cost_paisa,
                ]);
                $this->newSpecimen($item, 1);
            }

            $this->record($user, $order, 'ordered');

            return $order;
        }, 3);
    }

    public function visitForIntake(User $user, int $visitId, bool $lock = false): HealthVisit
    {
        $query = HealthScopeService::apply(HealthVisit::query(), $user)
            ->where('company_id', $user->company_id)
            ->where('status', '!=', HealthVisit::STATUS_CANCELLED)
            ->with(['patient' => fn ($patient) => HealthScopeService::applyBranchScope($patient, $user)
                ->where('company_id', $user->company_id)->where('is_active', true)]);
        $visit = ($lock ? $query->lockForUpdate() : $query)->findOrFail($visitId);
        abort_unless(HealthRecordAccessService::canOpenClinical(
            $user, $visit->patient, Company::find($user->company_id)
        ), 404);

        return $visit;
    }

    public function sendFeesToBilling(User $user, int $orderId): void
    {
        DB::transaction(function () use ($user, $orderId): void {
            $company = Company::find($user->company_id);
            abort_unless(HealthAccessService::can($user, 'billing.charge', $company), 403);
            $order = HealthLabOrder::visibleTo($user)->lockForUpdate()->findOrFail($orderId);
            $items = $order->items()->where('company_id', $order->company_id)
                ->where('price_paisa', '>', 0)->orderBy('id')->get();

            if ($items->isEmpty()) {
                $this->invalid('billing', 'no_billable_fees');
            }

            $posted = false;
            foreach ($items as $item) {
                $charge = HealthChargeService::post([
                    'company_id' => $order->company_id,
                    'branch_id' => $order->branch_id,
                    'health_department_id' => $order->health_department_id,
                    'health_patient_id' => $order->health_patient_id,
                    'health_visit_id' => $order->health_visit_id,
                    'category' => HealthCharge::CAT_LAB,
                    'description' => __('lab_workflow.billing_description'),
                    'reference' => $order->order_no,
                    'source_type' => HealthCharge::SOURCE_LAB_ORDER,
                    'source_id' => $order->id,
                    'source_reference' => $order->order_no,
                    'unit_price' => $item->price_paisa / 100,
                    'dedupe_key' => 'lab_order:'.$order->id.':item:'.$item->id,
                ], $user);

                if (!$charge) {
                    $this->invalid('billing', 'billing_unavailable');
                }
                $posted = $posted || $charge->wasRecentlyCreated;
            }

            if ($posted) {
                $this->record($user, $order, 'fees_posted');
            }
        }, 3);
    }

    public function collect(User $user, int $orderId, int $itemId, int $specimenId): void
    {
        DB::transaction(function () use ($user, $orderId, $itemId, $specimenId): void {
            [$order, $item] = $this->lockItem($user, $orderId, $itemId, 'lab.collect');
            $specimen = $this->lockedSpecimen($item, $specimenId);
            if ($specimen->status === HealthLabSpecimen::COLLECTED) {
                return;
            }
            if ($specimen->status !== HealthLabSpecimen::PENDING) {
                $this->invalid('specimen', 'state_changed');
            }
            $specimen->update([
                'status' => HealthLabSpecimen::COLLECTED,
                'collected_at' => now(),
                'collected_by' => $user->id,
            ]);
            $this->record($user, $order, 'specimen_collected', $item->id);
        }, 3);
    }

    public function reject(User $user, int $orderId, int $itemId, int $specimenId, string $reason): void
    {
        DB::transaction(function () use ($user, $orderId, $itemId, $specimenId, $reason): void {
            [$order, $item] = $this->lockItem($user, $orderId, $itemId, 'lab.collect');
            $specimen = $this->lockedSpecimen($item, $specimenId);
            if ($specimen->status !== HealthLabSpecimen::COLLECTED || $item->results()->exists()) {
                $this->invalid('specimen', 'cannot_reject');
            }
            $specimen->update([
                'status' => HealthLabSpecimen::REJECTED,
                'rejected_at' => now(),
                'rejected_by' => $user->id,
                'rejection_reason' => $reason,
            ]);
            $this->newSpecimen($item, $specimen->sequence + 1);
            $this->record($user, $order, 'specimen_rejected', $item->id);
        }, 3);
    }

    public function enterResult(User $user, int $orderId, int $itemId, array $data): void
    {
        DB::transaction(function () use ($user, $orderId, $itemId, $data): void {
            [$order, $item] = $this->lockItem($user, $orderId, $itemId, 'lab.result');
            $specimen = $this->lockedSpecimen($item, (int) $data['specimen_id']);
            if ($specimen->status !== HealthLabSpecimen::COLLECTED) {
                $this->invalid('specimen', 'collection_required');
            }
            $result = $item->results()->first();
            if ((int) $data['expected_revision'] !== ($result?->revision ?? 0)
                || (int) $data['expected_version'] !== ($result?->version ?? 0)) {
                $this->invalid('expected_revision', 'state_changed');
            }

            $attributes = [
                'value' => $data['value'],
                'unit' => $data['unit'] ?? null,
                'reference' => $data['reference'] ?? null,
                'amendment_reason' => $data['amendment_reason'] ?? null,
            ];
            $isAmendment = ($result?->revision ?? 0) > 1 || $result?->verified_at !== null;
            if ($isAmendment && !$attributes['amendment_reason']) {
                $this->invalid('amendment_reason', 'amendment_required');
            }

            if ($result && !$result->verified_at) {
                abort_unless((int) $result->entered_by === (int) $user->id, 403);
                $result->update($attributes + ['version' => $result->version + 1]);
            } else {
                $item->results()->create($attributes + [
                    'company_id' => $user->company_id,
                    'health_lab_specimen_id' => $specimen->id,
                    'entered_by' => $user->id,
                    'revision' => ($result?->revision ?? 0) + 1,
                    'version' => 1,
                ]);
            }
            $this->record($user, $order, 'result_entered', $item->id);
        }, 3);
    }

    public function verifyResult(User $user, int $orderId, int $itemId, int $resultId, int $version): void
    {
        DB::transaction(function () use ($user, $orderId, $itemId, $resultId, $version): void {
            [$order, $item] = $this->lockItem($user, $orderId, $itemId, 'lab.verify');
            $result = $item->results()->first();
            if (!$result || (int) $result->id !== $resultId || $result->version !== $version) {
                $this->invalid('result', 'state_changed');
            }
            if ((int) $result->entered_by === (int) $user->id) {
                $this->invalid('result', 'independent_verifier');
            }
            if ($result->verified_at) {
                return;
            }
            $specimen = $this->lockedSpecimen($item, (int) $result->health_lab_specimen_id);
            if ($specimen->status !== HealthLabSpecimen::COLLECTED) {
                $this->invalid('specimen', 'collection_required');
            }
            $result->update([
                'verified_at' => now(),
                'verified_by' => $user->id,
                'verifier_name' => mb_substr((string) $user->name, 0, 150),
            ]);
            $this->record($user, $order, 'result_verified', $item->id);
        }, 3);
    }

    private function lockItem(User $user, int $orderId, int $itemId, string $capability): array
    {
        abort_unless(HealthAccessService::can($user, $capability, Company::find($user->company_id)), 403);
        $order = HealthLabOrder::visibleTo($user)->lockForUpdate()->findOrFail($orderId);
        $item = $order->items()->where('company_id', $user->company_id)->lockForUpdate()->findOrFail($itemId);

        return [$order, $item];
    }

    private function lockedSpecimen(HealthLabOrderItem $item, int $specimenId): HealthLabSpecimen
    {
        $specimen = $item->specimens()->where('company_id', $item->company_id)->lockForUpdate()->first();
        if (!$specimen || (int) $specimen->id !== $specimenId) {
            $this->invalid('specimen', 'state_changed');
        }

        return $specimen;
    }

    private function newSpecimen(HealthLabOrderItem $item, int $sequence): void
    {
        $item->specimens()->create([
            'company_id' => $item->company_id,
            'sequence' => $sequence,
            'barcode' => 'S-'.Str::ulid(),
            'status' => HealthLabSpecimen::PENDING,
        ]);
    }

    private function postingBranch(User $user, Company $company, mixed $requested): ?int
    {
        $branch = $requested ? (int) $requested : (HealthPlatformService::activeBranchId()
            ?? HealthPlatformService::defaultBranchId($company));
        if ($branch && (!HealthScopeService::canAccessBranch($user, $branch)
            || !Branch::withoutGlobalScopes()->where('company_id', $company->id)->where('is_active', true)->whereKey($branch)->exists())) {
            $this->invalid('branch_id', 'unavailable_branch');
        }

        return $branch;
    }

    private function postingDepartment(User $user, ?int $branch, mixed $requested): ?int
    {
        $department = $requested ? (int) $requested : null;
        if ($department && !HealthScopeService::selectableDepartments($user)->contains(
            fn (HealthDepartment $row): bool => (int) $row->id === $department
                && (!$row->branch_id || (int) $row->branch_id === $branch)
        )) {
            $this->invalid('department_id', 'unavailable_department');
        }

        return $department;
    }

    private function record(User $user, HealthLabOrder $order, string $action, ?int $itemId = null): void
    {
        HealthAuditRecorder::record('lab.'.$action, [
            'company_id' => $order->company_id,
            'actor' => $user,
            'branch_id' => $order->branch_id,
            'health_department_id' => $order->health_department_id,
            'health_patient_id' => $order->health_patient_id,
            'category' => 'clinical',
            'action' => $action,
            'entity_type' => 'health_lab_orders',
            'entity_id' => $order->id,
            'entity_label' => $order->order_no,
            'meta' => ['order_item_id' => $itemId],
            'sensitive' => true,
        ]);
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => __('lab_workflow.'.$message)]);
    }
}

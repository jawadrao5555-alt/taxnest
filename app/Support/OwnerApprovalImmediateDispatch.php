<?php

namespace App\Support;

use App\Models\OwnerDeploymentApprovalRequest;

/**
 * Credential-free boundary for a possible future GitHub App dispatcher.
 *
 * This release intentionally ships no credential provider and performs no
 * outbound action. A later owner-approved implementation can replace this
 * seam without coupling password approval to token storage or weakening the
 * scheduled OIDC emergency path.
 */
final class OwnerApprovalImmediateDispatch
{
    /** @return array{enabled:bool,driver:string,label:string,guidance:string} */
    public static function status(): array
    {
        $driver = strtolower(trim((string) config('deployment_approval.immediate_dispatch.driver', 'disabled')));

        return [
            'enabled' => false,
            'driver' => $driver ?: 'disabled',
            'label' => 'Scheduled OIDC relay active',
            'guidance' => 'Immediate GitHub App pickup is not configured. One Admin approval stays queued for the scheduled relay; Approval Relay Dispatch remains the emergency wake-up.',
        ];
    }

    /**
     * Future integration seam. It is impossible to dispatch in this release,
     * even if configuration is changed accidentally.
     *
     * @return array{dispatched:false,mode:string,approval_request_id:string}
     */
    public function dispatch(OwnerDeploymentApprovalRequest $approval): array
    {
        return [
            'dispatched' => false,
            'mode' => 'scheduled_oidc',
            'approval_request_id' => (string) $approval->request_id,
        ];
    }
}

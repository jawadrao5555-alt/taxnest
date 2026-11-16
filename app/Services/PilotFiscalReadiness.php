<?php

namespace App\Services;

use App\Models\Company;

/** Evidence inventory, NOT an activation switch or a regulator adapter. */
final class PilotFiscalReadiness
{
    public static function forCompany(Company $company): array
    {
        abort_unless($company->isLocalCollectionPilot(), 404);
        $category = $company->isSchoolFeePilot() ? 'school' : 'hospital';

        // Evidence is deliberately not accepted from request data or tenant settings.
        // A generic retail/DI token or a legal schedule alone cannot satisfy this contract.
        $requirements = [
            'applicability' => 'Current official category applicability and exclusions for this company.',
            'reportable_event' => $category === 'school'
                ? 'Official fee receipt versus charge, period and adjustment reporting event.'
                : 'Official service bill versus installment, payment and adjustment reporting event.',
            'transport' => 'Applicable endpoint and versioned payload schema for this category.',
            'authentication' => 'Official authentication and enrollment bound to this company and category.',
            'tax_mapping' => $category === 'school'
                ? 'Fee-head, fee-period, discount, exemption and adjustment reporting/tax mapping.'
                : 'Consultation, laboratory, procedure, discount and partial-payment reporting/tax mapping.',
            'sandbox' => 'Authorized category sandbox enrollment and accepted/rejected submission evidence.',
            'acknowledgement' => 'Verified fiscal number and QR format from a valid acknowledgement.',
            'uncertain_recovery' => 'Official timeout status lookup, duplicate identity and safe retry contract.',
            'implementation' => 'Reviewed category adapter, immutable new-bill eligibility and concurrency acceptance.',
        ];

        return [
            'company_id' => $company->id,
            'category' => $category,
            'evidence_transfer_allowed' => false,
            'selected_route' => 'off',
            'routes' => array_map(static fn (string $route): array => [
                'company_id' => $company->id, 'category' => $category, 'route' => $route,
                'adapter_implemented' => false, 'sandbox_verified' => false, 'eligible' => false,
                'requirements' => array_map(static fn (string $id): array => [
                    'company_id' => $company->id, 'category' => $category, 'route' => $route,
                    'id' => $id, 'status' => $id === 'implementation' ? 'pending' : 'unverified',
                ], array_keys($requirements)),
            ], ['ims', 'di']),
            'integration_implemented' => false,
            'activation_available' => false,
            'sandbox_verified' => false,
            'requirements' => array_map(
                static fn (string $id, string $description): array => [
                    'company_id' => $company->id, 'category' => $category,
                    'id' => $id, 'status' => $id === 'implementation' ? 'pending' : 'unverified',
                    'description' => $description,
                ],
                array_keys($requirements), array_values($requirements)
            ),
            'historical_backfill_allowed' => false,
            'credential_instruction' => 'Use an authorized secure sandbox handoff; do not enter credentials in notes or evidence descriptions.',
        ];
    }
}

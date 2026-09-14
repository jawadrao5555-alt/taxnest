<?php

namespace App\Services;

use App\Models\Company;
use InvalidArgumentException;

/** Category-native operational workflows for service businesses.
 *
 * Categories absent from this map deliberately remain catalogue/billing only;
 * the UI must never pretend that a renamed generic screen is a complete trade
 * workflow. Hotel is intentionally absent because Hotel V1 owns stays/folios.
 */
class PosServiceWorkflowProfiles
{
    public const PROFILES = [
        'salon' => ['noun' => 'Appointment', 'prefix' => 'SAL', 'schedule' => true,
            'stages' => ['booked', 'checked_in', 'in_service', 'completed', 'cancelled'],
            'terminal' => ['completed'],
            'fields' => ['staff' => 'Stylist / therapist', 'station' => 'Chair / room']],
        'laundry' => ['noun' => 'Laundry Ticket', 'prefix' => 'LND', 'schedule' => true,
            'stages' => ['received', 'tagged', 'cleaning', 'ready', 'delivered', 'cancelled'],
            'terminal' => ['delivered'],
            'fields' => ['pieces' => 'Piece count', 'care' => 'Care instructions']],
        'workshop' => ['noun' => 'Workshop Job', 'prefix' => 'WKS', 'schedule' => false,
            'stages' => ['received', 'diagnosing', 'approval_pending', 'approved', 'in_progress', 'ready', 'delivered', 'cancelled'],
            'terminal' => ['delivered'],
            'fields' => ['asset' => 'Vehicle / asset', 'registration' => 'Registration / serial', 'complaint' => 'Reported fault']],
        'repair_service' => ['noun' => 'Repair Job', 'prefix' => 'RPR', 'schedule' => false,
            'stages' => ['received', 'diagnosing', 'approval_pending', 'approved', 'in_progress', 'ready', 'delivered', 'cancelled'],
            'terminal' => ['delivered'],
            'fields' => ['asset' => 'Device / equipment', 'serial' => 'Serial / IMEI', 'complaint' => 'Reported fault']],
        'courier' => ['noun' => 'Consignment', 'prefix' => 'CRR', 'schedule' => true,
            'stages' => ['booked', 'picked_up', 'in_transit', 'out_for_delivery', 'delivered', 'returned', 'cancelled'],
            'terminal' => ['delivered', 'returned'],
            'alternates' => ['out_for_delivery' => ['returned']],
            'fields' => ['recipient' => 'Recipient', 'destination' => 'Destination', 'weight' => 'Weight']],
        'cargo' => ['noun' => 'Cargo Job', 'prefix' => 'CGO', 'schedule' => true,
            'stages' => ['booked', 'received', 'in_transit', 'at_destination', 'delivered', 'returned', 'cancelled'],
            'terminal' => ['delivered', 'returned'],
            'alternates' => ['at_destination' => ['returned']],
            'fields' => ['consignee' => 'Consignee', 'destination' => 'Destination', 'weight' => 'Weight / volume']],
        'rent_a_car' => ['noun' => 'Rental', 'prefix' => 'CAR', 'schedule' => true,
            'stages' => ['reserved', 'checked_out', 'returned', 'completed', 'cancelled'],
            'terminal' => ['completed'],
            'fields' => ['asset' => 'Vehicle', 'driver' => 'Driver', 'odometer' => 'Opening odometer']],
        'equipment_rental' => ['noun' => 'Equipment Rental', 'prefix' => 'EQR', 'schedule' => true,
            'stages' => ['reserved', 'checked_out', 'returned', 'inspection', 'completed', 'cancelled'],
            'terminal' => ['completed'],
            'fields' => ['asset' => 'Equipment', 'serial' => 'Asset serial', 'condition' => 'Issue condition']],
        'tailoring' => ['noun' => 'Stitching Order', 'prefix' => 'TLR', 'schedule' => true,
            'stages' => ['received', 'measured', 'cutting', 'stitching', 'trial', 'ready', 'delivered', 'cancelled'],
            'terminal' => ['delivered'],
            'fields' => ['garment' => 'Garment', 'measurements' => 'Measurements', 'fabric' => 'Fabric details']],
        'printing' => ['noun' => 'Print Job', 'prefix' => 'PRN', 'schedule' => true,
            'stages' => ['quoted', 'artwork_pending', 'proof_pending', 'approved', 'printing', 'ready', 'delivered', 'cancelled'],
            'terminal' => ['delivered'],
            'fields' => ['size' => 'Size', 'material' => 'Material', 'copies' => 'Copies']],
        'photography' => ['noun' => 'Shoot Booking', 'prefix' => 'PHT', 'schedule' => true,
            'stages' => ['booked', 'shoot_complete', 'editing', 'proof_shared', 'approved', 'delivered', 'cancelled'],
            'terminal' => ['delivered'],
            'fields' => ['venue' => 'Venue', 'crew' => 'Crew', 'deliverables' => 'Deliverables']],
        'cleaning' => ['noun' => 'Cleaning Job', 'prefix' => 'CLN', 'schedule' => true,
            'stages' => ['booked', 'team_assigned', 'in_progress', 'inspection', 'completed', 'cancelled'],
            'terminal' => ['completed'],
            'fields' => ['site' => 'Site / address', 'team' => 'Assigned team', 'scope' => 'Cleaning scope']],
    ];

    public static function category(?Company $company): string
    {
        return PosFeatureService::profileCategory($company);
    }

    public static function forCompany(?Company $company): ?array
    {
        $category = self::category($company);

        return isset(self::PROFILES[$category]) ? self::PROFILES[$category] + ['category' => $category] : null;
    }

    public static function supports(?Company $company): bool
    {
        return self::forCompany($company) !== null;
    }

    public static function canTransition(array $profile, string $from, string $to): bool
    {
        if ($from === $to || $from === 'cancelled' || in_array($from, $profile['terminal'] ?? [], true)) {
            return false;
        }
        if ($to === 'cancelled') {
            return true;
        }

        return in_array($to, self::nextStatuses($profile, $from), true);
    }

    public static function nextStatuses(array $profile, string $from): array
    {
        if ($from === 'cancelled' || in_array($from, $profile['terminal'] ?? [], true)) {
            return [];
        }
        $stages = $profile['stages'];
        $fromIndex = array_search($from, $stages, true);
        $next = $fromIndex !== false ? ($stages[$fromIndex + 1] ?? null) : null;
        $result = ($next && $next !== 'cancelled') ? [$next] : [];

        return array_values(array_unique(array_merge($result, $profile['alternates'][$from] ?? [])));
    }

    public static function assertTransition(array $profile, string $from, string $to): void
    {
        if (! self::canTransition($profile, $from, $to)) {
            throw new InvalidArgumentException("Invalid {$profile['noun']} transition: {$from} to {$to}.");
        }
    }
}

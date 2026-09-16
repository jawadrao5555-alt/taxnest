<?php

namespace App\Services;

use App\Models\Company;
use App\Models\User;
use InvalidArgumentException;

/** Category-native operational workflows for service businesses.
 *
 * Hotel is intentionally absent because Hotel V1 owns stays/folios. Every
 * marketed service category has a typed operational record here; categories
 * from the other engines remain outside this map rather than being relabelled.
 */
class PosServiceWorkflowProfiles
{
    /**
     * Named baseline, deliberately inherited by every profile. These preserve
     * the established manager/cashier operational access of the first twelve
     * workflows while making the action contract inspectable and overrideable
     * per vertical. PosAuth custom access is evaluated before the controller,
     * so service_jobs remains the feature-level gate for every route below.
     */
    public const DEFAULT_PERMISSIONS = [
        'create' => ['pos_manager', 'pos_cashier'],
        'transition' => ['pos_manager', 'pos_cashier'],
        'invoice' => ['pos_manager', 'pos_cashier'],
        'report' => ['pos_manager', 'pos_cashier'],
    ];

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
        'gym' => ['noun' => 'Membership Record', 'prefix' => 'GYM', 'schedule' => false,
            'stages' => ['enquiry', 'enrolled', 'active', 'renewal_due', 'closed', 'cancelled'],
            'terminal' => ['closed'],
            'required_fields' => ['member', 'plan'],
            'fields' => ['member' => 'Member name / ID', 'plan' => 'Membership plan', 'trainer' => 'Trainer / programme']],
        'event_management' => ['noun' => 'Event Plan', 'prefix' => 'EVT', 'schedule' => true,
            'stages' => ['booked', 'brief_confirmed', 'planned', 'in_progress', 'event_complete', 'closed', 'cancelled'],
            'terminal' => ['closed'],
            'required_fields' => ['event', 'venue'],
            'fields' => ['event' => 'Event type', 'venue' => 'Venue / address', 'guest_count' => 'Expected guests']],
        'travel_agent' => ['noun' => 'Travel File', 'prefix' => 'TRV', 'schedule' => true,
            'stages' => ['inquiry', 'documents_pending', 'confirmed', 'in_progress', 'completed', 'closed', 'cancelled'],
            'terminal' => ['closed'],
            'required_fields' => ['traveler', 'itinerary'],
            'fields' => ['traveler' => 'Traveller(s)', 'itinerary' => 'Route / itinerary', 'reference' => 'Booking reference']],
        'property_dealer' => ['noun' => 'Property Deal File', 'prefix' => 'PRP', 'schedule' => false,
            'stages' => ['listed', 'viewing', 'negotiation', 'agreement_pending', 'closed', 'cancelled'],
            'terminal' => ['closed'],
            'required_fields' => ['property', 'deal_type'],
            'fields' => ['property' => 'Property / location', 'deal_type' => 'Sale / rental requirement', 'contact' => 'Other party contact']],
        'advertising' => ['noun' => 'Campaign Job', 'prefix' => 'ADV', 'schedule' => true,
            'stages' => ['brief_received', 'proposal_sent', 'approved', 'scheduled', 'live', 'completed', 'closed', 'cancelled'],
            'terminal' => ['closed'],
            'required_fields' => ['campaign', 'channel'],
            'fields' => ['campaign' => 'Campaign objective', 'channel' => 'Channel / placement', 'deliverables' => 'Planned deliverables']],
        'it_services' => ['noun' => 'Service Ticket', 'prefix' => 'ITS', 'schedule' => false,
            'stages' => ['logged', 'triaged', 'in_progress', 'awaiting_customer', 'resolved', 'closed', 'cancelled'],
            'terminal' => ['closed'],
            'required_fields' => ['system', 'request'],
            'fields' => ['system' => 'System / asset', 'request' => 'Request / issue', 'contact' => 'Site contact']],
        'security_services' => ['noun' => 'Service Assignment', 'prefix' => 'SEC', 'schedule' => true,
            'stages' => ['requested', 'site_review', 'assigned', 'active', 'review_due', 'closed', 'cancelled'],
            'terminal' => ['closed'],
            'required_fields' => ['site', 'shift'],
            'fields' => ['site' => 'Site / address', 'shift' => 'Shift / coverage', 'team' => 'Assigned team']],
        'clinic' => ['noun' => 'Visit Record', 'prefix' => 'CLV', 'schedule' => true,
            'stages' => ['booked', 'arrived', 'in_service', 'follow_up', 'completed', 'cancelled'],
            'terminal' => ['completed'],
            'required_fields' => ['visit_type', 'reason'],
            'fields' => ['visit_type' => 'Visit type', 'reason' => 'Visit reason', 'practitioner' => 'Assigned practitioner']],
        'education' => ['noun' => 'Enrollment Record', 'prefix' => 'EDU', 'schedule' => true,
            'stages' => ['inquiry', 'enrolled', 'active', 'assessment_due', 'completed', 'cancelled'],
            'terminal' => ['completed'],
            'required_fields' => ['learner', 'program'],
            'fields' => ['learner' => 'Learner name / ID', 'program' => 'Programme / course', 'term' => 'Term / batch']],
        'consultant' => ['noun' => 'Engagement File', 'prefix' => 'CON', 'schedule' => false,
            'stages' => ['inquiry', 'scoped', 'proposal_sent', 'engaged', 'delivered', 'closed', 'cancelled'],
            'terminal' => ['closed'],
            'required_fields' => ['engagement', 'scope'],
            'fields' => ['engagement' => 'Engagement name', 'scope' => 'Scope / objective', 'contact' => 'Client contact']],
        'architect' => ['noun' => 'Design Brief', 'prefix' => 'ARC', 'schedule' => false,
            'stages' => ['brief_received', 'site_review', 'design', 'review', 'approved', 'delivered', 'cancelled'],
            'terminal' => ['delivered'],
            'required_fields' => ['project', 'site'],
            'fields' => ['project' => 'Project name', 'site' => 'Site / location', 'scope' => 'Design scope']],
        'construction' => ['noun' => 'Site Job', 'prefix' => 'CNS', 'schedule' => false,
            'stages' => ['planned', 'mobilized', 'in_progress', 'inspection', 'handover', 'closed', 'cancelled'],
            'terminal' => ['closed'],
            'required_fields' => ['site', 'scope'],
            'fields' => ['site' => 'Site / location', 'scope' => 'Work scope', 'supervisor' => 'Site supervisor']],
        'manpower' => ['noun' => 'Staffing Request', 'prefix' => 'MNP', 'schedule' => true,
            'stages' => ['requested', 'shortlisted', 'assigned', 'active', 'completed', 'closed', 'cancelled'],
            'terminal' => ['closed'],
            'required_fields' => ['role_required', 'headcount'],
            'fields' => ['role_required' => 'Required role / skill', 'headcount' => 'Required headcount', 'assignment_site' => 'Assignment site']],
        'warehouse' => ['noun' => 'Storage Job', 'prefix' => 'WHR', 'schedule' => false,
            'stages' => ['received', 'stored', 'handling', 'ready_for_release', 'released', 'closed', 'cancelled'],
            'terminal' => ['closed'],
            'required_fields' => ['goods', 'storage_location'],
            'fields' => ['goods' => 'Goods description', 'storage_location' => 'Storage location', 'handling' => 'Handling requirement']],
        'media_production' => ['noun' => 'Production Job', 'prefix' => 'MED', 'schedule' => true,
            'stages' => ['booked', 'pre_production', 'production', 'editing', 'review', 'delivered', 'cancelled'],
            'terminal' => ['delivered'],
            'required_fields' => ['project', 'deliverables'],
            'fields' => ['project' => 'Project / production', 'venue' => 'Venue / location', 'deliverables' => 'Deliverables']],
        'entertainment' => ['noun' => 'Event Booking', 'prefix' => 'ENT', 'schedule' => true,
            'stages' => ['booked', 'planned', 'active', 'event_complete', 'closed', 'cancelled'],
            'terminal' => ['closed'],
            'required_fields' => ['event', 'venue'],
            'fields' => ['event' => 'Event / package', 'venue' => 'Venue / location', 'attendance' => 'Expected attendance']],
        'financial_services' => ['noun' => 'Service File', 'prefix' => 'FIN', 'schedule' => false,
            'stages' => ['requested', 'information_received', 'in_progress', 'review', 'delivered', 'closed', 'cancelled'],
            'terminal' => ['closed'],
            'required_fields' => ['service_type', 'reference'],
            'fields' => ['service_type' => 'Requested service', 'reference' => 'Customer reference', 'contact' => 'Primary contact']],
        'other_service' => ['noun' => 'Service Job', 'prefix' => 'SRV', 'schedule' => false,
            'stages' => ['requested', 'scoped', 'in_progress', 'review', 'completed', 'closed', 'cancelled'],
            'terminal' => ['closed'],
            'required_fields' => ['request'],
            'fields' => ['request' => 'Requested work', 'contact' => 'Site / contact', 'reference' => 'Customer reference']],
    ];

    public static function category(?Company $company): string
    {
        return PosFeatureService::profileCategory($company);
    }

    public static function forCompany(?Company $company): ?array
    {
        $category = self::category($company);

        if (!isset(self::PROFILES[$category])) {
            return null;
        }

        $profile = self::PROFILES[$category];
        $profile['permissions'] = self::permissions($profile);
        $profile['category'] = $category;

        return $profile;
    }

    public static function supports(?Company $company): bool
    {
        return self::forCompany($company) !== null;
    }

    /** Explicit per-action role policy used by the controller and action UI. */
    public static function allows(array $profile, ?User $user, string $action): bool
    {
        if (! $user) {
            return false;
        }
        if ($user->isCompanyAdmin() || $user->isPosAdmin()) {
            return true;
        }

        $roles = self::permissions($profile)[$action] ?? [];

        return in_array($user->pos_role ?? null, $roles, true);
    }

    public static function requiredFields(array $profile): array
    {
        return array_values($profile['required_fields'] ?? []);
    }

    /** Resolve the explicit per-action contract, preserving profile overrides. */
    public static function permissions(array $profile): array
    {
        return array_replace(self::DEFAULT_PERMISSIONS, $profile['permissions'] ?? []);
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

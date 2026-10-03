<?php

namespace App\Domain\Saas;

use App\Models\Feature;

/**
 * Catalogue of plan-controllable modules (boolean) and usage limits (limit).
 * Code-defined; `features:sync` mirrors it into the features table so plans
 * and overrides can reference keys with foreign keys.
 */
final class FeatureRegistry
{
    // Modules
    public const CALENDAR = 'calendar';

    public const CLIENTS = 'clients';

    public const CLIENT_PORTAL = 'client_portal';

    public const PUBLIC_BOOKING = 'public_booking';

    public const FORMS = 'forms';

    public const DOCUMENTS = 'documents';

    public const MESSAGING = 'messaging';

    public const TASKS = 'tasks';

    public const CLINICAL = 'clinical';

    public const BILLING = 'billing';

    public const INSURANCE = 'insurance';

    public const PROGRAMS = 'programs';

    public const LEVELS_OF_CARE = 'levels_of_care';

    public const GROUPS = 'groups';

    public const TELEHEALTH = 'telehealth';

    public const AI = 'ai';

    public const ADVANCED_REPORTING = 'advanced_reporting';

    public const INTEGRATIONS = 'integrations';

    // Limits (NULL = unlimited)
    public const MAX_STAFF = 'max_staff';

    public const MAX_ACTIVE_CLIENTS = 'max_active_clients';

    public const MAX_LOCATIONS = 'max_locations';

    public const MAX_PROGRAMS = 'max_programs';

    public const STORAGE_GB = 'storage_gb';

    public const TELEHEALTH_MINUTES = 'telehealth_minutes_monthly';

    /** @return list<array{key: string, name: string, type: string, unit: ?string, description: string}> */
    public static function definitions(): array
    {
        $b = Feature::TYPE_BOOLEAN;
        $l = Feature::TYPE_LIMIT;

        return [
            ['key' => self::CALENDAR, 'name' => 'Calendar & scheduling', 'type' => $b, 'unit' => null, 'description' => 'Calendar, availability, appointments.'],
            ['key' => self::CLIENTS, 'name' => 'Client management', 'type' => $b, 'unit' => null, 'description' => 'Client records, contacts and timeline.'],
            ['key' => self::CLIENT_PORTAL, 'name' => 'Client portal', 'type' => $b, 'unit' => null, 'description' => 'Clients sign in to see appointments, forms and documents.'],
            ['key' => self::PUBLIC_BOOKING, 'name' => 'Public booking', 'type' => $b, 'unit' => null, 'description' => 'Online booking and inquiries from the public website.'],
            ['key' => self::FORMS, 'name' => 'Forms', 'type' => $b, 'unit' => null, 'description' => 'Intake, consent and assessment forms.'],
            ['key' => self::DOCUMENTS, 'name' => 'Documents', 'type' => $b, 'unit' => null, 'description' => 'Document storage, versioning and signatures.'],
            ['key' => self::MESSAGING, 'name' => 'Secure messaging', 'type' => $b, 'unit' => null, 'description' => 'Staff and client messaging.'],
            ['key' => self::TASKS, 'name' => 'Tasks', 'type' => $b, 'unit' => null, 'description' => 'Assignable tasks and follow-ups.'],
            ['key' => self::CLINICAL, 'name' => 'Clinical records', 'type' => $b, 'unit' => null, 'description' => 'Notes, assessments, treatment plans.'],
            ['key' => self::BILLING, 'name' => 'Billing', 'type' => $b, 'unit' => null, 'description' => 'Invoices, payments, statements.'],
            ['key' => self::INSURANCE, 'name' => 'Insurance & claims', 'type' => $b, 'unit' => null, 'description' => 'Payers, policies, claims, remittance.'],
            ['key' => self::PROGRAMS, 'name' => 'Programs', 'type' => $b, 'unit' => null, 'description' => 'Programs, enrollment, admissions and discharge.'],
            ['key' => self::LEVELS_OF_CARE, 'name' => 'Levels of care', 'type' => $b, 'unit' => null, 'description' => 'Configurable levels of care and transitions.'],
            ['key' => self::GROUPS, 'name' => 'Groups & activities', 'type' => $b, 'unit' => null, 'description' => 'Group sessions, activities and attendance.'],
            ['key' => self::TELEHEALTH, 'name' => 'Telehealth', 'type' => $b, 'unit' => null, 'description' => 'Video sessions linked to appointments.'],
            ['key' => self::AI, 'name' => 'AI assistance', 'type' => $b, 'unit' => null, 'description' => 'Draft notes and summaries for clinician review.'],
            ['key' => self::ADVANCED_REPORTING, 'name' => 'Advanced reporting', 'type' => $b, 'unit' => null, 'description' => 'Saved, scheduled and exported reports.'],
            ['key' => self::INTEGRATIONS, 'name' => 'Integrations', 'type' => $b, 'unit' => null, 'description' => 'Third-party integrations and API access.'],
            ['key' => self::MAX_STAFF, 'name' => 'Staff seats', 'type' => $l, 'unit' => 'staff', 'description' => 'Active and invited staff members.'],
            ['key' => self::MAX_ACTIVE_CLIENTS, 'name' => 'Active clients', 'type' => $l, 'unit' => 'clients', 'description' => 'Active live clients (demo clients never count).'],
            ['key' => self::MAX_LOCATIONS, 'name' => 'Locations', 'type' => $l, 'unit' => 'locations', 'description' => 'Active locations.'],
            ['key' => self::MAX_PROGRAMS, 'name' => 'Programs', 'type' => $l, 'unit' => 'programs', 'description' => 'Active programs.'],
            ['key' => self::STORAGE_GB, 'name' => 'Storage', 'type' => $l, 'unit' => 'GB', 'description' => 'Document storage.'],
            ['key' => self::TELEHEALTH_MINUTES, 'name' => 'Telehealth minutes', 'type' => $l, 'unit' => 'minutes/month', 'description' => 'Video minutes per month.'],
        ];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_column(self::definitions(), 'key');
    }

    /** @return array<string, string> key => name, boolean modules only */
    public static function booleanOptions(): array
    {
        $options = [];
        foreach (self::definitions() as $definition) {
            if ($definition['type'] === Feature::TYPE_BOOLEAN) {
                $options[$definition['key']] = $definition['name'];
            }
        }

        return $options;
    }

    public static function isLimit(string $key): bool
    {
        foreach (self::definitions() as $definition) {
            if ($definition['key'] === $key) {
                return $definition['type'] === Feature::TYPE_LIMIT;
            }
        }

        throw new \InvalidArgumentException("Unknown feature [{$key}].");
    }

    /** Seed plans: key => [name, price_minor (GHS pesewas), features..., limits...]. */
    public static function defaultPlans(): array
    {
        $core = [self::CALENDAR, self::CLIENTS, self::DOCUMENTS, self::FORMS, self::TASKS, self::MESSAGING];

        return [
            'starter' => [
                'name' => 'Starter', 'price_minor' => 25000, 'sort' => 1, 'trial_days' => 14,
                'description' => 'For solo practitioners.',
                'features' => [...$core, self::CLIENT_PORTAL],
                'limits' => [self::MAX_STAFF => 3, self::MAX_ACTIVE_CLIENTS => 150, self::MAX_LOCATIONS => 1, self::MAX_PROGRAMS => 0, self::STORAGE_GB => 5, self::TELEHEALTH_MINUTES => 0],
            ],
            'professional' => [
                'name' => 'Professional', 'price_minor' => 60000, 'sort' => 2, 'trial_days' => 14,
                'description' => 'For group practices.',
                'features' => [...$core, self::CLIENT_PORTAL, self::PUBLIC_BOOKING, self::CLINICAL, self::BILLING, self::TELEHEALTH],
                'limits' => [self::MAX_STAFF => 15, self::MAX_ACTIVE_CLIENTS => 1000, self::MAX_LOCATIONS => 3, self::MAX_PROGRAMS => 2, self::STORAGE_GB => 50, self::TELEHEALTH_MINUTES => 3000],
            ],
            'advanced' => [
                'name' => 'Advanced', 'price_minor' => 150000, 'sort' => 3, 'trial_days' => 14,
                'description' => 'For programs and multi-site organizations.',
                'features' => [...$core, self::CLIENT_PORTAL, self::PUBLIC_BOOKING, self::CLINICAL, self::BILLING, self::INSURANCE, self::TELEHEALTH,
                    self::PROGRAMS, self::LEVELS_OF_CARE, self::GROUPS, self::ADVANCED_REPORTING],
                'limits' => [self::MAX_STAFF => 50, self::MAX_ACTIVE_CLIENTS => 5000, self::MAX_LOCATIONS => 10, self::MAX_PROGRAMS => 20, self::STORAGE_GB => 250, self::TELEHEALTH_MINUTES => 15000],
            ],
            'enterprise' => [
                'name' => 'Enterprise', 'price_minor' => 0, 'sort' => 4, 'trial_days' => 0, 'is_public' => false,
                'description' => 'Custom contract. Everything, unlimited.',
                'features' => self::keys(),
                'limits' => [],
            ],
        ];
    }
}

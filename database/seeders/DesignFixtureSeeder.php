<?php

namespace Database\Seeders;

use App\Domain\Clients\ClientStatus;
use App\Domain\Identity\MembershipStatus;
use App\Domain\Platform\CreateOrganization;
use App\Domain\Platform\OrganizationStatus;
use App\Domain\Scheduling\AppointmentStatus;
use App\Domain\Scheduling\Modality;
use App\Domain\Scheduling\ScheduleAppointment;
use App\Domain\Scheduling\ScheduleAppointmentData;
use App\Domain\Scheduling\TransitionAppointment;
use App\Domain\Settings\SettingsService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Client;
use App\Models\Location;
use App\Models\OrganizationMembership;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * LOCAL DESIGN VERIFICATION ONLY. Recreates the people, places and the
 * 27 Apr – 3 May 2025 week shown in docs/design/comps so rendered screens can
 * be diffed against the comps (run the app with APP_FAKE_NOW="2025-04-28 08:30:00").
 *
 *   createdb -O ehr_app ehr_design
 *   DB_DATABASE=ehr_design php artisan migrate:fresh --seed --seeder=DesignFixtureSeeder
 *
 * Sign in as sarah@wellnest.test / password-1234.
 */
class DesignFixtureSeeder extends Seeder
{
    private const TZ = 'Africa/Accra';

    public function run(TenantContext $tenant): void
    {
        if (! app()->isLocal() && ! app()->runningUnitTests()) {
            throw new RuntimeException('DesignFixtureSeeder is for local design verification only.');
        }

        $this->call(CatalogueSeeder::class);
        $password = Hash::make('password-1234');

        $sarah = $this->user('Sarah Carter', 'sarah@wellnest.test', $password);
        $created = app(CreateOrganization::class)(
            profile: [
                'name' => 'WellNest Therapy Center', 'slug' => 'wellnest', 'country_code' => 'GH',
                'timezone' => self::TZ, 'currency' => 'GHS', 'email' => 'info@wellnest.org', 'phone' => '+233241234567',
            ],
            plan: Plan::query()->where('key', 'advanced')->firstOrFail(),
            status: OrganizationStatus::Active,
            owner: $sarah,
        );
        $organization = $created->organization;
        $organization->forceFill([
            'tagline' => 'Mental Health & Wellness',
            'description' => 'We provide compassionate, evidence-based mental health care for individuals, families, and communities.',
            'website' => 'www.wellnest.org',
            'address_line1' => '123 Wellness Avenue', 'city' => 'Accra',
        ])->save();

        $tenant->runAs($organization, function () use ($organization, $created, $sarah, $password) {
            // Screens in the comps show "Apr 28, 2025" and "10:00 AM".
            app(SettingsService::class)->seedOrganization($organization, [
                'branding.primary_color' => '#2563EB', 'branding.secondary_color' => '#93C5FD',
                'general.date_format' => 'M j, Y', 'general.time_format' => 'g:i A',
                // Comp 01: weeks start on Sunday; the grid shows 8 AM – 5 PM rows (until 18:00).
                'general.week_starts_on' => '7',
                'scheduling.calendar_day_start' => '08:00', 'scheduling.calendar_day_end' => '18:00',
            ]);

            $owner = $created->ownerMembership;
            $owner->forceFill(['name_prefix' => 'Dr.', 'title' => 'Clinical Psychologist', 'color' => '#307EF6'])->save();

            // Calendar colour follows the clinician (blue, green, orange, teal); telehealth renders purple.
            $clinicians = ['sarah' => $owner];
            foreach ([
                'james' => ['James Allen', 'james.allen@wellnest.test', '#16B482', 'Counsellor'],
                'lisa' => ['Lisa Morgan', 'lisa.morgan@wellnest.test', '#F59E0B', 'Psychiatrist'],
                'emily' => ['Emily Johnson', 'emily.johnson@wellnest.test', '#14B8A6', 'Clinical Social Worker'],
            ] as $key => [$name, $email, $color, $title]) {
                $membership = new OrganizationMembership(['name_prefix' => 'Dr.', 'title' => $title, 'color' => $color, 'is_provider' => true]);
                $membership->forceFill(['user_id' => $this->user($name, $email, $password)->id, 'status' => MembershipStatus::Active, 'joined_at' => now()])->save();
                $membership->roles()->attach(Role::query()->forOrganization($organization->id)->where('key', 'clinician')->value('id'));
                $clinicians[$key] = $membership;
            }

            $locations = [];
            foreach (['Accra' => '123 Wellness Avenue', 'Kumasi' => '18 Prempeh II Street'] as $name => $street) {
                $location = new Location(['name' => $name, 'address_line1' => $street, 'city' => $name, 'country_code' => 'GH', 'timezone' => self::TZ]);
                $location->forceFill(['is_active' => true])->save();
                $locations[$name] = $location;
            }

            $services = [];
            foreach ([
                'Therapy Session' => [60, 30000], 'Follow-up Consultation' => [30, 20000],
                'Initial Assessment' => [60, 45000], 'Progress Review' => [45, 25000],
            ] as $name => [$minutes, $price]) {
                $service = new Service(['name' => $name, 'duration_minutes' => $minutes, 'allows_in_person' => true, 'allows_telehealth' => true, 'is_bookable_online' => true]);
                $service->forceFill(['price_minor' => $price, 'currency' => 'GHS', 'is_active' => true])->save();
                $service->providers()->attach(array_map(fn ($m) => $m->id, array_values($clinicians)));
                $service->locations()->attach(array_map(fn ($l) => $l->id, array_values($locations)));
                $services[$name] = $service;
            }

            // Weekday availability for every clinician: the comps show a fully set-up practice
            // (no onboarding checklist).
            foreach ($clinicians as $index => $membership) {
                foreach ([1, 2, 3, 4, 5] as $weekday) {
                    $rule = new \App\Models\AvailabilityRule([
                        'membership_id' => $membership->id,
                        'location_id' => $locations[$index === 'james' ? 'Kumasi' : 'Accra']->id,
                        'weekday' => $weekday, 'start_time' => '08:00', 'end_time' => '18:00',
                        'modality' => 'any', 'effective_from' => '2025-01-06',
                    ]);
                    $rule->save();
                }
            }
            app(\App\Domain\Organization\RefreshOnboardingStatus::class)($organization);

            $clients = $this->clients($owner);
            $this->appointments($clients, $services, $clinicians, $locations, $sarah);
        });

        $this->command?->info('Design fixture ready: sarah@wellnest.test / password-1234 — organization "wellnest". Serve with APP_FAKE_NOW="2025-04-28 08:30:00".');
    }

    private function user(string $name, string $email, string $password): User
    {
        $user = User::query()->create(['name' => $name, 'email' => $email, 'password' => $password]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    /** @return array<string, Client> */
    private function clients(OrganizationMembership $primary): array
    {
        $make = function (int $number, string $first, string $last, ClientStatus $status, ?string $phone = null) use ($primary) {
            $client = Client::factory()->make([
                'first_name' => $first, 'last_name' => $last,
                'email' => strtolower($first).'@example.com',
                'phone' => $phone ?? '+23324'.str_pad((string) (1000000 + $number * 7919 % 9000000), 7, '0', STR_PAD_LEFT),
                'primary_clinician_membership_id' => $primary->id,
            ]);
            $client->forceFill(['client_number' => $number, 'status' => $status])->save();

            return $client;
        };

        // CL-0001…0011 were archived long ago, so the default list starts at CL-0012 (as in the comp).
        foreach (range(1, 11) as $n) {
            $make($n, fake()->firstName(), fake()->lastName(), ClientStatus::Archived);
        }

        $named = [
            12 => ['Emily', 'Johnson', ClientStatus::Active, '+233241234567'],
            13 => ['Michael', 'Brown', ClientStatus::Active, '+233559876543'],
            14 => ['Sophia', 'Davis', ClientStatus::Active, '+233201112233'],
            15 => ['James', 'Wilson', ClientStatus::Pending, '+233264567890'],
            16 => ['Olivia', 'Martinez', ClientStatus::Active, '+233245556677'],
            17 => ['Daniel', 'Thomas', ClientStatus::Inactive, '+233578889999'],
            18 => ['Grace', 'Lee', ClientStatus::Active, '+233503332211'],
            19 => ['Matthew', 'Scott', ClientStatus::Active, '+233277771144'],
            20 => ['Liam', 'Taylor', ClientStatus::Active, null],
            21 => ['Sarah', 'Wilson', ClientStatus::Active, null],
            22 => ['Isabella', 'Martin', ClientStatus::Active, null],
            23 => ['Mason', 'White', ClientStatus::Active, null],
            24 => ['Ava', 'Thomas', ClientStatus::Active, null],
            25 => ['Emma', 'Garcia', ClientStatus::Active, null],
            26 => ['Ethan', 'Clark', ClientStatus::Active, null],
            27 => ['Noah', 'Harris', ClientStatus::Active, null],
            28 => ['Emma', 'Wilson', ClientStatus::Active, null],
        ];
        $clients = [];
        foreach ($named as $n => [$first, $last, $status, $phone]) {
            $clients["{$first} {$last}"] = $make($n, $first, $last, $status, $phone);
        }

        // 48 non-archived clients in total: 42 active, 1 pending, 5 inactive (the Clients comp's stat cards).
        foreach (range(29, 59) as $n) {
            $make($n, fake()->firstName(), fake()->lastName(), $n <= 55 ? ClientStatus::Active : ClientStatus::Inactive);
        }

        // Registration dates relative to the comps' "today" (28 Apr 2025): 6 new in the last
        // 30 days and 4 in the 30 before ("New Clients (30 days) 6 ↑ 50%"), the rest older.
        $today = CarbonImmutable::parse('2025-04-28 08:00', self::TZ);
        Client::query()->orderBy('client_number')->get()->each(function (Client $client) use ($today) {
            $n = $client->client_number;
            $daysAgo = match (true) {
                $n >= 54 => 3 + ($n - 54) * 4,     // 6 clients: 3–23 days ago
                $n >= 50 => 35 + ($n - 50) * 5,    // 4 clients: 35–50 days ago
                default => 90 + (53 - $n) * 6,     // older
            };
            $client->forceFill(['created_at' => $today->subDays($daysAgo), 'updated_at' => $today->subDays($daysAgo)])->saveQuietly();
        });

        return $clients;
    }

    /**
     * @param  array<string, Client>  $clients
     * @param  array<string, Service>  $services
     * @param  array<string, OrganizationMembership>  $clinicians
     * @param  array<string, Location>  $locations
     */
    private function appointments(array $clients, array $services, array $clinicians, array $locations, User $actor): void
    {
        $book = app(ScheduleAppointment::class);
        $transition = app(TransitionAppointment::class);

        // [date, time, client, service, clinician, location or Online, status]
        $week = [
            ['2025-04-28', '09:00', 'Emily Johnson', 'Therapy Session', 'sarah', 'Accra', AppointmentStatus::Confirmed],
            ['2025-04-28', '11:00', 'Michael Brown', 'Follow-up Consultation', 'james', 'Kumasi', AppointmentStatus::Confirmed],
            ['2025-04-28', '14:00', 'Sophia Davis', 'Initial Assessment', 'sarah', 'Online', AppointmentStatus::Scheduled],
            ['2025-04-28', '16:00', 'James Wilson', 'Therapy Session', 'lisa', 'Accra', AppointmentStatus::Confirmed],
            ['2025-04-28', '17:30', 'Daniel Thomas', 'Therapy Session', 'james', 'Online', AppointmentStatus::Scheduled],
            ['2025-04-29', '10:00', 'Olivia Martinez', 'Progress Review', 'emily', 'Accra', AppointmentStatus::Confirmed],
            ['2025-04-29', '13:00', 'Daniel Thomas', 'Therapy Session', 'james', 'Online', AppointmentStatus::Scheduled],
            ['2025-04-29', '15:00', 'Grace Lee', 'Follow-up Consultation', 'james', 'Kumasi', AppointmentStatus::Scheduled],
            ['2025-04-30', '09:30', 'Matthew Scott', 'Therapy Session', 'lisa', 'Accra', AppointmentStatus::Scheduled],
            ['2025-04-30', '12:00', 'Sarah Wilson', 'Initial Assessment', 'sarah', 'Kumasi', AppointmentStatus::Scheduled],
            ['2025-04-30', '15:30', 'Ethan Clark', 'Therapy Session', 'lisa', 'Online', AppointmentStatus::Scheduled],
            ['2025-05-01', '10:00', 'Liam Taylor', 'Follow-up Consultation', 'james', 'Accra', AppointmentStatus::Scheduled],
            ['2025-05-01', '13:30', 'Ava Thomas', 'Progress Review', 'sarah', 'Kumasi', AppointmentStatus::Scheduled],
            ['2025-05-01', '16:00', 'Noah Harris', 'Therapy Session', 'lisa', 'Accra', AppointmentStatus::Scheduled],
            ['2025-05-02', '09:00', 'Isabella Martin', 'Initial Assessment', 'sarah', 'Online', AppointmentStatus::Scheduled],
            ['2025-05-02', '11:30', 'Mason White', 'Follow-up Consultation', 'james', 'Accra', AppointmentStatus::Scheduled],
            ['2025-05-02', '14:30', 'Emma Garcia', 'Therapy Session', 'sarah', 'Kumasi', AppointmentStatus::Scheduled],
            // Earlier April visits (mini-calendar dots, "Last visit" column).
            ['2025-04-03', '10:00', 'Emily Johnson', 'Therapy Session', 'sarah', 'Accra', AppointmentStatus::Completed],
            ['2025-04-09', '11:00', 'Michael Brown', 'Follow-up Consultation', 'james', 'Kumasi', AppointmentStatus::Completed],
            ['2025-04-17', '14:00', 'Grace Lee', 'Therapy Session', 'sarah', 'Accra', AppointmentStatus::Completed],
        ];

        foreach ($week as [$date, $time, $client, $service, $clinician, $where, $status]) {
            $online = $where === 'Online';
            $appointment = $book(new ScheduleAppointmentData(
                client: $clients[$client],
                service: $services[$service],
                clinician: $clinicians[$clinician],
                modality: $online ? Modality::Telehealth : Modality::InPerson,
                startsAt: CarbonImmutable::parse("{$date} {$time}", self::TZ),
                location: $online ? null : $locations[$where],
                actor: $actor,
            ));

            if ($status === AppointmentStatus::Confirmed) {
                $transition($appointment, AppointmentStatus::Confirmed, $actor);
            } elseif ($status === AppointmentStatus::Completed) {
                // Past visits: stamped directly (the state machine's clock check
                // would refuse completing them "now" under a pinned clock).
                $appointment->forceFill(['status' => AppointmentStatus::Completed, 'completed_at' => $appointment->ends_at])->save();
            }
        }
    }
}

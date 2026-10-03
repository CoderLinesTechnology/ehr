<?php

namespace Database\Seeders;

use App\Domain\Clients\ClientContactPoints;
use App\Domain\Clients\ClientStatus;
use App\Domain\Clients\CoupleMembers;
use App\Domain\Clients\SaveClientContact;
use App\Domain\Identity\MembershipStatus;
use App\Domain\Messaging\ConversationKind;
use App\Domain\Organization\RefreshOnboardingStatus;
use App\Domain\Platform\ChangeOrganizationStatus;
use App\Domain\Platform\CreateOrganization;
use App\Domain\Platform\OrganizationCounters;
use App\Domain\Platform\OrganizationStatus;
use App\Domain\Programs\EnrollmentEventType;
use App\Domain\Programs\EnrollmentStatus;
use App\Domain\Scheduling\AppointmentStatus;
use App\Domain\Scheduling\Modality;
use App\Domain\Scheduling\ScheduleAppointment;
use App\Domain\Scheduling\ScheduleAppointmentData;
use App\Domain\Scheduling\TransitionAppointment;
use App\Domain\Settings\SettingsService;
use App\Domain\Shared\RecordEnvironment;
use App\Domain\Telehealth\AddTranscript;
use App\Domain\Telehealth\AttachRecording;
use App\Domain\Telehealth\Daily\FakeDailyClient;
use App\Domain\Telehealth\EndSession;
use App\Domain\Telehealth\OpenSession;
use App\Domain\Telehealth\PrepareRoom;
use App\Domain\Telehealth\RecordConsent;
use App\Domain\Telehealth\SaveSessionNotes;
use App\Domain\Telehealth\TelehealthSettings;
use App\Domain\Telehealth\TranscriptSource;
use App\Domain\Tenancy\TenantContext;
use App\Models\AvailabilityRule;
use App\Models\Client;
use App\Models\ClientContactPoint;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\LevelOfCare;
use App\Models\Location;
use App\Models\Message;
use App\Models\MessageReaction;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Plan;
use App\Models\Program;
use App\Models\ProgramEnrollment;
use App\Models\ProgramEnrollmentEvent;
use App\Models\ProgramSession;
use App\Models\ProgramSessionAttendance;
use App\Models\ProgramStaff;
use App\Models\Role;
use App\Models\Service;
use App\Models\TelehealthSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
                    $rule = new AvailabilityRule([
                        'membership_id' => $membership->id,
                        'location_id' => $locations[$index === 'james' ? 'Kumasi' : 'Accra']->id,
                        'weekday' => $weekday, 'start_time' => '08:00', 'end_time' => '18:00',
                        'modality' => 'any', 'effective_from' => '2025-01-06',
                    ]);
                    $rule->save();
                }
            }
            app(RefreshOnboardingStatus::class)($organization);

            $clients = $this->clients($owner);
            $this->appointments($clients, $services, $clinicians, $locations, $sarah);
            $this->conversations($organization, $owner, $clinicians, $clients, $password);
            $this->telehealth($organization, $clients, $services, $clinicians, $locations, $sarah);
        });

        $tenant->runAs($created->organization, fn () => $this->resources($created->organization));
        $tenant->runAs($created->organization, fn () => $this->programs());
        app(SettingsService::class)->setPlatform(['platform.support_email' => 'support@wellnest.org'], null);

        $this->platformConsole($password, $sarah);

        $this->command?->info('Design fixture ready: sarah@wellnest.test / password-1234 — organization "wellnest". Serve with APP_FAKE_NOW="2025-04-28 08:30:00".');
    }

    /**
     * Super Admin console fixture: a super admin and a support user (fixed TOTP secret JBSWY3DPEHPK3PXP,
     * so a screenshot tool can sign in) and a few more organizations so the lists are not trivial.
     */
    private function platformConsole(string $password, User $sarah): void
    {
        $admin = $this->user('Naa Adjei', 'admin@wellnest.test', $password);
        $support = $this->user('Kofi Mensah', 'support@wellnest.test', $password);
        foreach ([[$admin, 'super_admin'], [$support, 'platform_support']] as [$user, $key]) {
            $user->forceFill([
                'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
                'two_factor_recovery_codes' => encrypt(json_encode(['fixture-aaaaa', 'fixture-bbbbb'])),
                'two_factor_confirmed_at' => now(),
                'last_login_at' => now()->subHours(3),
            ])->save();
            $user->platformRoles()->attach(Role::query()->platform()->where('key', $key)->value('id'), [
                'scope' => 'platform', 'granted_at' => now()->subDays(20), 'granted_by_user_id' => $key === 'super_admin' ? null : $admin->id,
            ]);
        }

        $create = app(CreateOrganization::class);
        foreach ([
            ['Kumasi Mind Clinic', 'kumasi-mind', 'GH', 'Africa/Accra', 'GHS', 'hello@kumasimind.test', 'Efua Boateng', 'efua@kumasimind.test', 'starter', OrganizationStatus::Trial],
            ['Lagos Family Practice', 'lagos-family', 'NG', 'Africa/Lagos', 'NGN', 'care@lagosfamily.test', 'Tunde Bakare', 'tunde@lagosfamily.test', 'professional', OrganizationStatus::Active],
            ['Nairobi Wellness Hub', 'nairobi-wellness', 'KE', 'Africa/Nairobi', 'KES', 'info@nairobiwellness.test', 'Wanjiru Kamau', 'wanjiru@nairobiwellness.test', 'professional', OrganizationStatus::Active],
        ] as [$name, $slug, $country, $zone, $currency, $email, $ownerName, $ownerEmail, $plan, $status]) {
            $owner = $this->user($ownerName, $ownerEmail, $password);
            $owner->forceFill(['last_login_at' => now()->subDays(2)])->save();
            $create(
                profile: ['name' => $name, 'slug' => $slug, 'country_code' => $country, 'timezone' => $zone, 'currency' => $currency, 'email' => $email],
                plan: Plan::query()->where('key', $plan)->firstOrFail(),
                status: $status,
                owner: $owner,
                actor: $admin,
            );
        }

        // One organization that was suspended, so the status history and the restore action show.
        $lagos = Organization::query()->where('slug', 'lagos-family')->firstOrFail();
        app(ChangeOrganizationStatus::class)($lagos, OrganizationStatus::Suspended, $admin, 'Payment overdue for two billing cycles.');
        app(ChangeOrganizationStatus::class)($lagos, OrganizationStatus::Active, $admin, 'Payment received.');

        $this->command?->info('Console fixture: admin@wellnest.test / password-1234, TOTP secret JBSWY3DPEHPK3PXP.');
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

        $this->clientModel($clients);

        return $clients;
    }

    /**
     * The client model beyond the comp (user-requested, spec 10 "Decisions"): every e-mail/phone as a contact
     * point (a few clients have more than one), about a third on insurance, the telehealth comp's six clients
     * virtual, a minor with a parent on file, partner and emergency contacts, and one couple linking two
     * existing clients. The couple record REPLACES an anonymous filler client (CL-0044, not in any program), so every count on the
     * comps (48 clients, 42 active, ...) stays as it was.
     *
     * @param  array<string, Client>  $clients
     */
    private function clientModel(array $clients): void
    {
        $points = app(ClientContactPoints::class);
        $extra = [
            'Emily Johnson' => ['email' => [['emily.johnson@workmail.example.com', 'work']], 'phone' => [['+233302123456', 'home']]],
            'Michael Brown' => ['phone' => [['+233208765432', 'work']]],
            'Grace Lee' => ['email' => [['grace.lee@workmail.example.com', 'work']]],
        ];
        $virtual = ['Emily Johnson', 'Michael Brown', 'Sophia Davis', 'James Wilson', 'Olivia Martinez', 'Daniel Thomas'];

        foreach (Client::query()->orderBy('client_number')->get() as $client) {
            $name = $client->first_name.' '.$client->last_name;
            $current = $points->effective($client);
            $wanted = [];
            foreach (['email', 'phone'] as $kind) {
                $wanted[$kind] = $current[$kind];
                foreach ($extra[$name][$kind] ?? [] as [$value, $label]) {
                    $wanted[$kind][] = ['value' => $value, 'label' => $label, 'is_primary' => false];
                }
            }
            $points->sync($client, $wanted, []);

            $client->forceFill([
                'billing_type' => $client->client_number % 3 === 0 ? 'insurance' : 'self_pay',
                'is_virtual' => in_array($name, $virtual, true),
            ])->saveQuietly();
        }

        // A minor (13 on the comps' "today") with a parent on file who is also the emergency contact.
        $matthew = $clients['Matthew Scott'];
        $matthew->forceFill(['client_type' => 'minor', 'date_of_birth' => '2011-06-14'])->saveQuietly();
        $save = app(SaveClientContact::class);
        $save($matthew, ['name' => 'Rachel Scott', 'relationship' => 'Mother', 'relationship_type' => 'parent', 'phone' => '+233244567123', 'email' => 'rachel.scott@example.com', 'is_emergency_contact' => true]);
        $save($clients['Sophia Davis'], ['name' => 'Mark Davis', 'relationship' => 'Brother', 'relationship_type' => 'sibling', 'phone' => '+233241119988', 'is_emergency_contact' => true]);
        $save($clients['Olivia Martinez'], ['name' => 'Carlos Martinez', 'relationship' => 'Husband', 'relationship_type' => 'spouse', 'phone' => '+233245550001']);
        $save($clients['James Wilson'], ['name' => 'Linda Wilson', 'relationship' => 'Mother', 'relationship_type' => 'parent', 'phone' => '+233264500011', 'is_emergency_contact' => true]);

        // One couple of two existing clients, in place of filler CL-0044 (same number, status and registration date).
        $couple = Client::query()->where('client_number', 44)->firstOrFail();
        $emily = $clients['Emily Johnson'];
        $michael = $clients['Michael Brown'];
        $couple->forceFill(CoupleMembers::name($emily->first_name, $emily->last_name, $michael->first_name, $michael->last_name) + [
            'client_type' => 'couple', 'date_of_birth' => null, 'email' => null, 'phone' => null, 'billing_type' => 'self_pay', 'is_virtual' => true,
        ])->saveQuietly();
        ClientContactPoint::query()->where('client_id', $couple->id)->delete();
        $members = app(CoupleMembers::class);
        $members->link($couple, $emily);
        $members->link($couple, $michael);

        // The fixture numbers its clients itself: the next one registered in the app continues after them.
        DB::table('organization_counters')->updateOrInsert(
            ['organization_id' => $couple->organization_id, 'key' => OrganizationCounters::CLIENT],
            ['value' => (int) Client::query()->max('client_number')],
        );
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

    /**
     * Comp 09: four featured resources and the six latest, with real (tiny) PDFs written by hand for the PDF ones.
     * Written directly (the fixture has no signed-in manager); the app's own writer is SaveResource.
     */
    private function resources(Organization $organization): void
    {
        $guide = fn (string ...$p) => implode("\n\n", $p);
        $rows = [
            // [type, title, summary, body, minutes, published, featured, pdf, url]
            ['guide', 'New Client Guide', 'Step-by-step guide to getting started with your care.', $guide(
                'Welcome to WellNest. This guide walks you through your first weeks with us, from booking to your first session.',
                'Before your first appointment, complete the intake form and read how we protect your information.',
                'Arrive a few minutes early for an in-person visit, or open your telehealth link five minutes before the start time.',
            ), 5, '2025-04-20 09:00', true, true, null],
            ['form', 'Intake Form', 'Complete your intake information before your first appointment.', $guide(
                'Please have your contact details, emergency contact and any current medication to hand.',
                'Your answers are shared only with the clinician who will see you.',
            ), 10, '2025-04-19 09:00', true, false, null],
            ['video', 'Telehealth Guide', 'Learn how to join your telehealth sessions.', null, 3, '2025-04-18 09:00', true, false, 'https://example.org/wellnest/telehealth-guide'],
            ['document', 'Privacy & Consent', 'Understand how your information is protected.', $guide(
                'This document explains what we collect, why we collect it, who can see it and how long we keep it.',
            ), 4, '2025-04-17 09:00', true, true, null],
            ['guide', 'Therapy Session Expectations', 'What to expect during your therapy sessions.', $guide(
                'A session lasts about fifty minutes. Your clinician will start by asking how you have been since the last visit.',
                'You decide how much you share. You can pause, ask questions or stop at any time.',
            ), 5, '2025-04-16 09:00', false, false, null],
            ['form', 'Client Registration Form', 'Complete this form to create your client account.', $guide('Give us your name, date of birth and how you prefer to be contacted.'), 10, '2025-04-15 09:00', false, false, null],
            ['video', 'Managing Anxiety', 'Helpful tips for managing anxiety in daily life.', null, 8, '2025-04-14 09:00', false, false, 'https://example.org/wellnest/managing-anxiety'],
            ['document', 'Billing and Insurance Information', 'Learn about payment options and insurance coverage.', $guide('Fees are due at the time of the visit unless your insurer has agreed to pay us directly.'), 6, '2025-04-13 09:00', false, true, null],
            ['guide', 'Mindfulness Exercises', 'Simple exercises to help you feel more present.', $guide('Try one minute of slow breathing: in for four counts, hold for four, out for six.'), 7, '2025-04-12 09:00', false, false, null],
            ['form', 'Emergency Contact Form', 'Keep your emergency contact information up to date.', $guide('Tell us who we may call in an emergency and how they are related to you.'), 5, '2025-04-11 09:00', false, false, null],
        ];

        foreach ($rows as [$type, $title, $summary, $body, $minutes, $published, $featured, $pdf, $url]) {
            $path = null;
            $size = null;
            if ($pdf) {
                $path = "resources/{$organization->id}/".strtolower(Str::random(32)).'.pdf';
                $bytes = $this->tinyPdf($title, (string) $body);
                Storage::disk('local')->put($path, $bytes);
                $size = strlen($bytes);
            }

            $resource = new \App\Models\Resource([
                'type' => $type, 'title' => $title, 'summary' => $summary, 'body' => $body, 'external_url' => $url,
                'reading_minutes' => $minutes, 'audience' => 'everyone',
            ]);
            $resource->forceFill([
                'status' => 'published', 'published_at' => CarbonImmutable::parse($published, 'UTC'), 'is_featured' => $featured,
                'file_path' => $path, 'file_size_bytes' => $size,
            ])->save();
        }
    }

    /** A valid one-page PDF written by hand (Helvetica text, correct xref): no library, no download. */
    private function tinyPdf(string $title, string $body): string
    {
        $esc = fn (string $t) => str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], preg_replace('/[^\x20-\x7e]/', ' ', $t));
        $lines = [];
        foreach (explode("\n", wordwrap($body, 78, "\n", true)) as $line) {
            $lines[] = $line;
        }
        $stream = "BT /F1 18 Tf 56 780 Td ({$esc($title)}) Tj ET\nBT /F1 11 Tf 56 750 Td 16 TL\n";
        foreach ($lines as $line) {
            $stream .= '('.$esc($line).") Tj T*\n";
        }
        $stream .= 'ET';

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
            '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n{$object}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf."trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }

    /**
     * The Messages comp (07): Sarah's inbox as of 28 Apr 2025 10:30. Clients cannot write until the portal exists, so the
     * people in the comp are seeded as colleagues (direct threads); one real client thread (Sarah's own message) is added.
     *
     * @param  array<string, OrganizationMembership>  $clinicians
     * @param  array<string, Client>  $clients
     */
    private function conversations(Organization $organization, OrganizationMembership $sarah, array $clinicians, array $clients, string $password): void
    {
        $role = Role::query()->forOrganization($organization->id)->where('key', 'clinician')->value('id');
        $people = ['Sarah Carter' => $sarah, 'James Allen' => $clinicians['james'], 'Lisa Morgan' => $clinicians['lisa'], 'Emily Johnson' => $clinicians['emily']];
        foreach (['Michael Brown', 'Sophia Davis', 'James Wilson', 'Olivia Martinez', 'Daniel Thomas', 'Grace Lee', 'Matthew Scott'] as $name) {
            $member = new OrganizationMembership(['is_provider' => false]);
            $user = $this->user($name, strtolower(str_replace(' ', '.', $name)).'.staff@wellnest.test', $password);
            $member->forceFill(['user_id' => $user->id, 'status' => MembershipStatus::Active, 'joined_at' => now()])->save();
            $member->roles()->attach($role);
            $people[$name] = $member;
        }

        $at = fn (string $time) => CarbonImmutable::parse($time, self::TZ)->utc();
        $online = ['Emily Johnson', 'Michael Brown', 'Sophia Davis', 'James Wilson', 'Olivia Martinez', 'Daniel Thomas'];
        foreach ($online as $name) {
            DB::table('users')->where('id', $people[$name]->user_id)->update(['last_seen_at' => $at('2025-04-28 10:29')->format('Y-m-d H:i:s.uP')]);
        }

        // [kind, title|other person, client, members, [[sender, time, body]...], [member => read-through time], reaction]
        $threads = [
            [ConversationKind::Direct, 'Emily Johnson', null, ['Emily Johnson'], [
                ['Emily Johnson', '2025-04-28 10:12', "Hi Sarah,\nI just wanted to say thank you for the session today.\nIt really helped me. I feel more focused already."],
                ['Sarah Carter', '2025-04-28 10:15', "That's wonderful to hear, Emily! 😊\nI'm glad you found it helpful. Remember, you can message me anytime if you have questions or need support."],
                ['Emily Johnson', '2025-04-28 10:18', "Also, I'm not sure if I already sent the form for next week.\nCould you check on that for me?"],
                ['Sarah Carter', '2025-04-28 10:21', "Yes, I've just sent it to your portal. You should see it now.\nLet me know if you don't receive it."],
                ['Emily Johnson', '2025-04-28 10:24', 'Perfect! Got it. Thank you so much!'],
            ], ['Sarah Carter' => '2025-04-28 10:21', 'Emily Johnson' => '2025-04-28 10:25'], ['Sarah Carter', 4, '👍']],
            [ConversationKind::Direct, 'Michael Brown', null, ['Michael Brown'], [
                ['Sarah Carter', '2025-04-28 09:40', 'Can you confirm Thursday at 2 PM works?'],
                ['Michael Brown', '2025-04-28 09:48', "Sounds good. I'll be there."],
            ], ['Sarah Carter' => '2025-04-28 09:40', 'Michael Brown' => '2025-04-28 09:41']],
            [ConversationKind::Group, 'Therapy Team', null, ['James Allen', 'Lisa Morgan'], [
                ['Sarah Carter', '2025-04-28 09:05', 'Morning all. Please add your notes before Friday.'],
                ['James Allen', '2025-04-28 09:20', 'Will do.'],
                ['James Allen', '2025-04-28 09:32', "I've uploaded the document."],
            ], ['Sarah Carter' => '2025-04-28 09:10', 'James Allen' => '2025-04-28 09:32', 'Lisa Morgan' => '2025-04-28 09:32']],
            [ConversationKind::Direct, 'Sophia Davis', null, ['Sophia Davis'], [['Sophia Davis', '2025-04-27 16:10', 'Can you send me the form again?']], ['Sarah Carter' => '2025-04-27 16:20']],
            [ConversationKind::Direct, 'James Wilson', null, ['James Wilson'], [['James Wilson', '2025-04-27 11:02', 'Great, thank you!']], ['Sarah Carter' => '2025-04-27 11:05']],
            [ConversationKind::Direct, 'Olivia Martinez', null, ['Olivia Martinez'], [['Olivia Martinez', '2025-04-27 08:15', 'No problem. Let me know if you need anything else.']], ['Sarah Carter' => '2025-04-27 08:20']],
            [ConversationKind::Direct, 'Daniel Thomas', null, ['Daniel Thomas'], [['Daniel Thomas', '2025-04-26 15:30', 'The report is ready for review.']], ['Sarah Carter' => '2025-04-26 15:40']],
            [ConversationKind::Direct, 'Grace Lee', null, ['Grace Lee'], [['Grace Lee', '2025-04-25 12:00', "I'll follow up with the client."]], ['Sarah Carter' => '2025-04-25 12:10']],
            [ConversationKind::Direct, 'Matthew Scott', null, ['Matthew Scott'], [['Matthew Scott', '2025-04-24 17:45', 'Available at 2 PM tomorrow.']], ['Sarah Carter' => '2025-04-24 17:50']],
            [ConversationKind::Group, 'Team Updates', null, ['Lisa Morgan', 'James Allen'], [['Lisa Morgan', '2025-04-22 09:00', 'Maintenance scheduled for Sunday at 2 AM.']], ['Sarah Carter' => '2025-04-22 09:30']],
            [ConversationKind::Client, null, 'Liam Taylor', [], [['Sarah Carter', '2025-04-21 14:00', 'Hi Liam, just confirming your appointment on Thursday.']], ['Sarah Carter' => '2025-04-21 14:00']],
        ];

        foreach ($threads as $thread) {
            [$kind, $title, $clientName, $members, $messages, $reads, $reaction] = array_pad($thread, 7, null);
            $client = $clientName !== null ? $clients[$clientName] : null;
            $everyone = array_values(array_unique(['Sarah Carter', ...$members]));
            $first = $at($messages[0][1])->subMinutes(5);

            $key = match ($kind) {
                ConversationKind::Direct => 'd:'.collect([$sarah->id, $people[$title]->id])->sort()->implode('|'),
                ConversationKind::Client => 'c:'.$client->id.'|'.$sarah->id,
                default => null,
            };
            $conversation = new Conversation;
            $conversation->forceFill([
                'kind' => $kind, 'title' => $kind === ConversationKind::Group ? $title : null, 'client_id' => $client?->id,
                'record_environment' => $client?->record_environment ?? RecordEnvironment::Live, 'unique_key' => $key,
                'created_by_membership_id' => $sarah->id, 'last_message_at' => $at(end($messages)[1]),
            ])->save();

            foreach ($everyone as $name) {
                $read = isset($reads[$name]) ? $at($reads[$name]) : null;
                (new ConversationParticipant)->forceFill([
                    'conversation_id' => $conversation->id, 'membership_id' => $people[$name]->id, 'joined_at' => $first, 'last_read_at' => $read,
                ])->save();
            }

            foreach ($messages as $i => [$sender, $time, $body]) {
                $message = new Message;
                $message->forceFill(['conversation_id' => $conversation->id, 'sender_membership_id' => $people[$sender]->id, 'body' => $body, 'created_at' => $at($time)])->save();
                if ($reaction !== null && $reaction[1] === $i) {
                    (new MessageReaction)->forceFill(['conversation_id' => $conversation->id, 'message_id' => $message->id, 'membership_id' => $people[$reaction[0]]->id, 'emoji' => $reaction[2]])->save();
                }
            }
        }
    }

    /**
     * Comp 03: six programs with the comp's texts, levels of care, 56 active participants (12/8/15/10/6/5), four
     * completed enrollments and the three sessions of the Upcoming Program Schedule (plus two past ones with
     * attendance). Written straight through the models, like the other fixtures.
     */
    private function programs(): void
    {
        $locations = Location::query()->pluck('id', 'name');
        $at = fn (string $when) => CarbonImmutable::parse($when, self::TZ)->utc();

        // [name, color, icon, status, starts, ends, place, sud, description, levels (first = the card's tag), participants]
        $rows = [
            ['Substance Use Recovery Program', 'green', 'sprout', 'active', '2025-01-15', null, 'Accra', true, 'Structured support for individuals recovering from substance use disorders.',
                ['Level I – Outpatient', 'Level II – Intensive Outpatient', 'Level III – Residential'], 12],
            ['Mental Health Wellness Program', 'purple', 'heart-pulse', 'active', '2025-02-01', null, 'Kumasi', false, 'Therapeutic support, life skills, and coping strategies for long-term wellness.',
                ['Level II – Intensive Outpatient', 'Level I – Outpatient'], 8],
            ['Teen Empowerment Program', 'blue', 'users', 'active', '2025-03-10', null, 'Accra', false, 'Building confidence, skills, and brighter futures for teens.',
                ['Level III – Residential', 'Level II – Intensive Outpatient'], 15],
            ['Life Skills Development', 'orange', 'sun', 'upcoming', '2025-05-05', '2025-07-30', 'Kumasi', false, 'Practical skills for independent living and personal growth.',
                ['Level I – Outpatient'], 10],
            ['Family Support Program', 'red', 'users-round', 'active', '2025-01-20', null, 'online', false, 'Support for families navigating recovery and healing.',
                ['Level II – Intensive Outpatient'], 6],
            ['Relapse Prevention Program', 'teal', 'flower-2', 'on_hold', '2024-11-15', '2025-04-30', 'Accra', false, 'Tools and strategies to prevent relapse and maintain progress.',
                ['Level I – Outpatient'], 5],
        ];

        $people = Client::query()->where('status', ClientStatus::Active->value)->orderBy('client_number')->limit(30)->get();
        $programs = [];
        foreach ($rows as $i => [$name, $color, $icon, $status, $start, $end, $place, $sud, $description, $levels, $count]) {
            $program = new Program(['name' => $name, 'description' => $description, 'color' => $color, 'icon' => $icon, 'starts_on' => $start, 'ends_on' => $end,
                'location_id' => $place === 'online' ? null : $locations[$place], 'is_online' => $place === 'online']);
            $program->forceFill(['status' => $status, 'is_sud_program' => $sud, 'created_at' => $at('2025-01-02 09:00')->addMinutes($i), 'updated_at' => $at('2025-01-02 09:00')->addMinutes($i)])->save();

            $made = [];
            foreach ($levels as $order => $levelName) {
                $level = new LevelOfCare(['name' => $levelName, 'sort' => $order + 1, 'description' => null, 'eligibility' => null]);
                $level->forceFill(['program_id' => $program->id, 'is_active' => true])->save();
                $made[] = $level;
            }

            foreach ($people->take($count)->values() as $n => $client) {
                $this->enroll($program, $client, $made[$n % count($made)] ?? null, $at('2025-02-03 09:00')->addDays($n), 'active');
            }
            $programs[$name] = $program;
        }

        // Four participants who completed the Mental Health program earlier (the "Completed" tile).
        $mental = $programs['Mental Health Wellness Program'];
        foreach ($people->slice(20, 4)->values() as $n => $client) {
            $this->enroll($mental, $client, null, $at('2025-02-05 09:00'), 'completed', $at('2025-04-10 12:00')->addDays($n));
        }

        $staff = OrganizationMembership::query()->with('user:id,name')->get()->keyBy(fn ($m) => $m->user->name);
        foreach ([['Mental Health Wellness Program', 'James Allen', 'director'], ['Mental Health Wellness Program', 'Lisa Morgan', 'clinician'],
            ['Substance Use Recovery Program', 'Sarah Carter', 'director'], ['Teen Empowerment Program', 'Emily Johnson', 'coordinator']] as [$programName, $person, $role]) {
            (new ProgramStaff)->forceFill(['program_id' => $programs[$programName]->id, 'membership_id' => $staff[$person]->id, 'role' => $role])->save();
        }

        $sessions = [
            ['Group Therapy Session', 'Mental Health Wellness Program', '2025-04-28 10:00', '2025-04-28 11:00', 'Kumasi', 'James Allen'],
            ['Life Skills Workshop', 'Teen Empowerment Program', '2025-04-29 14:00', '2025-04-29 16:00', 'Accra', 'Emily Johnson'],
            ['Family Support Group', 'Family Support Program', '2025-04-30 11:00', '2025-04-30 12:30', 'online', 'Lisa Morgan'],
            ['Group Therapy Session', 'Mental Health Wellness Program', '2025-04-21 10:00', '2025-04-21 11:00', 'Kumasi', 'James Allen'],
            ['Life Skills Workshop', 'Teen Empowerment Program', '2025-04-22 14:00', '2025-04-22 16:00', 'Accra', 'Emily Johnson'],
        ];
        foreach ($sessions as [$title, $programName, $from, $to, $place, $facilitator]) {
            $session = new ProgramSession;
            $session->forceFill([
                'program_id' => $programs[$programName]->id, 'title' => $title, 'starts_at' => $at($from), 'ends_at' => $at($to), 'timezone' => self::TZ,
                'location_id' => $place === 'online' ? null : $locations[$place], 'is_online' => $place === 'online', 'facilitator_membership_id' => $staff[$facilitator]->id,
            ])->save();

            if ($at($from)->isPast()) {
                foreach (ProgramEnrollment::query()->where('program_id', $session->program_id)->where('status', 'active')->get() as $n => $enrollment) {
                    (new ProgramSessionAttendance)->forceFill([
                        'program_id' => $session->program_id, 'session_id' => $session->id, 'enrollment_id' => $enrollment->id,
                        'record_environment' => $enrollment->record_environment, 'status' => ['present', 'present', 'present', 'absent', 'excused'][$n % 5],
                    ])->save();
                }
            }
        }
    }

    private function enroll(Program $program, Client $client, ?LevelOfCare $level, CarbonImmutable $admitted, string $status, ?CarbonImmutable $ended = null): void
    {
        $enrollment = new ProgramEnrollment;
        $enrollment->forceFill([
            'record_environment' => $client->record_environment, 'client_id' => $client->id, 'program_id' => $program->id,
            'current_level_id' => $level?->id, 'status' => $status, 'admitted_at' => $admitted, 'ended_at' => $ended,
        ])->save();

        $events = [[EnrollmentEventType::Admitted, null, EnrollmentStatus::Active, $admitted]];
        if ($ended !== null) {
            $events[] = [EnrollmentEventType::Completed, EnrollmentStatus::Active, EnrollmentStatus::Completed, $ended];
        }
        foreach ($events as [$type, $from, $to, $when]) {
            (new ProgramEnrollmentEvent)->forceFill([
                'organization_id' => $enrollment->organization_id, 'record_environment' => $enrollment->record_environment, 'enrollment_id' => $enrollment->id,
                'event_type' => $type, 'from_status' => $from, 'to_status' => $to, 'to_level_id' => $level?->id, 'occurred_at' => $when,
            ])->save();
        }
    }

    /**
     * Telehealth comps 04/11/06: six telehealth sessions on Daily and Emily Johnson's 28 Apr 10:00 session run
     * through the real actions on a pinned clock — completed, with notes, a consented tiny WAV "recording" and an AI
     * DRAFT transcript. The recording's displayed size/duration mimic the comp (the stored file is a few KB).
     * Every session gets a simulated Daily room written directly (the fake client's address shape, the window
     * PrepareRoom would ask for), so the screens render their "ready" states with DAILY_FAKE=true and no network;
     * Emily's room is written after her session ended, so ending it calls nobody. Recording and AI settings are
     * switched back off at the end, as a new organization would have them.
     *
     * @param  array<string, Client>  $clients
     * @param  array<string, Service>  $services
     * @param  array<string, OrganizationMembership>  $clinicians
     * @param  array<string, Location>  $locations
     */
    private function telehealth(Organization $organization, array $clients, array $services, array $clinicians, array $locations, User $actor): void
    {
        $settings = app(SettingsService::class);
        $settings->seedOrganization($organization, ['telehealth.recording_enabled' => true, 'telehealth.ai_transcripts_enabled' => true]);

        $book = app(ScheduleAppointment::class);
        $sessions = [];
        foreach ([
            ['2025-04-28', '10:00', 'Emily Johnson', 'Therapy Session', 'sarah', '84311220012'],
            ['2025-04-29', '11:30', 'Michael Brown', 'Follow-up Consultation', 'james', '84311220013'],
            ['2025-04-30', '14:00', 'Sophia Davis', 'Initial Assessment', 'lisa', '84311220014'],
            ['2025-05-02', '09:00', 'James Wilson', 'Progress Review', 'emily', '84311220015'],
            ['2025-05-06', '10:30', 'Olivia Martinez', 'Therapy Session', 'sarah', '84311220016'],
            ['2025-05-07', '15:00', 'Daniel Thomas', 'Follow-up Consultation', 'james', '84311220017'],
        ] as [$date, $time, $client, $service, $clinician, $meeting]) {
            $appointment = $book(new ScheduleAppointmentData(
                client: $clients[$client], service: $services[$service], clinician: $clinicians[$clinician],
                modality: Modality::Telehealth, startsAt: CarbonImmutable::parse("{$date} {$time}", self::TZ), actor: $actor,
            ));
            $session = TelehealthSession::query()->where('appointment_id', $appointment->id)->firstOrFail();
            if ($client !== 'Emily Johnson') {
                $this->fakeRoom($session, "wnfixture{$meeting}");
            }
            $sessions[$client] = $session->refresh();
        }

        // Emily's follow-up (comp 06 "Next Steps": 5 May 2025, 10:00 AM), in person so the sessions list stays at six.
        $book(new ScheduleAppointmentData(
            client: $clients['Emily Johnson'], service: $services['Therapy Session'], clinician: $clinicians['sarah'],
            modality: Modality::InPerson, startsAt: CarbonImmutable::parse('2025-05-05 10:00', self::TZ), location: $locations['Accra'], actor: $actor,
        ));

        $emily = $sessions['Emily Johnson'];
        $previousNow = CarbonImmutable::getTestNow();
        try {
            CarbonImmutable::setTestNow(CarbonImmutable::parse('2025-04-28 10:00:00', 'UTC'));
            app(OpenSession::class)($emily, $actor);
            app(RecordConsent::class)($emily, true, $actor);
            CarbonImmutable::setTestNow(CarbonImmutable::parse('2025-04-28 11:00:00', 'UTC'));
            app(EndSession::class)($emily, $actor);
            app(SaveSessionNotes::class)($emily, 'Client discussed recent stressors and coping strategies. Reported improved mood compared to last session. Discussed homework and follow-up plan.', $actor);

            $wav = tempnam(sys_get_temp_dir(), 'wav');
            $samples = str_repeat("\x00\x00", 2000);
            file_put_contents($wav, 'RIFF'.pack('V', 36 + strlen($samples)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16).'data'.pack('V', strlen($samples)).$samples);
            $recording = app(AttachRecording::class)($emily, new UploadedFile($wav, 'session.wav', 'audio/wav', null, true), 3600, $actor);
            @unlink($wav);
            $recording->forceFill(['size_bytes' => 26004684])->save();

            app(AddTranscript::class)(
                $emily,
                "Clinician: How have you been since our last session?\nClient: A little better. The breathing exercises helped when work got stressful.\nClinician: That is good to hear. Let's plan some homework for the week ahead.",
                TranscriptSource::Ai,
                $recording,
                $actor,
            );
        } finally {
            CarbonImmutable::setTestNow($previousNow);
        }
        $this->fakeRoom($emily->refresh(), 'wnfixture84311220012');

        $settings->seedOrganization($organization, ['telehealth.recording_enabled' => false, 'telehealth.ai_transcripts_enabled' => false]);
    }

    /** A simulated Daily room (FakeDailyClient's address shape) with the window PrepareRoom would ask for — no network. */
    private function fakeRoom(TelehealthSession $session, string $name): void
    {
        [$notBefore, $expiresAt] = PrepareRoom::window(
            $session->starts_at, $session->ends_at, app(TelehealthSettings::class)->joinEarlyMinutes($session->organization_id),
        );
        $session->forceFill([
            'provider_room_name' => $name,
            'join_url' => FakeDailyClient::DOMAIN.'/'.$name,
            'provider_room_nbf' => $notBefore,
            'provider_room_exp' => $expiresAt,
        ])->save();
    }
}

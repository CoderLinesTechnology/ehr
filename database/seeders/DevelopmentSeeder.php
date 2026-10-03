<?php

namespace Database\Seeders;

use App\Domain\Identity\RoleTemplates;
use App\Domain\Platform\CreateOrganization;
use App\Domain\Platform\OrganizationStatus;
use App\Domain\Tenancy\TenantContext;
use App\Models\AvailabilityRule;
use App\Models\Location;
use App\Models\OrganizationMembership;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Local development only (DatabaseSeeder guards on the environment).
 * Every account uses the password "password-1234". Clients are NOT created
 * here: load demo data from Settings → Demo data, which marks them as demo.
 */
class DevelopmentSeeder extends Seeder
{
    public function run(CreateOrganization $createOrganization, TenantContext $tenant): void
    {
        $password = Hash::make('password-1234');

        $superAdmin = User::query()->firstOrCreate(
            ['email' => 'superadmin@carebase.test'],
            ['name' => 'Platform Owner', 'password' => $password],
        );
        $superAdmin->forceFill(['email_verified_at' => now()])->save();
        $role = Role::query()->platform()->where('key', RoleTemplates::SUPER_ADMIN)->firstOrFail();
        $superAdmin->platformRoles()->syncWithoutDetaching([$role->id => ['scope' => 'platform', 'granted_at' => now()]]);

        if (User::query()->where('email', 'admin@accrawellness.test')->exists()) {
            return;
        }

        $owner = User::query()->create(['name' => 'Ama Owusu', 'email' => 'admin@accrawellness.test', 'password' => $password]);
        $owner->forceFill(['email_verified_at' => now()])->save();

        $created = $createOrganization(
            profile: ['name' => 'Accra Wellness Centre', 'slug' => 'accra-wellness', 'country_code' => 'GH', 'timezone' => 'Africa/Accra', 'currency' => 'GHS', 'email' => 'hello@accrawellness.test', 'phone' => '+233302000111'],
            plan: Plan::query()->where('key', 'advanced')->firstOrFail(),
            status: OrganizationStatus::Active,
            owner: $owner,
        );
        $organization = $created->organization;

        $tenant->runAs($organization, function () use ($organization, $created, $password) {
            $osu = Location::query()->create(['name' => 'Osu Clinic', 'address_line1' => '12 Oxford Street', 'city' => 'Accra', 'region' => 'Greater Accra', 'country_code' => 'GH', 'timezone' => 'Africa/Accra']);
            $legon = Location::query()->create(['name' => 'East Legon Centre', 'address_line1' => '4 Lagos Avenue', 'city' => 'Accra', 'region' => 'Greater Accra', 'country_code' => 'GH', 'timezone' => 'Africa/Accra']);

            $staff = [
                ['Kwame Mensah', 'kwame@accrawellness.test', 'clinician', 'Clinical Psychologist', '#5b8def'],
                ['Efua Asante', 'efua@accrawellness.test', 'supervisor', 'Consultant Psychiatrist', '#3fb68b'],
                ['Yaw Boateng', 'yaw@accrawellness.test', 'receptionist', 'Front Desk', '#e4a33b'],
                ['Akosua Darko', 'akosua@accrawellness.test', 'billing', 'Billing Officer', '#a77bd8'],
            ];

            $providers = [$created->ownerMembership];
            foreach ($staff as [$name, $email, $roleKey, $title, $color]) {
                $user = User::query()->create(['name' => $name, 'email' => $email, 'password' => $password]);
                $user->forceFill(['email_verified_at' => now()])->save();
                $membership = OrganizationMembership::factory()->create([
                    'user_id' => $user->id, 'title' => $title, 'color' => $color,
                    'is_provider' => in_array($roleKey, ['clinician', 'supervisor'], true),
                ]);
                $membership->roles()->attach(Role::query()->forOrganization($organization->id)->where('key', $roleKey)->value('id'));
                if ($membership->is_provider) {
                    $providers[] = $membership;
                }
            }

            $services = [
                ['Initial assessment', '90791', 75, 45000],
                ['Individual therapy', '90837', 50, 30000],
                ['Couples therapy', '90847', 60, 40000],
                ['Psychiatric review', '99214', 30, 35000],
            ];
            foreach ($services as [$name, $code, $minutes, $price]) {
                $service = new Service([
                    'name' => $name, 'code' => $code, 'duration_minutes' => $minutes,
                    'allows_in_person' => true, 'allows_telehealth' => true, 'is_bookable_online' => true,
                ]);
                $service->forceFill(['price_minor' => $price, 'currency' => 'GHS', 'is_active' => true])->save();
                $service->providers()->attach(array_map(fn ($m) => $m->id, $providers));
                $service->locations()->attach([$osu->id, $legon->id]);
            }

            foreach ($providers as $index => $membership) {
                foreach ([1, 2, 3, 4, 5] as $weekday) {
                    AvailabilityRule::query()->create([
                        'membership_id' => $membership->id,
                        'location_id' => $index % 2 === 0 ? $osu->id : $legon->id,
                        'weekday' => $weekday, 'start_time' => '09:00', 'end_time' => '17:00',
                        'modality' => 'any', 'effective_from' => now()->startOfWeek()->toDateString(),
                    ]);
                }
            }
        });

        $this->command?->info('Development accounts (password "password-1234"): superadmin@carebase.test, admin@accrawellness.test, kwame@ / efua@ / yaw@ / akosua@accrawellness.test');
        $this->command?->warn('The super admin must set up two-factor authentication on first sign-in.');
    }
}

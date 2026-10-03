<?php

namespace Tests\Feature\Invitations\Http;

use App\Actions\Fortify\CreateNewUser;
use App\Domain\Identity\MembershipStatus;
use App\Domain\Settings\SettingsService;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Settings\Http\SettingsHttpTestCase;

class InvitationHttpTest extends SettingsHttpTestCase
{
    #[Test]
    public function a_guest_is_sent_to_sign_in_and_the_invited_address_is_remembered_for_registration(): void
    {
        [, $token] = $this->invitation('Nina@Example.com');

        $this->get("/invitations/{$token}")->assertRedirect(route('login'))
            ->assertSessionHas(CreateNewUser::INVITATION_SESSION_KEY, 'nina@example.com');
    }

    #[Test]
    public function the_remembered_address_lets_that_person_register_while_registration_is_closed(): void
    {
        app(SettingsService::class)->setPlatform(['registration.mode' => 'closed'], null);
        [, $token] = $this->invitation('nina@example.com');

        $this->get(route('register'))->assertNotFound();
        $this->get("/invitations/{$token}");
        $this->get(route('register'))->assertOk();
    }

    #[Test]
    public function a_signed_in_invitee_sees_the_invitation_and_accepts_it_without_a_verified_email(): void
    {
        [$invite, $token] = $this->invitation('nina@example.com');
        $user = User::factory()->unverified()->create(['email' => 'nina@example.com']);

        $this->actingAs($user)->get("/invitations/{$token}")->assertOk()
            ->assertSee('Accra Wellness Centre')->assertSee('Accept invitation');

        $this->post("/invitations/{$token}")->assertRedirect(route('app.dashboard', ['organization' => $this->org->slug]));
        $membership = OrganizationMembership::acrossTenants()->findOrFail($invite->id);
        $this->assertSame(MembershipStatus::Active, $membership->status);
        $this->assertSame($user->id, $membership->user_id);
    }

    #[Test]
    public function the_wrong_account_is_told_so_and_cannot_accept(): void
    {
        [$invite, $token] = $this->invitation('nina@example.com');

        $this->actingAs(User::factory()->create(['email' => 'someone.else@example.com']))->get("/invitations/{$token}")
            ->assertOk()->assertSee('was sent to nina@example.com', false)->assertDontSee('Accept invitation');
        $this->post("/invitations/{$token}")->assertSessionHasErrors();
        $this->assertSame(MembershipStatus::Invited, OrganizationMembership::acrossTenants()->findOrFail($invite->id)->status);
    }

    #[Test]
    public function acceptance_needs_a_signed_in_user(): void
    {
        [, $token] = $this->invitation('nina@example.com');

        $this->post("/invitations/{$token}")->assertRedirect(route('login'));
    }

    #[Test]
    public function unknown_expired_and_revoked_links_all_show_the_same_neutral_page(): void
    {
        [$expired, $expiredToken] = $this->invitation('a@example.com');
        DB::table('organization_memberships')->where('id', $expired->id)->update(['invitation_expires_at' => now()->subDay()]);
        [$revoked, $revokedToken] = $this->invitation('b@example.com');
        $this->actAs($this->admin, fn () => app(\App\Domain\Identity\RevokeInvitation::class)($revoked));

        $user = User::factory()->create();
        $bodies = [];
        foreach ([str_repeat('a', 64), $expiredToken, $revokedToken] as $token) {
            $response = $this->actingAs($user)->get("/invitations/{$token}")->assertNotFound()->assertSee('no longer valid');
            $bodies[] = preg_replace('/(name="_token" value=")[^"]*/', '$1', $response->getContent());
            $this->assertStringNotContainsString('Accra Wellness', $response->getContent());
        }
        $this->assertCount(1, array_unique(array_map(fn ($b) => preg_replace('/<meta[^>]*csrf[^>]*>/', '', $b), $bodies)));
    }

    #[Test]
    public function a_dead_link_does_not_leak_to_a_guest_either(): void
    {
        $this->get('/invitations/'.str_repeat('z', 64))->assertNotFound()->assertSessionMissing(CreateNewUser::INVITATION_SESSION_KEY);
    }

    #[Test]
    public function the_link_is_throttled(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $last = 200;
        for ($i = 0; $i < 25; $i++) {
            $last = $this->get('/invitations/'.str_repeat('q', 64))->getStatusCode();
        }

        $this->assertSame(429, $last);
    }

    #[Test]
    public function a_malformed_token_is_not_found_without_touching_the_database(): void
    {
        $this->actingAs(User::factory()->create())->get('/invitations/short')->assertNotFound();
    }
}

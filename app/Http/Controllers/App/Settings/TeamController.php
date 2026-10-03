<?php

namespace App\Http\Controllers\App\Settings;

use App\Domain\Identity\AccessGuard;
use App\Domain\Identity\ChangeMembershipStatus;
use App\Domain\Identity\InviteStaff;
use App\Domain\Identity\MembershipStatus;
use App\Domain\Identity\ResendInvitation;
use App\Domain\Identity\RevokeInvitation;
use App\Domain\Identity\RoleDirectory;
use App\Domain\Identity\StaffSeats;
use App\Domain\Identity\TeamDirectory;
use App\Domain\Identity\UpdateMember;
use App\Domain\Saas\EntitlementService;
use App\Domain\Saas\FeatureRegistry;
use App\Http\Controllers\Controller;
use App\Models\OrganizationMembership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Settings → Team: members, invitations, roles per person, calendar colour and status. */
final class TeamController extends Controller
{
    /** Clinician calendar colour families (SPEC decision 6); purple is reserved for telehealth. */
    public const COLORS = ['#307ef6' => 'Blue', '#16b482' => 'Green', '#f59e0b' => 'Orange', '#14b8a6' => 'Teal', '#ec4899' => 'Pink', '#64748b' => 'Slate'];

    public function index(Request $request, TeamDirectory $directory, RoleDirectory $roles, AccessGuard $guard, StaffSeats $seats, EntitlementService $entitlements): View
    {
        $organization = tenant()->organizationOrFail();
        $status = in_array($request->query('status'), MembershipStatus::values(), true) ? (string) $request->query('status') : null;
        $search = trim((string) $request->query('q', ''));

        $members = $directory->paginate($status, $search === '' ? null : mb_substr($search, 0, 100));

        return view('app.settings.team.index', [
            'members' => $members,
            'counts' => $directory->statusCounts(),
            'status' => $status,
            'search' => $search,
            'canInvite' => Gate::allows('invite', OrganizationMembership::class),
            'roleOptions' => $this->roleOptions($roles, $guard),
            'seatsUsed' => $seats->inUse(),
            'seatLimit' => $entitlements->limit($organization, FeatureRegistry::MAX_STAFF),
            'myMembershipId' => tenant()->membership()?->id,
        ]);
    }

    public function invite(Request $request, InviteStaff $invite): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'roles' => ['required', 'array', 'min:1', 'max:50'],
            'roles.*' => ['string', 'max:40'],
            'title' => ['nullable', 'string', 'max:100'],
            'credentials' => ['nullable', 'string', 'max:100'],
            'is_provider' => ['boolean'],
        ], ['roles.required' => 'Choose at least one role.', 'roles.min' => 'Choose at least one role.']);

        $invitation = $invite($data['email'], $data['roles'], $data['title'] ?? null, (bool) ($data['is_provider'] ?? false), $data['credentials'] ?? null);

        return redirect()->route('app.settings.team.index')->with('success', "An invitation was sent to {$invitation->invited_email}.");
    }

    public function edit(OrganizationMembership $member, RoleDirectory $roles, AccessGuard $guard, TeamDirectory $directory): View
    {
        $directory->attachRoles(collect([$member]));
        $member->loadMissing('user:id,name,email');

        return view('app.settings.team.edit', [
            'member' => $member,
            'roleOptions' => $this->roleOptions($roles, $guard),
            'heldRoleIds' => $member->roles->pluck('id')->all(),
            'colors' => self::COLORS,
            'canChangeAccess' => Gate::allows('changeAccess', $member),
            'canChangeStatus' => Gate::allows('changeStatus', $member),
            'isSelf' => $member->id === tenant()->membership()?->id,
            'services' => $directory->serviceNames($member),
        ]);
    }

    public function update(Request $request, OrganizationMembership $member, UpdateMember $update): RedirectResponse
    {
        $data = $request->validate([
            'name_prefix' => ['nullable', 'string', 'max:20'],
            'title' => ['nullable', 'string', 'max:100'],
            'credentials' => ['nullable', 'string', 'max:100'],
            'is_provider' => ['boolean'],
            'color' => ['nullable', 'string', Rule::in([...array_keys(self::COLORS), strtolower((string) $member->color)])],
            'roles' => ['required', 'array', 'min:1', 'max:50'],
            'roles.*' => ['string', 'max:40'],
        ], ['roles.required' => 'Choose at least one role.', 'roles.min' => 'Choose at least one role.', 'color.in' => 'Choose one of the listed colours.']);

        $update($member, [
            'name_prefix' => $data['name_prefix'] ?? null,
            'title' => $data['title'] ?? null,
            'credentials' => $data['credentials'] ?? null,
            'is_provider' => (bool) ($data['is_provider'] ?? false),
            'color' => $data['color'] ?? null,
        ], $data['roles']);

        return redirect()->route('app.settings.team.index')->with('success', 'The details of '.$member->displayName().' were saved.');
    }

    public function status(Request $request, OrganizationMembership $member, ChangeMembershipStatus $change): RedirectResponse
    {
        $data = $request->validate([
            'to' => ['required', Rule::in(['active', 'suspended', 'deactivated'])],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $change($member, MembershipStatus::from($data['to']), $data['reason'] ?? null);

        return redirect()->route('app.settings.team.edit', ['member' => $member])
            ->with('success', $member->displayName().' is now '.$data['to'].'.');
    }

    public function resend(OrganizationMembership $invitation, ResendInvitation $resend): RedirectResponse
    {
        $resend($invitation);

        return redirect()->route('app.settings.team.index')->with('success', "The invitation to {$invitation->invited_email} was sent again.");
    }

    public function revoke(OrganizationMembership $invitation, RevokeInvitation $revoke): RedirectResponse
    {
        $email = $invitation->invited_email;
        $revoke($invitation);

        return redirect()->route('app.settings.team.index')->with('success', "The invitation to {$email} was withdrawn.");
    }

    /** @return list<array{id: string, name: string, description: ?string, assignable: bool}> */
    private function roleOptions(RoleDirectory $roles, AccessGuard $guard): array
    {
        $all = $roles->overview(tenant()->organizationOrFail()->id);
        $assignable = array_flip($guard->manageableRoleIds($all));

        return $all->map(fn ($role) => [
            'id' => $role->id, 'name' => $role->name, 'description' => $role->description, 'assignable' => isset($assignable[$role->id]),
        ])->values()->all();
    }
}

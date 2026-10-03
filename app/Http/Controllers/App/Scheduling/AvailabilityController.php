<?php

namespace App\Http\Controllers\App\Scheduling;

use App\Domain\Organization\RefreshOnboardingStatus;
use App\Domain\Scheduling\AvailabilityModality;
use App\Domain\Scheduling\AvailabilityRuleData;
use App\Domain\Scheduling\BlockedTimeData;
use App\Domain\Scheduling\BlockedTimeKind;
use App\Domain\Scheduling\CreateBlockedTime;
use App\Domain\Scheduling\DeleteAvailabilityRule;
use App\Domain\Scheduling\DeleteBlockedTime;
use App\Domain\Scheduling\SaveAvailabilityRule;
use App\Http\Requests\Scheduling\AvailabilityRuleRequest;
use App\Http\Requests\Scheduling\BlockedTimeRequest;
use App\Models\AvailabilityRule;
use App\Models\BlockedTime;
use App\Models\Location;
use App\Models\OrganizationMembership;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Weekly availability and blocked time. `availability.manage_own` is the member's own calendar only;
 * `availability.manage_all` is anyone's, plus organization-wide blocks. The scope is decided here
 * (and again by the form requests), never from a posted flag. Availability changes re-check the
 * onboarding checklist.
 */
final class AvailabilityController
{
    use MapsDomainErrors;

    private const FIELDS = ['effective_from' => 'effective_from', 'start_date' => 'start_date', 'ends_at' => 'end_time'];

    public function index(Request $request): View
    {
        $all = $this->canManageAll();
        $own = tenant()->membership();
        abort_unless($all || Gate::allows('availability.manage_own'), 403);

        $providers = OrganizationMembership::query()->providers()->with('user:id,name')->limit(100)->get()
            ->when(! $all, fn ($c) => $c->where('id', $own->id))
            ->sortBy(fn ($m) => mb_strtolower($m->displayName()))->values();
        $selected = $providers->firstWhere('id', $request->query('clinician')) ?? $providers->firstWhere('id', $own->id) ?? $providers->first();

        $rules = collect();
        $blocked = collect();
        $services = collect();
        if ($selected !== null) {
            $rules = AvailabilityRule::query()->where('membership_id', $selected->id)
                ->with(['location:id,organization_id,name', 'services:id,name'])
                ->orderBy('weekday')->orderBy('start_time')->limit(200)->get();
            $services = $selected->services()->where('services.is_active', true)->orderBy('services.name')->limit(200)->get(['services.id', 'services.name']);
            $blocked = BlockedTime::query()
                ->where(fn ($q) => $q->where('membership_id', $selected->id)->orWhereNull('membership_id'))
                ->where('ends_at', '>=', CarbonImmutable::now()->subDay())
                ->with('location:id,organization_id,name')
                ->orderBy('starts_at')->limit(100)->get();
        }

        return view('app.settings.availability.index', [
            'providers' => $providers,
            'selected' => $selected,
            'rules' => $rules,
            'editing' => $request->filled('rule') && Str::isUuid((string) $request->query('rule')) ? $rules->firstWhere('id', $request->query('rule')) : null,
            'services' => $services,
            'blocked' => $blocked,
            'locations' => Location::query()->active()->orderBy('sort')->orderBy('name')->limit(100)->pluck('name', 'id')->all(),
            'weekdays' => [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'],
            'modalities' => collect(AvailabilityModality::cases())->mapWithKeys(fn ($m) => [$m->value => $m->label()])->all(),
            'kinds' => collect(BlockedTimeKind::cases())->mapWithKeys(fn ($k) => [$k->value => $k->label()])->all(),
            'canManageAll' => $all,
            'ownId' => $own->id,
            'timezone' => tenant()->organizationOrFail()->timezone,
        ]);
    }

    public function storeRule(AvailabilityRuleRequest $request, SaveAvailabilityRule $save, RefreshOnboardingStatus $onboarding): RedirectResponse
    {
        $this->attempt(fn () => $save($this->ruleData($request, $request->membership)), self::FIELDS);
        $onboarding(tenant()->organizationOrFail());

        return $this->back($request->membership)->with('success', 'Availability added.');
    }

    public function updateRule(AvailabilityRuleRequest $request, AvailabilityRule $rule, SaveAvailabilityRule $save, RefreshOnboardingStatus $onboarding): RedirectResponse
    {
        $membership = $this->ownerOf($rule->membership_id);
        // The window stays with its clinician: a posted membership_id never moves it.
        $this->attempt(fn () => $save($this->ruleData($request, $membership), $rule), self::FIELDS);
        $onboarding(tenant()->organizationOrFail());

        return $this->back($membership)->with('success', 'Availability updated.');
    }

    public function destroyRule(AvailabilityRule $rule, DeleteAvailabilityRule $delete, RefreshOnboardingStatus $onboarding): RedirectResponse
    {
        $membership = $this->ownerOf($rule->membership_id);
        $this->attempt(fn () => $delete($rule));
        $onboarding(tenant()->organizationOrFail());

        return $this->back($membership)->with('success', 'Availability removed.');
    }

    public function storeBlocked(BlockedTimeRequest $request, CreateBlockedTime $create): RedirectResponse
    {
        $actor = $request->user();
        $kind = BlockedTimeKind::from($request->input('kind'));
        $title = $request->filled('title') ? $request->input('title') : null;

        $data = $request->boolean('all_day')
            ? BlockedTimeData::allDay($kind, $request->input('start_date'), $request->input('end_date') ?: null, $request->membership, $request->location, $title, $actor)
            : BlockedTimeData::timed($kind, ...$request->instants(), membership: $request->membership, location: $request->location, title: $title, actor: $actor);

        $this->attempt(fn () => $create($data), ['ends_at' => 'end_time', 'end_date' => 'end_date']);

        return $this->back($request->membership)->with('success', 'Time blocked.');
    }

    public function destroyBlocked(BlockedTime $blocked, DeleteBlockedTime $delete): RedirectResponse
    {
        // Organization-wide blocks (no clinician) are managed by "manage all" only.
        abort_unless(Gate::any(['availability.manage_own', 'availability.manage_all']), 403);
        $membership = $blocked->membership_id === null ? $this->requireAll() : $this->ownerOf($blocked->membership_id);
        $this->attempt(fn () => $delete($blocked));

        return $this->back($membership)->with('success', 'Blocked time removed.');
    }

    private function ruleData(AvailabilityRuleRequest $request, ?OrganizationMembership $membership): AvailabilityRuleData
    {
        return new AvailabilityRuleData(
            membership: $membership ?? abort(404),
            weekday: (int) $request->input('weekday'),
            startTime: $request->input('start_time'),
            endTime: $request->input('end_time'),
            modality: AvailabilityModality::from($request->input('modality')),
            effectiveFrom: $request->input('effective_from'),
            location: $request->location,
            effectiveUntil: $request->input('effective_until') ?: null,
            repeatEveryWeeks: (int) $request->input('repeat_every_weeks'),
            isBookableOnline: $request->boolean('is_bookable_online'),
            isActive: $request->boolean('is_active'),
            serviceIds: array_values(array_unique((array) $request->input('service_ids', []))),
        );
    }

    /** The clinician a stored record belongs to, if the member may manage them; otherwise this is a 404. */
    private function ownerOf(string $membershipId): OrganizationMembership
    {
        $own = tenant()->membership();
        abort_unless(Gate::any(['availability.manage_own', 'availability.manage_all']), 403);
        abort_unless($this->canManageAll() || ($membershipId === $own->id && Gate::allows('availability.manage_own')), 404);

        return OrganizationMembership::query()->findOrFail($membershipId);
    }

    private function requireAll(): ?OrganizationMembership
    {
        abort_unless($this->canManageAll(), 404);

        return null;
    }

    private function canManageAll(): bool
    {
        return Gate::allows('availability.manage_all');
    }

    private function back(?OrganizationMembership $membership): RedirectResponse
    {
        return redirect()->route('app.settings.availability.index', array_filter(['clinician' => $membership?->id]));
    }
}

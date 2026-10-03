<?php

namespace App\Domain\Clients;

use App\Models\Location;
use App\Models\OrganizationMembership;

/**
 * The choices behind the client form's and filters' selects. Bounded reads
 * (an organization has a few dozen staff and a handful of locations).
 */
final class ClientFormOptions
{
    private const LIMIT = 200;

    /**
     * Clinicians a client can be assigned to: active providers, plus
     * $alsoInclude (the clinician already assigned) even if they have since
     * left, so editing a client never silently drops them.
     *
     * @return array<string, string> membership id => "Name, Title"
     */
    public function clinicians(?string $alsoInclude = null): array
    {
        $memberships = OrganizationMembership::query()
            ->where(function ($query) use ($alsoInclude) {
                $query->where(fn ($provider) => $provider->where('status', 'active')->where('is_provider', true));
                if ($alsoInclude !== null) {
                    $query->orWhere('id', $alsoInclude);
                }
            })
            ->with('user:id,name')
            ->limit(self::LIMIT)
            ->get();

        $options = [];
        foreach ($memberships->sortBy(fn (OrganizationMembership $m) => mb_strtolower($m->displayName())) as $membership) {
            $label = $membership->displayName().($membership->title ? ', '.$membership->title : '');
            $options[$membership->id] = $membership->isActive() && $membership->is_provider ? $label : $label.' (no longer active)';
        }

        return $options;
    }

    /**
     * @return array<string, string> location id => name
     */
    public function locations(?string $alsoInclude = null): array
    {
        return Location::query()
            ->select(['id', 'name', 'is_active', 'sort'])
            ->where(function ($query) use ($alsoInclude) {
                $query->where('is_active', true);
                if ($alsoInclude !== null) {
                    $query->orWhere('id', $alsoInclude);
                }
            })
            ->orderBy('sort')->orderBy('name')
            ->limit(self::LIMIT)
            ->get()
            ->mapWithKeys(fn (Location $l) => [$l->id => $l->is_active ? $l->name : $l->name.' (closed)'])
            ->all();
    }
}

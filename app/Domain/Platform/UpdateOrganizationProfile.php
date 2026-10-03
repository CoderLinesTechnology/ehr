<?php

namespace App\Domain\Platform;

use App\Domain\Audit\AuditContext;
use App\Domain\Audit\AuditLogger;
use App\Models\Organization;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Edits an organization's profile. Status and plan have their own actions;
 * the slug (the organization's address) never changes. Only the mass-assignable
 * profile columns can be written, so this cannot touch status or tenancy.
 *
 * The audit entry holds the old and new value of every changed column, as
 * AuditLogger::recordChanges would, but with the Platform context written
 * explicitly: recordChanges infers the context from the HTTP route, so the
 * same edit made from a command or a job would be filed under "system".
 */
final class UpdateOrganizationProfile
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $profile */
    public function __invoke(Organization $organization, array $profile): Organization
    {
        if (isset($profile['country_code'])) {
            $profile['country_code'] = strtoupper($profile['country_code']);
        }
        if (isset($profile['currency'])) {
            $profile['currency'] = strtoupper($profile['currency']);
        }

        return DB::transaction(function () use ($organization, $profile) {
            /** @var Organization $locked */
            $locked = Organization::query()->lockForUpdate()->findOrFail($organization->id);

            $locked->fill(Arr::only($profile, $locked->getFillable()));

            $changed = array_diff_key($locked->getDirty(), ['updated_at' => true]);
            if ($changed !== []) {
                $before = [];
                foreach (array_keys($changed) as $column) {
                    $before[$column] = $locked->getRawOriginal($column);
                }

                $this->audit->record(
                    'organization.updated',
                    subject: $locked,
                    before: $before,
                    after: $changed,
                    summary: "Profile of {$locked->name} updated",
                    context: AuditContext::Platform,
                );
                $locked->save();
            }

            $organization->setRawAttributes($locked->getAttributes(), true);

            return $organization;
        });
    }
}

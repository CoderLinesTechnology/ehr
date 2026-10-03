<?php

namespace App\Domain\Programs;

use App\Domain\Clients\ClientVisibility;
use App\Domain\Identity\PermissionResolver;
use App\Models\Client;
use App\Models\OrganizationMembership;
use App\Models\Program;
use App\Models\ProgramEnrollment;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may see which part of a program. One rule for the lists, the counts, the search, the detail pages and
 * the actions (through the policies), so they cannot disagree:
 *
 *   programs.view      the programs themselves, their levels, staff and schedule
 *   participants       a program's enrollments: needs programs.view AND, for a program flagged as substance-use
 *                      treatment (42 CFR Part 2), programs.view_sud; and only clients the member may see
 *                      (ClientVisibility: clients.view_all, or their own clients)
 *   counts             participant COUNTS follow the first two conditions (an aggregate of the segmented set is
 *                      still a disclosure) but not the per-client rule
 *
 * Only an ACTIVE membership sees anything.
 */
final class ProgramVisibility
{
    public const VIEW = 'programs.view';

    public const SUD = 'programs.view_sud';

    public static function canView(OrganizationMembership $membership): bool
    {
        return $membership->isActive() && app(PermissionResolver::class)->membershipHas($membership, self::VIEW);
    }

    public static function seesSud(OrganizationMembership $membership): bool
    {
        return $membership->isActive() && app(PermissionResolver::class)->membershipHas($membership, self::SUD);
    }

    /** May this member see the participants of $program (the segmentation rule only)? */
    public static function seesParticipantsOf(Program $program, OrganizationMembership $membership): bool
    {
        return self::canView($membership) && (! $program->is_sud_program || self::seesSud($membership));
    }

    /**
     * Enrollments whose existence the member may know about, for COUNTS (no per-client rule).
     *
     * @param  Builder<ProgramEnrollment>  $query
     * @return Builder<ProgramEnrollment>
     */
    public static function countable(Builder $query, OrganizationMembership $membership): Builder
    {
        if (! self::canView($membership)) {
            return $query->whereRaw('1 = 0');
        }
        if (self::seesSud($membership)) {
            return $query;
        }

        $table = $query->getModel()->getTable();

        return $query->whereNotIn("{$table}.program_id", Program::query()->where('is_sud_program', true)->select('programs.id'));
    }

    /**
     * Enrollments the member may open: countable AND of a client they may see.
     *
     * @param  Builder<ProgramEnrollment>  $query
     * @return Builder<ProgramEnrollment>
     */
    public static function enrollments(Builder $query, OrganizationMembership $membership): Builder
    {
        $query = self::countable($query, $membership);

        if (ClientVisibility::seesAll($membership)) {
            return $query;
        }

        $table = $query->getModel()->getTable();

        return $query->whereIn("{$table}.client_id", ClientVisibility::apply(Client::query(), $membership)->select('clients.id'));
    }

    /** The same rule for one enrollment (the policy's question). */
    public static function allowsEnrollment(ProgramEnrollment $enrollment, OrganizationMembership $membership): bool
    {
        if ($enrollment->organization_id !== $membership->organization_id) {
            return false;
        }

        return self::enrollments(ProgramEnrollment::query()->whereKey($enrollment->getKey()), $membership)->exists();
    }
}

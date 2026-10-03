<?php

namespace App\Domain\Programs;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\AccessGuard;
use App\Domain\Organization\AuditDiff;
use App\Domain\Shared\DomainException;
use App\Models\Location;
use App\Models\Program;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Creates (as Upcoming) or edits a program of the current organization.
 *
 *  - Needs `programs.manage`. The 42 CFR Part 2 flag can only be set, or changed, by someone who holds
 *    `programs.view_sud`: nobody can hide or reveal a program's participants without being able to see them.
 *  - Status is never taken from input (ChangeProgramStatus owns it). A new program takes a place of the plan's
 *    max_programs limit.
 *  - Place is a location of this organization, or online.
 *
 * Audit: `program.created` / `program.updated` (before/after of the changed fields; the description text itself is
 * never written to the audit trail).
 */
final class SaveProgram
{
    public function __construct(
        private readonly AccessGuard $guard,
        private readonly AuditLogger $audit,
        private readonly ActiveProgramLimit $limit,
    ) {}

    /**
     * @param  array<string, mixed>  $input  name, description, color, icon, starts_on, ends_on, place ('online' | location id | ''), is_sud_program
     *
     * @throws DomainException
     */
    public function __invoke(array $input, ?Program $program = null): Program
    {
        $this->guard->requirePermission('programs.manage');
        $organization = $this->guard->organization();
        if ($program !== null) {
            $this->guard->assertInOrganization($program);
        }

        $attributes = $this->attributes($input);
        $sud = (bool) ($input['is_sud_program'] ?? false);

        return DB::transaction(function () use ($organization, $program, $attributes, $sud) {
            $locked = $program === null ? null : Program::query()->whereKey($program->id)->lockForUpdate()->firstOrFail();
            $target = $locked ?? new Program;
            $creating = $locked === null;

            if (($creating && $sud) || (! $creating && $sud !== $target->is_sud_program)) {
                $this->guard->requirePermission('programs.view_sud');
            }
            if (! $creating && $target->is_sud_program) {
                $this->guard->requirePermission('programs.view_sud'); // editing a segmented program
            }

            $target->fill($attributes);

            if ($creating) {
                $this->limit->assertRoomFor($organization);
                $target->forceFill(['status' => ProgramStatus::Upcoming, 'is_sud_program' => $sud, 'created_by_user_id' => $this->guard->actor()->user_id]);
                $target->save();
                $this->audit->record(
                    'program.created',
                    $target,
                    after: ['name' => $target->name, 'status' => 'upcoming', 'is_sud_program' => $sud],
                    summary: "Created the program “{$target->name}”",
                );
            } else {
                $target->forceFill(['is_sud_program' => $sud]);
                [$before, $after] = AuditDiff::of($target, ['updated_at', 'description']);
                $descriptionChanged = $target->isDirty('description');
                $target->save();
                if ($after !== [] || $descriptionChanged) {
                    $this->audit->record(
                        'program.updated',
                        $target,
                        before: $before,
                        after: $after,
                        metadata: $descriptionChanged ? ['description_changed' => true] : [],
                        summary: "Updated the program “{$target->name}”",
                    );
                }
            }

            if ($program !== null) {
                $program->setRawAttributes($target->getAttributes(), true);

                return $program;
            }

            return $target;
        });
    }

    /** @return array<string, mixed> */
    private function attributes(array $input): array
    {
        $name = ProgramText::required($input['name'] ?? null, 120, 'name', 'program name');
        $description = ProgramText::optional($input['description'] ?? null, 1000, 'description', 'description');

        $color = ProgramColor::tryFrom((string) ($input['color'] ?? ''))
            ?? throw new DomainException('Choose a colour for the program.', 'color', 'color');
        $icon = (string) ($input['icon'] ?? '');
        if ($icon === '') {
            $icon = $color->defaultIcon();
        }
        if (! in_array($icon, ProgramColor::ICONS, true)) {
            throw new DomainException('Choose one of the offered icons.', 'icon', 'icon');
        }

        $start = $this->date($input['starts_on'] ?? null, 'starts_on', 'start date', true);
        $end = $this->date($input['ends_on'] ?? null, 'ends_on', 'end date', false);
        if ($end !== null && $end < $start) {
            throw new DomainException('The end date cannot be before the start date.', 'dates', 'ends_on');
        }

        $place = (string) ($input['place'] ?? '');
        $online = $place === 'online';
        $locationId = null;
        if ($place !== '' && ! $online) {
            $locationId = \Illuminate\Support\Str::isUuid($place) ? Location::query()->active()->whereKey($place)->value('id') : null;
            if ($locationId === null) {
                throw new DomainException('Choose one of your locations, or Online.', 'place', 'place');
            }
        }

        return [
            'name' => $name, 'description' => $description, 'color' => $color, 'icon' => $icon,
            'starts_on' => $start, 'ends_on' => $end, 'location_id' => $locationId, 'is_online' => $online,
        ];
    }

    private function date(mixed $value, string $field, string $label, bool $required): ?string
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '') {
            return $required ? throw new DomainException("Enter the {$label}.", 'required', $field) : null;
        }

        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC') : false;
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new DomainException("Enter a valid {$label}.", 'invalid_date', $field);
        }

        return $value;
    }
}

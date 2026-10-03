<?php

namespace App\Domain\Scheduling;

use ArrayIterator;
use Countable;
use DateTimeInterface;
use IteratorAggregate;
use Traversable;

/**
 * SlotFinder's answer: slots sorted by start, then clinician.
 *
 * @implements IteratorAggregate<int, Slot>
 */
final readonly class SlotResult implements Countable, IteratorAggregate
{
    /** @param list<Slot> $slots */
    public function __construct(public array $slots) {}

    public static function empty(): self
    {
        return new self([]);
    }

    public function isEmpty(): bool
    {
        return $this->slots === [];
    }

    public function count(): int
    {
        return count($this->slots);
    }

    /** @return Traversable<int, Slot> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->slots);
    }

    public function first(): ?Slot
    {
        return $this->slots[0] ?? null;
    }

    /** Whether exactly this start is offered for this clinician, place and modality. */
    public function contains(DateTimeInterface $startsAt, string $clinicianMembershipId, ?string $locationId, Modality $modality): bool
    {
        $timestamp = $startsAt->getTimestamp();

        foreach ($this->slots as $slot) {
            if ($slot->startsAt->getTimestamp() === $timestamp
                && $slot->clinicianMembershipId === $clinicianMembershipId
                && $slot->locationId === $locationId
                && $slot->modality === $modality) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, list<Slot>> local date ('Y-m-d', in each slot's timezone) => slots */
    public function byLocalDate(): array
    {
        $days = [];
        foreach ($this->slots as $slot) {
            $days[$slot->localDate()][] = $slot;
        }

        return $days;
    }

    /** @return list<string> clinician membership ids that have at least one slot, in slot order */
    public function clinicianIds(): array
    {
        return array_values(array_unique(array_map(fn (Slot $slot) => $slot->clinicianMembershipId, $this->slots)));
    }
}

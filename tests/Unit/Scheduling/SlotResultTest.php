<?php

namespace Tests\Unit\Scheduling;

use App\Domain\Scheduling\Modality;
use App\Domain\Scheduling\Slot;
use App\Domain\Scheduling\SlotResult;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SlotResultTest extends TestCase
{
    private function slot(string $start, string $clinician, ?string $location, Modality $modality, string $timezone = 'UTC'): Slot
    {
        $startsAt = CarbonImmutable::parse($start, 'UTC');

        return new Slot($startsAt, $startsAt->addHour(), $clinician, $location, $modality, $timezone);
    }

    #[Test]
    public function contains_matches_start_clinician_place_and_modality_exactly(): void
    {
        $result = new SlotResult([$this->slot('2026-10-05 09:00', 'c1', 'l1', Modality::InPerson)]);
        $at = CarbonImmutable::parse('2026-10-05 10:00', 'Africa/Lagos'); // the same instant as 09:00 UTC

        $this->assertTrue($result->contains($at, 'c1', 'l1', Modality::InPerson));
        $this->assertFalse($result->contains($at->addMinute(), 'c1', 'l1', Modality::InPerson));
        $this->assertFalse($result->contains($at, 'c2', 'l1', Modality::InPerson));
        $this->assertFalse($result->contains($at, 'c1', 'l2', Modality::InPerson));
        $this->assertFalse($result->contains($at, 'c1', null, Modality::Telehealth));
        $this->assertFalse(SlotResult::empty()->contains($at, 'c1', 'l1', Modality::InPerson));
    }

    #[Test]
    public function slots_group_by_the_local_date_of_their_display_timezone(): void
    {
        $late = $this->slot('2026-10-05 23:30', 'c1', null, Modality::Telehealth, 'Africa/Lagos'); // 00:30 on the 6th in Lagos
        $early = $this->slot('2026-10-05 09:00', 'c2', 'l1', Modality::InPerson, 'Africa/Accra');
        $result = new SlotResult([$early, $late]);

        $this->assertSame(['2026-10-05' => [$early], '2026-10-06' => [$late]], $result->byLocalDate());
        $this->assertSame(['c2', 'c1'], $result->clinicianIds());
        $this->assertSame('00:30', $late->localStart()->format('H:i'));
        $this->assertCount(2, $result);
        $this->assertSame($early, $result->first());
    }
}

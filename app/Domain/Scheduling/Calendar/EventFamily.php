<?php

namespace App\Domain\Scheduling\Calendar;

use App\Domain\Scheduling\Modality;
use App\Models\Appointment;

/**
 * Which colour family an appointment is drawn in (SPEC decision 6): telehealth is always
 * purple; everything else follows the clinician's colour, snapped to the nearest family.
 */
final class EventFamily
{
    public const FAMILIES = ['blue', 'green', 'purple', 'orange', 'teal'];

    /** Dot / swatch colour per family (mini calendar, clinician columns). */
    public const SWATCH = ['blue' => '#1D7BDB', 'green' => '#0AAE7F', 'purple' => '#7460D9', 'orange' => '#F59E0B', 'teal' => '#14B8A6'];

    public static function forAppointment(Appointment $appointment, ?string $clinicianColor): string
    {
        return $appointment->modality === Modality::Telehealth ? 'purple' : self::forColor($clinicianColor);
    }

    /** Nearest family to a "#RRGGBB" colour by hue; unknown or grey colours are blue. */
    public static function forColor(?string $hex): string
    {
        if ($hex === null || preg_match('/^#([0-9a-f]{6})$/i', $hex, $m) !== 1) {
            return 'blue';
        }

        [$r, $g, $b] = array_map(fn ($c) => hexdec($c) / 255, str_split($m[1], 2));
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        if ($max - $min < 0.08) {
            return 'blue';
        }

        $hue = match ($max) {
            $r => 60 * fmod(($g - $b) / ($max - $min), 6),
            $g => 60 * (($b - $r) / ($max - $min) + 2),
            default => 60 * (($r - $g) / ($max - $min) + 4),
        };
        $hue = fmod($hue + 360, 360);

        return match (true) {
            $hue < 70 || $hue >= 330 => 'orange',
            $hue < 165 => 'green',
            $hue < 195 => 'teal',
            $hue < 255 => 'blue',
            default => 'purple',
        };
    }
}

<?php

namespace App\Http\Requests\Clients;

use App\Domain\Scheduling\Modality;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class StoreClientRequest extends ClientRequest
{
    /** Where to go after the client is created ("appointment": back to New appointment with them chosen). */
    public const THEN_APPOINTMENT = 'appointment';

    protected function isNewClient(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return parent::rules() + [
            'then' => ['nullable', Rule::in([self::THEN_APPOINTMENT])],
            // New appointment's step-1 choices, carried through the client form and sanitised again on the way back.
            'carry' => ['nullable', 'array'],
            'carry.*' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function fromAppointment(): bool
    {
        return $this->input('then') === self::THEN_APPOINTMENT;
    }

    /**
     * New appointment's step-1 choices to put back on its URL: ids (uuid), a modality, a date (Y-m-d) and a
     * time (HH:MM) only; anything else is dropped. The route is fixed, so nothing here can redirect elsewhere.
     *
     * @return array<string, string>
     */
    public function carried(): array
    {
        $carry = $this->input('carry');
        if (! is_array($carry)) {
            return [];
        }
        $text = static fn (string $key): ?string => is_string($carry[$key] ?? null) ? trim($carry[$key]) : null;

        $out = [];
        foreach (['service', 'clinician', 'location'] as $key) {
            if (($value = $text($key)) !== null && Str::isUuid($value)) {
                $out[$key] = strtolower($value);
            }
        }
        if (($modality = Modality::tryFrom((string) $text('modality'))) !== null) {
            $out['modality'] = $modality->value;
        }
        $date = (string) $text('date');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && ($d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date)) && $d->format('Y-m-d') === $date) {
            $out['date'] = $date;
        }
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $text('time')) === 1) {
            $out['time'] = (string) $text('time');
        }

        return $out;
    }
}

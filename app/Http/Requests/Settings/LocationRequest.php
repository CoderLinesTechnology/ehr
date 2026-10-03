<?php

namespace App\Http\Requests\Settings;

use App\Domain\Organization\BusinessHours;
use App\Models\Location;
use App\Support\Regions;
use Closure;
use DateTimeZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create and edit form of a location (the same shape; `{location}` is present only when editing).
 * `hours` is posted per ISO weekday as hours[1][closed|open|close].
 */
final class LocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    private function location(): ?Location
    {
        $location = $this->route('location');

        return $location instanceof Location ? $location : null;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $ignore = $this->location()?->id;

        $uniqueName = function (string $attribute, mixed $value, Closure $fail) use ($ignore): void {
            $taken = Location::query()
                ->whereRaw('lower(name) = ?', [mb_strtolower(trim((string) $value))])
                ->when($ignore, fn ($q) => $q->whereKeyNot($ignore))
                ->exists();

            if ($taken) {
                $fail('You already have a location with that name.');
            }
        };

        return [
            'name' => ['required', 'string', 'max:120', $uniqueName],
            'address_line1' => ['nullable', 'string', 'max:200'],
            'address_line2' => ['nullable', 'string', 'max:200'],
            'city' => ['nullable', 'string', 'max:120'],
            'region' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:32'],
            'country_code' => ['nullable', 'string', Rule::in(array_keys(Regions::countries()))],
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^\+?[0-9 ()\-.]{4,32}$/'],
            'email' => ['nullable', 'email:rfc', 'max:254'],
            'timezone' => ['required', 'string', Rule::in(DateTimeZone::listIdentifiers())],
            'hours' => ['nullable', 'array'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Give the location a name.',
            'email.email' => 'Enter a valid email address, like name@example.com.',
            'phone.regex' => 'Enter a phone number using digits, spaces, + ( ) - or .',
            'country_code.in' => 'Choose a country from the list.',
            'timezone.in' => 'Choose a timezone from the list.',
            '*.max' => 'The :attribute is too long: use at most :max characters.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['address_line1' => 'address', 'address_line2' => 'address line 2', 'postal_code' => 'postal code', 'country_code' => 'country'];
    }

    /** @return array<int, \Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $hours = $this->input('hours');
            if (! is_array($hours)) {
                return;
            }

            foreach (BusinessHours::DAYS as $day => $name) {
                $row = $hours[$day] ?? $hours[(string) $day] ?? null;
                if (! is_array($row) || filter_var($row['closed'] ?? false, FILTER_VALIDATE_BOOL)) {
                    continue;
                }

                $open = trim((string) ($row['open'] ?? ''));
                $close = trim((string) ($row['close'] ?? ''));

                if ($open === '' && $close === '') {
                    continue; // treated as closed
                }

                $time = '/^(?:[01]\d|2[0-3]):[0-5]\d$/';
                if (! preg_match($time, $open)) {
                    $validator->errors()->add("hours.{$day}.open", "Enter the opening time for {$name} as hours and minutes, like 08:00.");
                } elseif (! preg_match($time, $close)) {
                    $validator->errors()->add("hours.{$day}.close", "Enter the closing time for {$name} as hours and minutes, like 17:00.");
                } elseif ($open >= $close) {
                    $validator->errors()->add("hours.{$day}.close", "On {$name} the closing time must be after the opening time.");
                }
            }
        }];
    }

    /**
     * The validated details in the shape the domain takes, opening hours in storage format.
     *
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return $this->safe()->except('hours') + ['business_hours' => BusinessHours::fromForm($this->input('hours'))];
    }
}

<?php

namespace App\Http\Requests\Telehealth;

use App\Domain\Shared\DomainException;
use App\Domain\Telehealth\Providers\MeetingLinkPolicy;
use App\Domain\Telehealth\TelehealthSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The telehealth settings form. The default meeting link is a write-only secret: leaving it empty keeps the stored
 * one, "remove" clears it, a new value is checked against the hosts being saved in the same request.
 */
final class TelehealthSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route's `can:telehealth.manage`
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'allowed_hosts' => ['required', 'string', 'max:2000'],
            'join_early_minutes' => ['required', 'integer', 'min:0', 'max:'.TelehealthSettings::MAX_EARLY_MINUTES],
            'recording_enabled' => ['nullable', 'boolean'],
            'ai_transcripts_enabled' => ['nullable', 'boolean'],
            'default_link' => ['nullable', 'string', 'max:'.MeetingLinkPolicy::MAX_LENGTH],
            'remove_default_link' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'allowed_hosts' => 'allowed meeting-link hosts', 'join_early_minutes' => 'join window',
            'default_link' => 'default meeting link',
        ];
    }

    /** @return array<int, \Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $lines = preg_split('/[\r\n,]+/', strtolower((string) $this->input('allowed_hosts'))) ?: [];
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line !== '' && ! MeetingLinkPolicy::validPattern($line)) {
                    $validator->errors()->add('allowed_hosts', "“{$line}” is not a valid host. Use names like zoom.us or *.zoom.us.");

                    return;
                }
            }
            if (MeetingLinkPolicy::parseHosts((string) $this->input('allowed_hosts')) === []) {
                $validator->errors()->add('allowed_hosts', 'List at least one video host, or meeting links cannot be used.');

                return;
            }

            $link = trim((string) $this->input('default_link', ''));
            if ($link !== '' && ! $this->boolean('remove_default_link')) {
                try {
                    MeetingLinkPolicy::check($link, MeetingLinkPolicy::parseHosts((string) $this->input('allowed_hosts')), 'default_link');
                } catch (DomainException $e) {
                    $validator->errors()->add('default_link', $e->userMessage());
                }
            }
        }];
    }

    /** @return array<string, mixed> setting key => value (the stored default link is left alone unless replaced or removed) */
    public function values(): array
    {
        $values = [
            'telehealth.allowed_hosts' => implode("\n", MeetingLinkPolicy::parseHosts((string) $this->validated('allowed_hosts'))),
            'telehealth.join_early_minutes' => (int) $this->validated('join_early_minutes'),
            'telehealth.recording_enabled' => $this->boolean('recording_enabled'),
            'telehealth.ai_transcripts_enabled' => $this->boolean('ai_transcripts_enabled'),
        ];

        $link = trim((string) $this->validated('default_link', ''));
        if ($this->boolean('remove_default_link')) {
            $values['telehealth.default_link_secret'] = null;
        } elseif ($link !== '') {
            $values['telehealth.default_link_secret'] = $link;
        }

        return $values;
    }
}

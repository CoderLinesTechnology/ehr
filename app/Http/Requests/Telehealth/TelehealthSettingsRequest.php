<?php

namespace App\Http\Requests\Telehealth;

use App\Domain\Telehealth\TelehealthSettings;
use Illuminate\Foundation\Http\FormRequest;

/** The telehealth settings form: the join window and the two opt-ins (recording, AI transcripts). */
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
            'join_early_minutes' => ['required', 'integer', 'min:0', 'max:'.TelehealthSettings::MAX_EARLY_MINUTES],
            'recording_enabled' => ['nullable', 'boolean'],
            'ai_transcripts_enabled' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['join_early_minutes' => 'join window'];
    }

    /** @return array<string, mixed> setting key => value */
    public function values(): array
    {
        return [
            'telehealth.join_early_minutes' => (int) $this->validated('join_early_minutes'),
            'telehealth.recording_enabled' => $this->boolean('recording_enabled'),
            'telehealth.ai_transcripts_enabled' => $this->boolean('ai_transcripts_enabled'),
        ];
    }
}

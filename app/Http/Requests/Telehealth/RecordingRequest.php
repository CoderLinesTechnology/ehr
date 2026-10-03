<?php

namespace App\Http\Requests\Telehealth;

use App\Domain\Telehealth\AttachRecording;
use Illuminate\Foundation\Http\FormRequest;

/** The uploaded file's real type is decided by the domain action (byte sniffing); this only bounds the size. */
final class RecordingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route's `can:clinical,session`
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['recording' => ['required', 'file', 'max:'.(AttachRecording::MAX_BYTES / 1024)]];
    }
}

<?php

namespace App\Http\Requests\Telehealth;

use App\Domain\Telehealth\SaveSessionNotes;
use Illuminate\Foundation\Http\FormRequest;

final class SaveNotesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route's `can:clinical,session`
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['notes' => ['required', 'string', 'max:'.SaveSessionNotes::MAX_LENGTH]];
    }
}

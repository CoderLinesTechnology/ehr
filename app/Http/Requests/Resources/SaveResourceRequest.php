<?php

namespace App\Http\Requests\Resources;

use App\Domain\Resources\ResourceAudience;
use App\Domain\Resources\ResourceFiles;
use App\Domain\Resources\ResourceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create and edit form of a resource (the same shape). Authorization is declared on the routes; the
 * PDF itself is judged by its content inside the domain (ResourceFiles), not by name or declared type.
 */
final class SaveResourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(ResourceType::values())],
            'title' => ['required', 'string', 'max:120'],
            'summary' => ['required', 'string', 'max:300'],
            'body' => ['nullable', 'string', 'max:20000'],
            'external_url' => ['nullable', 'string', 'max:2048', 'regex:#^https://[^/@\s]+([/?\#]|$)#i', 'url:https'],
            'reading_minutes' => ['nullable', 'integer', 'min:1', 'max:600'],
            'audience' => ['required', 'string', Rule::in(ResourceAudience::values())],
            'is_featured' => ['nullable', 'boolean'],
            'file' => ['nullable', 'file', 'max:'.(ResourceFiles::MAX_BYTES / 1024)],
            'remove_file' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'title.required' => 'Give the resource a title.',
            'summary.required' => 'Add a short description.',
            'external_url.regex' => 'The link must be a full https:// address.',
            'external_url.url' => 'The link must be a full https:// address.',
            'file.max' => 'The PDF must be 20 MB or smaller.',
            'file.file' => 'The file could not be uploaded. Try again.',
        ];
    }

    /** @return array<string, mixed> */
    public function resourceInput(): array
    {
        return $this->safe()->except(['file', 'remove_file']);
    }
}

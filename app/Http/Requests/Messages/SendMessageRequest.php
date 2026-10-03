<?php

namespace App\Http\Requests\Messages;

use Illuminate\Foundation\Http\FormRequest;

/** Shape only; the length rule, the file sniffing and access live in SendMessage. */
final class SendMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // the route's `can:view,conversation` decides
    }

    public function rules(): array
    {
        return [
            'body' => ['nullable', 'string', 'max:20000'],
            'attachment' => ['nullable', 'file', 'max:10240'],
        ];
    }
}

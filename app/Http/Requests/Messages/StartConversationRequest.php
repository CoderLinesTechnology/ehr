<?php

namespace App\Http\Requests\Messages;

use App\Domain\Clients\ClientVisibility;
use App\Domain\Messaging\ConversationKind;
use App\Models\Client;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StartConversationRequest extends FormRequest
{
    public ?Client $client = null;

    public function authorize(): bool
    {
        return true;   // StartConversation checks the kind's permission
    }

    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::enum(ConversationKind::class)],
            'title' => ['nullable', 'string', 'max:200'],
            'participants' => ['nullable', 'array', 'max:60'],
            'participants.*' => ['bail', 'string', 'uuid'],
            'client_id' => ['nullable', 'bail', 'string', 'uuid'],
        ];
    }

    /** A client id outside the member's visibility simply "does not exist". */
    public function after(): array
    {
        return [function ($validator) {
            $id = $this->input('client_id');
            if (is_string($id) && $id !== '' && ! $validator->errors()->has('client_id')) {
                $this->client = ClientVisibility::apply(Client::query(), tenant()->membership())->find($id);
                if ($this->client === null) {
                    $validator->errors()->add('client_id', 'Choose a client you can see.');
                }
            }
        }];
    }

    public function kind(): ConversationKind
    {
        return ConversationKind::from((string) $this->validated('kind'));
    }
}

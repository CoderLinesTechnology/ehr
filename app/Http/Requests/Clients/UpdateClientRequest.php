<?php

namespace App\Http\Requests\Clients;

final class UpdateClientRequest extends ClientRequest
{
    protected function isNewClient(): bool
    {
        return false;
    }
}

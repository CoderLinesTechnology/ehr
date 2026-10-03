<?php

namespace App\Http\Requests\Clients;

final class StoreClientRequest extends ClientRequest
{
    protected function isNewClient(): bool
    {
        return true;
    }
}

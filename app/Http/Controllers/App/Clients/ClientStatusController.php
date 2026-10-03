<?php

namespace App\Http\Controllers\App\Clients;

use App\Domain\Clients\ChangeClientStatus;
use App\Domain\Clients\ClientStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clients\ChangeClientStatusRequest;
use App\Models\Client;
use Illuminate\Http\RedirectResponse;

/**
 * Archive, restore, mark inactive / active. Who may (clients.archive for
 * archiving and restoring, clients.edit for the other moves) is decided by
 * the form request before anything is validated. Clients are never deleted.
 */
final class ClientStatusController extends Controller
{
    public function __invoke(ChangeClientStatusRequest $request, Client $client, ChangeClientStatus $change): RedirectResponse
    {
        $from = $client->status;
        $to = ClientStatus::from($request->validated('status'));

        $change($client, $to, $request->user(), $request->validated('reason'));

        $message = match (true) {
            $from === $to => 'No change: the client is already '.mb_strtolower($to->label()).'.',
            $to === ClientStatus::Archived => 'Client archived. The record is kept and can be restored.',
            $to === ClientStatus::Inactive => 'Client marked inactive.',
            $from === ClientStatus::Archived => 'Client restored.',
            default => 'Client marked active.',
        };

        return redirect()->route('app.clients.show', ['client' => $client])->with('success', $message);
    }
}

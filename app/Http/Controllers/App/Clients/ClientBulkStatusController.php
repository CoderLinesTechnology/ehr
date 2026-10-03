<?php

namespace App\Http\Controllers\App\Clients;

use App\Domain\Clients\ChangeClientStatus;
use App\Domain\Clients\ClientStatus;
use App\Domain\Clients\ClientVisibility;
use App\Domain\Saas\LimitReached;
use App\Domain\Shared\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clients\BulkClientStatusRequest;
use App\Models\Client;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Mark the ticked clients inactive. Each client goes through the same rule as the profile's button:
 * only clients the member can see are even looked up, ClientPolicy::changeStatus decides per client,
 * and ChangeClientStatus owns the transition (own transaction, audit and timeline entry per client).
 * Clients that cannot be changed are only counted, never named.
 */
final class ClientBulkStatusController extends Controller
{
    public function __invoke(BulkClientStatusRequest $request, ChangeClientStatus $change): RedirectResponse
    {
        $membership = tenant()->membership();

        $clients = ClientVisibility::apply(Client::query()->whereIn('id', $request->validated('clients')), $membership)->get();

        $changed = 0;
        foreach ($clients as $client) {
            if (! Gate::forUser($request->user())->allows('changeStatus', [$client, ClientStatus::Inactive])) {
                continue;
            }

            try {
                $wasInactive = $client->status === ClientStatus::Inactive;
                $change($client, ClientStatus::Inactive, $request->user(), $request->validated('reason'));
                $changed += $wasInactive ? 0 : 1;
            } catch (DomainException|LimitReached) {
                // e.g. an archived client: skipped, reported in the total below
            }
        }

        $skipped = count($request->validated('clients')) - $changed;

        if ($changed === 0) {
            return redirect()->back()->with('error', 'No clients were changed. Archived clients, or clients you may not edit, are left as they are.');
        }

        return redirect()->back()->with('success', $changed.' '.($changed === 1 ? 'client' : 'clients').' marked inactive.'.($skipped > 0 ? " {$skipped} left unchanged." : ''));
    }
}

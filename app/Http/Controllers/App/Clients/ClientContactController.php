<?php

namespace App\Http\Controllers\App\Clients;

use App\Domain\Clients\DeleteClientContact;
use App\Domain\Clients\SaveClientContact;
use App\Http\Requests\Clients\ClientContactRequest;
use App\Models\Client;
use App\Models\ClientContact;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Emergency and other contacts of a client. Reading needs to see the client;
 * adding, changing and removing need `clients.edit` (the routes' `can:`
 * middleware). {contact} is resolved through the client (scoped binding), so
 * another client's contact id is a 404 here.
 */
final class ClientContactController extends ClientProfileController
{
    public function index(Request $request, Client $client): View
    {
        $this->recordView($request, $client, 'contacts');

        return view('app.clients.contacts', $this->header($client, 'contacts') + [
            'contacts' => $client->contacts()->get(),
            'canEdit' => Gate::allows('update', $client),
            'maxContacts' => SaveClientContact::MAX_PER_CLIENT,
            'country' => tenant()->organizationOrFail()->country_code,
        ]);
    }

    public function store(ClientContactRequest $request, Client $client, SaveClientContact $save): RedirectResponse
    {
        $save($client, $request->validated(), null, $request->user());

        return redirect()->route('app.clients.contacts.index', ['client' => $client])
            ->with('success', 'Contact added.');
    }

    public function edit(Request $request, Client $client, ClientContact $contact): View
    {
        $this->recordView($request, $client, 'contacts');

        return view('app.clients.contact-edit', $this->header($client, 'contacts') + [
            'contact' => $contact,
            'country' => tenant()->organizationOrFail()->country_code,
        ]);
    }

    public function update(ClientContactRequest $request, Client $client, ClientContact $contact, SaveClientContact $save): RedirectResponse
    {
        $save($client, $request->validated(), $contact, $request->user());

        return redirect()->route('app.clients.contacts.index', ['client' => $client])
            ->with('success', 'Contact saved.');
    }

    public function destroy(Request $request, Client $client, ClientContact $contact, DeleteClientContact $delete): RedirectResponse
    {
        $delete($client, $contact, $request->user());

        return redirect()->route('app.clients.contacts.index', ['client' => $client])
            ->with('success', 'Contact removed.');
    }
}

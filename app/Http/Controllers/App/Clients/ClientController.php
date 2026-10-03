<?php

namespace App\Http\Controllers\App\Clients;

use App\Domain\Clients\ClientDirectory;
use App\Domain\Clients\ClientFormOptions;
use App\Domain\Clients\ClientListFilters;
use App\Domain\Clients\ClientSex;
use App\Domain\Clients\ContactMethod;
use App\Domain\Clients\CreateClient;
use App\Domain\Clients\UpdateClient;
use App\Http\Requests\Clients\StoreClientRequest;
use App\Http\Requests\Clients\UpdateClientRequest;
use App\Models\Client;
use App\Support\Regions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Client list, registration and the profile's overview and edit screens.
 * Authorization is declared on the routes (`can:` middleware); this class
 * validates input shape, calls one domain action and presents the result.
 */
final class ClientController extends ClientProfileController
{
    public const PER_PAGE = 25;

    public function index(Request $request, ClientDirectory $directory, ClientFormOptions $options): View
    {
        $filters = ClientListFilters::from($request->query());

        $clients = $directory->query(tenant()->membership(), $filters)
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('app.clients.index', [
            'clients' => $clients,
            'filters' => $filters,
            'clinicians' => $options->clinicians(),
            'canCreate' => Gate::allows('create', Client::class),
            'country' => tenant()->organizationOrFail()->country_code,
        ]);
    }

    public function create(ClientFormOptions $options): View
    {
        return view('app.clients.create', $this->formData($options, null));
    }

    public function store(StoreClientRequest $request, CreateClient $createClient): RedirectResponse
    {
        $client = $createClient($request->validated(), $request->user());

        return redirect()->route('app.clients.show', ['client' => $client])
            ->with('success', 'Client '.$client->formattedNumber().' has been added.');
    }

    public function show(Request $request, Client $client): View
    {
        $this->recordView($request, $client, 'overview');

        $client->load(['primaryClinician.user:id,name', 'primaryLocation:id,name', 'contacts']);

        return view('app.clients.show', $this->header($client, 'overview') + [
            'country' => tenant()->organizationOrFail()->country_code,
        ]);
    }

    public function edit(Request $request, Client $client, ClientFormOptions $options): View
    {
        $this->recordView($request, $client, 'edit');

        return view('app.clients.edit', $this->formData($options, $client));
    }

    public function update(UpdateClientRequest $request, Client $client, UpdateClient $updateClient): RedirectResponse
    {
        $updateClient($client, $request->validated(), $request->user());

        return redirect()->route('app.clients.show', ['client' => $client])
            ->with('success', 'Changes saved.');
    }

    /** @return array<string, mixed> */
    private function formData(ClientFormOptions $options, ?Client $client): array
    {
        return [
            'client' => $client,
            'clinicians' => $options->clinicians($client?->primary_clinician_membership_id),
            'locations' => $options->locations($client?->primary_location_id),
            'sexOptions' => ClientSex::options(),
            'contactMethods' => ContactMethod::options(),
            'countries' => Regions::countries(),
            'country' => tenant()->organizationOrFail()->country_code,
        ];
    }
}

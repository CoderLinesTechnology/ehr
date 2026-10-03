<?php

namespace App\Http\Controllers\App\Clients;

use App\Domain\Clients\BillingType;
use App\Domain\Clients\ClientContactPoints;
use App\Domain\Clients\ClientDirectory;
use App\Domain\Clients\ClientFormOptions;
use App\Domain\Clients\ClientListFilters;
use App\Domain\Clients\ClientListRows;
use App\Domain\Clients\ClientRelationships;
use App\Domain\Clients\ClientStatus;
use App\Domain\Clients\ClientType;
use App\Domain\Clients\ClientVisibility;
use App\Domain\Clients\CreateClient;
use App\Domain\Clients\CreateCouple;
use App\Domain\Clients\UpdateClient;
use App\Http\Requests\Clients\StoreClientRequest;
use App\Http\Requests\Clients\UpdateClientRequest;
use App\Models\Client;
use App\Models\OrganizationMembership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

/**
 * Client list, registration and the profile's overview and edit screens.
 * Authorization is declared on the routes (`can:` middleware); this class
 * validates input shape, calls one domain action and presents the result.
 */
final class ClientController extends ClientProfileController
{
    /** The comp shows eight rows ("Showing 1–8 of 48 clients"). */
    public const PER_PAGE = 8;

    public function index(Request $request, ClientDirectory $directory, ClientListRows $rows, ClientFormOptions $options, ClientRelationships $relationships): View
    {
        $membership = tenant()->membership();

        // The list is ordered by client number unless the URL says otherwise (the comp's order).
        $query = $request->query();
        $query['sort'] ??= 'client_number';
        $filters = ClientListFilters::from($query);

        $clients = $directory->listQuery($membership, $filters)
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $access = $rows->appointmentAccess($membership);

        return view('app.clients.index', [
            'clients' => $clients,
            'filters' => $filters,
            'appointments' => $rows->appointmentsFor($clients->getCollection(), $membership, $access),
            'relationships' => $relationships->forPage($clients->getCollection(), $membership),
            'showAppointments' => $access !== null,
            'stats' => $rows->stats($membership, $access),
            'clinicians' => $options->clinicians(),
            'locations' => [ClientListFilters::VIRTUAL => 'Virtual (telehealth)'] + $options->locations(),
            'clientTypes' => ClientType::options(),
            'billingOptions' => BillingType::options(),
            // The live/demo filter only means something while the organization holds demo data.
            'hasDemo' => $filters->records !== 'all' || Client::query()->demo()->exists(),
            'canCreate' => Gate::allows('create', Client::class),
            'canBulk' => Gate::allows('clients.edit'),
            'canEdit' => Gate::allows('clients.edit'),
            'canBook' => Route::has('app.appointments.create') && $access !== null && Gate::allows('appointments.create'),
            'seesAll' => ClientVisibility::seesAll($membership),
            'country' => tenant()->organizationOrFail()->country_code,
        ]);
    }

    public function create(ClientFormOptions $options): View
    {
        return view('app.clients.create', $options->form(null, tenant()->membership()));
    }

    /**
     * A person (CreateClient) or a couple (CreateCouple). From New appointment (`then=appointment`) the new
     * client is chosen there on the way back, with the step-1 choices it carried (sanitised: ids, a modality,
     * a date and a time; nothing else survives).
     */
    public function store(StoreClientRequest $request, CreateClient $createClient, CreateCouple $createCouple): RedirectResponse
    {
        $membership = tenant()->membership();
        $input = $request->validated();
        $carried = $request->fromAppointment() ? $request->carried() : [];

        if ($request->fromAppointment() && blank($input['primary_clinician_membership_id'] ?? null)) {
            // Booking for someone you cannot see is a dead end: a clinician without clients.view_all sees their own clients.
            $input['primary_clinician_membership_id'] = $this->defaultClinician($carried['clinician'] ?? null, $membership);
        }

        $client = $request->clientType() === ClientType::Couple
            ? $createCouple($input, $request->user())
            : $createClient($input, $request->user());

        if (! $request->fromAppointment()) {
            return redirect()->route('app.clients.show', ['client' => $client])
                ->with('success', 'Client '.$client->formattedNumber().' has been added.');
        }

        if (! ClientVisibility::allows($client, $membership)) {
            return redirect()->route('app.appointments.create', $carried)->with('error',
                'Client '.$client->formattedNumber().' has been added, but they are not in your client list, so you cannot book for them here. '
                .'Ask a colleague who can see all clients to book, or assign them a clinician you work with.');
        }

        return redirect()->route('app.appointments.create', ['client' => $client->id] + $carried)
            ->with('success', 'Client '.$client->formattedNumber().' has been added. Now choose the appointment details.');
    }

    /** New appointment's clinician when it is an active provider, else the creator when they are one. */
    private function defaultClinician(?string $chosen, OrganizationMembership $membership): ?string
    {
        if ($chosen !== null && OrganizationMembership::query()->providers()->whereKey($chosen)->exists()) {
            return $chosen;
        }

        return $membership->isActive() && $membership->is_provider ? $membership->id : null;
    }

    public function show(Request $request, Client $client, ClientListRows $rows, ClientRelationships $relationships, ClientContactPoints $contactPoints): View
    {
        $this->recordView($request, $client, 'overview');

        $client->load(['primaryClinician.user:id,name', 'primaryLocation:id,name', 'contacts', 'contactPoints']);

        $membership = tenant()->membership();
        $access = $rows->appointmentAccess($membership);
        $links = $relationships->all(collect([$client]), $membership)[$client->id];

        return view('app.clients.show', $this->header($client, 'overview') + [
            'country' => tenant()->organizationOrFail()->country_code,
            'statusActions' => $this->statusActions($client),
            'appointments' => $rows->appointmentsFor(collect([$client]), $membership, $access)[$client->id],
            'showAppointments' => $access !== null,
            'points' => $contactPoints->effective($client),
            'coupleLinks' => array_values(array_filter($links, fn (array $line) => in_array($line['label'], ['Members', 'Couple'], true))),
        ]);
    }

    /**
     * The status moves this member may make from the client's current status (the domain's transition table,
     * filtered through ClientPolicy::changeStatus so a button is never offered that the request would refuse).
     *
     * @return list<array{to: ClientStatus, label: string, reason: bool, title: string, message: string, confirm: string, icon: string, danger: bool}>
     */
    private function statusActions(Client $client): array
    {
        $moves = match ($client->status) {
            ClientStatus::Pending => [ClientStatus::Active, ClientStatus::Inactive, ClientStatus::Archived],
            ClientStatus::Active => [ClientStatus::Inactive, ClientStatus::Archived],
            ClientStatus::Inactive => [ClientStatus::Active, ClientStatus::Archived],
            ClientStatus::Archived => [ClientStatus::Active],
        };

        $actions = [];
        foreach ($moves as $to) {
            if (! Gate::allows('changeStatus', [$client, $to])) {
                continue;
            }
            $restore = $client->status === ClientStatus::Archived;
            $actions[] = match ($to) {
                ClientStatus::Active => [
                    'to' => $to, 'label' => $restore ? 'Restore' : 'Mark active', 'reason' => false, 'icon' => $restore ? 'archive-restore' : 'user-check',
                    'title' => $restore ? 'Restore this client?' : 'Mark this client active?',
                    'message' => 'They will count towards your plan\'s active-client limit.',
                    'confirm' => $restore ? 'Restore client' : 'Mark active', 'danger' => false,
                ],
                ClientStatus::Inactive => [
                    'to' => $to, 'label' => 'Mark inactive', 'reason' => true, 'icon' => 'user-minus',
                    'title' => 'Mark this client inactive?',
                    'message' => 'Inactive clients stay in the list and keep their record. They no longer count towards the active-client limit.',
                    'confirm' => 'Mark inactive', 'danger' => false,
                ],
                default => [
                    'to' => $to, 'label' => 'Archive', 'reason' => true, 'icon' => 'archive',
                    'title' => 'Archive this client?',
                    'message' => 'Archived clients are hidden from everyday lists. Their record is kept, and you can restore them later.',
                    'confirm' => 'Archive client', 'danger' => true,
                ],
            };
        }

        return $actions;
    }

    public function edit(Request $request, Client $client, ClientFormOptions $options): View
    {
        $this->recordView($request, $client, 'edit');

        return view('app.clients.edit', $options->form($client, tenant()->membership()));
    }

    public function update(UpdateClientRequest $request, Client $client, UpdateClient $updateClient): RedirectResponse
    {
        $updateClient($client, $request->validated(), $request->user());

        return redirect()->route('app.clients.show', ['client' => $client])
            ->with('success', 'Changes saved.');
    }
}

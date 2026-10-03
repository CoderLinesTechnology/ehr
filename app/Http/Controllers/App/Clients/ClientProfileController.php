<?php

namespace App\Http\Controllers\App\Clients;

use App\Domain\Clients\RecordClientAccess;
use App\Domain\Saas\EntitlementService;
use App\Domain\Saas\FeatureRegistry;
use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/**
 * Shared by every screen of one client's profile (overview, appointments,
 * timeline, contacts, edit): the sensitive-access audit and the header
 * (name, badges, quick actions, tabs) they all render.
 *
 * The route's `can:view,client` middleware has already decided the user may
 * see this client (404 otherwise); nothing here re-checks visibility.
 */
abstract class ClientProfileController extends Controller
{
    public function __construct(private readonly RecordClientAccess $recordView) {}

    /** `client.viewed` in the audit trail: once per user and client per ten minutes. */
    protected function recordView(Request $request, Client $client, string $tab): void
    {
        ($this->recordView)($client, $request->user(), $tab);
    }

    /**
     * What the profile header needs.
     *
     * @return array{client: Client, tab: string, tabs: list<array<string, mixed>>, can: array<string, bool>, bookUrl: ?string}
     */
    protected function header(Client $client, string $tab): array
    {
        $organization = tenant()->organizationOrFail();
        $entitlements = app(EntitlementService::class);

        // The header names the primary clinician on every tab; the overview has usually loaded them already.
        $client->loadMissing('primaryClinician.user:id,name');

        $showAppointments = $entitlements->allows($organization, FeatureRegistry::CALENDAR)
            && Gate::any(['appointments.view', 'appointments.view_all']);

        $tabs = [
            ['label' => 'Overview', 'icon' => 'user', 'url' => route('app.clients.show', ['client' => $client]), 'active' => $tab === 'overview'],
        ];

        if ($showAppointments) {
            $tabs[] = ['label' => 'Appointments', 'icon' => 'calendar', 'url' => route('app.clients.appointments', ['client' => $client]), 'active' => $tab === 'appointments'];
        }

        $tabs[] = ['label' => 'Timeline', 'icon' => 'history', 'url' => route('app.clients.timeline', ['client' => $client]), 'active' => $tab === 'timeline'];
        $tabs[] = ['label' => 'Contacts', 'icon' => 'phone', 'url' => route('app.clients.contacts.index', ['client' => $client]), 'active' => $tab === 'contacts'];

        $archived = $client->status->value === 'archived';
        $canBook = ! $archived
            && Route::has('app.appointments.create')
            && $entitlements->allows($organization, FeatureRegistry::CALENDAR)
            && Gate::allows('appointments.create');

        return [
            'client' => $client,
            'tab' => $tab,
            'tabs' => $tabs,
            'can' => [
                'update' => Gate::allows('update', $client),
                'archive' => Gate::allows('archive', $client),
            ],
            'bookUrl' => $canBook ? route('app.appointments.create', ['client' => $client]) : null,
        ];
    }
}

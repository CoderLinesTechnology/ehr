<?php

namespace App\Http\Controllers\App\Clients;

use App\Domain\Identity\PermissionResolver;
use App\Models\Appointment;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The profile's Appointments tab: read-only, the Scheduling module owns the
 * data and the actions. Someone with `appointments.view_all` sees every
 * appointment of the client; someone with only `appointments.view` sees the
 * ones they are the clinician on, even where the client is theirs through
 * the primary-clinician rule.
 */
final class ClientAppointmentController extends ClientProfileController
{
    public const UPCOMING_LIMIT = 25;

    public const PAST_PER_PAGE = 15;

    /** Columns the tab shows: no scheduling notes, prices or cancellation reasons. */
    private const COLUMNS = [
        'id', 'organization_id', 'record_environment', 'client_id', 'service_id', 'clinician_membership_id', 'location_id',
        'starts_at', 'ends_at', 'timezone', 'modality', 'status',
    ];

    public function __invoke(Request $request, Client $client, PermissionResolver $permissions): View
    {
        $membership = tenant()->membership();
        $all = $permissions->membershipHas($membership, 'appointments.view_all');

        // The appointments tab needs an appointments permission of its own, on top of seeing the client.
        abort_unless($all || $permissions->membershipHas($membership, 'appointments.view'), 403);

        $this->recordView($request, $client, 'appointments');

        $appointments = fn () => Appointment::query()
            ->select(self::COLUMNS)
            ->where('client_id', $client->id)
            ->when(! $all, fn ($query) => $query->where('clinician_membership_id', $membership->id))
            ->with(['service:id,name', 'clinician.user:id,name', 'location:id,name']);

        $now = now();

        // One more than shown, to know whether to say "and more".
        $upcoming = $appointments()->where('starts_at', '>=', $now)->orderBy('starts_at')->orderBy('id')
            ->limit(self::UPCOMING_LIMIT + 1)->get();

        $past = $appointments()->where('starts_at', '<', $now)->orderByDesc('starts_at')->orderByDesc('id')
            ->paginate(self::PAST_PER_PAGE)->withQueryString();

        return view('app.clients.appointments', $this->header($client, 'appointments') + [
            'upcoming' => $upcoming->take(self::UPCOMING_LIMIT),
            'upcomingHasMore' => $upcoming->count() > self::UPCOMING_LIMIT,
            'past' => $past,
            'ownOnly' => ! $all,
        ]);
    }
}

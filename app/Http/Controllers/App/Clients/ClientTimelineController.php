<?php

namespace App\Http\Controllers\App\Clients;

use App\Domain\Clients\ClientTimelineReader;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The profile's Timeline tab: newest first, grouped by day, keyset-paginated with ?before=<cursor>. */
final class ClientTimelineController extends ClientProfileController
{
    public function __invoke(Request $request, Client $client, ClientTimelineReader $reader): View
    {
        $this->recordView($request, $client, 'timeline');

        $before = $request->query('before');
        $page = $reader->page($client, tenant()->membership(), is_string($before) ? $before : null);

        return view('app.clients.timeline', $this->header($client, 'timeline') + [
            // Grouped by the organization's calendar day, in order.
            'days' => $page->entries->groupBy(fn ($entry) => fmt()->local($entry->occurred_at)->format('Y-m-d')),
            'nextUrl' => $page->next !== null
                ? route('app.clients.timeline', ['client' => $client, 'before' => $page->next])
                : null,
            'isFirstPage' => ! is_string($before) || $before === '',
        ]);
    }
}

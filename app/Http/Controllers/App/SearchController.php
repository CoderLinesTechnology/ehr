<?php

namespace App\Http\Controllers\App;

use App\Domain\Clients\GlobalSearch;
use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Global search (the box in the top bar): a thin page over GlobalSearch, which decides per section whether
 * the member may search it at all and bounds every section (10 clients, 5 staff).
 *
 * Asked for JSON (Accept: application/json), it answers the clients section only, for the client pickers'
 * type-ahead (the couple form): id, name, number and type per client — nothing a result row on the page
 * does not already show.
 */
final class SearchController extends Controller
{
    public function __invoke(Request $request, GlobalSearch $search): View|JsonResponse
    {
        $term = $request->query('q');

        if ($request->wantsJson()) {
            $hits = $search->clients(tenant()->membership(), is_string($term) ? $term : null);

            return response()->json([
                'clients' => $hits->items->map(fn (Client $client) => [
                    'id' => $client->id,
                    'name' => $client->displayName(),
                    'number' => $client->formattedNumber(),
                    'type' => $client->client_type->value,
                    'archived' => $client->status->value === 'archived',
                ])->values(),
                'more' => $hits->more,
            ]);
        }

        return view('app.search.index', [
            'results' => $search(tenant()->membership(), is_string($term) ? $term : null),
            'country' => tenant()->organizationOrFail()->country_code,
        ]);
    }
}

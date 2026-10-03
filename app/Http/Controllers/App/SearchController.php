<?php

namespace App\Http\Controllers\App;

use App\Domain\Clients\GlobalSearch;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Global search (the box in the top bar): a thin page over GlobalSearch, which decides per section whether
 * the member may search it at all and bounds every section (10 clients, 5 staff).
 */
final class SearchController extends Controller
{
    public function __invoke(Request $request, GlobalSearch $search): View
    {
        $term = $request->query('q');

        return view('app.search.index', [
            'results' => $search(tenant()->membership(), is_string($term) ? $term : null),
            'country' => tenant()->organizationOrFail()->country_code,
        ]);
    }
}

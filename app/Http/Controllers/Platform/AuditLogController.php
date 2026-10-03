<?php

namespace App\Http\Controllers\Platform;

use App\Domain\Platform\PlatformAbility;
use App\Domain\Platform\Queries\PlatformAuditQuery;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Platform\Concerns\AuthorizesPlatform;
use App\Http\Controllers\Platform\Concerns\ReadsListQuery;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The platform audit log: platform actions and system events. The query always goes through
 * AuditLog::platformVisible(), so tenant activity is out of reach of every filter on this page.
 */
final class AuditLogController extends Controller
{
    use AuthorizesPlatform;
    use ReadsListQuery;

    public function index(Request $request, PlatformAuditQuery $query): View
    {
        $this->allow(PlatformAbility::ViewAuditLog);

        $from = $this->queryDate($request, 'from');
        $to = $this->queryDate($request, 'to');

        $entries = $query->paginate([
            'action' => $this->queryText($request, 'action'),
            'actor' => $this->queryText($request, 'actor', 254),
            'context' => $this->queryChoice($request, 'context', ['platform', 'system']),
            'from' => $from,
            // The chosen end date is included in full: everything before the start of the next day.
            'before' => $to?->addDay(),
        ])->withQueryString();

        return view('platform.audit.index', [
            'entries' => $entries,
            'filters' => [
                'action' => $this->queryText($request, 'action'),
                'actor' => $this->queryText($request, 'actor', 254),
                'context' => $this->queryChoice($request, 'context', ['platform', 'system']),
                'from' => $from !== null ? (string) $request->query('from') : '',
                'to' => $to !== null ? (string) $request->query('to') : '',
            ],
            'timezone' => $request->user()->timezone,
        ]);
    }
}

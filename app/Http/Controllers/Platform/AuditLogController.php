<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Platform\Concerns\ReadsListQuery;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * The platform audit log: platform actions and system events. The query always
 * goes through AuditLog::platformVisible(), so tenant activity (clinical or
 * administrative) is out of reach of every filter on this page.
 */
final class AuditLogController extends Controller
{
    use ReadsListQuery;

    private const PER_PAGE = 50;

    public function index(Request $request): View
    {
        Gate::authorize('platform.audit.view');

        $action = strtolower($this->queryText($request, 'action', 100));
        $actor = mb_strtolower($this->queryText($request, 'actor', 254));
        $context = $this->queryChoice($request, 'context', ['platform', 'system']);
        $from = $this->queryDate($request, 'from');
        // The chosen end date is included in full: everything before the start of the next day.
        $before = $this->queryDate($request, 'to')?->addDay();

        $query = AuditLog::query()
            ->platformVisible()
            ->when($context !== null, fn ($q) => $q->where('context', $context))
            ->when($action !== '', fn ($q) => $q->where('action', 'like', $this->prefixPattern($action)))
            ->when($actor !== '', function ($q) use ($actor) {
                // An address matches exactly; anything else matches the start of an address.
                $ids = User::query()
                    ->when(
                        str_contains($actor, '@'),
                        fn ($users) => $users->whereRaw('lower(email) = ?', [$actor]),
                        fn ($users) => $users->whereRaw('lower(email) like ?', [$this->prefixPattern($actor)]),
                    )
                    ->limit(50)
                    ->pluck('id');

                $q->whereIn('actor_user_id', $ids);
            })
            ->when($from !== null, fn ($q) => $q->where('occurred_at', '>=', $from))
            ->when($before !== null, fn ($q) => $q->where('occurred_at', '<', $before))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');

        // Simple pagination: the log only grows, so it is never counted.
        $entries = $query->simplePaginate(self::PER_PAGE)->withQueryString();

        $organizationIds = $entries->getCollection()->pluck('organization_id')->filter()->unique()->values()->all();
        $organizations = $organizationIds === []
            ? []
            : Organization::query()->whereIn('id', $organizationIds)->get(['id', 'name', 'slug'])->keyBy('id')->all();

        return view('platform.audit.index', [
            'entries' => $entries,
            'organizations' => $organizations,
            'filters' => [
                'action' => $this->queryText($request, 'action', 100),
                'actor' => $this->queryText($request, 'actor', 254),
                'context' => $context,
                'from' => $this->queryDate($request, 'from') ? $request->query('from') : '',
                'to' => $this->queryDate($request, 'to') ? $request->query('to') : '',
            ],
            'timezone' => $request->user()->timezone,
        ]);
    }
}

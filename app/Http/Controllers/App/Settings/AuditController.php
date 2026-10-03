<?php

namespace App\Http\Controllers\App\Settings;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Settings → Audit log: the organization's own entries, newest first, simple-paginated (an append-only table is never counted). */
final class AuditController extends Controller
{
    public const PER_PAGE = 30;

    public function index(Request $request): View
    {
        $organizationId = tenant()->organizationOrFail()->id;
        $q = trim((string) $request->query('q', ''));
        $area = preg_replace('/[^a-z_]/', '', strtolower((string) $request->query('area', ''))) ?? '';

        $entries = AuditLog::query()
            ->forOrganization($organizationId)
            ->with('actor:id,name')
            ->when($area !== '', fn ($query) => $query->where('action', 'like', $area.'.%'))
            ->when($q !== '', function ($query) use ($q) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower(mb_substr($q, 0, 100))).'%';
                $query->where(fn ($w) => $w->whereRaw('lower(summary) like ?', [$like])->orWhereRaw('lower(actor_label) like ?', [$like])->orWhere('action', 'like', $like));
            })
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->simplePaginate(self::PER_PAGE, ['id', 'occurred_at', 'action', 'actor_user_id', 'actor_label', 'summary', 'context'])
            ->withQueryString();

        return view('app.settings.audit.index', ['entries' => $entries, 'q' => $q, 'area' => $area]);
    }
}

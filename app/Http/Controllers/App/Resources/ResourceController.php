<?php

namespace App\Http\Controllers\App\Resources;

use App\Domain\Resources\ReadResource;
use App\Domain\Resources\ResourceFilters;
use App\Domain\Resources\ResourceFiles;
use App\Domain\Resources\UpcomingForViewer;
use App\Domain\Settings\SettingsService;
use App\Http\Controllers\Controller;
use App\Models\Resource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resources for everyone who may view them: the page (featured, latest, search, type tabs), one resource and
 * its PDF. Authorization is declared on the routes (`can:` middleware → ResourcePolicy).
 */
final class ResourceController extends Controller
{
    public function index(Request $request, ReadResource $read, UpcomingForViewer $upcoming, SettingsService $settings): View
    {
        $membership = tenant()->membership();
        $organization = tenant()->organizationOrFail();
        $canManage = $read->canManage($membership);
        $filters = ResourceFilters::from($request->query(), $canManage);

        return view('app.resources.index', [
            'filters' => $filters,
            'page' => $read->page($membership, $filters),
            'canManage' => $canManage,
            'upcoming' => ($upcoming)($membership, $organization),
            'quickLinks' => $this->quickLinks($settings, $upcoming->allowed($membership, $organization)),
            'supportEmail' => $this->supportEmail($settings),
            'canSeeCalendar' => $upcoming->allowed($membership, $organization) && Route::has('app.calendar.index'),
        ]);
    }

    public function show(Resource $resource): View
    {
        return view('app.resources.show', [
            'resource' => $resource,
            'canManage' => Gate::allows('update', $resource),
            'paragraphs' => $resource->body === null ? [] : preg_split("/\n{2,}/", $resource->body),
        ]);
    }

    /** The attached PDF. Authorized by the route (`can:file`); the path comes from the row, never from the URL. */
    public function file(Resource $resource): Response
    {
        $organization = tenant()->organizationOrFail();
        $path = (string) $resource->file_path;

        abort_unless(ResourceFiles::isOwnPath($path, $organization->id), 404);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($path), 404);

        $name = Str::slug($resource->title) ?: 'resource';

        return $disk->response($path, $name.'.pdf', [
            'Cache-Control' => 'private, no-cache',
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$name.'.pdf"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * The rail's links: only to screens that exist and that this member may open. "My Forms" and "My
     * Documents" appear when those modules do; "Contact Support" when the platform has a support address.
     *
     * @return list<array{label: string, icon: string, href: string}>
     */
    private function quickLinks(SettingsService $settings, bool $mayOpenCalendar): array
    {
        $links = [];
        if ($mayOpenCalendar && Route::has('app.calendar.index')) {
            $links[] = ['label' => 'My Appointments', 'icon' => 'calendar', 'href' => route('app.calendar.index')];
        }
        if (Route::has('app.forms.index')) {
            $links[] = ['label' => 'My Forms', 'icon' => 'clipboard-list', 'href' => route('app.forms.index')];
        }
        if (Route::has('app.documents.index')) {
            $links[] = ['label' => 'My Documents', 'icon' => 'file', 'href' => route('app.documents.index')];
        }
        if (($email = $this->supportEmail($settings)) !== null) {
            $links[] = ['label' => 'Contact Support', 'icon' => 'headset', 'href' => 'mailto:'.$email];
        }

        return $links;
    }

    private function supportEmail(SettingsService $settings): ?string
    {
        $email = $settings->platform('platform.support_email');

        return is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class OrganizationChooserController extends Controller
{
    public function __invoke(Request $request): View
    {
        $organizations = Organization::query()
            ->whereIn('id', $request->user()->activeMemberships()->select('organization_id'))
            ->orderBy('name')
            ->get(['id', 'slug', 'name', 'status', 'logo_path']);

        return view('organizations.choose', ['organizations' => $organizations]);
    }
}

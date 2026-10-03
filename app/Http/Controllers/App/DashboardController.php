<?php

namespace App\Http\Controllers\App;

use App\Domain\Dashboard\StaffDashboard;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Staff home: thin, the read model does the work (see StaffDashboard). */
final class DashboardController extends Controller
{
    public function __invoke(Request $request, StaffDashboard $dashboard): View
    {
        return view('app.dashboard', [
            'dashboard' => $dashboard->for($request->user(), tenant()->membership(), tenant()->organizationOrFail()),
        ]);
    }
}

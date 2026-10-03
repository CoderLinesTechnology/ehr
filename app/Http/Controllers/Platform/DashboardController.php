<?php

namespace App\Http\Controllers\Platform;

use App\Domain\Platform\PlatformDashboard;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

final class DashboardController extends Controller
{
    public function __invoke(Request $request, PlatformDashboard $dashboard): View
    {
        Gate::authorize('platform.dashboard.view');

        return view('platform.dashboard', [
            'data' => $dashboard($request->integer('period', PlatformDashboard::DEFAULT_PERIOD)),
            'periods' => PlatformDashboard::PERIODS,
            'timezone' => $request->user()->timezone,
        ]);
    }
}

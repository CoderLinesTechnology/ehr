<?php

namespace App\Http\Controllers\Platform;

use App\Domain\Platform\PlatformAbility;
use App\Domain\Platform\PlatformDashboard;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Platform\Concerns\AuthorizesPlatform;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class DashboardController extends Controller
{
    use AuthorizesPlatform;

    public function __invoke(Request $request, PlatformDashboard $dashboard): View
    {
        $this->allow(PlatformAbility::ViewDashboard);

        return view('platform.dashboard', [
            'data' => $dashboard($request->integer('period', PlatformDashboard::DEFAULT_PERIOD)),
            'periods' => PlatformDashboard::PERIODS,
            'timezone' => $request->user()->timezone,
        ]);
    }
}

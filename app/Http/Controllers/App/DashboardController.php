<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

/** Staff home. Owned by the dashboard module (fleshed out in its own change). */
final class DashboardController extends Controller
{
    public function __invoke(): View
    {
        return view('app.dashboard');
    }
}

<?php

namespace App\Http\Controllers\App\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

/** /settings opens the first section the member may use. */
final class SettingsController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        $first = SettingsSections::visible()[0] ?? null;

        abort_if($first === null, 403);

        return redirect($first['url']);
    }
}

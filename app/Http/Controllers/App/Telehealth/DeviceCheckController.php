<?php

namespace App\Http\Controllers\App\Telehealth;

use App\Domain\Settings\SettingsService;
use App\Http\Controllers\Controller;
use Illuminate\View\View;

/** The device check: the join page's preview panel without a session. Nothing is uploaded or recorded. */
final class DeviceCheckController extends Controller
{
    public function show(SettingsService $settings): View
    {
        $email = $settings->platform('platform.support_email');

        return view('app.telehealth.check', ['supportEmail' => is_string($email) && $email !== '' ? $email : null]);
    }
}

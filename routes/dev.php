<?php

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
|--------------------------------------------------------------------------
| Design-system styleguide (local only)
|--------------------------------------------------------------------------
| routes/web.php includes this file when the app is running locally. Everything here uses sample data: no
| database, no tenant, no signed-in user. The $shell composer is swapped for a stub on these routes so the
| layouts render from the sample shell below and never query settings.
*/

$sampleShell = static function (bool $platform = false): array {
    $item = static fn (string $key, string $label, string $icon, bool $active = false, ?int $badge = null): array => [
        'key' => $key, 'label' => $label, 'icon' => $icon, 'url' => '#'.$key, 'active' => $active, 'badge' => $badge,
    ];

    return [
        'platformName' => 'Carebase',
        'announcement' => ['message' => 'Scheduled maintenance on Sunday between 02:00 and 03:00 UTC. The app stays available but may be slower.', 'level' => 'info'],
        'legal' => ['termsUrl' => '#terms', 'privacyUrl' => '#privacy'],
        'user' => ['name' => 'Avery Mensah', 'email' => 'avery.mensah@example.org', 'initials' => 'AM'],
        'organization' => $platform ? null : ['name' => 'Harbor Light Behavioral Health', 'slug' => 'harbor-light', 'logoUrl' => null, 'isDemoDataPresent' => true],
        'organizations' => $platform ? [] : [
            ['name' => 'Harbor Light Behavioral Health', 'url' => '#harbor-light', 'current' => true],
            ['name' => 'Northfield Counseling Group', 'url' => '#northfield', 'current' => false],
        ],
        'nav' => $platform ? [
            $item('dashboard', 'Dashboard', 'activity', true),
            $item('organizations', 'Organizations', 'building'),
            $item('users', 'Users', 'users'),
            $item('plans', 'Plans & features', 'layers'),
            $item('settings', 'Platform settings', 'sliders'),
            $item('audit', 'Audit log', 'history'),
        ] : [
            $item('dashboard', 'Dashboard', 'home', true),
            $item('calendar', 'Calendar', 'calendar'),
            $item('clients', 'Clients', 'users'),
            $item('messages', 'Messages', 'message', false, 3),
            $item('tasks', 'Tasks', 'check-square', false, 12),
            $item('billing', 'Billing', 'credit-card'),
            $item('documents', 'Documents', 'file'),
            $item('telehealth', 'Telehealth', 'video'),
            $item('programs', 'Programs', 'layers'),
            $item('reports', 'Reports', 'bar-chart'),
        ],
        'secondaryNav' => $platform ? [] : [$item('settings', 'Settings', 'settings')],
        'searchUrl' => url('/dev/styleguide'),
        'accountUrl' => Route::has('account.profile') ? route('account.profile') : '#account',
        'logoutUrl' => route('dev.styleguide.logout'),
        'platformUrl' => $platform ? null : url('/dev/styleguide/platform'),
        'appUrl' => $platform ? url('/dev/styleguide') : null,
    ];
};

// Every icon the library knows, read from the component itself so the grid can never drift.
$iconNames = static function (): array {
    preg_match_all("/^\s+'([a-z0-9-]+)' => '/m", (string) file_get_contents(resource_path('views/components/ui/icon.blade.php')), $matches);

    return $matches[1];
};

// Layouts read $shell from the composer; on these routes they read the sample shell instead, so the composer is
// swapped for a stub (no settings queries, no tenant) before any view is made.
$useSampleShell = static function (): void {
    app()->instance(\App\View\ShellComposer::class, new class
    {
        public function compose($view): void {}
    });
};

Route::prefix('dev/styleguide')
    ->name('dev.styleguide.')
    ->group(function () use ($sampleShell, $iconNames, $useSampleShell) {
        Route::get('/', function () use ($sampleShell, $iconNames, $useSampleShell) {
            $useSampleShell();
            // Shared with every component, so controls named sg_* show their error state.
            view()->share('errors', (new ViewErrorBag)->put('default', new MessageBag([
                'sg_email' => ['Enter a valid email address, such as name@example.org.'],
                'sg_password' => ['The password must be at least 12 characters.', 'The password must contain at least one number.'],
                'sg_terms' => ['Please accept the terms to continue.'],
                'sg_role' => ['Choose a role.'],
            ])));

            $rows = collect(range(1, 6))->map(fn ($i) => [
                'name' => ['Jordan Avery', 'Kwame Boateng', 'Priya Natarajan', 'Sam Okafor', 'Lena Fischer', 'Mateo Alvarez'][$i - 1],
                'status' => ['Active', 'Active', 'Waitlist', 'Active', 'Discharged', 'Intake'][$i - 1],
                'next' => ['Tue 09:30', 'Wed 14:00', '—', 'Thu 11:15', '—', 'Fri 08:45'][$i - 1],
                'demo' => $i === 3,
            ]);

            return view('dev.styleguide', [
                'shell' => $sampleShell(),
                'rows' => $rows,
                'paginator' => new LengthAwarePaginator(range(31, 40), 137, 10, 4, ['path' => url('/dev/styleguide')]),
                'simplePaginator' => new Paginator(range(11, 20), 10, 2, ['path' => url('/dev/styleguide')]),
                'icons' => $iconNames(),
            ]);
        })->name('index');

        Route::get('/platform', function () use ($sampleShell, $useSampleShell) {
            $useSampleShell();

            return view('dev.layout-platform', ['shell' => $sampleShell(true)]);
        })->name('platform');
        Route::get('/auth', function () use ($sampleShell, $useSampleShell) {
            $useSampleShell();

            return view('dev.layout-auth', ['shell' => $sampleShell()]);
        })->name('auth');
        Route::get('/minimal', function () use ($sampleShell, $useSampleShell) {
            $useSampleShell();

            return view('dev.layout-minimal', ['shell' => $sampleShell()]);
        })->name('minimal');

        Route::get('/errors/{code}', function (int $code) {
            $messages = [403 => 'Your organization is suspended.', 404 => 'No query results for model [App\\Models\\Client] 0198-example', 419 => 'CSRF token mismatch.', 429 => 'Too Many Requests', 500 => 'SQLSTATE[08006] connection refused', 503 => 'Service Unavailable'];

            return response()->view("errors.{$code}", ['exception' => new HttpException($code, $messages[$code], null, $code === 429 ? ['Retry-After' => '45'] : [])], $code);
        })->whereIn('code', [403, 404, 419, 429, 500, 503])->name('error');

        // Targets for the sample forms: they only flash a message and come back.
        Route::post('/flash', function (Request $request) {
            $kind = (string) $request->input('kind');
            $statuses = ['verification-link-sent', 'two-factor-authentication-enabled', 'two-factor-authentication-confirmed', 'two-factor-authentication-disabled', 'recovery-codes-generated', 'password-updated', 'profile-information-updated'];

            $response = redirect(route('dev.styleguide.index').'#flash');

            return match (true) {
                in_array($kind, ['success', 'error', 'warning', 'info'], true) => $response->with($kind, ucfirst($kind).' message: this is how a flash looks after a redirect.'),
                in_array($kind, $statuses, true) => $response->with('status', $kind),
                default => $response->with('status', 'We have emailed your password reset link.'),
            };
        })->name('flash');

        Route::post('/logout', fn () => redirect()->route('dev.styleguide.index')->with('info', 'Signing out is not wired up in the styleguide.'))->name('logout');
    });

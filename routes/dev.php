<?php

use App\View\ShellComposer;
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

$sampleShell = static function (bool $platform = false, string $preset = 'default'): array {
    $item = static fn (string $key, string $label, string $icon, bool $active = false, ?int $badge = null): array => [
        'key' => $key, 'label' => $label, 'icon' => $icon, 'url' => '#'.$key, 'active' => $active, 'badge' => $badge,
    ];

    // Presets reproduce the shells of the comps (docs/design/comps) for pixel comparison:
    // "clients" = comp 10 (6 items, Clients active), "appointments" = comp 01 (10 items), "settings" = comp 02.
    $active = match ($preset) {
        'clients' => 'clients', 'appointments' => 'calendar', 'settings' => 'settings', default => 'dashboard'
    };
    $six = [
        $item('dashboard', 'Dashboard', 'house', $active === 'dashboard'),
        $item('clients', 'Clients', 'users', $active === 'clients'),
        $item('calendar', 'Appointments', 'calendar', $active === 'calendar'),
        $item('messages', 'Messages', 'message-circle', false, 3),
        $item('resources', 'Resources', 'book-open'),
    ];
    $ten = [
        $item('dashboard', 'Dashboard', 'house'),
        $item('clients', 'Clients', 'users'),
        $item('calendar', 'Appointments', 'calendar', $active === 'calendar'),
        $item('messages', 'Messages', 'message-circle', false, 3),
        $item('tasks', 'Tasks', 'calendar-check'),
        $item('documents', 'Documents', 'file-text'),
        $item('telehealth', 'Telehealth', 'video'),
        $item('programs', 'Programs', 'users-round'),
        $item('reports', 'Reports', 'chart-column'),
    ];

    return [
        'platformName' => 'WellNest',
        'announcement' => $preset === 'default' ? ['message' => 'Scheduled maintenance on Sunday between 02:00 and 03:00 UTC. The app stays available but may be slower.', 'level' => 'info'] : null,
        'legal' => ['termsUrl' => '#terms', 'privacyUrl' => '#privacy'],
        'user' => ['name' => $preset === 'default' ? 'Avery Mensah' : 'Sarah Carter', 'email' => 'sarah@example.org', 'initials' => 'SC'],
        'organization' => $platform ? null : ['name' => 'WellNest Therapy Center', 'slug' => 'wellnest', 'logoUrl' => null, 'isDemoDataPresent' => $preset === 'default'],
        'organizations' => $platform || $preset !== 'default' ? [] : [
            ['name' => 'WellNest Therapy Center', 'url' => '#wellnest', 'current' => true],
            ['name' => 'Northfield Counseling Group', 'url' => '#northfield', 'current' => false],
        ],
        'nav' => $platform ? [
            $item('dashboard', 'Dashboard', 'layout-dashboard', true),
            $item('organizations', 'Organizations', 'building-2'),
            $item('users', 'Users', 'users'),
            $item('plans', 'Plans & features', 'layers'),
            $item('settings', 'Platform settings', 'sliders-horizontal'),
            $item('audit', 'Audit log', 'history'),
        ] : ($preset === 'appointments' ? $ten : $six),
        'secondaryNav' => $platform ? [] : [$item('settings', 'Settings', 'settings', $active === 'settings')],
        'searchUrl' => url('/dev/styleguide'),
        'accountUrl' => Route::has('account.profile') ? route('account.profile') : '#account',
        'logoutUrl' => route('dev.styleguide.logout'),
        'platformUrl' => $platform ? null : url('/dev/styleguide/platform'),
        'appUrl' => $platform ? url('/dev/styleguide') : null,
        'roleLabel' => $platform ? 'Super Admin' : ($preset === 'default' ? 'Organization Administrator' : 'Organization'),
        'unreadNotifications' => $preset === 'default' ? 0 : 1,
        'notifications' => [],
    ];
};

// A curated set of Lucide icons (the product uses these); the full set is in resources/icons/lucide.
$iconNames = static fn (): array => [
    'house', 'users', 'user', 'user-plus', 'user-check', 'users-round', 'calendar', 'calendar-check', 'calendar-plus', 'clock', 'message-circle', 'send',
    'book-open', 'settings', 'bell', 'search', 'funnel', 'plus', 'pencil', 'trash-2', 'x', 'check', 'chevron-down', 'chevron-up', 'chevron-left', 'chevron-right',
    'arrow-up', 'arrow-down', 'arrow-left', 'arrow-right', 'arrow-left-right', 'ellipsis', 'menu', 'eye', 'eye-off', 'lock', 'shield-check', 'log-out',
    'file-text', 'file', 'video', 'phone', 'mail', 'map-pin', 'globe', 'building-2', 'layers', 'chart-column', 'heart', 'info', 'circle-alert',
    'triangle-alert', 'circle-check', 'circle-x', 'copy', 'printer', 'download', 'upload', 'refresh-cw', 'link', 'image', 'smartphone', 'flask-conical', 'inbox',
    'layout-dashboard', 'sliders-horizontal', 'history', 'clipboard-list', 'stethoscope', 'activity', 'star', 'paperclip',
];

// Layouts read $shell from the composer; on these routes they read the sample shell instead, so the composer is
// swapped for a stub (no settings queries, no tenant) before any view is made.
$useSampleShell = static function (): void {
    app()->instance(ShellComposer::class, new class
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

        // Shell presets that reproduce the comps' sidebar/top bar, for tools/visual comparison.
        Route::get('/shell/{preset}', function (string $preset) use ($sampleShell, $useSampleShell) {
            $useSampleShell();

            return view('dev.shell', ['shell' => $sampleShell(false, $preset), 'preset' => $preset]);
        })->whereIn('preset', ['clients', 'appointments', 'settings'])->name('shell');

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

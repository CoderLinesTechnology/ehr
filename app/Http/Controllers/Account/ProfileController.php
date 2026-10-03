<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The signed-in user's own profile page. Name and email are saved by Fortify
 * (user-profile-information.update); the personal preferences saved here are the
 * user's own and never touch another account.
 */
class ProfileController extends Controller
{
    public function show(Request $request): View
    {
        $user = $request->user();

        return view('account.profile', [
            'user' => $user,
            'timezoneOptions' => $this->timezoneOptions((string) $user->timezone),
        ]);
    }

    public function updatePreferences(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'timezone' => ['required', 'string', Rule::in(DateTimeZone::listIdentifiers())],
        ]);

        $request->user()->update(['timezone' => $validated['timezone']]);

        return redirect()->route('account.profile')->with('success', 'Your preferences have been saved.');
    }

    /**
     * Timezones grouped by region for a <select>, labelled with the current UTC offset.
     *
     * @return array<string, array<string, string>>
     */
    private function timezoneOptions(string $current): array
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $groups = [];

        foreach (DateTimeZone::listIdentifiers() as $identifier) {
            $offset = (new DateTimeZone($identifier))->getOffset($now);
            $abs = abs($offset);
            [$region, $place] = str_contains($identifier, '/') ? explode('/', $identifier, 2) : ['Other', $identifier];

            $groups[$region][$identifier] = sprintf(
                '(UTC%s%02d:%02d) %s',
                $offset < 0 ? '-' : '+',
                intdiv($abs, 3600),
                intdiv($abs % 3600, 60),
                str_replace('_', ' ', $place),
            );
        }

        ksort($groups);
        if (isset($groups['Other'])) {
            $other = $groups['Other'];
            unset($groups['Other']);
            $groups['Other'] = $other;
        }

        // A stored value that is no longer a canonical identifier must stay visible, not silently change on save.
        if ($current !== '' && ! in_array($current, DateTimeZone::listIdentifiers(), true)) {
            $groups = ['Current setting' => [$current => $current]] + $groups;
        }

        return $groups;
    }
}

# UI conventions

> **Visual design is governed by `docs/design/SPEC.md`** (measured from the WellNest comps in `docs/design/comps/`).
> Where this file and the spec disagree on appearance, the spec wins; this file keeps the structural rules.

Server-rendered Blade. One stylesheet (`public/css/app.css`) and one script (`public/js/app.js`)
shared by every screen; a module that needs more (the calendar) adds its own file under
`public/css/<module>.css` / `public/js/<module>.js` and pushes it with `@push('styles')` /
`@push('scripts')` (only `<link>` / `<script src>` — the CSP forbids inline script).

## Layouts
| Component | Used for |
|---|---|
| `<x-layouts.app title="…">` | Staff application (`/o/{organization}/…`). Slots: `breadcrumbs`, `header`. |
| `<x-layouts.platform title="…">` | Super Admin console (`/platform/…`). |
| `<x-layouts.auth title="…">` | Sign-in, registration, password and 2FA screens. |
| `<x-layouts.minimal title="…">` | Onboarding, organization status pages, errors. |

Layouts receive `$shell` from `App\View\ShellComposer` (navigation filtered by entitlement and
permission, organization switcher, banners). Pages never build navigation themselves.

## Components (`resources/views/components/ui`, used as `<x-ui.*>`)
`icon`, `button`, `field` + `input` / `textarea` / `select` / `checkbox` / `radio-group` / `toggle` /
`color-input`, `card`, `page-header`, `breadcrumbs`, `table` + `sort-link`, `badge`, `alert`, `flash`,
`empty-state`, `modal`, `drawer`, `confirm-form`, `tabs`, `dropdown` + `dropdown-item`, `stat`,
`avatar`, `dl` + `dl-item`, `section`, `search-input`, `filter-bar`, `spinner`, `progress`,
`checklist-item`. The styleguide (`/dev/styleguide`, local only) renders every one with its props.

Layout utilities (in `app.css`): `.stack` (`--sm`, `--lg`), `.cluster` (`--between`), `.grid-2` / `.grid-3` /
`.grid-4`, `.form`, `.form-grid`, `.form-actions`, `.text-muted`, `.text-sm`, `.text-xs`, `.text-right`,
`.nowrap`, `.truncate`, `.sr-only`, `.status-page`, `.choice-list`. Prefer these to page-specific CSS.

Rules:
* Every input sits in an `<x-ui.field>` (label, help, error, `aria-describedby`).
* Destructive or access-removing actions use `<x-ui.confirm-form>` (reason field when the domain
  requires one). Status changes are POST/PATCH/DELETE forms, never links.
* Lists: search + filters (`<x-ui.filter-bar>`), `<x-ui.sort-link>`, pagination, and an
  `<x-ui.empty-state>` that says what to do next. Never load unbounded lists.
* Demo records always carry `<x-ui.badge tone="demo">Demo</x-ui.badge>`.
* Status never by colour alone: badges carry text.
* Forms that create things use `data-submit-once`.
* Never render user content with `{!! !!}`.
* Dates/times/money through `fmt()` (organization formats and timezone): `fmt()->date($dob)`,
  `fmt()->dateTime($instant, $timezone)`, `fmt()->time(...)`, `fmt()->money($minor, $currency)`.

## Controllers
Thin: authorize (`$this->authorize()` / `Gate::authorize()` / policies), validate (Form Request or
`$request->validate`), call one domain action, redirect with a `success` flash. Domain exceptions
render automatically as a flash + field error (see `bootstrap/app.php`).

## Route names (staff app, prefix `app.`)
| Area | Route names |
|---|---|
| Dashboard | `app.dashboard` |
| Search | `app.search` |
| Clients | `app.clients.index`, `.create`, `.store`, `.show`, `.edit`, `.update`, `app.clients.appointments`, `app.clients.timeline`, `app.clients.contacts.*`, `app.clients.status` |
| Calendar & appointments | `app.calendar.index`, `app.appointments.create`, `.store`, `.show`, `.edit`, `.update`, `app.appointments.transition`, `app.appointments.reschedule` |
| Settings | `app.settings.index`, `app.settings.organization.edit`, `app.settings.locations.*`, `app.settings.services.*`, `app.settings.team.*`, `app.settings.roles.*`, `app.settings.scheduling.edit`, `app.settings.clients.edit`, `app.settings.audit.index`, `app.settings.subscription.show`, `app.settings.availability.*`, `app.settings.demo.*` |
| Platform (prefix `platform.`) | `platform.dashboard`, `platform.organizations.*`, `platform.users.*`, `platform.plans.*`, `platform.settings.edit`, `platform.audit.index`, `platform.admins.*` |

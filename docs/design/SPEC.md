# WellNest visual specification

The supplied comps (`comps/`) are the specification. Build to them exactly; where comps disagree with each
other, the decisions below say which wins. Measured values live in:

| File | Covers |
|---|---|
| [`tokens.css`](tokens.css) | All design tokens (colours, type scale, spacing, radii, shadows), each with where it was measured |
| [`spec/design-system.md`](spec/design-system.md) | Every component on the design-system sheet (comp 05) |
| [`spec/app-shell.md`](spec/app-shell.md) | Sidebar, top bar, main area, page header, cards (canonical values + per-comp drift) |
| [`spec/screens/08-dashboard.md`](spec/screens/08-dashboard.md) | Dashboard |
| [`spec/screens/10-clients.md`](spec/screens/10-clients.md) | Clients list |
| [`spec/screens/01-appointments.md`](spec/screens/01-appointments.md) | Appointments (calendar week view, today's table, right rail) |
| [`spec/screens/02-settings-organization.md`](spec/screens/02-settings-organization.md) | Settings → Organization |

Not yet measured (measure when the module is built): 03 Programs, 04/11/06 Telehealth, 07 Messages, 09 Resources.

## Brand
WellNest — "Better Care. Healthier Tomorrows." Font **Inter** (self-hosted, `public/fonts/inter`). Icons **Lucide**
(`resources/icons/lucide`, inlined server-side). Leaf logo mark: vectorised from the comps.

## Decisions (where comps conflict)
1. **Rendered screen colours win over hexes printed on the sheet.** Primary renders `#2F7FF6` on every screen
   (the sheet prints `#2563EB`). The printed values stay in `tokens.css` as `--spec-*` reference only. The
   organization's *branding* colours (Settings → Branding, default `#2563EB`) are tenant data shown in that card;
   they do not restyle the application chrome.
2. **Shell:** canonical values from `spec/app-shell.md` (majority of comps 01/02/10). Comp 08's smaller sidebar
   wordmark and dull avatar are render drift — ignored.
3. **Light sidebar for the staff application** (every screen comp). The dark navy sidebar on the sheet is used for
   the **Super Admin console**, which must look visibly different from an organization's workspace.
4. **Navigation** shows the comps' items in the comps' order — Dashboard, Clients, Appointments, Messages, Tasks,
   Documents, Resources, Telehealth, Programs, Reports, Settings — each only when its module exists and the user
   may use it. The 6-item and 10-item variants in the comps are two such states.
5. **Stat card rows** span the page grid: three cards in the main column, the fourth in the right-rail column —
   which is why the fourth card is wider in comps 08 and 10.
6. **Calendar event colour follows the clinician** (Sarah Carter blue, James Allen green, Lisa Morgan orange, a
   fourth clinician teal); **any telehealth appointment is purple**. A clinician's colour is chosen from these
   families (Settings → Team).
7. **Status pills** (screen colours, not the sheet's): an appointment that is booked but not yet confirmed
   (`scheduled`) reads **Pending** in blue — the majority of comp rows (dashboard 08, 4 of 5 rows in 01); the
   single grey "Scheduled" row in comp 01 is not reproduced. Confirmed/Completed green, Cancelled grey, No-show
   red; client status Pending blue, Active green, Inactive grey. Use `AppointmentStatus::badgeLabel()/badgeTone()`.
8. **Comp artifacts are not reproduced:** "44:00 PM" (→ 04:00 PM), garbled glyphs on the sheet ("Deofoed",
   "Icon Brittped"), "Filters s" (→ "Filters"), glitch-filled icons (→ plain Lucide `map-pin` / `video`).
9. Sample people and numbers in the comps are content, not design: real screens show the organization's data.
   `database/seeders/DesignFixtureSeeder.php` recreates the comps' data locally for visual comparison.

## Verification
`tools/visual/shoot.py` + `tools/visual/compare.py` against the comp at its exact size, using the design fixture:

```bash
createdb -O ehr_app ehr_design
DB_DATABASE=ehr_design php artisan migrate:fresh --seed --seeder=DesignFixtureSeeder
cd public && DB_DATABASE=ehr_design APP_FAKE_NOW="2025-04-28 08:30:00" SESSION_DRIVER=file CACHE_STORE=file \
  PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8123 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
python tools/visual/shoot.py --login sarah@wellnest.test --url http://127.0.0.1:8123/o/wellnest --out /tmp/08.png
python tools/visual/compare.py --comp docs/design/comps/08-dashboard.png --shot /tmp/08.png --out /tmp/cmp-08
```

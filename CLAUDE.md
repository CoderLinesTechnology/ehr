# WellNest — multi-tenant practice management / EHR SaaS

Laravel 13 + Blade, PostgreSQL 16, PHP 8.5. Read `docs/architecture/README.md` (the contract),
`docs/design/SPEC.md` (the visual spec — match the comps in `docs/design/comps/` exactly),
`docs/architecture/ui-conventions.md`, `docs/compliance/REGISTER.md` and `docs/ROADMAP.md` first.

## Local environment
* Binaries are not on the default PATH: `export PATH=/usr/local/bin:/usr/local/opt/postgresql@16/bin:$PATH`.
* No Node/npm: plain CSS (`public/css/app.css`) and vanilla JS (`public/js/app.js`), no build step.
* Database: role `ehr_app` (not a superuser), databases `ehr` (dev) and `ehr_test*` (tests).
  `php artisan migrate:fresh --seed` seeds catalogue + a development organization (accounts listed by the seeder).
* Tests run on PostgreSQL only (constraints/triggers are behaviour). Concurrent runs need their own DB:
  `DB_DATABASE=ehr_test_x php artisan test`.
* Deploy step: `php artisan migrate --force && php artisan catalogue:sync`.

## Non-negotiables
* Tenant data: models use `BelongsToOrganization` (fail-closed scope); cross-references use composite
  `(organization_id, x_id)` FKs (`tenantForeign()` macro). Cross-tenant reads only via `TenantContext::bypass()`
  and only for platform aggregates — never clinical content.
* Rules live in `app/Domain/<Context>` actions; controllers are thin. Status/money/tenant/environment columns are
  never mass-assignable; history tables are insert-only (trait + DB trigger); every state change is audited
  through `AuditLogger`.
* Permissions are code (`PermissionRegistry`), roles are data; entitlements (`EntitlementService`) are not
  permissions — a module needs both. Settings only through `SettingsRegistry` + `SettingsService`.
* Demo data is `record_environment = 'demo'`, enforced by FKs, excluded from real counts and external delivery.
* Money is integer minor units + ISO currency. Timestamps are `timestamptz` (UTC); appointments keep their timezone.
* No CDNs, no inline scripts (CSP `script-src 'self'`), no `{!! !!}` for user content.

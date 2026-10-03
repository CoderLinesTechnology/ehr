# Architecture

Multi-tenant practice-management / EHR SaaS. Laravel 13 + Blade, PostgreSQL 16.
This document is the contract the code follows. When code and this document disagree,
fix one of them in the same change.

## 1. Layers

```
HTTP (controllers, form requests)  ─┐
Console commands, queued jobs       ├─► Domain actions/services (app/Domain/<Context>) ─► Eloquent models ─► PostgreSQL
Scheduler                           ─┘          │
                                                 ├─► Events (after commit) ─► listeners: timeline, audit, notifications
                                                 └─► AuditLogger (insert-only audit_logs)
```

* **Adapters are thin.** Controllers authorize, validate the input shape, call one domain action and
  present the result. They never write status, money or tenant columns directly.
* **Domain actions own the rules**: state transitions, conflict checks, snapshots, audit, events.
  They throw `App\Domain\Shared\DomainException` subclasses whose `userMessage()` is safe to show.
* **Status, money and tenant columns are not mass-assignable.** They are written with `forceFill`
  inside domain actions only.
* Models live in `app/Models` (flat, conventional for factories); everything with rules lives in
  `app/Domain/<Context>`.

## 2. Tenancy

Single database, shared schema, `organization_id` on every tenant-owned row.

| Layer | Mechanism |
|---|---|
| URL | Staff app routes are `/o/{organization:slug}/…`. The tenant is in every URL, so two tabs open on two organizations can never write into the wrong one (no session "current org" switch). |
| Request | `ResolveTenant` middleware (runs **after** authentication and **before** route-model binding) loads the organization, verifies an *active* membership for the user, checks the organization's lifecycle status, and sets `TenantContext`. |
| Query | `BelongsToOrganization` trait adds `TenantScope`. **Fails closed**: querying a tenant model with no tenant context throws `MissingTenantContext`. Cross-tenant platform queries must opt out explicitly with `TenantContext::bypass()` / `withoutTenantScope()`, which is grep-able and reviewed. |
| Write | `creating` hook stamps `organization_id` from context and refuses a mismatching one. |
| Database | Cross-references between tenant rows use **composite foreign keys** `(organization_id, x_id) → x(organization_id, id)`. A row in organization A physically cannot point at a row in organization B, whatever the application does. |
| Jobs | Queued jobs carry the organization id and restore `TenantContext` in job middleware. |

Platform (Super Admin) context never sets a tenant. Platform screens read aggregate counts via explicit
bypass and **never** render clinical content.

Planned hardening (roadmap): PostgreSQL row-level security keyed on `app.organization_id` as a second wall.

## 3. Identity, roles and permissions

```
users (global identity, MFA)        organizations
   │                                     │
   └──< organization_memberships >───────┘    one user ↔ many organizations
            │
            └──< membership_roles >── roles (organization scope) ──< role_permissions >── permissions (code catalogue)

users ──< platform_user_roles >── roles (platform scope) ──< role_permissions >── permissions (platform.*)
```

* The **permission catalogue is code** (`App\Domain\Identity\PermissionRegistry`), synced to the
  `permissions` table by `php artisan permissions:sync`. Roles are **data**: organizations create and
  edit their own roles; system roles are created from templates when an organization is created.
* `role_permissions.scope` with composite FKs to both `roles(id, scope)` and `permissions(key, scope)`
  makes it **impossible at the database level** for an organization role to carry a `platform.*`
  permission (privilege-escalation guard). `membership_roles` composite FKs make it impossible to
  attach another organization's role.
* `Gate::before` resolves permission keys: `platform.*` against the user's platform roles, everything
  else against the current tenant membership. Record-level rules (e.g. a clinician sees only their own
  clients unless `clients.view_all`) live in policies. UI hiding is cosmetic; every route authorizes.
* Platform permissions grant **no** tenant data access. A Super Admin who needs to see an
  organization's clients must be a member of that organization like anyone else (audited).
* Super Admin requires confirmed TOTP MFA; destructive platform actions require password re-confirmation.

## 4. SaaS: plans, features, entitlements, subscriptions

* `features` (code catalogue: boolean modules and numeric limits) → `plan_features` (per plan) →
  `organization_entitlements` (per-organization overrides with reason and optional expiry).
* Effective entitlement = override (if present and not expired) else plan value of the organization's
  live subscription. `EntitlementService` is the only reader; `feature:<key>` route middleware and the
  navigation use it. **Entitlement ≠ permission**: a route needs both.
* `subscriptions` snapshot price/currency/interval at sale; at most one live subscription per
  organization (partial unique index). `subscription_histories` is insert-only.
* Payment-provider coupling is deferred behind `provider`/`provider_reference` columns; a provider
  adapter will drive status transitions through the same domain action.

## 5. Settings (four layers, never mixed)

| Layer | Who | Storage |
|---|---|---|
| Code configuration | Developers | `config/*.php`, `.env` |
| Platform settings | Super Admin | `platform_settings` (key → jsonb) |
| Organization settings | Organization Admin | `organization_settings` (organization_id, key → jsonb) and core columns on `organizations` |
| User preferences | The user | columns on `users` (timezone); a preferences table arrives with notification preferences |

Every setting key is declared once in `App\Domain\Settings\SettingsRegistry` with type, default,
validation rules, group and whether it is secret. Unknown keys cannot be written. Secret values are
encrypted at rest and are write-only in the UI. Values are read at call time (never cached in a
constructor) so a change takes effect on the next request.

## 6. Audit

`audit_logs` is **insert-only**: the model refuses update/delete and a PostgreSQL trigger raises on
`UPDATE`/`DELETE`. One `AuditLogger`, called from domain actions, records actor, organization,
dot-namespaced action (`appointment.cancelled`), subject, before/after (with a redaction list for
secrets/tokens/MFA material), IP, user agent, request id and context (`platform`, `organization`,
`portal`, `system`). Sensitive reads (opening a client chart) are audited too.

Organization admins see their organization's audit log. Platform admins see platform-context entries
only — not tenant clinical activity.

## 7. Demo data

`record_environment` (`live` | `demo`, CHECK-constrained, immutable) on clients and every client-derived
record. Child records reference the client with a composite FK that **includes** `record_environment`,
so a demo appointment cannot point at a live client or vice versa. Reports, dashboard counts and (later)
claims/revenue filter `record_environment = 'live'`. External delivery (email/SMS/payments/claims) is
suppressed for demo records by the delivery layer. Demo data can be generated, reset and deleted per
organization; each run is audited.

## 8. Module map and data ownership

Each entity has one owning module; other modules reference it, never copy it.

| Module | Owns | Status |
|---|---|---|
| Platform | organizations, organization_status_histories, platform_settings | Phase 1 |
| SaaS | features, plans, plan_features, subscriptions, subscription_histories, organization_entitlements | Phase 1 |
| Identity | users, organization_memberships, roles, permissions, role_permissions, membership_roles, platform_user_roles | Phase 1 |
| Organization | locations, organization_settings, organization_counters | Phase 1 |
| Audit | audit_logs | Phase 1 |
| Clients | clients, client_contacts, timeline_entries | Phase 1 |
| Scheduling | services, service_providers, service_locations, availability_rules, availability_rule_services, blocked_times, appointments, appointment_status_histories | Phase 1 |
| Communications | notifications, templates, deliveries, conversations, messages | Phase 2 |
| Tasks | tasks | Phase 2 |
| Forms & Documents | forms, versions, submissions, documents, versions, signatures | Phase 3 |
| Clinical | notes (+versions, signatures, amendments), assessments, treatment plans, diagnoses, care teams | Phase 4 |
| Programs | programs, levels of care, enrollments, transitions, groups, activities, requirements, outcomes, attendance | Phase 5 |
| Billing | charges, invoices, invoice lines, payments, refunds, credits, adjustments | Phase 6 |
| Insurance | payers, policies, authorizations, eligibility, claims, remittance, denials | Phase 7 |
| Telehealth & AI | sessions, providers, recordings, transcripts, AI processing | Phase 8 |
| Portal, public site, booking, inquiries, waitlist | (uses scheduling engine and clients) | Phase 9 |
| Marketing | subscribers, campaigns, suppression | Phase 10 |
| Reporting & exports | report definitions/executions | Phase 11 |

## 9. Scheduling engine (single source of truth)

`App\Domain\Scheduling\SlotFinder` is the only code that answers "when can this service be booked?".
Staff booking, public booking, the client portal and waitlist matching all call it.

* Availability rules are wall-clock windows (`weekday`, `start_time`, `end_time`, `repeat_every_weeks`,
  `effective_from/until`) in the **location's** timezone (organization timezone for telehealth-only
  rules); expansion converts each date to UTC instants with DST-correct arithmetic.
* Free time = availability − blocked time (clinician-specific or organization-wide) − occupying
  appointments. Slots are aligned to the organization's slot interval and filtered by minimum notice and
  maximum advance window from organization settings.
* Appointments store `starts_at`/`ends_at` as `timestamptz` (UTC) plus a `timezone` snapshot for display.
* **Double-booking is prevented by the database**: an exclusion constraint
  `EXCLUDE USING gist (organization_id WITH =, clinician_membership_id WITH =, tstzrange(starts_at, ends_at) WITH &&)`
  over occupying statuses. Staff can deliberately overbook (`allow_overlap`, separate permission);
  public booking never can. A lost race surfaces as a "slot was just taken" message, not a 500.
* Appointment lifecycle is a state machine with an insert-only history. Reschedule = the original becomes
  `rescheduled` and links to a new appointment, so late-cancellation and no-show history is never lost.
* Price is snapshotted onto the appointment at booking; billing (Phase 6) charges from the snapshot.

## 10. Events

Domain events are dispatched **after commit** (`ShouldDispatchAfterCommit`). Phase 1 events:
`OrganizationCreated`, `OrganizationStatusChanged`, `SubscriptionChanged`, `MembershipInvited`,
`ClientCreated`, `AppointmentScheduled`, `AppointmentStatusChanged`, `AppointmentRescheduled`.
Listeners write timeline entries now; notifications, tasks and analytics subscribe in later phases
without touching the emitting code.

## 11. Identifiers

All primary keys are UUIDv7 (time-ordered, non-enumerable). No sequential id is ever exposed.
Human-facing numbers (client number, later invoice numbers) come from `organization_counters`
(per-organization, gap-free under row lock).

## 12. Integrations

Every external service sits behind an interface in `app/Domain/<Context>/Contracts` with a driver chosen
by platform setting: mail (Laravel mailers), SMS, payments, telehealth video, AI, storage (Laravel
filesystem), claims clearinghouse. Core code depends on the interface only.

## 13. Frontend

Server-rendered Blade with a component library under `resources/views/components/ui`. One hand-written
stylesheet (`public/css/app.css`, design tokens as CSS custom properties, light + dark) and one small
vanilla script (`public/js/app.js`). No CDN, no web fonts (system font stack), icons are inline SVG.
Native `<dialog>` for modals/drawers. Strict CSP: `script-src 'self'`.

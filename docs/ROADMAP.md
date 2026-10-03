# Roadmap

The master specification describes the whole product. It is delivered in phases. Each phase ships
working, tested, connected modules on the shared foundation rather than placeholder screens. A
module appears in the navigation only once it exists.

| Phase | Scope | Status |
|---|---|---|
| **1. Foundation & core practice** | Multi-tenancy (URL-scoped tenants, fail-closed scoping, composite FKs), identity (users, memberships, roles, permission catalogue), MFA (TOTP) and auth flows, platform console (organizations, lifecycle, plans, features, entitlements, subscriptions, platform settings, users, platform admins, platform audit), organization settings (profile, locations, services, team & invitations, roles & permissions, scheduling/client settings, audit, subscription & usage), clients (records, contacts, search, timeline), scheduling engine (availability, blocked time, slot finder, appointments state machine, double-booking constraint, reschedule), calendar (day/week/month), dashboard, demo data, design system, compliance register | **In progress** |
| 2. Communications | Notification engine (in-app/email/SMS channels, templates with validated variables, delivery records, retries, preferences), appointment reminders (scheduler), secure messaging (staff↔staff, staff↔client, attachments), tasks (assignment, due, overdue, notifications) | Planned |
| 3. Forms & documents | Versioned form builder (conditional fields, signatures), assignments and submissions, document storage (private disk, versioning, categories, access rules, signatures, expiry) | Planned |
| 4. Clinical / EHR | Note templates, progress notes (draft → review → sign → amend, versioned), assessments, diagnoses, treatment plans (goals, objectives, interventions), care teams with granular clinical permissions, clinical timeline categories | Planned |
| 5. Programs & levels of care | Programs, staff, levels of care, referrals → admission → enrollment → transitions → discharge (insert-only history), groups, activities, attendance, requirements, outcomes; program sessions on the central calendar | Planned |
| 6. Billing & payments | Charges from appointment price snapshots, invoices (numbering via organization counters), payments (provider abstraction, mobile money/card), refunds, credits, adjustments, statements, balances | Planned |
| 7. Insurance & claims | Payers, policies, authorizations, eligibility, claims lifecycle (clearinghouse-agnostic), remittance, denials, resubmission tasks | Planned |
| 8. Telehealth & AI | Provider-agnostic video sessions linked to appointments, consented recording/transcripts, AI drafts (labelled, reviewed, never auto-final), AI vendor under DPA/BAA | Planned |
| 9. Client portal & public site | Portal (appointments, booking, forms, documents, payments, messages, guardians), public organization site (subdomain/custom domain), public booking on the same engine, inquiries → client conversion, waitlist matching | Planned |
| 10. Marketing communications | Marketing subscribers (consent, suppression, bounce), campaigns, templates, scheduling, statistics, re-engagement sequences on the event foundation | Planned |
| 11. Reporting, exports, imports, retention | Operational/clinical/program/financial reports (live data only), async CSV/PDF exports (audited), validated imports, subject-access export, retention policies | Planned |
| 12. Operations hardening | PostgreSQL row-level security as a second tenant wall, system-operations console (failed jobs/notifications/integrations), impersonation (explicit, bannered, audited), backups/DR runbooks | Planned |

## Notes
* **Frontend (product-owner decisions, 2026-10-02):** the UI follows the supplied WellNest comps exactly
  (`docs/design/comps/`), measured into `docs/design/SPEC.md` + `docs/design/tokens.css`. No deviations, no
  "improvements". Earlier provisional UI files are replaced to match the comps. Modules shown in the comps
  without a backend yet (Messages, Tasks, Documents, Telehealth, Programs, Resources, Reports) get their
  backend and screens built; a navigation item appears once its module exists — never as a dead link.
* The pasted master prompt was truncated at section 128 (Super Admin vs Organization Admin boundary).
  Sections 128+ have not been seen; anything they add must be reconciled with this roadmap.
* Each phase updates `docs/compliance/REGISTER.md` (new processing, processors, retention) before it ships.

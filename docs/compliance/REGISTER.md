# Compliance register

What applies, what it means for engineering, and where it lives in the code. Every change is checked
against this register before it is called done. Items marked **⚖ Decision needed** require a legal or
business owner. Engineering cannot make them true on its own.

> This register is an engineering aid, not legal advice. Have it reviewed by counsel in each
> jurisdiction before go-live.

## Product facts that drive obligations

* Processes **health data**, including behavioral/mental-health and potentially substance-use
  treatment records (programs, levels of care) — special-category / sensitive data everywhere.
* Multi-tenant SaaS: each organization is the **data controller** (US: covered entity) for its
  clients; the platform operator is a **processor** (US: business associate).
* First customer in **Ghana**; architecture must support the **United States** and other countries.
* Handles payments, sends email/SMS, offers telehealth and AI assistance, has public pages and a
  marketing newsletter.

## Register

| # | Obligation | Engineering requirements | Where in code | Status |
|---|---|---|---|---|
| 1 | **Ghana Data Protection Act, 2012 (Act 843)** — processing principles, security safeguards (s.28), special personal data incl. health (s.37), data-subject access/correction (s.32–35), direct-marketing objection (s.40) | Tenant isolation; least-privilege access; access audit; data minimisation in forms; subject-access export and correction paths; marketing consent separate from service messages | `app/Domain/Tenancy`, `app/Domain/Identity`, `audit_logs`, marketing module (Phase 10) | Isolation/RBAC/audit: Phase 1. Export/correction: Phase 11 |
| 2 | Ghana DPA — **registration with the Data Protection Commission** (controller and processor) | None (operator obligation) | — | **⚖ Decision needed**: operator and each customer organization must register |
| 3 | **Mental Health Act, 2012 (Act 846)** and Ghana health-record confidentiality norms | Clinical data behind granular permissions separate from administrative/financial; care-team scoping; no clinical content in platform views, notifications or audit summaries visible outside the tenant | Permission catalogue (`clinical_*` keys arrive in Phase 4), policies | Design: Phase 1; enforcement: Phase 4 |
| 4 | **US HIPAA Privacy, Security and Breach Notification Rules** (when serving US covered entities) | Access control, unique user ids, automatic logoff (idle session timeout), audit controls, integrity (no overwrite of signed records), transmission security (TLS), encryption at rest, breach-detection signals; BAAs with every subprocessor that touches PHI | Session config, `audit_logs`, signed-record versioning (Phase 4), deployment | Technical controls: in progress. Telehealth video goes to Daily.co (see processors below): Daily's Healthcare add-on (HIPAA mode, BAA at no cost) must be active before any real patient call; WellNest already sends only UUID user ids and never names rooms (HIPAA-mode compatible). **⚖ Decision needed**: BAA template; subprocessors' BAAs (Daily BAA signed per environment) |
| 5 | **42 CFR Part 2** (US substance-use-disorder treatment records) | Segmentation flag on SUD program records; disclosure only with Part 2-compliant consent; redisclosure notice on exports | Programs: `programs.is_sud_program` + `programs.view_sud` (`ProgramVisibility`, `ProgramPolicy`, `ProgramEnrollmentPolicy`); exports (Phase 11) | Segmentation built (lists, counts, search, attendance, actions and audit summaries; no default role holds `programs.view_sud`). **⚖ Decision needed**: does any US customer run a Part 2 program? Consent-to-disclose and the redisclosure notice on exports are not built |
| 6 | **GDPR / UK GDPR** (if EU/UK clients or users) | Lawful basis per processing; Art. 9 condition for health data; erasure/restriction paths covering every new table (balanced against medical-record retention); DPAs with subprocessors; records of processing | Retention & erasure framework (Phase 11) | Not yet in scope; keep designs compatible |
| 7 | **Medical-record retention** (Ghana and US state rules differ; often ≥ 7–10 years, longer for minors) | No automatic destructive deletion; clinical, billing and audit records soft-retained; configurable retention policies per category only with an approved policy | Soft-delete/archival everywhere; no hard-delete paths for clinical/billing/audit | **⚖ Decision needed**: retention periods per jurisdiction |
| 8 | **Electronic signatures** — Ghana Electronic Transactions Act, 2008 (Act 772); US ESIGN/UETA | Signature = signer identity + timestamp + content hash + intent; signed content immutable; amendments are new versions | Clinical/document signatures (Phases 3–4) | Planned |
| 9 | **PCI DSS** | Never store card data; use a provider's hosted fields/tokens (SAQ A scope); payment-provider abstraction | Billing (Phase 6) | Planned |
| 10 | **Bank of Ghana — Payment Systems and Services Act, 2019 (Act 987)** (mobile money/card) | Use a licensed PSP; verify webhooks; idempotent payment recording | Billing (Phase 6) | Planned |
| 11 | **Marketing email/SMS** — Ghana DPA s.40, US CAN-SPAM and TCPA, ePrivacy (EU) | Marketing consent separate from transactional; unsubscribe on every marketing message, honoured immediately; suppression list; SMS consent and quiet hours | Marketing (Phase 10), notification preferences (Phase 2) | Planned |
| 12 | **AI transparency and human oversight** (EU AI Act Art. 50 if EU; professional duty everywhere) | AI output labelled, stored as draft, never auto-finalises or alters signed records; human review recorded; per-tenant opt-in; PHI to an AI vendor only under a DPA/BAA | `app/Domain/Telehealth` (`AddTranscript`, `ReviewTranscript`), `telehealth.ai_transcripts_enabled` (off by default) | Built for transcripts: always "Draft" until a clinician marks it reviewed (who/when recorded; DB check refuses reviewed-without-reviewer); never auto-finalised. No AI vendor is connected yet; Daily's own transcription and live captions are not enabled (no transcription properties are set on rooms or tokens). **⚖ Decision needed**: AI vendor, DPA/BAA, patient-consent wording |
| 13 | **Telehealth** — recording consent (all-party consent states in the US), clinician licensure in the patient's jurisdiction | Recording off by default and only with recorded consent; session metadata only; provider abstraction | `app/Domain/Telehealth` (`RecordConsent`, `AttachRecording`, `IssueCallPass`, `Daily\HandleDailyWebhook`), `session_recordings`/`session_transcripts` FKs on `consent_to_record` | Built: recording off unless the organization enables it AND consent is recorded per session (the database refuses a recording/transcript row otherwise). Daily cloud recording is possible only in calls where the staff member's pass allows it (organization setting + recorded consent + clinical access); withdrawing consent stops a running recording and later passes cannot record; a recording that arrives for a session without consent is deleted at Daily and never stored (audited as refused). Manual uploads on the private disk; Daily recordings stay at Daily/the configured bucket and download through a 15-minute link minted per authorized, audited, no-store download. Clients wait in a lobby until the clinician admits them; demo data never reaches Daily. Metadata-only audit. Clinician licensure in the client's jurisdiction is not checked. Erase/export (Phase 11) must also cover the recording files on the private disk, recordings held at Daily/the bucket (delete through Daily's API), transcripts and note versions. **⚖ Decision needed**: consent text; whether Daily's in-call "recording" notice is sufficient notice to the client |
| 14 | **Accessibility** — WCAG 2.2 AA (ADA Title III in the US, EAA in the EU) | Semantic HTML, labelled inputs, visible focus, keyboard-operable dialogs, 4.5:1 contrast, no colour-only status, reduced motion | `resources/views/components/ui`, `public/css/app.css` | Phase 1 components built to AA |
| 15 | **Cookies / tracking** | Only strictly necessary cookies (session, CSRF) — no consent banner needed. Any analytics or marketing pixel requires consent first | Layouts | Compliant (no trackers) |
| 16 | **Security baseline — OWASP ASVS L2** | AuthN (rate-limited login, MFA, secure reset), session (secure/httponly/samesite cookies, idle timeout, regeneration), access control on every route, input validation, output encoding, CSP, file upload controls, secrets out of the repo, dependency updates | `bootstrap/app.php`, `app/Http/Middleware`, Fortify config | Phase 1 |
| 17 | **Ghana Cybersecurity Act, 2020 (Act 1038)** — incident reporting if designated critical information infrastructure | Incident-response runbook; security event logging | Ops | **⚖ Decision needed**: is the operator/customer designated CII? |
| 18 | **Data residency / cross-border transfer** (Ghana DPA, GDPR Ch. V, some US customer contracts) | Hosting region configurable per deployment; subprocessor list maintained | Deployment, `docs/compliance/SUBPROCESSORS.md` (to create when the first vendor is chosen), `DAILY_GEO` | Video calls are relayed by Daily's media servers in the region set by `DAILY_GEO` (none in West Africa: `eu-west-2` London or `af-south-1` Cape Town are the candidates for Ghana; unset = Daily's routing); cloud recordings are stored by Daily or in the bucket configured with Daily (its region). **⚖ Decision needed**: hosting region; video media region and recording storage region (cross-border transfer basis for Ghana DPA/GDPR) |

## Third-party processors (outbound data flows)

Phase 1 had none (mail goes to the `log` mailer locally). Every new outbound flow (email provider, SMS,
payments, video, AI, storage, error tracking) must be added here before it ships, with the data sent and the
legal basis/agreement in place.

| Vendor | Purpose | Data sent | Agreement | Status |
|---|---|---|---|---|
| Daily.co (Daily, Inc., US) — the platform's video processor for every organization | Telehealth video calls (Daily Prebuilt framed in WellNest's call page) and, when enabled and consented, cloud recording | **Room metadata** (Daily-generated room name, open/close times, lobby and recording settings — no client name, no appointment details); **staff display names (professional name) and membership UUIDs** in the per-call passes; **participants' audio/video streams and IP addresses** while in a call (processed by Daily); **display names clients type** in Daily's lobby; **recordings** when the organization enables recording and the client's consent is recorded. Daily's event logs keep join/leave times and network quality. No data for demo records; nothing before someone opens a session's join page. The join page's camera preview stays in the browser tab | **⚖ Decision needed**: Daily BAA (Healthcare add-on, HIPAA mode) and DPA signed before patient use, per environment; media region (`DAILY_GEO`) and recording storage (Daily or own S3 bucket via `recordings_bucket`); Daily's retention of event logs and recordings | Built (`app/Domain/Telehealth/Daily`, `Providers/DailyProvider.php`; setup `docs/integrations/daily.md`). Replaces the former "external meeting link" provider; existing sessions were moved to Daily and their pasted links removed |

## Change checklist (apply to every change)

1. New personal data collected? Needed for the stated purpose (minimisation)? Shown only to roles that need it?
2. Tenant-owned table? It has `organization_id`, the tenant scope, and composite FKs to other tenant rows.
3. New processing or outbound flow? Declared above, with a lawful basis.
4. Retention and deletion: does the erase/export path (Phase 11) need to cover a new table?
5. Security: authorization on every route, validated input, no internal ids in URLs, secrets not logged.
6. Audit: does this state change belong in `audit_logs`?
7. Demo safety: does this create records that could leak into live reports or trigger external delivery?
8. Accessibility of any new UI.

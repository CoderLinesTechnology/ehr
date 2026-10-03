# 10 Clients — measured spec

Comp `docs/design/comps/10-clients.png`, 1536x1024. Boxes `x,y,w,h`. Shell values (sidebar edge x271, topbar 72+1px border, search field 297,18,557x40, active item Clients pill 19,151,236x48, badge "3") → `../app-shell.md`. Font sizes = Inter width-fit (cap height reads ~7% larger). Main bg `#f8fbfe`; cards `#fff` + 1px `#ebf1f9`, radius 12.

## 1. Page header (y92–148)

| Element | Spec |
|---|---|
| Icon tile | circle 56px at 302,92, bg `#e8f1fe`; Lucide `users` 28px stroke `#0a5bf0` |
| Title "Clients" | x380, cap-top y99 (ink 80x21); 24px/600 `#01092e` |
| Subtitle "Manage your clients and view their information." | x380,y134 (ink 293x14); 13px/400 `#697c9e` |
| Primary button "Add Client" | 1368,100,140x41 (right edge x1507); fill `#307ef6` (slight vertical gradient top `#3c8cf5` → bottom `#2179f9`), radius 8, no border; Lucide `plus` 14px white at x1390 (stroke 2), label 14px/500 `#fff` at x1417 (gap 14); h-pad 22 |

## 2. Stat cards — y169–336 (h 168), gap 18

Measured x-extents (unequal in comp): 302–549 (248), 568–832 (265), 851–1116 (266), 1134–1507 (373). Same caveat as 08: use measured `fr` ratios 248:265:266:373 for exact match, or 4 equal (flag).

| Element | Spec |
|---|---|
| Icon tile | **rounded square 36x36, radius 10** at (card.x+24, 187) → 326,187; icon Lucide 20px |
| Label | x card.x+24, ink-top y234; 13px/400 `#506385` |
| Value | y258–276 (digit height 18 → 25px); **26px/600** `#00031f` |
| Delta | y289; arrow-up (Lucide `arrow-up`, 10x10, `#109769`) + text 12px/500 `#14a270`, gap 8 |
| Caption | "vs. last 30 days" y307, 12px/400 `#8897b3` |

| # | Tile bg / icon (Lucide) | Label | Value | Delta |
|---|---|---|---|---|
| 1 | `#e4effe` / `users` `#095bf0` | Total Clients | 48 | ↑ 12% |
| 2 | `#dff7ef` / `user-check` `#0ca484` (teal) | Active Clients | 42 | ↑ 10% |
| 3 | `#dfedfd` / `clock` `#0953e2` | Upcoming Appointments | 12 | ↑ 20% |
| 4 | `#e1eefe` / `user-plus` `#084fdd` | New Clients (30 days) | 6 | ↑ 50% |
All captions "vs. last 30 days". Delta green `#14a270` (arrow `#109769`).

## 3. Layout below stats (y354–1006)

| Block | Box |
|---|---|
| Table panel | 302,354,872x652 (x302–1174) |
| Filters panel | 1192,354,315x652 (x1192–1507); gap between panels 17–18 |

## 4. Table panel

**Toolbar** (y372–403, h 32, panel padding 22):
- Search: 324,372,700x32 (x324–1024), bg `#f5f8fc`, 1px border `#eff4fc`, radius 8; Lucide `search` 15px `#6579a1` at x333; placeholder "Search clients by name, email, phone, or ID..." x360, 12px/400 `#8796b4` (ellipsis is three dots "...").
- "Filters" button: 1054,372,100x32 (x1054–1154), bg `#f4f7fb`, 1px border `#e8effa`, radius 8; Lucide `funnel` 14px `#0f56cc` at x1070; label "Filters" 12.5px/500 `#1352ba` at x1097.

**Header row** (y421–460; line 1px `#f0f4fc` at y460/461): text 11–12px/500 `#4d6388`, vertical centre y442. Header checkbox 15x15 at x323, border `#bdc9dc` 1.5px, radius 4, white fill. Column text x (ink): Client 356 · Contact 540 · Status 716 · Next Appointment 828 · Last Visit 964 · Actions 1109 (right-aligned to x1149; text "Actions"). Header copy exactly: Client, Contact, Status, Next Appointment, Last Visit, Actions.

**Rows** (8): pitch ≈ 61.7; separators 1px `#f3f7fb`–`#f1f6fb` at y523, 585, 649, 711, 772, 832, 894, 954, spanning x322–1156 (inset from panel edges; final one at 954 closes the list). Row h ≈ 62, vertical centre = row top + 31 (first row 462→523, centre 492).

| Cell | Spec |
|---|---|
| Checkbox | 15x15 at x323, border `#bac7dd` 1.5px, radius 4, bg white, centred vertically (y484) |
| Avatar | circle 41px at x355 (row top +9), photo or initials. Initials avatars: bg gradient (light→lighter) , text 15–16px/500 centred |
| Name | x412, y477 ink-top (row top +15), 12px/500 `#2b4060` |
| Client ID | x412, 11px/400 `#8f9eb8`, 19px below name top |
| Phone line | Lucide `phone` 11px `#576b8e` at x541; text x561, 11px/400 `#7788a2` (y477) |
| Email line | Lucide `mail` 12x10 `#63779b` at x540; text x561, 11px/400 `#818fa8` (y497) — line pitch 20 |
| Status pill | x715 (w 51 Active / 60 Pending / 58 Inactive), h 24, radius 999, text 11px/500 centred, h-pad 11 |
| Next appointment | Lucide `calendar` 13x14 `#5f718e` at x829; date line x853 11px/400 `#7381a0` (y480), time line 11px `#7b89a8` (y497, pitch 17); empty → "—" (en/em dash 11x2 `#8e9fbb`) at x830, vertically centred |
| Last Visit | x964, 11px/400 `#7889a9`, vertically centred |
| Actions button | 1119,row top+13,36x32 (to x1155): bg `#f6f9fc`, 1px border `#e8effa`, radius 8; Lucide `ellipsis` 3 dots 13x3, `#0b5ccb` (blue), centred |

Status pill colours: **Active** bg `#daf7ec` text `#228e6d`; **Pending** bg `#d2e5fe` text `#1f6bf3`; **Inactive** bg `#e9edf1` text `#637796`.

Initials avatars: **JW** (James Wilson) bg `#cce0fd`→`#d6e7fe` blue, text `#1e6be4`; **DD** (Daniel Thomas) bg `#ddd7fd`→`#d7d0fd` violet, text `#4c40d9`; **MS** (Matthew Scott) bg `#c9f3ea`→`#c2f2e6` mint, text `#0b9176`. Text ink height 11 (≈15px/500). Photo avatars: Emily, Michael, Sophia, Olivia, Grace (no border).

Row data (exact copy):
| # | Name | ID | Phone | Email | Status | Next appt | Last visit |
|---|---|---|---|---|---|---|---|
| 1 | Emily Johnson | CL-0012 | +233 24 123 4567 | emily@example.com | Active | Apr 28, 2025 / 10:00 AM | Apr 15, 2025 |
| 2 | Michael Brown | CL-0013 | +233 55 987 6543 | michael@example.com | Active | Apr 29, 2025 / 11:30 AM | Apr 10, 2025 |
| 3 | Sophia Davis | CL-0014 | +233 20 111 2233 | sophia@example.com | Active | Apr 30, 2025 / 2:00 PM | Apr 8, 2025 |
| 4 | James Wilson | CL-0015 | +233 26 456 7890 | james@example.com | Pending | — | Mar 20, 2025 |
| 5 | Olivia Martinez | CL-0016 | +233 24 555 6677 | olivia@example.com | Active | May 2, 2025 / 9:00 AM | Apr 12, 2025 |
| 6 | Daniel Thomas | CL-0017 | +233 57 888 9999 | daniel@example.com | Inactive | — | Feb 15, 2025 |
| 7 | Grace Lee | CL-0018 | +233 50 333 2211 | grace@example.com | Active | May 5, 2025 / 3:00 PM | Apr 18, 2025 |
| 8 | Matthew Scott | CL-0019 | +233 27 777 1144 | matthew@example.com | Active | May 6, 2025 / 10:30 AM | Apr 16, 2025 |
(Dates are `M d, Y` with unpadded day; times 12h `g:i A`, unpadded hour.)

**Pagination** (y ≈ 967–990, centre 978; below last separator y954):
- Left text "Showing 1–8 of 48 clients" (en dash) x321, 10–10.5px/400 `#8a98b3`.
- Controls right-aligned, right edge ≈ x1146: `<` button 942,966,25x24 (bg `#f3f7fb`, radius 7, Lucide `chevron-left` ink 6x9 `#607ca2`); pages **1** (active) 976,967,23x24 fill `#2a7bf5` text white 12px/600, radius 7; **2,3,4,5** at x1006, 1037, 1068, 1099 (pitch 31, 23–24 wide) bg `#f5f8fc`, text 12px/500 `#294076`; `>` ink 6x9 `#647aa4` at x1140 (no filled bg visible, sits right of 5).
- Panel bottom y1005; bottom padding ≈ 15 below pagination.

## 5. Filters panel (1192,354,315x652)

Padding 18 left (controls x1210–1489, **w 280**). 
| Element | Spec |
|---|---|
| Title "Filters" | x1212,y378; ~15px/600 `#182a54`. **Comp glitch: rendered as "Filters s" / "Filter s" (garbled stray "s") — intended copy is "Filters".** |
| "Reset" | right aligned, ink right x1488, y380; 11.5px/500 `#206de8` (link) |
| Field label | 11px/500 `#354972`–`#3e4d73` (label→control gap ≈ 12; ink-top at label y, control top = label y + 19) |
| Select | 1210,y+19,280x32 (e.g. Status 437–469); bg `#fff`, 1px border `#e8effa`, radius 8; value x1220, 11px/400 `#6d7b99`; chevron-down ink 10x6 `#63769f` at x1466 (Lucide `chevron-down` 14–16px) |
| Field pitch | label y 417, 487, 556, 626, 696 → **pitch ≈ 69.7** |
| Last Appointment | label y770 (11px/500 `#374c6d`); two date inputs y790–821 (h 32): Start 1210–1344 (w 134), End 1354–1489 (w 135), gap 10; same style as select; icons: `calendar` 13px `#5c7294` at x1220, text "Start date" x1244 11px `#7f90aa`; `arrow-left-right` 13x8 `#64789c` at x1365, text "End date" x1389 `#8391b0` |
| Divider | 1px `#f3f6fb` full control width at y≈835 and y≈885 (very faint) |
| Toggle row | "Only show my clients" x1210,y855 11.5px/500 `#394e6f`; switch 1452,851,37x20 (to x1489), track `#d8e0ee` (off), knob white 18px circle with 1px `#d2dbeb` ring at left (x1452) |
| Apply button | 1210,911,280x31 (to y942), fill `#2e7cf5`, radius 8; Lucide `search` 13px white at x1301; label "Apply Filters" 12.5px/500 white (x1326, gap 12) → icon+label centred |
| Panel bottom | empty space under button (≈ 64px) to y1006 |

Select placeholders (exact): Status → "All Statuses"; Location → "All Locations"; Clinician → "All Clinicians"; Program → "All Programs"; Client Type → "All Client Types". Labels: Status, Location, Clinician, Program, Client Type, Last Appointment.

## Uncertain
- Stat-card unequal widths; avatar gradient exact stops; pill/pagination radii ±2.
- Text sizes ±0.5px (table text is 10–12px: smallest in any comp; do not go below 11px for accessibility — if raised, keep column widths per this spec).
- The "Filters" title glitch (see §5).

## Decisions — user-requested changes vs the comp (2026-10-03)

The comp is still the reference for typography, row height, spacing, colours, the stat cards and the filters panel.
These changes were asked for by the product owner and win over the comp:

| Area | Comp | Now |
|---|---|---|
| Columns | Client (+ ID) · Contact · Status · Next Appointment · Last Visit · Actions | Client (avatar, name, a small **Minor** / **Couple** tag, then the PRIMARY phone and email lines — the old Contact column merged in) · **Relationship** · **Billing** · Status · Next Appointment · Last Visit · Actions |
| Client number | Under the name (`CL-0012`) | **Not in the table.** Still searchable ("CL-12", "0012") and shown on the profile and edit page |
| Relationship | — | Up to 3 lines (11px/15px): `Clinician: Dr. Sarah Carter`, `Members: A, B` (a couple) / `Couple: Emily & Michael Johnson` (a member), `Guardian: …`, `Partner: …`, `Emergency: …`; client names link to their profile; more → `+N more` (links to the profile). Linked clients the viewer may not see are left out |
| Billing | — | Pill, same geometry as the status pill: **Insurance** (`#efeafe` / `#5b3fc4`) or **Self pay** (`#eef2f7` / `#4a5f80`) |
| Column widths | — | check 4.6% · client 24% · relationship 26% · billing 8.8% · status 8.6% · next 12.2% · last 9.4% · actions 6.4% |
| Filters | Client Type = (stand-in) live/demo | **Client Type** = Adult / Minor / Couple; **Billing** (Insurance / Self pay) added below it; **Location** gains "Virtual (telehealth)" first; the live/demo stand-in moved to a **Records** select shown only while the organization has demo data |
| Phone (< 40rem) | — (table scrolled sideways) | Each row is a card: tick · client · actions, then Relationship, then Billing \| Status, then Next Appointment \| Last Visit, each value under its column name; no horizontal scroll |

Rows stay 62px with three client lines (name 16 + two 16px lines); the Relationship cell fits three lines plus "+N more" at 15px.

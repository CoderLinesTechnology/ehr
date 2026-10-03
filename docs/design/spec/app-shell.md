# App shell — measured spec (WellNest)

Source comps 1536x1024 (= 1x desktop viewport): `08-dashboard`, `10-clients`, `02-settings-organization` (6-item nav), `01-appointments` (10-item nav).
All values measured from pixels (median colour of flat regions, edge scans, Inter-width fits). Hex = sRGB. Boxes = `x,y,w,h` in comp px.

**Read first: the comps are not pixel-consistent with each other** (generated renders; shell drifts by 2-8 px between screens, 08 is smallest-scale). Tables give each comp's value; the **Canonical** column is what to build (median / majority of 10, 02, 08, 01). Fonts: all text is Inter-like. Size rule used: width-fit against Inter (`public/fonts/inter`), cross-checked with cap height (cap reads ~7% taller than width-fit because of anti-alias blur). Values below are the width-fit size rounded to the nearest 0.5px.

## Palette (shell)

| Token | Hex | Where |
|---|---|---|
| sidebar bg | `#f4f8fb` (08 `#f4f8fb`, 10 `#f4f9fd`, 02 `#f4f8fb`, 01 `#f3f8fc`) flat, no gradient (samples top/mid/bottom within 1 unit) | sidebar |
| topbar bg | `#fefefe` (08 `#fcfdfe`) | topbar |
| main bg | `#f8fbfe` flat (08 `#f8fafd`, 10 `#f8fbfe`, 02 `#f9fbfd`, 01 `#f7fbfe`); sampled at 8 points, spread < 2 units → treat as flat | main |
| card bg | `#ffffff` (renders `#fefefe`) | all cards/panels |
| card border | 1px `#ebf1f9` (range `#e9f0f8`–`#edf3f9`) | cards/panels |
| strong ink (titles, wordmark) | `#04102c` (08 `#04102c`, 10 `#01092e`, 02 `#000625`) | |
| nav label inactive | `#485b7f` (08 darker `#324662`) | |
| nav icon inactive | `#42587e` (08 `#364c6e`) stroke | |
| active text/icon | `#1262e6` text (08 `#1259cd`, 10 `#0e62ee`, 02 `#1868df`, 01 `#115df1`); icon stroke `#0656ed` | |
| active pill bg | `#e6f0fd` + 1px border `#d9e8fa` | |
| primary blue (fills) | `#2f7ff6` (10 button `#307ef6`, top `#3c8cf5` → bottom `#2179f9` very slight vertical gradient; 01 button `#4089f9`) | |
| link blue (text) | `#196ad3`–`#206de8` | "View all", "Reset" |
| danger/notification red | badge `#f6485f` (10 `#f74f66`, 02 `#f93f54`, 01 `#fc4e66`, 08 `#eb6272`), bell dot `#fd3d60` (08 `#e9596b`) | |
| teal (logo) | `#16c7ba` (10), `#24c4ba` (02), `#3dbdb3` (08 avg) | leaf |

Comp 02 prints brand "Primary Color #2563EB" / "Secondary #93C5FD" but rendered buttons measure `#2f7ff6`; build the UI to the **measured** `#2f7ff6`, keep `#2563EB` as the org-brand default token (flag for owner).

## Sidebar

| Property | 08 | 10 | 02 | 01 | Canonical |
|---|---|---|---|---|---|
| width (edge) | 276 | 271 | 273 | 269 | **272** |
| right border | none; step `#f4f8fb`→`#f9fbfd`; faint 1px `#eaeff6` at x275 | none (step only) | none | none | **no border**; optional 1px `#eef3f9` |
| logo mark (leaf) box | 33,30,33x32 | 29,24,38x36 | 30,24,36x35 | 28,22,41x38 | **38x36 at x29,y24** |
| wordmark box | 80,38,73x15 | 81,33,94x18 | 81,32,91x18 | 85,32,106x20 | **x81, cap-centred y≈42** |
| wordmark size/weight | ≈17px/600 | 21.5px/600 | 21px/600 | 24px/600 | **22px / 600**, `#03082a`, no letter-spacing |
| active pill box | 24,106,229x42 | 19,151,236x48 | 21,364,234x42 | 19,192,237x46 | **x20, w 234, h 44** |
| item pitch (centre-centre) | 55.6 | 55.4 | 53.5 | 51 (10 items) | **55** (6-item), **51** (10-item) |
| first item centre y | 127 | 121 | 119 | 116 | **121** (topbar 73 + 48) |
| icon ink box | 22x22 @x35 | 23x22 @x33 | 22x21 @x33 | 22x22 @x31 | Lucide **24px**, stroke 1.75–2, box left x32 |
| label x (ink) | 81–82 | 82 | 80 | 80 | **x81** → icon→label gap 24 |
| label size/weight | 13.3px/500 | 13px/500 | 12.4px/500 | 12.3px/500 | **13px / 500** (cap height reads 14–15; width-fit 13) |
| item radius | ~10–12 (corner starts curving ≈8–10px in) | 12 | 12 | 12 | **12px** |

Layout of a nav item: `display:flex; align-items:center; height:44px; padding:0 14px 0 12px; gap:24px; border-radius:12px; margin:0 18px 0 20px` (pill right edge ≈ 254). Vertical gap between items = pitch − 44 = 11px (6-item) / 7px (10-item).

- **Active pill**: bg `#e6f0fd`, 1px border `#d9e8fa`, label + icon `#1262e6`/`#0656ed`, label weight 500 (no visible weight jump).
- **Inactive**: no bg; icon `#42587e`, label `#485b7f`.
- **Messages badge**: circle 21–22px, fill `#f6485f`, white digit 12px/600 centred; right edge x≈248 (24px from sidebar edge); vertically centred on the row (08: 226,281,21x21; 10: 227,274,22x22; 02: 225,267,21x21).
- **Icons (Lucide)**: Dashboard `house`, Clients `users`, Appointments `calendar`, Messages `message-circle` (rounded bubble, tail bottom-left), Resources `book-open`, Settings `settings` (gear). 10-item extras: Tasks `calendar-check` (shown as calendar w/ check), Documents `file-text`, Telehealth `video`, Programs `users-round` (3-person group), Reports `chart-column` (bar chart in a rounded square), Settings `settings`.
- **Bottom card** ("Better care. / Healthier tomorrows."): 08 box 24,890,228x97; 10 27,866,226x88; 02 22,882,230x94; 01 27,883,222x98 → **x24, w 228, h ~92, bottom gap ≈ 37 from viewport bottom (anchor bottom: 36px)**. bg `#ebf2f8` (08 `#edf2f8`, 10 `#eaf2f9`), 1px border `#e4ecf6`, radius 12. Heart icon Lucide `heart` 22–23px stroke `#2c466a` at card-left+21, card-top+16 (08: 45,906). Text left = icon left (x46): line 1 "Better care." 12px/600 `#435871` (y 940), line 2 "Healthier tomorrows." 12px/400 `#6a7d96` (y 959; line pitch 19).

### Nav variants

| | 6-item (08, 10, 02) | 10-item (01) |
|---|---|---|
| items | Dashboard, Clients, Appointments, Messages, Resources, Settings | Dashboard, Clients, Appointments, Messages, **Tasks, Documents, Telehealth, Programs, Reports**, Settings |
| pitch | 55 | 51 (centres 116, 166, 216, 266, 316, 366, 418, 471, 523, 574) |
| pill h | 44 | 46 |
| active item | per page | Appointments |
| visual difference | none other than pitch/pill height; same colours, icon size 24, label 13px, same badge on Messages |

Copy of 10-item labels exactly: Dashboard, Clients, Appointments, Messages, Tasks, Documents, Telehealth, Programs, Reports, Settings. (A page in the 6-item shell highlights its own item: 08 Dashboard, 10 Clients, 02 Settings.)

## Top bar

| Property | 08 | 10 | 02 | 01 | Canonical |
|---|---|---|---|---|---|
| height (to border row) | 81 | 72 | 73 | 76 | **72 + 1px border = 73** |
| bg | `#fcfdfe` | `#fefefe` | `#fefefe` | `#fefefe` | `#fefefe` |
| bottom border | 1px `#ecf1f7` (y81) | `#eff4fc` (y72) | `#eef3f8` (y73) | `#e9f1fb` (y76) | 1px `#edf2f9` |
| search field | **none on 08** | 297,18,557x40 | 298,18,552x39 | 292,17,513x40 | **x(sidebar+26), y16, w 557 (max), h 40** |
| search bg / border | – | `#f4f8fc` / 1px `#eaf0f9` | `#f4f7fb` | `#f3f8fb` | bg `#f4f8fc`, border 1px `#eaf0f9`, radius ≈10 |
| search icon | – | Lucide `search` 16px `#657aa2` @x312 | 16px `#5e7197` | 15px | 16px `#657aa2`, left pad 14 |
| placeholder | – | 13px/400 `#6f7fa0` @x343 | 12.5px `#72839e` | 12px `#8697b5` | "Search clients, appointments, or anything..." 13px `#6f7fa0`, gap icon→text 12 |
| bell | 1246,25,25x27 `#617593` | 1229,19,32x32 box | 1243,20,25x29 | 1227,20 | Lucide `bell` 24px stroke `#5a6f93`, x≈1240 |
| bell dot | ~10px `#e9596b` (top-right of bell) | 10x11 `#fd3d60` | – | `#fa4b66` | 10px circle `#fd3d60`, offset +13/−6 from bell centre, 2px white ring not visible |
| divider | 1x36 `#f2f6fa` @x1288 (y23–59) | x1275, `#edf3fc` | x1288 `#f3f7fb` | x1274 `#e7eff7` | 1px × 36px `#edf3fa`, 18px left of avatar |
| avatar | circle 40, `#acc3de` (dull), initials white 16px/500 | circle 43 (x1294–1336), `#7aabfc`–`#7fb0fd` | 40, `#7fb1fa` | 42, `#87b3fd` | **42px circle, `#7fb0fb`, "SC" white 14px/600**, centred |
| name | "Sarah Carter" 12.5px/500 `#293c55` @x1357,y26 | 13px/600 `#1e3051` @x1350 | 12px `#2f4060` | 11px | **13px / 600 `#1e3051`**, gap avatar→text 14 |
| role | "Organization" 11.5px/400 `#8492a8` @y47 | 12px `#7a88aa` | 11.3px | 11px | **12px / 400 `#7a88aa`**, line pitch 21 |
| chevron | 11x7 `#607898` @x1490 | 12x7 `#506b96` @x1491 | 12x7 `#5a7193` | 11x6 | Lucide `chevron-down` 16px (ink 12x7) `#5a7193`, right edge 33px from viewport edge |

Right cluster, left→right: bell (x≈1240) · divider (x1275) · avatar (x1294) · name/role (x1350) · chevron (x1491). Name text is **not** wrapped in a visible button/box.
Copy: "Sarah Carter" / "Organization" are placeholder identity (build: user name / org name).

## Main area

| Property | Value |
|---|---|
| bg | flat `#f8fbfe` (no gradient; 8 sampled points differ ≤ 2 units, the only tinted regions are cards) |
| content box | left = sidebar edge + 31 (10: 302; 08: 307; 02: 300; 01: 287), right pad 29 (content right edge x1507) → width ≈ 1205 |
| top | header block begins ≈ 19px under the topbar border (10: tile y92; 02: y94; 01: y89) |
| gaps | header → stat row 14–16; row ↔ row 17–22; column gap 17–21 (rail gap 21 on 08, 17 on 10) |
| right rail width | 08: 413 (x1094–1507); 10 filters panel: 316 (x1192–1507); 01: ~272 |
| card | bg `#fff`, border 1px `#ebf1f9`, radius **12px** (08 stat cards: row-1 inset 7, filled by row 8 → r≈10–12), shadow essentially none: `0 1px 2px rgba(15,40,80,.04)` (outside bottom edge 1px `#f5f9fc` then bg) |
| inner card padding | 08 stat cards 20 left / 18 top; 10 stat cards 24 left / 18 top; panels 24–27 |

### Page header pattern (title screens: 10, 02, 01; 08 has greeting instead)

| | 10 Clients | 02 Settings | 01 Appointments | Canonical |
|---|---|---|---|---|
| icon tile | circle 56 @302,92, bg `#e8f1fe`, Lucide `users` 28px `#0a5bf0` | circle 57 @302,95, bg `#e8f2fe`, `settings` 28px `#0e4aa3` | rounded-square 60 @288,90, bg `#eef6fd`, 1px `#e2eefc`, radius ≈16, `calendar` 30px `#0d6af7` | **56px circle `#e8f1fe`, icon 28px `#0a5bf0`** (01's square tile: keep as variant, 60px r16) |
| title | 24px/600 `#01092e` @x380 (cap-top y99) | 23.5px/600 `#000625` @x381 | 23px/600 `#071942` @x368 | **24px / 600**, gap tile→text 22 |
| subtitle | 13px/400 `#697c9e` @y134 | 13px `#617593` | 12px `#6f87ae` | **13px / 400 `#697c9e`**, 8px under title |
| right action | primary button "Add Client" (see 10-clients.md) | – | "New Appointment" + "Add Availability" | |

08 (Dashboard) page header: no tile — "Good morning, Sarah 👋" 26px/600 `#04102c` (box 310,104,262x24; emoji 23x23 Apple waving-hand, 8px gap) + subtitle 14px/400 `#596b84` at y142 (box 310,142,321x15).

## Icon & text rules shared by all screens

- Lucide, stroke ≈ 1.75–2 px at 24px box, line caps round. Colours: nav/body icons `#42587e`–`#5b728d`; active/brand icons `#0656ed`.
- Chevron-right in rows: 7x12 ink, `#687d98` (Lucide `chevron-right` 16px).
- Weights: titles 600, labels/buttons/pills 500, body 400. No text-transform; no letter-spacing.
- Avatars photographic (circle, no border) or initials (see screens).

## Uncertain

- Exact radii (±2px), exact weight 500 vs 600 for headings (strokes look 600), nav label 13 vs 14px (cap height says 14, width says 13).
- Whether 08's smaller sidebar/wordmark scale (and duller avatar `#acc3de`) is intended: treated as a render drift; build canonical values.
- Stat cards have unequal widths on 08 and 10 (see screen specs); canonical build = equal columns unless owner wants the measured widths.

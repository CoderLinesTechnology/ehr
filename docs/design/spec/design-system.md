# WellNest Design System — measured spec (comp 05, cross-checked with 08 and 10)

Tokens: `docs/design/tokens.css`. Legend: **[S]** sheet 05 sample, **[P]** printed on sheet, **[C]** 10-clients, **[D]** 08-dashboard, **[est]** estimated (comp too soft/garbled to measure).

**Scale rule.** Sheet 05 is a ~0.5x miniature (primary button 20 px tall on the sheet, 40 px on [C]/[D]). Build sizes below = sheet px x 2, confirmed against screens wherever a screen shows the same control. **Screens override the sheet.** Colours on the sheet are over-saturated vs screens (primary swatch `#066AFD`, button `#1272FA`, printed `#2563EB`, screen button `#307EF6`); build uses the screen value. Accuracy of every number: +-2 px, +-3 per colour channel.

Global: font Inter (self-hosted), Lucide icons, stroke 1.75-2, 20 px nav / 16 px inline. Heading ink `#0B1735`, body `#21395C`, label `#475A80`, muted `#64769A`, caption `#8793B1`. Page bg `#F8FBFE`, card `#FFF` + 1px `#EEF3FB` + radius 12 + very soft shadow.

## 1. Brand & colour tokens (sheet §1)
| Token | Printed | Sheet pixel | Screen pixel | Used |
|---|---|---|---|---|
| Primary Blue | #2563EB | #066AFD | #307EF6 [C] button | `--color-primary` #307EF6 |
| Primary Dark | #1E3A8A | #143B82 | – | #143B82 |
| Accent Green | #10B981 | #09BE9C | logo leaf | #09BE9C |
| Accent Teal | #14B8A6 | #1DB5B1 | logo leaf | #1DB5B1 |
| Gray 900/700/500/300/200/100 | #111827/#374151/#6B7280/#D1D5DB/#E5E7EB/#F3F4F6 | #0C1934/#414E61/#778190/#C3CAD4/#DEE1E4/#EDEFF2 | – | sheet pixels (navy-tinted greys) |
| Success | #10B981 | #16B483 (btn #16B082) | text #0F9D68 [C] | #16B482 |
| Info | #3B82F6 | #2090FD (btn #308AFA) | – | #308AFA |
| Warning | #F59E0B | #FE9E0E (btn #FEA818) | – | #FEA818 |
| Danger | #EF4444 | #FA4244 (btn #F24A4A) | badge #F4425C [C] | #F24A4C; count badge #F4425C |
| Purple / Pink | #8B5CF6 / #EC4899 | #9461FC / #FC4E9A | – | printed (swatches oversaturated) |
| Backgrounds | Page #F8FAFC, Card #FFF, Sidebar #0F2747, Hover #EFF6FF | #F7FBFE, #FEFEFE, #03234D, #F1F8FE | page #F8FBFE, sidebar light #F5F9FD, active nav #E5EFFD | see tokens |

Swatches are circles 42 px (sheet 21) with name 12/500 + hex 11 under; backgrounds are 64x24 rounded-8 chips.

## 2. Typography (sheet §2, all [P])
Inter, weights 300/400/500/600/700/800. H1 32/2rem, H2 28/1.75rem, H3 24/1.5rem, H4 20/1.25rem, H5 18/1.125rem, H6 16/1rem, Body 14/.875rem, Small 12/.75rem, XSmall 11/.6875rem, Caption 10/.625rem. Line-heights not printed -> 40/36/32/28/26/24/20/16/16/14 [est]. Sheet text styles: headings bold (700) on sheet; screens show semibold (~600): page title "Clients" cap height 20 px = 28 px / 600 [C]; stat number digit height 17-18 = 24 px / 600 [C]; nav, buttons, labels ascender 10-11 px = 14 px/500 [C]; table head 12/500; pill 12/500; ID/footers 11/400.

## 3. Layout & grid (sheet §3, [P])
Containers xs 480, sm 640, md 768, lg 1024, xl 1280, 2xl 1536. 12 columns, gutter 24, margin 24. Spacing 0,4,8,12,16,24,32,48,64,96. **Screens differ from printed gutter:** [C] sidebar 272 (divider px 271), top bar 72 (+1px border), content inset 31 left / 29 right, card gap 17-18 px, stat card h 167. [D] sidebar 276, content x309. Use `--layout-card-gap:18px`, `--layout-page-pad:31px` unless owner picks 24.

## 4. Buttons (sheet §5; build = sheet x2)
Common: radius 8, label 14/500 white (primary) , h 40 (default), px ~20, gap icon 8, inline-flex centered. [C] Add Client: 138x40 at 1368-1506, fill `#307EF6`, label 14/500 white, plus-icon 18 + gap 8, flat (top highlight 1px `#3C8CF5` then flat), radius 8.
| Variant | Default | Hover | Pressed | Disabled |
|---|---|---|---|---|
| Primary | #1272FA→#247AFA gradient on sheet; build flat #307EF6 | ~same, 4% darker #2670EA [est] (sheet indistinguishable) | #0C6AF6 on sheet; build #1F63D8 | white bg, 1px #E3ECF8 border, label #6B7A90 at ~60% (#8C99B3) |
| Secondary | white, 1px border #CFE0F7, label #307EF6 | border 1.5px #7DB2F8, bg #F5F9FF | bg #EAF2FE, border #BBD5F7 | white, border #E8EEF6, label #8793B1 |
| Success | #16B082 | lighter/same | slightly darker #12A074 [est] | white + very pale border, label green @25% |
| Warning | #FEA818 | same | #F59E0B [est] | as above amber |
| Danger | #F24A4A | same | #DC3A3C [est] | as above red |
| Info | #308AFA | same | #2579E6 [est] | as above blue |
Colour-variant labels white 14/500. Sheet shows secondary "Default" state with a 1.5px blue border (focus-like) — treat as hover.
**Icon button:** 40x40 [est] radius 8, white fill + 1px #E3ECF8 border (primary variant solid #307EF6 with white plus 18). Icons shown: plus (primary), paperclip/edit (neutral), trash (red #F24A4C), settings/grid (blue), refresh/camera (neutral). **Sizes:** large 40 / medium 36 / small 28 / xsmall 24; labels 14/13/12/11; px 20/16/12/10 [est from sheet 18/17/11/10 px x2]. Kebab "more" button [C]: 36x32, radius 8, bg #F3F7FC, border #E9F0F8, dots stroke blue #095FCD.

## 5. Form controls (sheet §6)
| Control | Spec |
|---|---|
| Input default | h 40 (sheet 20), w fill, radius 8, 1px #E1E8F3, bg #FFF, px 12, text 14/400 #21395C, placeholder 14 #8293B1. Compact h 30-32 for filter panels [C]. |
| Focused | border 1.5-2px #307EF6 + halo 0 0 0 3px rgba(48,126,246,.25) [est alpha]; text blue on sheet sample |
| Filled | same as default, value text #21395C |
| Disabled | bg #F0F6FC, border #E1E8F3, text #A9B6CE |
| With icon | Lucide search 16 at left 12, text starts ~x+40; gap 12 |
| Topbar search [C] | 556x40 at x297,y18, bg #F5F8FC, border #F0F5FA, radius 8, icon 18 #475A80, placeholder 14 #8293B1 |
| Textarea | h 96 (sheet 47), radius 8, same border, p 12, placeholder top-left; below it sheet shows two tiny labelled mini-fields "Default / Focused" (artifact) |
| Select | trigger = input h 40, placeholder #8793B1, chevron-down 16 right 12 (#21395C). Open menu: card radius 12, 1px border, shadow dropdown, p 4; option h ~28-32, text 14, hover/selected row bg #EAF2FE text primary radius 6 |
| Search input w/ clear | input + search icon left 16 + X 16 right (#21395C) |
| Checkbox | 16 box (sheet 20; screens 14 [C]) radius 4, unchecked 1px #C3CDE0 white; checked fill #307EF6 white check 12 stroke 2. Label 14 #475A80, gap 8 |
| Radio | 18 circle; selected: 2px #307EF6 ring + 8 px dot; unselected 1.5px #C3CDE0 |
| Toggle | 36x20 [C] (sheet 46x20), radius 999, on #307EF6 with white thumb 16 (sheet thumb has blue centre dot), off #E5ECF6 / thumb white with grey shadow |
| Range | track h 4 #E8EEF7, fill #307EF6, thumb 16 white w/ 2px #307EF6 (sheet ring) + tiny value tooltip above; ends labelled 0 and 100 (12 #8793B1); value "100" 14/500 right |
| File dropzone | w fill, radius 12, 1.5px dashed #CFE0F7 [est: edge looks solid pale], bg #F3F8FE, p 24, upload icon in 32 circle tint #E1EEFE; title "Drag & drop files here" 14/500 #143B82 + "or click to browse" 13 muted; "Supports: JPG, PNG, PDF (Max 10MB)" 11 #8793B1 |
| Date / Time picker | input h 40; leading calendar / clock 16 (#21395C) + trailing same icon at right; text 14 |
| Colour picker | 28 circle swatch #307EF6 (sheet shows #2563EB hex text) + hex 14/600 #143B82; label has a droplet icon |

## 6. Navigation (sheet §4; real screens 08/10)
**Light sidebar [C]:** w 272, bg #F5F9FD, right border 1px #E9F1FB. Logo row: leaf mark ~36 px (teal→green gradient) + "WellNest" 24/600 #143B82-navy, top pad ~24. Item: x19-255 (236 wide), h 47, pitch 55 (gap 8), radius 8, icon 22 at x33, label at x82 (gap ~28 from icon edge) 14/500 #40547B. Active: bg #E5EFFD, icon+label #095AF0. Badge: 22 px circle #F4425C, white 11/600, right at x237. Footer card: x28-253 (225), y865-952 (87), bg #EBF2F9, radius 12, heart icon 20, "Better care." 13 #21395C, "Healthier tomorrows." 13 #8793B1.
**Dark sidebar [S]:** bg `#04214C` (printed #0F2747), radius 8 on the demo panel. Logo white wordmark 24/600 + teal leaf. Items h ~38-40, icon 20 white 85%, label 14/500 white; active bg `#163F71` radius 8; badges red circles #F4425C 18-20 with white count; items: Dashboard, Clients, Appointments, Messages(3), Tasks(2), Documents, Telehealth, Programs, Reports, Billing, Settings (screens only use 6 — see open questions). **Collapsed:** 40x40 tiles, bg #04214C radius 8, icon centered; first tile carries teal logo. **Expanded (light):** white card radius 12 border #EEF3FB; icons blue #307EF6, labels #64769A.
**Top navbar [C]:** h 72, bg #FFF, 1px #F0F4FC bottom; search at left (see §5); right cluster: bell 24 (#475A80) with 9 px dot #FD4767 at top-right, 1px divider, avatar 40 circle (bg #79ABFC→#5B9BF0, white "SC" 14/600), name 14/500 #21395C, org 12 #8793B1, chevron-down 16. Sheet variant is a rounded 12 bordered bar.
**Breadcrumbs:** bar radius 8 bg #F5F8FC (sheet), px 16, items 13, "Home > Clients > Client Details", separators chevron-right 12 #8793B1, links #64769A, current #21395C/500.
**Pagination [C]:** boxes 24x24, radius 6, pitch 31 (gap 7), text 12/500 #294076; active fill #307EF6 white text; prev/next chevrons 14; neutral boxes bg #FFF 1px #EEF3FB. Sheet variant (ellipsis, 10) boxes appear ~36 (sheet 18) — unresolved, see open questions.
**Tabs (pill, sheet):** h 36, px 16, radius 8, label 14/500. Active: white bg + 1px #BBD5F7 border, text #307EF6/600; Inactive: white, border #EEF3FB, text #64769A; Disabled: bg #F3F8FD, text #A9B6CE. **Underline tabs** (drawer, notifications): label 13-14, active #307EF6/600 + 2px bottom bar, inactive #8793B1, 1px #EEF3FB baseline.

## 7. Table anatomy (sheet §8 + [C])
Card radius 12, 1px #EEF3FB. Header row ~44, bg #FFF/#F8FBFE, text 12/500 #384F79 (Name, Email, Role, Status, Actions). Row pitch 62 [C] (sheet 30x2 = 60), 1px divider #F0F4FA, px 16-24; leading checkbox 14, avatar 40 circle (initials avatar bg tint + text 14/500: JW `#D2E4FE`/blue, DD `#E0D9FD`/purple, MS `#D4F5EE`/teal), name 14/500 #21395C, sub-id 11 #8793B1; contact cells: phone/mail icon 14 #64769A + text 12 #475A80; date cell: calendar icon 14 + two lines 12; actions: kebab 36x32. Status pill: h 20, px 8, radius 999, 12/500, "Active" 48 wide. Footer: "Showing 1-8 of 48 clients" 11 #8793B1 left, pagination right, 1px divider above. Sheet table (5 rows, h 60 each): same, status pill column + `...` actions in navy.

## 8. Badges / tags (sheet §8)
Pills h ~20 (screens) px 8, radius 999, 12/500. Status: Active `#D9F7EC`/`#12906A`; Inactive `#EAEEF2`/`#6B7A90`; Pending **sheet** amber `#FFF2DD`/`#EA8A06`, **screens** blue `#D5E7FE`/`#1F61D3`; Completed `#E8F5FE`/`#1668CE`. Priority: High Priority bg `#FEE6E3` + 1px red border text `#E5383B`; Medium bg `#FDECD8` text `#EA6A0A`; Low transparent + 1px teal `#1DB5B1` border, text #0F7FB0 [est]. Tags (Client, Clinician, Staff, Admin, Location, Service, Telehealth): bg `#DAEEFF`/`#E6F0FC`, text #1668CE 12-13/500, radius 999, px 12, h 28 [est]; Admin/Staff slightly greyer.

## 9. Stat cards
[C] (4-up): card 246-373 x 167, radius 12, 1px #EEF3FB, p 23-24. Stack: icon tile (38-40; blue `#E1EEFE`, green `#E2F8F0`, blue `#E3F0FE`, blue `#E7F0FE`; icon 20 stroke #1668CE / green #16B482) at y+20; label 14/400 #475A80 (gap 14); value 24/600 #0B1735 (gap 14); delta row arrow-up 14 + "12%" 12/500 `#0F9D68` (gap 14); "vs. last 30 days" 12 #8793B1 (gap 8). [D] tiles are circles 42-44 `#ECF3FD`, delta red variant uses alert-circle 16 + "1 new" `#E75C6F`.
Sheet cards (8): label 12 #475A80 → value 24/600 (first card value is blue #1668CE) → delta 11 with 8 px green/red dot or arrow ("+12% vs last month" green, "2 overdue" red), card w 120 sheet → ~240, h ~110, radius 12, p 16-20. "Revenue" value `$12,480` 24/600.

## 10. Progress & steps
Stepper: 24 px circles, active filled #307EF6 white "1"; inactive white, 1px #E3ECF8 + #8793B1 number; labels 12/500 ("Personal Info", "Health Info", "Confirmation"), gap 8. Bar below: h 4-5, track #EAF1FB, fill #307EF6, radius 999, "67%" 12/600 right.

## 11. Alerts / notifications rows (sheet)
Row h 46-48, radius 10-12, 1px tinted border, bg tint (see tokens `--alert-*`; green #F2FCF8, blue #F2F8FE, amber #FFF4E2, red #FEF4F3, gradient fading right), p 12-16, leading solid circle 22 (green #16B482 check, blue #308AFA info, amber #FEA818 triangle, red #F24A4C x) white glyph 12, message 14/600 in the semantic colour (#12906A / #1668CE / #EA8A06 / #E5383B), trailing chevron-right 16 same colour.

## 12. Modals & drawer (sheet §9)
**Confirmation:** card w ~460 [est: sheet ~170], radius 16, shadow modal, p 24; title "End Session?" 20/600 `#0B1735`; body 14 `#64769A`; footer right-aligned gap 12: Cancel (secondary h 40) + "End Session" (primary). **Form modal:** title "Create New Client" 16/600, close X 20 top-right #475A80; fields: label 12-13/500 #21395C above input h 40 (placeholders 13 #8793B1), vertical gap 16; footer Cancel + Create right. **Drawer (right):** w ~ 400 [est], radius 16 on demo, header "Client Details" 16/600 + X; avatar 56 circle bg `#CEEEFE` text #307EF6 "EM" 20/600; name 16/600, "CL-0012" 12 #8793B1; underline tabs Overview/Appointments/Notes; key-value rows (label 13 #8793B1 left col ~120, value 13 #21395C) row pitch 24; footer "View Full Profile" secondary full-width h 40 (blue label 13/600).

## 13. Notifications dropdown & message thread (sheet §10)
Dropdown: w ~ 300, radius 12, 1px border, shadow dropdown; header 52 high bg #F5F9FD "Notifications" 16/600 + X 16; underline tabs All/Unread/Mentions (13); item pitch ~ 60, p 12, 1px divider, leading 28-32 circle (purple `#D98BFB`, teal `#18BFB9`, pink `#FDD0D3` w/ red glyph, violet `#8C7CF2`) with white 14 glyph; title 13/500 #21395C, subtitle 12 #8793B1, time 12 #8793B1; footer link "View all notifications" 13/500 #307EF6.
Thread card: radius 12; header 64: avatar 40 (photo) + "Emily Johnson" 14/600 + "Online" 12 `#16B482` (+ dot), chevron-down 16; incoming bubble bg `#F1F5FB` border #E4ECF7 text #21395C 14, radius 14 (top-left 4), max w ~ 75%, timestamp 11 #8793B1 below; outgoing bubble `#308AFA` (sheet #1192FC) white text 14 radius 14 (bottom-right 4), right aligned; composer: row p 12, input pill radius 10 bg #F5F8FC placeholder "Type a message..." 14, send button 36 circle #307EF6 w/ white send icon 16.

## 14. Charts (sheet §8 / "12. Charts & Visuals")
**Line:** stroke 2 `#307EF6` (rendered `#2F7DF6`), points 6 px solid blue circles, area gradient `rgba(48,126,246,.14)`→0, light horizontal grid #E8EEF7 1px, y-labels 10 #8793B1 (0/..), x-labels Jan-Jun 10 #8793B1; header with small chart icon tag. **Bar:** 2-tone vertical bars w ~8 [est]: lower segment `#1272FA`-family (solid primary), upper lighter `#7DB4FA`, radius 2-3 top; legend dots 8: Completed `#307EF6`, Cancelled `#F24A4C`, legend text 12 #475A80. **Donut:** ring thickness ≈ 1/5 diameter, centre label "Total" 11 #8793B1 + "248" 20/700 #0B1735; legend rows: dot 8 + label 12 #475A80 + right-aligned value 12/500 — Active teal `#1DB5B1` 168, Inactive amber `#FEC37B` 52, Pending blue `#307EF6` 28. **Pie:** colours blue `#1297FE`→use #307EF6/#308AFA, amber `#FEAA1C`, green `#13BF98`; legend Telehealth 52% blue, In-Person 38% amber, Online 10% teal/green. Palette order: blue, teal, amber, pink `#F9A8B4`, green.

## 15. Calendar view controls + legend
Mini month: header "April 2025" 12/500 with prev/next chevrons 14; weekday letters 10 #8793B1; dates 11-12 #21395C, 24 px cells; selected day: 24 circle `#307EF6` white 12/600; other-month dates #C3CAD4. Legend (title 12/600) dots 10 px + label 12: Appointment `#18ABFA`, Telehealth `#D26BE4`, Blocked `#FEC37B`, Unavailable `#F9D6D2`, Available `#11C399`. Footer: "Today" secondary h 32 px 12 (blue label 13/500) + two 32 icon buttons (chevron-left/right).

## 16. Responsive (sheet §14)
**Phone** (frame ~ 110x150 sheet; bezel 3-4 px `#0F2747`, radius 24, notch pill top): header row logo (leaf 24 + "WellNest" 16/600 navy) + bell icon button; greeting "Good morning" 12 muted + "Sarah Carter" 18/600 #0B1735; stat rows (card radius 12, 1px border, label 12 #475A80 with 16 icon, value 20/600, sub-line 11 caption), gap 8, p 12; **bottom tab bar**: h ~56, bg #FFF, top border #EEF3FB, 4 items Home/Calendar/Clients/More, icon 22 + label 10; active #307EF6 / inactive #475A80.
**Tablet** (landscape, dark-navy outer frame radius 16): dark sidebar w ~25% (`#04214C`) with logo + icon/label items 12 white, active `#163F71`; content = white rounded-12 panel: search bar (radius 8 `#F5F8FC`) + filter icon; title "Dashboard" 20/600; card "Today's Appointments" 14/500 with rows: time 12/500 left, name 13/500, appointment type 12 in `#307EF6` (blue), 1px dividers; footer "View Calendar" secondary 32 high.

## 17. Artifacts & open questions
- **Printed hex vs rendered pixels disagree everywhere** (e.g. #2563EB printed, #066AFD swatch, #307EF6 on screens). Tokens use screen pixels; printed values kept as comments. Owner to confirm whether they want Tailwind-printed values instead.
- **Pending pill:** sheet = amber, screens 08 and 10 = blue (`#D5E7FE`/`#1F61D3`). Used screens; amber kept as `--pill-warning-*`. Inactive pill on sheet table is red, on screen 10 grey — used screen. Scheduled/upcoming/pink/purple pills not rendered anywhere; derived [est].
- **Sheet scale ~0.5x:** ratios drift (buttons 0.5, pagination boxes sheet 18 vs screen 24, checkbox sheet 10 vs screen 14, pills sheet 13 vs screen 20), so sheet-only controls (toggle 36x20 used from screen, range, dropzone, pickers, modals, drawer, dropdown, chat, charts) are x2 estimates +-20%.
- **Garbled / AI-artifact text on the sheet:** "Deofoed" (should be Default), duplicated "Focused" label on inputs, "Icon Brittped" (Icon Upload?), "Rage Go client month" (file chip), "emily@wellnest.coopy" in a card title, "Powering Tasks", "Visasols", "Funny Clients 23688", section numbering jumps (12 inside 8, 13 missing), unreadable axis ticks, 5-segment donut vs 3-row legend, pie with 4 slices vs 3-row legend. Ignore the text, keep the structure.
- **Sheet nav lists 11 items** (Tasks, Documents, Telehealth, Programs, Reports, Billing…) but 08/10 sidebars show 6 (Dashboard, Clients, Appointments, Messages, Resources, Settings). Dark sidebar exists only on the sheet/tablet; no real screen uses it.
- Layout: printed gutter/margin 24 vs screens 17-18 gap / 31 inset; sidebar 272 (C) vs 276 (D). 1536 wide may be a 1.0667 scaling of a 1440 design (sidebar 256, gap 16, page pad ~29) — not provable.
- Icon-tile shape: [D] circles, [C] stat tiles look rounded-square ~12; both tokens provided.
- Shadows, hover/pressed/disabled colours, modal/drawer/dropdown widths, scrim, line-heights, focus-ring alpha are estimated (soft edges, faint states). Stat-card/avatar/heading colours were sampled as darkest AA pixels so true ink may be slightly darker.
- Sheet header banner is a navy gradient (`#03234E` left → `#112E58`), footer `#05244F`/`#102E56` — decorative, not a product component.

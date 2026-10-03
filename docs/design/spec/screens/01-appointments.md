# Spec: 01 Appointments (comp `01-appointments.png`, 1536x1024 @1x)

Measured from pixels. Shell (sidebar, top bar) excluded. All coords are comp px (x,y from top-left); sizes are px.
Conventions: **cap** = measured cap/digit height; font px = cap / 0.727 (Inter metric). The comp face is narrower than Inter
(geometric, Figtree-like): text *widths* are given only as a sanity check, do not size by width. **ink** = darkest anti-aliased
sample of the text (thin text renders lighter than its true colour, so real value is at most ~1 step darker). Borders are all
1px very-light blue. No box-shadows were measurable on cards, buttons or event cards (sampled outside edges = page bg).

## 0. Page frame
| Item | Value |
|---|---|
| Page bg | `#F9FCFE` (vertical gradient to `#F8FCFE`; effectively flat) |
| Card bg | `#FFFFFF` |
| Card border | 1px `#EAF1FA` (range `#E9F1FA`-`#EDF4FA`), radius 12 |
| Main column | x 287 -> 1228 (w 941) |
| Right rail | x 1245 -> 1517 (w 272); gap main/rail 17 (use 16) |
| Rail card gap | 16 (cards at y 95-401, 417-692, 708-1001) |
| Gap header block -> toolbar card | header content ends y 149, toolbar card top y 163 (14) |

## 1. Page header
| Element | x,y,w,h | Style |
|---|---|---|
| Icon tile | 287,89,60x60 | rounded-square radius ~18, bg `#F8FCFE`, 1px border `#E4EFFB`; icon Lucide `calendar` 28px, stroke 2, colour `#1B76F9` (blue) |
| Title "Appointments" | x 367, cap-top y 99, h cap 17 | 24px / 700, ink `#010832` (navy-black) |
| Subtitle "View and manage your appointments across all locations." | x 367, y 129-143 | cap 10 -> 14px / 400, ink `#456A9A` |
| Title->subtitle | baseline gap ~14 | tile-to-title gap 20 |
| **New Appointment** (primary) | 963,96,154x39 | fill `#4890FB` (flat; top `#4B93FC` bottom `#438FF9`), radius 8, no border, no shadow. Text white 12.5px(cap 9)/500 at x 1004; Lucide `plus` 14px stroke 2 white at x 978 (icon->text gap 12). Padding-left 15, right 16 |
| **Add Availability** (outline) | 1124,96,103x40 | fill `#FFFFFF`, 1px `#E4EEFC`, radius 8, text `#1464E2` 12.5px/500, text starts x 1135 (pad 11), ends 1215 (pad 12), no icon |
| Gap between buttons | 8 | right edge of Add = 1227 = main column right edge |

## 2. Toolbar (top of the calendar card)
Calendar card = toolbar + week grid in ONE card: x 286,y 163,w 942,h 510 (bottom border at y 672), radius 12. Toolbar height 62 (y 164-226), separated from grid by 1px `#F0F5F9` at y 225. Controls vertically y 177-209 (h 32), padding-left 9, padding-right 7.

| Control | x..x2 | Style |
|---|---|---|
| Segmented Day / Week / Month | 296..499, h 32 | three separate pills (not a joined track), gap 4. Day x296 w63, **Week x362 w67 (active)**, Month x433 w66. Radius full (16) |
| - inactive pill | | bg `#F5F9FC`, 1px `#E9F0F8`, text 12.5px/500 ink `#4A6188` |
| - active pill (Week) | | fill `#4A92FB`, no border, no shadow, text `#FFFFFF` 12.5px/500 |
| Date-range navigator | 537..757 (w 220), h 33 | white, 1px `#EBF2FA`, radius 9. Contents: Lucide `chevron-left` 16 at x~548 (ink `#476592`), label "Apr 27 – May 3, 2025" 12.5px/500 ink `#253F6F` (starts x 577; en dash with spaces), Lucide `calendar` 14 at x~710 ink `#4A6188`, Lucide `chevron-right` 16 at x~739 |
| Select "All Locations" | 777..879 (w 102), h 33 | white, 1px `#EAF0F8`, radius 9, text 12px/400 ink `#4A5D84` at pad-left 9 (x 786), Lucide `chevron-down` 14 ink `#5A6B8E` right pad 11 |
| Select "All Clinicians" | 890..992 (w 102) | same |
| Select "All Services" | 1009..1110 (w 102) | same (gaps 11/17/14 in comp; use 14) |
| "More filters" button | 1124..1221 (w 97) | white, 1px `#E8F0FA`, radius 9, Lucide `filter` (funnel) 14 at x 1134 colour `#1B5BE0`, text "More filters" 12px/500 ink `#164EE2` at x 1156 |

## 3. WEEK GRID
Header "Apr 27 – May 3, 2025"; week Sun-Sat; **today = Mon Apr 28**.

### 3.1 Geometry
| Item | Measured |
|---|---|
| Grid origin | x 286 (card edge), header row top y 226 |
| Header row | y 226 -> 287, **h 61**, bottom border 1px at y 287 |
| Time gutter | x 287 -> 354, **w 67**, right border 1px at x 354 (gutter bg `#FFFFFF`) |
| Day columns (x edges) | 354 | 465 | 592 | 720 | 848 | 976 | 1104 | 1227 -> widths Sun 111, Mon 127, Tue/Wed/Thu/Fri 128, Sat 123 (comp is uneven by up to 13px; **implement as `67px repeat(7, 1fr)`** = 124.7 each; the uneven widths are drawing noise, flag if exactness needed) |
| Body | y 287 -> 672, **385 px = 10 hour rows** (8:00 AM ... 5:00 PM), **row h 38.5** (measured line y: 287, 325, 362, 399, 437, 478, 516, 554, 593, 631, 672) |
| Gridlines | horizontal 1px `#F1F5FC`; vertical 1px `#EBF2F9`; outer border `#EAF1FA`; header bottom border `#EAF1FA` |
| Body bg | `#FFFFFF`; no zebra |

### 3.2 Header cells
| Element | Style |
|---|---|
| Day name ("Sun", "Mon" ...) | cap 8-9 -> 12px / 600, centred in column, ink `#2A4E78` (non-today) ; today ink `#081052`; y cap-top 241 |
| Date ("Apr 27") | cap ~9-10 -> 12px / 400-500, centred, ink `#344E7E`; today `#0D4CDA` (blue, weight 500); y 258-268 |
| Name->date line gap | 17 (two lines, line-height ~17) |
| **Today column** | header bg `#E7F2FE` (y 226-281, then fades to `#F7FAFE`), body bg `#F7FAFE`; column outlined by vertical 1px lines `#EBF3FA` at x 465 and 592; header has rounded top corners (radius ~8 at the top) |
| Gutter header cell | empty |

### 3.3 Hour labels
"8:00 AM" ... "5:00 PM" (no leading zero). Left-aligned at x 303 (gutter pad-left 16), cap 8 -> 11px / 400, ink `#8196B3`. Label vertical centre sits ~10 px below its row line (8 AM centre y 297, row line 287); all 10 labels step 38.9 (297 ... 647). Labels are NOT centred on the line. No label for 6 PM.

### 3.4 Event positioning (comp is internally loose; this reproduces it)
* Card inset in column: 6 px left, 7 px right (card w = col w - 13: 114 in a 128 col).
* Card height **67** (60-minute services; 45-min look identical). For other durations use `max(67, dur_h * 38.5)`.
* Card top = body_top + (t - 8h) * 38.5 - 8 (best fit; individual comp cards deviate up to +-20 px: e.g. Mon 9:00 -> 317, 11:00 -> 402, 2 PM -> 495, 4 PM -> 581). Overlaps: split column width.
* Cards stack above gridlines (z-index), no margin between consecutive cards (min gap in comp 8-15 px).

### 3.5 EVENT CARD anatomy (all families identical except colours)
* No border, no left accent stripe, no shadow. Flat fill with ~2% lighter top-right (use flat median). **Radius 6.**
* Padding: left 8, top 8 (cap-top of time sits 9 below card top), right ~6, bottom ~6.
* 4 lines, pitch 13 px: **time** (cap 7-8, 10px / 600), **client name** (cap 8, 11px / 600... measured ~10.5px, use 11px/600), **service** (10px / 400), **location row** (starts 17 below service baseline; icon 10x10 + 5 gap + text 10px/400).
* Time format "9:00 AM" / "12:00 PM" (no leading zero), start time only.
* Location row: Lucide `map-pin` 10px stroke 1.5 for in-person (Accra, Kumasi); Lucide `video` 10px stroke 1.5 for Online. (Comp has two artefact variants - a filled video glyph and a filled "globe-pin" - normalise to outline `map-pin` / `video`.)
* Icon colour = location text colour.

### 3.6 Colour families (5 observed). Fill = median of card body; inks = darkest sample per line
| Family | Fill | Time | Client name | Service | Location (text/icon) | Observed cards |
|---|---|---|---|---|---|---|
| blue | `#DEEEFE` (today col `#E0EEFD`) | `#2263D8` | `#214CAE` | `#6588BF` | `#386199` | Emily Johnson (Mon 9:00), Sarah Wilson (Wed 12:00), Ava Thomas (Thu 1:30), Emma Garcia (Fri 2:30) |
| green | `#DFF8EB` (range `#D8F7E7`-`#DFF8EB`) | `#107154` | `#0A5A59` | `#6993AC` | `#2D6485` | Michael Brown (Mon 11:00), Grace Lee (Tue 3:00), Liam Taylor (Thu 10:00), Mason White (Fri 11:30) |
| purple | `#EEE9FE` (range `#EBE6FD`-`#EEE9FD`) | `#664EC9` | `#4F45A9` | `#6760AF` | `#7460D9` | Sophia Davis (Mon 2:00 PM), Daniel Thomas (Tue 1:00 PM), Ethan Clark (Wed 3:30 PM), Isabella Martin (Fri 9:00) - ALL are Online |
| orange | `#FEF1E7` (range `#FEEFE5`-`#FEF0E5`) | `#515158` | `#5C4E4C` | `#9192A1` | `#466289` | James Wilson (Mon 4:00 PM), Matthew Scott (Wed 9:30), Noah Harris (Thu 4:00 PM) |
| teal/cyan | `#DEF6FC` (`#DCF5FB`) | `#076685` | `#0F487A` | `#5C8AB7` | `#4D7394` | Olivia Martinez (Tue 10:00) |
Note: orange text inks are greyish (low saturation) in the comp; the fills run slightly warm-peach (`#FEEBD8` at Thu 4 PM bottom).
**Family assignment is NOT derivable from the data** (Emily/James/Matthew are all "Therapy Session, Accra" yet blue/orange/orange). Only rule that holds: Online -> purple; Follow-up Consultation -> green. Implement as a per-service colour setting (blue, green, purple, orange, teal).

Events as drawn (day, start, client, service, location):
Mon Apr 28: 9:00 AM Emily Johnson / Therapy Session / Accra; 11:00 AM Michael Brown / Follow-up Consultation / Kumasi; 2:00 PM Sophia Davis / Initial Assessment / Online; 4:00 PM James Wilson / Therapy Session / Accra.
Tue Apr 29: 10:00 AM Olivia Martinez / Progress Review / Accra; 1:00 PM Daniel Thomas / Therapy Session / Online; 3:00 PM Grace Lee / Follow-up Consultation / Kumasi.
Wed Apr 30: 9:30 AM Matthew Scott / Therapy Session / Accra; 12:00 PM Sarah Wilson / Initial Assessment / Kumasi; 3:30 PM Ethan Clark / Therapy Session / Online.
Thu May 1: 10:00 AM Liam Taylor / Follow-up Consultation / Accra; 1:30 PM Ava Thomas / Progress Review / Kumasi; 4:00 PM Noah Harris / Therapy Session / Accra.
Fri May 2: 9:00 AM Isabella Martin / Initial Assessment / Online; 11:30 AM Mason White / Follow-up Consultation / Accra; 2:30 PM Emma Garcia / Therapy Session / Kumasi.
Sun Apr 27 and Sat May 3: empty.

## 4. Today's Appointments card
Card: x 287,y 685,w 941,h 316 (to y 1001), white, 1px `#EDF4FA`, radius 12. Padding 15 (table band x 302 -> 1212; right pad of header items 20).

| Element | Measured |
|---|---|
| Header icon | Lucide `calendar` 18px, ink `#344F7B`, at x 306,y 699 |
| Title "Today's Appointments" | x 337 (icon->title gap 14), cap 11 -> 15px / 600, ink `#020442`, y 702-717 |
| "View all" | right aligned, ends x 1207, cap 9 -> 12px / 500, `#0C65EC` |
| Header band | x 302..1212 (w 910), y 731..769 (**h 38**), fill `#F6FAFD`, radius 8, no border |
| Header text | cap 8 -> 11px / 600, ink `#2E5091`-`#395785`, labels vertically centred (y 747) |
| Row | **h 44** (separators at y 812, 856, 900, 944; last row 944-988, card bottom pad 13), separator 1px `#F3F8FD`, no hover shown, no zebra |

Columns (left x of text) and cell styles:
| Col | x | Content style |
|---|---|---|
| Time | 309 | "09:00 AM" 11px / 600, ink `#13214E` |
| Client | 386 (header) / avatar 385, name 425 | avatar 26x26 circle photo; name 11px / 500, ink `#294B7C`; avatar->name gap 14 |
| Service | 539 | 11px / 400, ink `#5D6A8A` |
| Clinician | 692 (header) / avatar 691, name 731 | avatar 26x26 circle; name "Dr. Sarah Carter" 10.5px / 400 ink `#4E648E`; gap 14 |
| Location | 839 (header) / icon 841, text 856 | map-pin / video icon 10px `#5C74A0`, text 10px / 400 ink `#5C74A0` |
| Modality | 948 | "In-person" / "Telehealth" 11px / 400 ink `#56709A` |
| Status | 1043 | pill |
| Actions | header centred x 1175 (text 1159-1192) | `...` button |

Status pills (left x 1043, h 21-22, fully rounded, padding-x 14, text 11px / 500):
| Status | bg | text | width |
|---|---|---|---|
| Confirmed | `#D9F7EA` | `#1B8D6C` | 76 |
| Pending | `#D9ECFE` | `#1262E6` | 66 |
| Scheduled | `#E8EEF6` | `#657EA6` | 74 |
Actions button: x 1166, **26x22**, white, 1px `#E4ECF8`, radius 6, Lucide `ellipsis` (3 dots, 2px) ink `#234F92`, centred.

Rows (as drawn):
| Time | Client | Service | Clinician | Location | Modality | Status |
|---|---|---|---|---|---|---|
| 09:00 AM | Emily Johnson | Therapy Session | Dr. Sarah Carter | Accra | In-person | Confirmed |
| 11:00 AM | Michael Brown | Follow-up Consultation | Dr. James Allen | Kumasi | In-person | Confirmed |
| 02:00 PM | Sophia Davis | Initial Assessment | Dr. Sarah Carter | Online | Telehealth | Pending |
| **44:00 PM** (comp typo -> should be 04:00 PM) | James Wilson | Therapy Session | Dr. Lisa Morgan | Accra | In-person | Confirmed |
| 05:30 PM | Daniel Thomas | Therapy Session | Dr. James Allen | Online | Telehealth | Scheduled |
Location icons: `map-pin` for Accra/Kumasi (comp draws Kumasi with a globe-like glyph, normalise to `map-pin`), `video` for Online.

## 5. Right rail (x 1245, w 272, every card: white, 1px `#EAF1FA`, radius 14, padding ~18-20)

### 5.1 Calendar card (y 95..401, h 306)
| Element | Measured |
|---|---|
| Icon | Lucide `calendar` 20px ink `#0D2E5A` at x 1266,y 108 |
| Title "Calendar" | x 1300, cap 11 -> 15px / 600, ink `#000130`; header row centre y 117 (22 below card top) |
| Month nav row | centre y 160: Lucide `chevron-left` 16 (ink `#476592`) at x 1276; "April 2025" centred (x 1350-1410), cap 10 -> 14px / 600 ink `#051342`; `chevron-right` at x 1482 |
| Weekday labels | Sun..Sat, centre y 195, cap 8 -> 11px / 500, ink `#566F9C` |
| Day grid | 7 cols, **cell pitch 35.7 x 35.5** (col centres 1275, 1311, 1346, 1382, 1418, 1453, 1489; row centres 226, 261, 296, 332, 368), grid padding 12 left / 10 right |
| Day numbers | cap 9-10 -> 13px / 500, ink `#26436E`; out-of-month (30, 31, 1, 2, 3) ink `#ABB9CD` |
| **Selected day (28)** | filled circle **34-35 px** dia (x 1294-1328, y 351-386), fill `#4690FC`, number `#FFFFFF` 13px / 600 |
| Event dots | 5px circle, centred under the number, centre = number centre + 13 px (y). Colours: green `#0AAE7F` (3, 9, 29, 30); strong blue `#1D7BDB` (27); light blue `#7AA6EA` (17). Selected-day dot: none shown |
Dots as drawn: Apr 3 green, Apr 9 green, Apr 17 light blue, Apr 27 blue, Apr 29 green, Apr 30 green. Rows: [30 31 1 2 3 4 5] [6..12] [13..19] [20..26] [27 28 29 30 1 2 3].
No highlight on today beyond the selected circle; no weekend tint.

### 5.2 Quick Actions card (y 417..692, h 275)
| Element | Measured |
|---|---|
| Header | Lucide `badge-check` 20px ink `#21396B` at x 1264; title "Quick Actions" x 1299, 15px / 600 ink `#000024`; header centre y 442 |
| Rows | 6 rows, **pitch 36.4** (text centre y 481, 517, 554, 590, 627, 664), row = icon + label, no dividers, no hover shown |
| Icon | 18-19px Lucide, stroke ~1.75, ink `#274674`, at x 1267 |
| Label | x 1304 (icon->text gap 19), cap 9-10 -> 13px / 400, ink `#285493` (range `#19387F`-`#285493`; use `#23417C`) |
Rows: `calendar` Create Appointment; `clock` Add Availability; `users` Create Client; `message-square` Send Message; `square-check` Create Task; `file-text` Create Invoice.

### 5.3 Filters card (y 708..1001, h 293)
| Element | Measured |
|---|---|
| Header | Lucide `filter` 18px ink `#29416D` at x 1264; title "Filters" x 1295, 15px / 600 ink `#020D33`, centre y 732; "Clear all" right aligned ending x 1499, cap 9 -> 12px / 500, `#1563EE` |
| Label | cap 8-9 -> 12px / 500, ink `#43567F`, x 1271; label top 20 px below previous select bottom |
| Select | x 1267..1500 (w 233), **h 28**, white, 1px `#EBF0F8`, radius 8, text 12px / 400 ink `#415079` pad-left 11, `chevron-down` 14 ink `#7790B6` right pad 10 |
| Vertical rhythm | block pitch 60.3: label y 760, select 776-803; label 820, select 836-862; label 880, select 897-923; label 941, select 957-984; card bottom pad 17 |
Fields: Location "All Locations"; Clinician "All Clinicians"; Service "All Services"; Status "All Statuses".

## 6. Uncertain / comp quirks
* Event vertical placement and column widths are not mathematically consistent in the comp (see 3.1, 3.4).
* Event-family -> service mapping is arbitrary in the comp (see 3.6); needs a product decision.
* Text sizes derived from cap height assume Inter; the comp font is narrower, so measured text widths run ~15% shorter than Inter at the same cap height.
* Primary blue renders `#4890FB` here (and in the segmented pill) while the Settings comp lists "#2563EB" as the primary colour; confirm which is the button token.
* Grid card shows no shadow anywhere; if SPEC.md calls for one, none was measurable (outside-edge samples equal page bg).

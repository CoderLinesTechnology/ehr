# Spec: 02 Settings > Organization (comp `02-settings-organization.png`, 1536x1024 @1x)

Measured from pixels; shell excluded (comp 02's content starts at x 299 because its sidebar is ~4px wider than comp 01's; the shell spec owns that).
Conventions: **cap** = measured cap/digit height; font px = cap / 0.727 (Inter). The comp face is narrower than Inter; do not size by text width.
**ink** = darkest anti-aliased sample (thin text really is up to 1 step darker). Borders 1px very-light blue. No measurable shadows.
The comp is cropped at y 1024: the Contact Information card body and the Settings nav/panel bottoms below that are not visible.

## 0. Frame
| Item | Value |
|---|---|
| Page bg | `#F9FCFE` / `#F8FBFE` (flat) |
| Card bg | `#FFFFFF`, border 1px `#EDF2F9` (range `#EBF1F9`-`#F0F4FA`), radius 12 |
| Content left / right edge | x 299 / x 1513 |
| Nav card | x 299, y 172, **w 308**, bottom y 918 (h 746) |
| Panel card | x 625, y 172, **w 888**, runs past the viewport; gap nav->panel 18 |
| Inner cards (inside panel) | x 643 -> 1496 (w 853): panel padding 18 left / 17 right; card gap **9-10** (y 261-463, 472-726, 735-932, 941-...) |

## 1. Page header
| Element | Measured |
|---|---|
| Icon tile | **circle 58 px** at x 301,y 95; fill `#E8F2FE`; no border; Lucide `settings` 24px, stroke 2, ink `#1A57A9` (blue-navy) |
| Title "Settings" | x 381, cap ~17 -> 24px / 700, ink `#000009` (near-black navy) |
| Subtitle "Manage your organization's settings and preferences." | x 381, y 134-147, cap 10-11 -> 14px / 400, ink `#485B7E` |
| Tile -> title gap | 22; header bottom y 152 -> cards top y 172 (20) |
(Note the Appointments header tile is a 60px rounded square; this one is a circle.)

## 2. Settings section nav (left card)
Card x 299..607, y 172..918, white, 1px `#EDF2F9`, radius 12, padding 15 horizontal / 17 vertical.

| Item | Measured |
|---|---|
| Row box | x 314..593 (w 279), **h 39**, pitch **41.9** (gap ~3); row centres y 209, 251, 293, 334, 376, 418, 459, 501, 543, 586, 628, 670, 712, 754, 797, 839, 881 |
| Icon | 18px Lucide, stroke ~1.75, at x 324 (row-left + 10), ink `#2D4B74`-`#3A547D` (use `#34507A`) |
| Label | x 364 (icon left + 40), cap ~10.5 -> 14px / 500 (inactive ink `#2A3F66`; range `#192A4B`-`#31496B`) |
| Chevron | Lucide `chevron-right` ~14px (glyph 7x11) at x 574, right pad 12 from row edge, ink `#919EBA` (inactive) |
| **Active row** (Organization) | fill `#E8F2FE`, no border, radius ~10, label + icon `#0B48D4`/`#0244D0` (blue), weight 500, chevron `#7AA1E3` |
| Hover/focus | not shown in comp |
Gap after last row: card bottom pad 17-18.

**Items - the comp shows 17 (brief said 16):**
| # | Label | Lucide icon |
|---|---|---|
| 1 | Organization | `building-2` |
| 2 | Locations | `map-pin` |
| 3 | Team | `users` |
| 4 | Roles & Permissions | `shield-check` |
| 5 | Scheduling | `calendar` |
| 6 | Services | `layout-list` |
| 7 | Clients | `user` |
| 8 | Forms | `file-text` |
| 9 | Documents | `file` |
| 10 | Clinical | `heart-pulse` |
| 11 | Billing | `credit-card` |
| 12 | Programs | `users-round` |
| 13 | Telehealth | `video` |
| 14 | Notifications | `bell` |
| 15 | Public Website | `globe` |
| 16 | Integrations | `link` |
| 17 | Security | `lock` |

## 3. Organization Settings panel header
| Element | Measured |
|---|---|
| Panel padding | 18 left (cards at x 643); heading x 646 (21 from panel edge) |
| Title "Organization Settings" | x 646, y 197, cap-with-overshoot 14 -> 18-19px / 600, ink `#000018` |
| Subtitle "Update your organization's basic information and branding." | x 646, y 227-241, cap 10 -> 14px / 400, ink `#415C81` |
| Title -> subtitle | 28 (cap-top to cap-top 31); subtitle bottom -> first card top 261: ~20 |

## 4. Card header pattern (all four cards)
Card padding left 16 (icon at x 659) / top 15. Header row centre = card top + ~24. Icon -> title gap 14. Title cap ~12.5 -> **17px / 600**, ink `#000013`-`#00011B`. Icon ink navy `#14357A`-`#254274`.
| Card | Icon | Icon tile | Title x |
|---|---|---|---|
| Organization Profile | Lucide `building-2` 20px | circle **35px** `#E7F1FD` at 659,277 | 708 |
| General Information | Lucide `info` 20px, blue stroke `#1B4FA8` + light fill `#E3F0FC` | none (the icon is a 20px outlined circle) at 659,493 | 696 |
| Branding | Lucide `image` 18px | circle **28px** `#E6F1FD` at 659,752 | 702 |
| Contact Information | Lucide `mail` 20px | circle **~32px** `#EDF2F9` at 660,960 | 705 |
(Tile sizes differ per card in the comp: 35 / none / 28 / 32. Recommend one 34px circle token + the plain info icon exception if exactness is waived.)
Contact Information also has a subtitle line "Keep your contact details up to date." (x 706, y 984-993, cap 9 -> 13px / 400, ink `#566587`) 19px under the title.

### Edit button (Organization Profile + Contact Information)
Org Profile: x 1415, y 278, **65x29**; Contact: x 1416, y 958, **65x28**. Fill `#ECF5FF` (`#E9F3FD`), 1px border `#D9E7FC`, radius 8. Icon Lucide `pencil` 14px stroke 1.75 ink `#0636AE` at x 1426 (10 from left); label "Edit" x 1449, cap 9-10 -> 13px / 500, ink `#0653DE`. Right edge 1480 = card right (1496) - 16.

## 5. Organization Profile card (x 643,y 261,w 853,h 202)
| Element | Measured |
|---|---|
| Header row | circle y 277-312; title centre y 291; Edit button top-right |
| Logo tile | x 659,y 337, **96x98 -> use 96x96**, white `#FCFEFE`, 1px `#EBF0F7`, radius 14; logo mark (leaf, teal `#2FD4C5` -> `#67DDD7` gradient) ~50px centred (x 689-735, y 366-407) |
| Name "WellNest Therapy Center" | x 779 (tile right + 24), cap 12 -> 16px / 600, ink `#000019`, y 345-361 |
| Tagline "Mental Health & Wellness" | x 779, y 369-379, cap 10 -> 14px / 400, ink `#273F5C` (lighter render ~`#5A6C8C`) ; name->tagline 10 |
| **Active pill** | x 779,y 392, **50x21**, fill `#D2F5E6`, text "Active" 12px / 500 `#0E7E60`, pad-x 10, fully rounded; tagline->pill 13 |
| Vertical divider | x 1089, y 337-436, 1px `#EFF4F8` |
| Contact rows (right block) | icon x 1115 (14px Lucide: `mail`, `phone`, `map-pin`, `globe`; stroke ~1.75, ink `#4C6381`), text x 1141 (gap 12), pitch **28.7** (centres y 342, 371, 400, 428), cap ~10 -> 14px / 400, ink `#384C68`-`#445982` (use `#3F5273`) |
Contact copy: `info@wellnest.org`, `+233 24 123 4567`, `123 Wellness Avenue, Accra, Ghana`, `www.wellnest.org`.

## 6. General Information card (x 643,y 472,w 853,h 254)
Two columns split by a 1px `#EDF2F7` divider at x 1089 (y 534-708). Left col x 659..1064 (405 w), gap 25 to divider, right col x 1115..1480 (365 w).

### Left: label/value grid
| Element | Measured |
|---|---|
| Label column | x 659, label cap 9-10 -> 13px / 500 (ink `#3E5377`-`#41567C`), column width ~132 (value/inputs start x 791) |
| Row 1 "Organization Name" | plain text value (no input box) "WellNest Therapy Center" at x 791, 13px / 400 ink `#2F4767`, centre y 541 |
| Rows 2-5 Timezone / Currency / Date Format / Language | select boxes x 791..1064 (**w 273**), **h 29-30**, tops y 561, 600, 639, 679 (**pitch 39.3**, gap ~10) |
| Select style | white, 1px `#EDF2F8`, radius 8, text 13px / 400 ink `#2A3D5D`, pad-left 11 (x 802); Lucide `chevron-down` 16px (glyph 10x6) ink `#3E5981` at x 1042 (right pad 12) |
| Label vertical | centred on its select (label centres y 576, 615, 654, 693) |
Values: Timezone `(GMT) Africa/Accra`; Currency `GHS (Ghanaian Cedi)`; Date Format `DD/MM/YYYY`; Language `English`.

### Right: Description
| Element | Measured |
|---|---|
| Label "Description" | x 1115,y 536, cap 10 -> 13px / 500, ink `#495A7A` |
| Textarea | x 1115..1480 (**w 365**), y 553..646 (**h 93**), white, 1px `#E4EBF5`, radius 8, padding 11/12, text 13px / 400 ink `#2A3F66`, line pitch 19.5 (line cap-tops y 566, 585). Content: "We provide compassionate, evidence-based mental health care for individuals, families, and communities." (wraps after "mental"). Not resizable (no grip visible) |
| Counter "94/500" | right-aligned to x 1480, y 653-664, ~12px / 400 ink `#465F86` (lighter `#6B7C99` expected), 7 below textarea |

## 7. Branding card (x 643,y 735,w 853,h 197)
Two columns split by 1px `#F1F5FA` divider at x 1022 (y ~810-932). Left col x 659..~1000; right col x 1047..1480.

| Element | Measured |
|---|---|
| Label "Logo" | x 660,y 801, 13px / 500 ink `#4E5C7E` |
| Logo tile | x 659,y 819, **66x66**, white, 1px `#EDF2F9`, radius 12; leaf mark ~34px (teal) centred |
| **Change** button | x 748,y 827, **62x28-29**, fill `#ECF5FE`, 1px `#DBEBFD`, radius 8, text "Change" 13px / 500 `#105EDE`, centred (pad-x ~15) ; tile->button gap 24, button vertically aligned to tile's upper part (button top 8 below tile top) |
| Help text | x 749; "Recommended size: 512 x 512px" (y 867) and "File type: PNG, JPG" (y 882), 11px / 400, ink `#717B97`-`#74849D`, line pitch 15; button->help 12 |
| Right col label "Primary Color" | x 1047,y 809, cap 9 -> 12px / 500 ink `#505F7D` |
| Swatch (primary) | circle **24px** at x 1046,y 830, fill `#2563EB` (rendered `#2268EC`) |
| Colour field | x 1078..1200 (**w 122**), y 828..855 (**h 27-28**), white, 1px `#EFF4FA`, radius 8, text "#2563EB" 12px / 400 ink `#516280` at x 1088 (pad-left 10), swatch->field gap 9 |
| Secondary row | label "Secondary Color" y 867; swatch 24px at y 887, fill `#93C5FD` (rendered `#75ADFC`); field y 885..912, text "#93C5FD" |
| Row pitch | label->swatch 21; primary block y 809, secondary block y 867 (**pitch 57**) |

## 8. Contact Information card (x 643,y 941; visible only to y 1024)
Header as section 4 (circle 32px + `mail`, title x 705 "Contact Information", subtitle under it, Edit at x 1416,y 958). Body not visible in comp (cut off).

## 9. Uncertain
* Section nav has 17 items, not 16 (see 2).
* Icon tile sizes and the circle-vs-square header tile differ across cards (see 4); no consistent token.
* Page title / card title sizes are cap-derived (24 / 18 / 17 px); the narrower comp font makes width checks unreliable.
* Secondary swatch renders `#75ADFC` but the hex shown is `#93C5FD` (comp gradient/overlay); use the hex value.
* "Primary Color #2563EB" vs the `#4890FB` button blue measured on the Appointments comp.
* Lucide icon names for nav items are best-visual matches at 18px (Services, Clinical, Programs, Integrations least certain).

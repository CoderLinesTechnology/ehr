# 08 Dashboard — measured spec

Comp `docs/design/comps/08-dashboard.png`, 1536x1024. Boxes `x,y,w,h`. Shell (sidebar/topbar) → `../app-shell.md`; this comp's own shell values: sidebar edge x276, topbar 81 high with **no search field**, active item Dashboard (pill 24,106,229x42), Messages badge "3". Font sizes are Inter width-fits (cap height reads ~7% larger). Colours = ink colour of text / median of flat fills.

Global: main bg `#f8fafd`; cards `#fff`, 1px border `#ebf1f9`, radius ≈10–12, no real shadow; content column x307–1507.

## 1. Greeting (no icon tile)

| Element | Box | Spec |
|---|---|---|
| Title "Good morning, Sarah" + emoji 👋 (waving hand, Apple style, 23x23 @549,104) | 310,104,262x24 | 26px/600 `#04102c` |
| Subtitle "Here's a quick look at what's happening today." | 310,142,321x15 | 14px/400 `#596b84` |

(Apostrophes are straight/typographic `'`; copy exactly as above.)

## 2. Stat cards row — y182–367 (h 186), gap ≈ 19–20

Measured x-extents (unequal in comp): 307–540 (233), 561–822 (261), 841–1101 (260), 1120–1507 (387). Gaps 21/19/19. Equal-width columns would be 286; **use measured widths via `grid-template-columns: 233fr 261fr 260fr 387fr`** if exact match is required, else 4 equal (flag to owner).

Per card (card 1 coordinates; others same offsets from card left):
| Element | Spec |
|---|---|
| Icon tile | circle 42px at (card.x+20, 200) → 327,200 (top pad 18), bg `#eef5fe` (cards: `#eef5fe`, `#e5effc`, `#e9f2fd`, `#eaf2fd`), icon Lucide 22px `#0a4fc0`-ish (`#0344bb`…`#0a4fc0` → use `#0a4fc0`), 1.75 stroke |
| Label | x card.x+23 (330), y253 (ink top), 14px/400 `#485972` (13.5 fit) |
| Value | y283–303 (digit height 20 → 28px), 28px/600 `#00061d` |
| Delta | y315, arrow-up (Lucide `arrow-up`, 10x12, `#1ea876`) then text 14px/500 `#1e9d6e`; gap arrow→text 8 |
| Caption | "vs. last 30 days" y336, 13px/400 `#8595ab` |
Vertical rhythm: tile 200–242, label 253, value 283, delta 315, caption 336, card bottom 367.

| # | Tile icon (Lucide) | Label | Value | Delta | Delta colour |
|---|---|---|---|---|---|
| 1 | `users` | Total Clients | 48 | ↑ 12% | green `#1e9d6e` |
| 2 | `calendar-days` | Upcoming Appointments | 12 | ↑ 20% | green |
| 3 | `message-circle` | Messages | 3 | "(!) 1 new" — red filled circle-alert icon 10px `#e15569` + text `#e65e72` 14px/500 | red |
| 4 | `file-text` | Active Programs | 5 | ↑ 25% | green |
Card 3 caption also "vs. last 30 days".

## 3. Upcoming Appointments panel — 307,389, 766x394 (to y782)

| Element | Spec |
|---|---|
| Panel padding | 27 left; title cap-top y411 (22 below panel top) |
| Title "Upcoming Appointments" | 334,411,202x17 ink; 18px/600 `#091a35` |
| "View all" | right-aligned, ink right edge x1045, 1000,414,45x11; 13px/500 `#196ad3` |
| Row | pitch 64.7 (avatar tops 450, 515, 579, 645, 709), separator 1px `#eef2f7` at y505, 570, 635, 699 spanning x402–1046 (starts under text column, not under avatar; none after last row) |
| Avatar | photo circle 46px at x334; no border (very faint white ring) |
| Name | x403; 14px/500 `#12233b` (cap-top = row top+7) |
| Sub (type) | x403, 12px/400 `#748297`, ≈21px below name baseline |
| Time | x787 left-aligned, 12px/400 `#54677d`, y = row top+9 (above pill centre; ≈ 3px higher than pill text) |
| Status pill | x906, h 23, radius 999; Confirmed w 74 bg `#e2f8f0` text `#2ea17c`; Pending w 63 bg `#e5f1fd` text `#2563d4`; text 11.5px/500, h-pad 11 |
| Chevron | Lucide `chevron-right`, ink 7x12 `#687d98` at x1037 |

Rows (exact copy):
1. Emily Johnson · Therapy Session · Today, 10:00 AM · Confirmed
2. Michael Brown · Follow-up Consultation · Today, 11:30 AM · Confirmed
3. Sophia Davis · Initial Assessment · Today, 2:00 PM · Pending
4. James Wilson · Therapy Session · Today, 4:30 PM · Confirmed
5. Olivia Martinez · Progress Review · Tomorrow, 10:00 AM · Pending

Note: "Tomorrow, 10:00 AM" (ends x892) runs closer to the pill (x905) than other times; give the time column a fixed width (~120px, x787–907) and let the pill be left-aligned at x906 (gap 0–14 → add 12 min).

## 4. Recent Clients panel — 307,798, 766x150 (to y947)

| Element | Spec |
|---|---|
| Title "Recent Clients" | 334,819,106x13; 17px/600 `#06132d` |
| "View all" | same style as above, 1000,~827 |
| Mini cards (4) | y855–926 (h 71); x 323–494, 512–683, 701–872, 890–1061 (w 172, pitch 189, gap 17); bg `#fff`, 1px border `#ecf1f7`, radius 12, soft shadow `0 2px 6px rgba(15,40,80,.05)` |
| Avatar | photo circle 44px, at card.x+14, y867 |
| Name | card.x+69 (392), y876 ink-top, 12.5px/500 `#22334a` |
| Status | green dot 8px `#1aa771` at x card.x+69, y897 + "Active" 11px/400 `#8997a9` (x405, gap 5) |
Copy: Emma Wilson · Liam Taylor · Ava Thomas · Noah Harris, each "Active".

## 5. Right rail (x1094–1507, w 413)

**Hero card** — 1094,391,413x157 (y391–548), border 1px `#e0eaf7`, radius 12; bg horizontal gradient left `#fbfcfe` → right `#ebf3fe` (sampled left `#fbfcfe`, centre `#f7fafe`, right `#ebf3fe`).
- Circle 70px at 1121,412, bg `#e5effa` (soft inner glow), icon Lucide `hand-heart` 32x28 stroke `#3e6290` centred.
- Title "You're making a difference": x1205, y413; 15px/600 `#112441`.
- Body 2 lines, 12.5px/400 `#728097`, line pitch 18–19: "Every session, every conversation," / "contributes to better mental health." (x1205, y441 and y459).
- Button "View All Clients": 1204,491,153x36 (to y526); bg `#e5f0fc`, 1px border `#d1e1fb`–`#d9e7fd`, radius 8; text 13px/500 `#1661ce` centred (ink 91 wide).

**Quick Actions card** — 1094,566,413x216 (y566–782), padding 24.
- Header: Lucide `zap` (12x17, `#284364`) at x1123,y584; title "Quick Actions" x1150, 15px/600 `#12213d` (cap-top y586).
- 4 rows, pitch 39.7 (text centres y634, 673, 713, 753), separators 1px `#f0f4f8` at y654, 694, 734 spanning x1170–1484; **icon column** x1124 (17px Lucide, `#5b728d`), label x1170 13px/400 `#354862` (first row looks 12px/`#3d4f67`; use 13px), chevron-right ink 6x11 `#647995` at x1474.
- Rows: `user-plus` Add New Client · `calendar` Schedule Appointment · `message-circle` Send Message · `book-open` View Resources.

**Today's Schedule card** — 1094,799,413x186 (y799–985).
- Header: Lucide `calendar` (16x18, `#365270`) x1117,y814; title "Today's Schedule" x1147, 15px/600 `#10233f`.
- 4 rows, pitch 32.4; separators 1px `#f0f4f8` at y871, 903, 935 spanning x1173–1484; columns: time x1112 (11px/400 `#8694a5`, 44 wide col), name x1185 (11.5px/**600** `#1f2e44`), type x1288 (11px/400 `#8f9aae`).
- Rows: 10:00 AM · Emily Johnson · Therapy Session / 11:30 AM · Michael Brown · Follow-up Consultation / 2:00 PM · Sophia Davis · Initial Assessment / 4:30 PM · James Wilson · Therapy Session. (Time labels are unpadded: "2:00 PM".)

## 6. Vertical map (y)
Topbar 0–81 · greeting 104–157 · stats 182–367 · main panels 389/798, rail 391/566/799 · page bottom 985 (content ends 947 left, 985 right; rest empty). Gap panel→panel left: 782→798 = 16; rail: 548→566 = 18, 782→799 = 17.

## Uncertain
- Stat card widths unequal (see §2). Avatar photos are placeholders — build with initials/photo upload; photo avatars have no border.
- Text sizes ±0.5px (width-fit vs cap). Weights 500/600 judged from stroke.
- Sub-pixel layout of time column vs pill (§3).

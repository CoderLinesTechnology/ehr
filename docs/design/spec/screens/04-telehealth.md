# 04 Telehealth (sessions list) — measured spec

Comp `docs/design/comps/04-telehealth.png`, 1536x1024. Boxes `x,y,w,h`. Shell → `../app-shell.md` (sidebar edge x272, topbar 73). The comp's main column starts at x302 and its rail ends at x1516 (the shell's content box ends 1507), so the wrapper claims the difference with `margin-inline:-2px -9px` (≥70rem). Font sizes are Inter **width-fits** of the comp's ink (the comp's face is ~7–10% narrower than Inter at equal cap height); sizes under 11px follow the comp. CSS: `public/css/screens/telehealth.css` (section "Telehealth", prefix `tele-`). Verification: `docs/design/SPEC.md` → Verification, fixture `DesignFixtureSeeder` (sessions at the end).

## Layout
Grid `minmax(0,1fr) 314px`, gap 24 (main 302–1178, rail 1202–1516). Main column rows: header → action cards (y178–335) → tabs (y363–399) → list panel (y414–977). Rail: ready card y96–404, Quick Links y421–675, Tips y692–891 (gaps 17).

## Header
Tile 64px circle `#f1f6fe`-family (301,96), Lucide `video` 30px stroke 2.3 `#1a6df2`; title "Telehealth" x384 ink-top 105, 25px/600 `#000019` (ink 124x20); subtitle x383 ink-top 140, 12.3px `#37517f` (ink 486 wide).

## Action cards (4)
Boxes y177–334 (h157), x 302/523/746/969, width 207–208, gap 14. White, 1px `#f1f5fb`, radius 12, shadow `0 2px 7px rgba(30,70,140,.06)`. Tile 36x37 radius 10 `#f4f7fd` at (card+18, card+19); Lucide `video`, `calendar-days`, `link`, `settings` 20px stroke 2.3 `#1f78f0`. Title ink-top y249, 12.9px/500 `#08163f`; description ink-top y272, 10.4px/400 `#536b93`, **two fixed lines** (the comp breaks "Check your camera, mic, / and internet before a session." by hand — the view renders each line as a block). Arrow Lucide `arrow-right` 14px `#1f78f0`, ink 9x9 at (card.right-25, 308).

## Tabs
Track y363–399 (h37) x305–585, bg `#ecf3f9`, radius 999. Active pill 305–417 (112x36) gradient `#5599fb`→`#4593fb`→`#4f99fc`, white 11.4px/500, text "Upcoming (5)" — the count is shown on the active Upcoming tab only (Past and All carry it as screen-reader text). Inactive "Past" (min width 86), "All" (82): `#1d2f53` 11.4px/500.

## List panel
x302–1178, 1px `#f1f5fd`, radius 12, padding 10/16. Title "Upcoming Telehealth Sessions" ink-top y437, 14.9px/600 `#01062a` (ink 220x16). Rows pitch **75.2** (separators y468.5, 543.7, 618, 693.5, 769, 844.5, 920.5; `#f4f7fc`), columns (px from x320): avatar+name 0, service 187, date 349, video 525, status 635, action 753, kebab 816 (grid `187fr 162fr 176fr 108fr 120fr 63fr 24px`).
* Avatar circle 45px; tint from the client number (n mod 6 → blue `#cde4fe`/`#0a49f2`, mint `#c9f2ea`/`#0b7a54`, violet `#e7ddfe`/`#3a14e0`, teal `#ccf3ef`/`#078478`, rose `#fde7e7`/`#c2121f`, sky `#d9e6f8`/`#223f8f`); initials 13.4px/500. (The comp prints "EM" for Emily Johnson — a comp artifact; the app shows the real initials "EJ".)
* Name ink-top row+25, 11.5px/500 `#162357`; id "CL-0012" +46, 9.3px `#6b84a8`. Service +26 10.5px `#1a285a`; "With Dr. …" +47 9.3px. Date line: Lucide `calendar` 12px (stroke 2) `#3d4f77`, text at x696, 10.1px `#1a285a`; time line below, pitch 20. "Video": Lucide `video` 14px + 10.3px text, 3px above row centre. Status pill 71x22 `#dfeefe`/`#2a73e8` 10.3px/500 (in progress amber, completed green, cancelled grey, missed red). Join button 58x33 radius 8 gradient `#3a89f7`→`#2579f8` white 11.4px/500; View: 1px `#d7e3f4` white, `#1a3a78`. Kebab Lucide `ellipsis-vertical` 16px `#25345f` centred at x1148.
* Footer: separator y920.5, "Showing 1–6 of 6 sessions" ink-top y946, 9.8px `#5d7096`, x318. Panel bottom y977.

## Rail
* **Ready card** x1202,96,314x308: bg `#ecf5fe`, 1px `#e7f0fb`, radius 12. Tile 68px circle `#e0eefe` (centre x1359, top 126), Lucide `video` 32px stroke 2.2 `#1f78f0`. Title ink-top y219, 14.4px/600 `#01062a`; text 11.5px/400 `#536b93`, 3 centred lines pitch 20.5 (max-width 238), first ink y253. Button x1223,336,272x41 radius 6 gradient `#3d8bf7`→`#2a7cf6`, Lucide `video` 18px + "Join Session" 12.4px/500 white.
* **Quick Links** 1202,421,314x254: white, 1px `#f1f5fb`, radius 12. Title Lucide `link` 20px `#3a527f` at x1224, text x1252 ink y444, 14.9px/600. Four items pitch 47.3 (text ink y492, 539, 587, 634; 12.5px/400 `#0c1a45`), hairlines `#eef2f8` from x1231 to x1491 (inset), chevron `chevron-right` 14px `#5e7296` right edge x1492. Items: My Appointments (calendar), Telehealth Instructions (resources search "telehealth"), Help & Support (`mailto:` the platform support address), Provider Directory (Settings → Team) — each only when the screen exists and the member may open it.
* **Telehealth Tips** 1202,692,314x199: bg `#eef7fe`, 1px `#e7f0fb`, radius 12; Lucide `lightbulb` 18px `#25447c` at x1230, title x1259 ink y717, 12.2px/600; four tips (Lucide `check` 14px `#19a58a`, text 11.5px `#4d6389`, pitch 25, last-but-one wraps at 200px).

## Decisions
* "Join" appears only from `telehealth.join_early_minutes` (default 15, max 120) before the start until the end, for members holding `telehealth.join`; everyone else gets "View". The comp shows Join on five rows, which is only reproducible with a wider window: the screenshot check therefore compares Join on row 1 (inside the window) and View on row 6, and the other View rows against the same View style.
* The comp's tab reads "Upcoming (5)" over a list of six rows (its sixth row, Emily Johnson's 28 Apr session, is the completed one in comp 06); the app counts what it lists.
* Cards the member cannot use (no `telehealth.manage`, no calendar permission) are left out, like nav items.
* The list never hands out a video room link: "Join" leads to the session's join page (Daily, docs/design/spec/screens/11-telehealth-join.md), where the room is prepared.
* The comp's side nav shows Tasks/Documents/Reports; those modules do not exist yet, so the app nav (shell, not this screen) shows fewer items.

## Verification
Fixture at `APP_FAKE_NOW="2025-04-28 09:55:00"`, comp size 1536x1024, with the fixture's other telehealth sessions set aside and Emily's session upcoming (see the comp's own inconsistency above). Region metrics (MAE / % differing / SSIM): header + cards 4.87 / 4.9 / 0.82; tabs + list 6.69 / 6.5 / 0.72; rail 5.99 / 5.1 / 0.76; whole page 5.56 / 5.1 / 0.76. List geometry matches within 1 px (row separators at y 619, 694, 769, 844, 920 vs the comp's 618, 693.5, 769, 844.5, 920.5); text ink widths within ±3%. Differences left: the comp's Join buttons on rows 2–5 (the app shows View outside the join window), "Upcoming (6)" vs the comp's "(5)", avatar initials (EJ vs the comp's EM), the shell's nav items (modules not built). Phone 390px: no horizontal scroll.

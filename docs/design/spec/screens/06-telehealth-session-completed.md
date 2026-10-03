# 06 Telehealth session completed — measured spec

Comp `docs/design/comps/06-telehealth-session-completed.png`, **1224x1285**. Boxes `x,y,w,h`. The comp's shell is narrower than the app's at that viewport (sidebar edge 238 vs 272, topbar 68 vs 73, main column 260–862 vs 303–858), so main-column positions below are given in the comp's own pixels and were compared with a per-region offset (shot = comp + dx 43, dy 5; rail dx -3). Heights and vertical spacing are kept as measured. CSS: section "Session completed page" (`ts-`).

## Layout
Grid `minmax(0,1fr) 314px`, gap 22. Main cards (comp): client 248–465, summary 479–773, recording 785–997, next steps 1009–1206 (gaps 14/12/12). Rail: details 89.5–466.5, quick actions 484–750, saved 770–862 (gaps 18/20).

## Header
The header block (back link, tick, title) is inset 11px from the cards (x271 vs card edge x260). Back link x271,y95 11.6px `#0051d9`. Tick circle 64px (x271,y129) `#cdeedd`, Lucide `check` 34px stroke 2.6 `#0e7a4e`; title x355 ink-top 141 23.2px/600 `#000012` (ink 217x24); lines x354: ink-top 179 12.6px `#34507e` (ink 328) and 205 12.5px (ink 280): "Apr 28, 2025 • 10:00 AM – 11:00 AM  (1h 00m)".

## Client card (248–465)
White, 1px `#edf2f9`, radius 12. Avatar 64px at (285,270). Name x367 ink-top 273 15px/600 `#000018` (ink 106x16); "Client • CL-0012" ink-top 298 10.4px `#5e6b9f`; chips y319–341: "Therapy Session" (calendar 12px) and "Video" (video 12px), bg `#e8f1fd`, 9.9px/500 `#243f72`, height 22, padding 11/10, icon gap 7 (chip widths 127 and 63). Divider y355 (`#f0f4fa`). Tiles y370–443 (h73), x283/432/580/729, widths 142/141/141/111, gap 7, bg `#f5f9fd`, 1px `#f0f6fd`, radius 12; icon 20px `#243f72` (ink-top 386), label 10.5px/400 `#243f72` (ink-top 418): View Client Record (users), Send Message (message-circle), Schedule Next Session (calendar-check, prefilled booking: client, service, clinician, telehealth), More (ellipsis; opens the appointment / all sessions).

## Session Summary (479–773)
Title Lucide `file-text` 22px + 13.3px/600 `#000026` (ink-top 502). Boxes y536–756 (h220): left x276–525 (white, 1px `#eef3fa`, radius 12): labels 10.4px `#6a7aa0` ink-top 551/605/658/713, values 11.1px/500 `#1d396a` ink-top 571/625/679/732 (Session Type, Duration, Provider "Dr. Sarah Carter", Location with Lucide `video` 16px "Telehealth (Zoom)"). Right x537–847, bg `#f5f9fe`: "Notes" label ink-top 551 10.1px; note panel x545–835,y565–741 bg `#f5f9fe` with a 1px `#eef4fc` border, radius 8 (outer box `#f7fafe`), text 12.5px/400 `#32487c` line pitch 20.5. **The note panel is an editable field** for the session's clinician (or `telehealth.notes`): a borderless textarea that looks like the comp; a small "Save notes" button appears on focus. Saving adds an insert-only version.

## Recording & Transcript (785–997)
Shown to people allowed to see clinical content, when a recording/transcript exists or consent was recorded. Title Lucide `video` 22px + 13.3px/600 (ink-top 807); a green "Client consented to recording" badge sits at the right of the heading (consent must stay visible; not in the comp). Inner list x276–847, y837–983 (white, 1px `#eef3fa`, radius 12), two rows of 73 (separator y912): play circle 37px `#f3f0fe` with filled Lucide `play` `#1a5cf0` (x289), title x339 11.5px/500 `#1d3164` (ink-top 862), meta 10.4px `#46699a` "1h 00m • Apr 28, 2025 • 24.8 MB" (ink-top 883); Download button x734,858,99x33 white, 1.5px `#d8e6fb`, radius 10, Lucide `download` + 10.5px/500 `#0a54ea`. Transcript row: doc circle `#f3f1fe` with Lucide `file-text` `#4a35d6`, "AI Transcript (Draft)" (always "Draft" until reviewed), "Generated from recording", View button 58x33.

## Next Steps (1009–1206)
Title Lucide `clipboard-check` 22px + 13.3px/600 (ink-top 1032). Box x277–843,y1061–1126 bg `#eef4fc` radius 10: circle 37px `#dce9fc` with Lucide `calendar` `#1160f0` (x293), "Follow-up Appointment" x344 ink-top 1081 10.4px/600 `#0b1a4a`, text 9.5px `#46699a` ("A follow-up session is scheduled for May 5, 2025 at 10:00 AM." = the client's next booked appointment, ink 286 wide), "View Calendar" button 94x30. "← Return to Appointments" x275–847,y1148–1190 (h42) white, 1.5px `#d8e6fb`, radius 10, 10.6px/500 `#0a54ea` (text ink 119 wide plus the 13px arrow).

## Rail (x884, w314)
* **Session Details** 89.5–466.5: padding 19/22, title Lucide `calendar` 22px + 13.3px/600; five rows pitch 61.4 (label ink-top y160, 11.9px `#627298`; value ink-top y181, 12.3px/500 `#1d396a`; icons x909 stroke 2 `#2b4680`).
* **Quick Actions** 484–750: title Lucide `settings` + 13.3px/600; four items pitch 49.3 (text 11.7px `#1d396a`, icons 22px at x907): View Client Record, Send Message, Add Note (focuses the notes field), Create Task (only once the Tasks module exists).
* **Session saved** 770–862: bg `#eefbf5`, 1px `#dff3e8`, radius 12; filled Lucide `shield-check` 26px `#2eaa6e`; title x953 ink-top 792 11px/500 `#0d714a`; text 11px `#4e618d` two lines.

## Decisions
* A session that is not yet finished sends its viewers to the join page; one that never happened (cancelled, missed) back to the list. Without a next appointment the Next Steps box offers "Schedule".
* The comp's photo is replaced by initials; "Create Task" needs the Tasks module (verified with a stub route, not shipped).

## Verification
`tools/visual/shoot.py` with the fixture (the session is already completed in it). Two shots: **1224x1285** as the comp (the app's shell is wider than the comp's there, so the main column is 556 px instead of 602) and **1270x1285**, where the main column is exactly 602 px; the 1270 shot is shifted dx +43 / dy +5 (shell offsets) onto the comp for region metrics. Region metrics (mean abs error / % pixels differing >24 / tile SSIM), comp vs shifted render: header 8.98 / 8.8 / 0.69; client card 6.92 / 6.0 / 0.78; summary 6.22 / 5.6 / 0.73; recording + next steps 5.33 / 6.3 / 0.71; rail 5.90 / 5.8 / 0.71; whole content area 5.05 / 4.9 / 0.79. Remaining differences: the client photo (initials here), the "Client consented to recording" badge and the missing "Create Task" row (Tasks module). Phone 390px: no horizontal scroll (`scrollWidth == innerWidth`).

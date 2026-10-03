# Messages — measured spec (comp 07)

Comp 1536x1024. Shell = canonical `app-shell.md` (Messages item active, badge 3). Measured by edge scans and median colours
(comp is a soft AI render: edges blur over 2-3 px, so values are +-1-2 px). Hex = sRGB. Boxes `x,y,w,h` in comp px.

## Layout
| Region | Box | Notes |
|---|---|---|
| Main grid | x290..1521, y77..969 | content box starts 17 px right of the 272 sidebar (not 31 as on other screens), top 4 px under the top bar; bottom gap to viewport 55. Screen claims the difference with negative margins (like the calendar). |
| List panel | 290,77,372,892 | card bg `#fefefe`, 1px `#edf3fa`, radius 12 |
| Gap | 16 | between panels |
| Thread panel | 678,77,843,892 | same card |

## List panel
* Title "Messages" 24px/600 `#04102c`, ink 102 wide at x304, cap top y103. Subtitle 13px/400 `#596f93`, ink 254 wide, y133. Panel padding-left 12 (title) / 10 (tabs, search).
* Tabs row y171..207 (36 high pills, radius 999): **All** 300..372 active fill `#3b87fd` (gradient `#4793fd` to `#2e82fd`), white 13px/500 text, count chip 16px circle `rgba(30,30,120,.55)` = `#4c4caf` over blue, white 11px/600. Inactive: bg `#f6f9fc`, 1px `#eef3f9`, text 13px/500 `#32456a`; count chip 15px circle `#dee5f0` text `#4d5f84` 11px. Clients 385..462, Team 481..544, Groups 564..637 (gap ~16-19, text padding ~14-17).
* Search y221..259 (38) x301..603: bg `#f7fafc`, 1px `#eef3fa`, radius 10, search icon 16px `#6f84a6` at x313, placeholder 13px `#6f7fa0` at x341. Filter button x613..651: white `#fbfdfe` box, 1px `#eff4fa`, radius 10, Lucide `funnel` 16px `#14284e` (ink 15x15 at x626,y233).
* Rows start y271, pitch 69 (270.5 + 69n), full panel width, no separators visible (very faint `#f6f9fd`). **Selected row** bg `#e6f1fe`, 2px right edge `#98c0f9` (x661-662), y271..341.
* Row anatomy: avatar 44px circle at x308, vertically centred (row top + 14); presence dot 11px `#0bb47f` with 2px white ring at avatar bottom-right (ink 7x6 at x343,y316); group avatar: 44 circle `#efeafd`, Lucide `users` 20px `#6d5bd0`.
  Name x370, 13.5px/500, ink 87 wide for "Emily Johnson", colour `#02134c`; cap top row+19. Preview x369, 12.5px/400, row+40, `#61729a` (selected row `#2f65bb`), ellipsis at ~195 px. Time right-aligned to x647, 11.5px/400 `#65769a` (selected `#306ace`), row+20. Unread badge 16px circle `#f6485f`, white 10.5px/600, right edge x647, row+37 (to row+53). Group "2" badge same.
  "James Wilson" shows an inline 7px green dot after the name (online client, row 5).
* Avatars are photos in the comp (initials avatars here, tinted).

## Thread panel
* Header y77..165 (88 high) + 1px `#ebf1fa` bottom border. Avatar 52px at x698,y98. Name 17px/600 `#0b1a40` at x765, cap top y103. Status line y130: 9px dot `#16b482` at x764 + "Online" 12.5px `#6b7fa0` at x778 (hidden when offline; groups show "N members").
* Header buttons (call, video, kebab): 3 boxes 41x41 at x1360, 1410, 1461 (right edge 1501), y103..144, bg `#f6f9fc`, 1px `#edf3fa`, radius 10, Lucide `phone`, `video`, `ellipsis-vertical` 20px `#4f6389`.
* Day separator "Today": pill x1059..1112 (centre 1085: the scroll area has 28px extra right padding), y181..206, bg `#f5f8fc`, 1px `#edf3fa`, text 12.5px/500 `#5b6f94`, radius 8.
* Messages area x698..1500, first bubble top y240, bubble gap 16.
* **Incoming**: avatar 40px at x698 (bubble top + 4), bubble x751 (gap 13), bg `#f0f5fb`, 1px `#ecf2fa`, radius 14 (all corners; top-left 14), padding 12 14, max-width 380, text 14px/1.55 `#2c3c5c` (line pitch 21.5), time 11.5px `#7a8ba9` 8px below text.
* **Outgoing**: right aligned to x1500, bg `#e6f1fe`, no border, radius 14, same padding, text 14px `#1f5db8` (blue), time 11.5px `#5d7fb4` right aligned + Lucide `check-check` 14px `#3b82f6` (read) or `check` `#8aa3c8` (sent).
* Reaction chip under an incoming bubble: x751, 52x25, white, 1px `#e7f0f9`, radius 999, emoji 14px + count 12px `#4d5f84`, margin-top 4.
* Composer card y887..953 (66), x694..1503, bg `#fbfcfe`, 1px `#edf3fa`, radius 14, padding 10. Paperclip Lucide `paperclip` 20px `#5f759c` at x714; input x751..1405 (h 46, y897..943) bg `#fdfdfe`, 1px `#f0f5fd`, radius 10, placeholder 14px `#8a99b8` "Type a message..."; emoji Lucide `smile` 20px `#5f759c` at x1411-1431; send button 40x39 at x1451,y901, fill `#3a89fd`, radius 10, white Lucide `send` 18px.
* Scroll area between: bg transparent over page; thread panel bg is `#fefefe` card with faint blue wash (ignored).

## Not in the comp, specified here
Phone (<=900px): list and thread are separate views (thread view has a back button); retracted message = italic "This message was removed" in `#8a99b8`; client threads show a subtle note; empty states use `<x-ui.empty-state>`.

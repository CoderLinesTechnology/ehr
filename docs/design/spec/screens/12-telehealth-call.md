# 12 Telehealth call — design decisions (no comp)

There is no comp for this screen: it follows the telehealth screens' tokens (`public/css/screens/telehealth.css`,
section "Call page", prefix `tv-`) and the join page's (comp 11) cards. Route `app.telehealth.call`
(`GET telehealth/{session}/call`, `can:join`); only a session **in progress** has a call (an upcoming one leads to its
join page, a completed one to its completed page). Shell → `../app-shell.md`.

## Layout (1536x1024, measured on the fixture)
Grid `minmax(0,1fr) 314px`, gap 20, wrapper `margin-inline:-6px 1px` (≥70rem) like the join page.
* **Main panel** x297–1172, y100–984: bg `#fefefe`, radius 12, faint shadow, padding 3/13/13.
  * Header (y103–177): back link "← Back to session" (the join page; `tj-back`), then a row: initials avatar 44px
    (client-number tint), client name 17px/600 `#000a2a`, line "service • date • time range" 12px `#42588a`, and the
    "In progress" pill at the right (`tele-pill--warning`, as in the sessions list).
  * **Stage** x310–1159, y191–971 (849x780): radius 12, `#17212a` behind the frame, height
    `calc(100vh - topbar - 172px)` (min 420) so the page does not scroll at 1024; the iframe fills it.
* **Rail** x1192–1506 (314 wide, like comps 04/06), gap 15:
  * **Session Details** (the join page's card, compact: padding 20, rows ≥56): date & time, client, provider,
    service, location "Telehealth (Daily)". y100–442.
  * **Client link** (y457–607): Lucide `link` + "Client link" 14px/600; text 12px `#4d6389` "Send this link to
    {client}. They wait in the lobby until you admit them from the call."; full-width outline button "Copy Meeting
    Link" (copies the room's own link — the client link).
  * **Recording** — only when the organization allows recording: a pill ("Client consented" green / "Not recorded"
    grey), one line of explanation, and for viewers with clinical access the consent toggle ("Client consents to
    recording" / "Withdraw consent (stops recording)"). Changing it reloads the page and with it the pass.
  * **End session** — full-width outline danger button (`#c62828` on white, border `#f3c4c4`, Lucide `phone-off`)
    behind a confirmation dialog ("Everyone is removed from the call and the session is marked completed.").
* **Phone (<70rem)**: one column; the stage is `min(72vh, 620px)` (min 360) and the rail follows below. At 390 px:
  no horizontal scroll (`scrollWidth === innerWidth`), stage 332x608, rail from y887.

## The frame
`<iframe class="tv-frame" src="{room url}?t={pass}" title="Video call with {client}" allow="camera; microphone;
autoplay; display-capture; fullscreen" allowfullscreen referrerpolicy="no-referrer">` — Daily Prebuilt, nothing
else. No Daily script in the page (`script-src 'self'` unchanged); the page alone sends
`frame-src https://*.daily.co https://*.dailywebrtc.com https://*.dailywebrtc.net` and a Permissions-Policy that
delegates camera, microphone, display-capture, fullscreen and autoplay to the frame. The pass is minted per page
load (owner = the session's clinician or `telehealth.manage`; recording only with the organization setting, the
client's consent and clinical access) and is printed only into this `no-store` page.

## States
* Video cannot be used — demo session, not set up, the vendor unreachable, or the room's time is over: the stage
  becomes a light box (`#f7fafe`, 1px `#e3ecf8`) centring the shared notice (`partials-video-notice`), plus "Try
  again" when the vendor was unreachable. The rail stays. The page never fails because of the vendor.

## Verification
Fixture (`DAILY_FAKE=true`, `APP_FAKE_NOW="2025-04-28 09:55:00"`, Emily Johnson's session set in progress), headless
Chrome with `*.daily.co` blocked (the frame then shows the browser's blocked-page placeholder): 1536x1024 —
scrollHeight 1024 = innerHeight, geometry above; 390x844 (mobile emulation) — scrollWidth 390 = innerWidth.

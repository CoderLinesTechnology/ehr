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
    "In progress" pill, then (with a call, where `document.fullscreenEnabled`) a "Full screen" outline button
    (`tj-btn--outline`, 32 high, Lucide `maximize`; icon-only below 40rem with the label kept for screen readers).
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
  * **Leave call** — full-width outline danger button (`#c62828` on white, border `#f3c4c4`, Lucide `phone-off`): a link
    to the join page. Leaving the call is NOT finishing the session: it stays in progress, so it can be rejoined
    (or completed) from the join page. No "Leave the call?" prompt (the explicit way out).
  * **Complete session** — full-width outline in the primary blue (`#0a54ea`, border `#d8e6fb`, Lucide
    `circle-check`) behind a confirmation ("The call ends for everyone, and the session and its appointment are
    marked completed."). A session started early (inside the join window) can be completed before its scheduled
    start: finishing a visit that is under way is never "too early" (TransitionAppointment).
  * One hint line under them (11.5px `#5e6b9f`, centred): "Leaving keeps the session open so you can rejoin.
    Complete it when the visit is over."
* **Phone (<70rem)**: one column; the stage is `min(72vh, 620px)` (min 360) and the rail follows below. At 390 px:
  no horizontal scroll (`scrollWidth === innerWidth`), stage 332x608, rail from y887.

## The frame
`<iframe class="tv-frame" src="{room url}?t={pass}" title="Video call with {client}" allow="camera; microphone;
autoplay; display-capture; fullscreen" allowfullscreen referrerpolicy="no-referrer">` — Daily Prebuilt, nothing
else. No Daily script in the page (`script-src 'self'` unchanged); the page alone sends
`frame-src 'self' https://*.daily.co https://*.dailywebrtc.com https://*.dailywebrtc.net` ('self' for the app frame,
below) and a Permissions-Policy that delegates camera, microphone, display-capture, fullscreen and autoplay to the
frame. The pass is minted per page
load (owner = the session's clinician or `telehealth.manage`; recording only with the organization setting, the
client's consent and clinical access) and is printed only into this `no-store` page.

## The call window: docked, floating, full screen
The Daily frame sits in a **dock** (`.tv-dock`, `role="region"`, `aria-label="Video call with {client}"`) inside the
stage. **An iframe that is moved in the DOM reloads (the call would drop), so the dock never moves**: modes change
only its classes and inline geometry.

* **Docked** (the call view): the dock fills the stage (`position:absolute; inset:0`), its bar hidden — the page
  looks exactly as above, with or without JavaScript.
* **Floating** (`.is-floating`, while the user browses the app): `position:fixed`, z-index 301, **360x240** (bar 36 +
  video 204 ≈ 16:9), radius 12, shadow `0 14px 34px rgba(8,22,63,.3), 0 2px 8px rgba(8,22,63,.18)`, placed with
  `transform` inside `.tv-float-area` (fixed, inset 16). Phones (<40rem): **256x178** (bar 34), area inset
  12/12/`68px + safe area` (clears the framed page's 56px tab bar). Default bottom-right.
* **Window bar** (`.tv-bar`, shown floating and in full screen): `#17212a`, white client name 12.5/600 (ellipsis),
  then 30x30 icon buttons `#c9d6ea` (hover white on 12% white, focus ring `#8fbaff`): **Expand call**
  (`maximize-2`, back to the call view), **Full screen** (`maximize`, `aria-pressed`), **Move to next corner**
  (`move`; bottom-right → bottom-left → top-left → top-right, the keyboard alternative to dragging), **Leave call**
  (`phone-off`, `#ffb0b0`, hover on `#c62828`; the whole tab goes to the page being viewed in the app frame, the
  session stays in progress). Every control has a name (`aria-label` + `title`).
* **Drag**: pointer events on the bar (mouse and touch, `touch-action:none`, pointer capture; both iframes ignore
  the pointer while dragging), clamped to the float area. The position is kept as a fraction of the free space, so it
  survives resizes, and in `sessionStorage` (`wellnest:call-window`, try/catch) so it survives a refresh.
* **Full screen**: `requestFullscreen()` on the dock — never on or around a moved iframe — from the header button or
  the bar; the bar stays (name, Full screen pressed, Leave call; Expand and Move hidden). Esc or the button exits
  (`fullscreenchange` keeps `aria-pressed` and the title "Exit full screen (Esc)" in step; the visible label stays
  "Full screen", because a toggle's name must not change while it carries `aria-pressed`). Hidden when
  `document.fullscreenEnabled` is false (iPhone Safari). Daily's own full-screen control still works
  (`allow … fullscreen` + `allowfullscreen`).

## Browsing during a call (the call host)
With a call, the page is a **call host** (`.tv[data-call-host="/o/{slug}"]`, plus the call and join paths):

* **App frame**: `<iframe name="wellnest-app" class="tv-appframe" title="WellNest" hidden>` — fixed, inset 0, z-index
  300, background `--shell-main-bg`. Same origin; the pages in it are ordinary pages with their own scripts
  (no PJAX). `allow="camera 'none'; microphone 'none'; display-capture 'none'"`: a page in it never takes the camera
  from the call (a same-origin frame would otherwise inherit them).
* **Opening**: the script gives `target="wellnest-app"` to this page's same-origin GET links and GET forms that lead
  into this organization's app (sidebar, top-bar search, phone tab bar, links in the rail…). Not retargeted: this
  session's own join/call links ("Back to session" still leaves the call — after asking), new-tab/download/fragment
  links, POST forms (End session, consent, sign out), anything outside the app (account, platform, other
  organizations). On click/submit the call floats, the frame shows, everything else becomes `inert` (the page behind
  does not scroll), focus moves into the frame, and a polite live region (inside the dock) says "Call minimized —
  still connected". The tab title follows the framed page.
* **Every frame load** is checked: this session's **join or call page** → the call view comes back ("Call expanded",
  focus on the client's name heading) and the frame is blanked; a readable page **outside this organization's app**
  → the whole tab goes there (the call ends, as on sign-out); a page that **cannot be read** (it refuses frames, or the
  network failed) → `HEAD /o/{slug}` without following redirects: signed out → the whole tab reloads through the
  server (to sign-in); otherwise the frame goes back to its last page once ("That page could not be opened during
  the call."), then gives up and expands.
* **Inside the frame** (shared `public/js/app.js`, active only when framed by a call host): links and forms that leave
  this organization's app target `_top` (those pages refuse frames); a POST that leaves (sign out) first marks the host
  so it does not ask "Leave the call?".
* **The call page requested inside a frame** (`Sec-Fetch-Dest: iframe`/`frame`): no pass, no Daily — a stub
  (`call-open.blade.php`, the app shell with a small card: "This call is already open" / "Another call is open" +
  "Open this call" to the whole tab) whose script asks the host to expand when it is the host's own call. A call never
  nests or connects twice. A browser that sends no `Sec-Fetch-Dest` gets the full page, and its script removes the
  nested Daily frame at once.
* **Refresh**: the host URL stays the call URL; while floating, the frame's path+query is mirrored into `?app=`
  (`history.replaceState`). The server prints `?app=` back only when it is a relative path inside `/o/{slug}`
  (URL characters only — no scheme, `//`, backslash, quotes, fragment, control characters, dot segments or
  encoded slash/backslash/dot/control characters; ≤ 512 bytes: `CallPageRequest::appPath()`); the script reopens it
  with the call floating at the remembered position.
* **Leaving**: `beforeunload` asks while the call runs, except for Leave call, right after this page's own form
  submits (Complete session, consent, sign out), sign-out inside the frame, and the script's own redirects above.
* **Camera / microphone**: the call starts the way the join page's switches were left (pass properties
  `start_video_off` / `start_audio_off`, remembered per session in the staff member's server session so a refresh
  joins the same way); during the call they are switched with Daily Prebuilt's own controls.
* **Headers** (`SecurityHeaders`): staff-app pages (`app.*`) send `frame-ancestors 'self'` + `X-Frame-Options:
  SAMEORIGIN`; sign-in, account, platform console, webhooks and responses without a route keep `'none'` + `DENY`.
  Pages inside the frame keep their own Permissions-Policy.
* **Motion**: only the corner move glides (`transform .22s`), behind `prefers-reduced-motion: no-preference`.

## States
* Video cannot be used — demo session, not set up, the vendor unreachable, or the room's time is over: the stage
  becomes a light box (`#f7fafe`, 1px `#e3ecf8`) centring the shared notice (`partials-video-notice`), plus "Try
  again" when the vendor was unreachable. The rail stays. The page never fails because of the vendor.

## Verification
Fixture (`DAILY_FAKE=true`, `APP_FAKE_NOW="2025-04-28 09:55:00"`, Emily Johnson's session set in progress), headless
Chrome with `*.daily.co` blocked (the frame then shows the browser's blocked-page placeholder): 1536x1024 —
scrollHeight 1024 = innerHeight, geometry above; 390x844 (mobile emulation) — scrollWidth 390 = innerWidth.

Floating call (fixture as above, one of Sarah's sessions in progress; Daily's hosts mapped to a closed port and
blocked over CDP): a marker on the Daily iframe element and a count of its `load` events stay unchanged through full
screen (docked and floating), Clients via the sidebar (floating 360x240 at 1160,768), navigation inside the frame,
corner moves, drags (clamped), an unframeable page, Expand, the leave prompt, the stub and the join page landing.
Refresh with `?app=` restores the page and the corner; 390x844: window 256x178 at 122,598, no horizontal scroll in
the host or the framed page; sign-out inside the frame lands the tab on /login without a prompt.

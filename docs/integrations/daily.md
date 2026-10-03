# Daily.co — telehealth video

WellNest's telehealth calls run on [Daily](https://www.daily.co) (Daily Prebuilt, framed in WellNest's own call
page). This page is for whoever operates a WellNest installation. Code: `app/Domain/Telehealth/Daily`,
`app/Domain/Telehealth/Providers/DailyProvider.php`; contract: `docs/architecture/README.md` §12.

## How it works

* **One room per session**, created by Daily (WellNest never names it) the first time someone opens the session's
  join page. Rooms are **private with a lobby** (`privacy: private`, `enable_knocking`, Daily's pre-join screen):
  the room's link is the **client link** — a client who opens it waits until someone inside admits them. Chat is
  off (clinical conversation belongs in WellNest's audited Messages). The room opens when the join window opens
  (`telehealth.join_early_minutes`, at most 120) and expires two hours after the booked end, ejecting anyone left.
* **Staff join inside WellNest**: "Join Session" starts the session and opens the call page, which frames the room
  with a **meeting token minted for that page load** (always bound to the room, expiring with it and within four
  hours, never stored or logged). The session's clinician — and anyone holding `telehealth.manage` — is an
  **owner** and admits the client from Daily's lobby. Nobody else can admit; if no owner is in the call, the client
  keeps waiting.
* A rescheduled session **keeps its room** (the client's link still works); cancelling, a no-show, moving the
  appointment in person and ending the session **delete the room** (best effort, after the change is saved).
* No Daily script runs in WellNest's pages: the call page only frames Daily (CSP `frame-src` for `*.daily.co`,
  `*.dailywebrtc.com`, `*.dailywebrtc.net`) and delegates camera, microphone, screen sharing, fullscreen and
  autoplay to that frame. Every other page keeps camera and microphone off.
* **Demo data never reaches Daily**: demo sessions show "Demo session — video is not connected".
* **Recording** (off by default) is Daily cloud recording. It is possible only when the organization allows
  recording (Telehealth settings) **and** the client's consent is recorded for the session **and** the staff
  member may see the session's clinical content; then their token carries `enable_recording: cloud` and Daily's
  Record button. Withdrawing consent during a call stops a running recording (`POST /rooms/:name/recordings/stop`)
  and later tokens cannot record. When Daily has processed a recording it calls WellNest's webhook: with consent
  the recording is listed on the completed session (downloads use a 15-minute link minted per download, audited);
  without consent it is **deleted at Daily** and nothing is stored.

## Setup

1. **Daily account** — a paid account (webhooks and cloud recording need one). Use a **separate account per
   environment** (staging, production): an API key belongs to one domain.
2. **Before any real patient uses it: the Healthcare add-on and a BAA.** Apply for Daily's Healthcare (HIPAA)
   add-on and sign Daily's BAA (and a DPA for Ghana/EU data). HIPAA mode is switched on by Daily support. In HIPAA
   mode Daily refuses custom room names (WellNest never sets one), drops non-UUID user ids from its logs (WellNest
   sends the staff member's membership UUID), and **stores no cloud recordings itself**: configure a
   `recordings_bucket` (your own S3 bucket, at domain level with Daily support, `allow_api_access: true` so download
   links work) before switching recording on. Recordings then live in your bucket; WellNest still deletes refused
   ones through Daily's API — confirm with Daily that this removes the object from your bucket too.
3. **Environment** (`.env`, never in the repository):

   ```
   DAILY_API_KEY=…                 # Daily dashboard → Developers
   DAILY_WEBHOOK_SECRET=…          # php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
   DAILY_GEO=                      # optional media region, see below
   DAILY_FAKE=false                # true only for local development (ignored in production)
   ```

   Blank values and placeholders (`REPLACE_ME`, `changeme`, `your-api-key`, `xxx…`) count as **not set**: video
   is then "not set up" (no outbound call; staff see a notice, administrators what to configure). Organization →
   Settings → Telehealth shows the status.
4. **Deploy first, then register the webhook.** Daily calls the receiver synchronously with a signed
   `{"test":"test"}` and refuses the registration unless it answers 200 within 8 seconds:

   ```
   php artisan telehealth:daily-webhook https://YOUR-HOST/webhooks/daily
   ```

   It registers `recording.ready-to-download` and `recording.error`, your `DAILY_WEBHOOK_SECRET` as the HMAC
   secret, and **exponential retries** (Daily's default circuit breaker silently disables a webhook after three
   failures). Note the printed webhook id; re-run with `--uuid=<id>` to change the URL or re-activate it. Rotating
   the secret = change `DAILY_WEBHOOK_SECRET`, deploy, re-run with `--uuid`.
5. **Media region (`DAILY_GEO`)** — where Daily's media servers for new rooms run. Daily has **no West-Africa
   region**; for users in Ghana the candidates are `eu-west-2` (London) and `af-south-1` (Cape Town). Measure
   latency from your users before choosing, and record the choice in the compliance register (cross-border
   transfer, row 18). Unset = Daily's default routing.

## The webhook receiver

`POST /webhooks/daily` (no session, cookies, CSRF or sign-in; throttled per sender address, bodies over 256 KB
refused). The signature is checked **first**: `X-Webhook-Signature` must equal
`base64(HMAC-SHA256(base64_decode(secret), X-Webhook-Timestamp + "." + raw body))` (constant-time compare) and the
timestamp must be within ±5 minutes (seconds or milliseconds). Anything else — or any request while no secret is
configured — is a bare 401. Events for rooms WellNest does not know are acknowledged (200) and ignored: Daily's
webhooks cover the whole domain. 503 means "deliver again" (a refused recording whose deletion at Daily failed).

## Things to verify against a live Daily account

These could not be tested without a real key and are worth one supervised run per environment:

* the signature matches Daily's real deliveries byte for byte (if it does not, the registration itself fails: the
  receiver answers Daily's ping with 401 and Daily refuses with 400);
* the timestamp unit and Daily's retry behaviour with `exponential`;
* Prebuilt in a plain iframe: lobby, admit/deny, Record button, screen sharing, leaving the call;
* a refused recording disappears from the configured bucket;
* the room `exp`/`eject_at_room_exp` behaviour for a session that overruns.

#!/usr/bin/env python3
"""Headless-Chrome screenshot harness (CDP over websocket). Development only.

  python tools/visual/shoot.py --url http://127.0.0.1:8123/o/wellnest/calendar --out /tmp/shot.png \
      [--width 1536 --height 1024] [--login EMAIL [--password PW] [--totp-secret BASE32]] \
      [--full-page] [--wait-ms 400] [--mobile] [--dark]

Signs in through the real form (and the 2FA challenge when a TOTP secret is given), then captures
the viewport at device scale factor 1 with reduced motion. Fresh Chrome profile and port per run.
"""
import argparse, base64, hashlib, hmac, json, shutil, socket, struct, subprocess, sys, tempfile, time, urllib.request

import websocket  # websocket-client

CHROME = "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"


def free_port():
    s = socket.socket(); s.bind(("127.0.0.1", 0)); p = s.getsockname()[1]; s.close(); return p


def totp(secret_b32, step=30, digits=6):
    key = base64.b32decode(secret_b32.upper() + "=" * (-len(secret_b32) % 8))
    digest = hmac.new(key, struct.pack(">Q", int(time.time()) // step), hashlib.sha1).digest()
    o = digest[-1] & 0x0F
    return str((struct.unpack(">I", digest[o:o + 4])[0] & 0x7FFFFFFF) % (10 ** digits)).zfill(digits)


class CDP:
    def __init__(self, ws_url):
        self.ws = websocket.create_connection(ws_url, timeout=90, suppress_origin=True)
        self.i = 0

    def call(self, method, **params):
        self.i += 1
        my = self.i
        self.ws.send(json.dumps({"id": my, "method": method, "params": params}))
        while True:
            msg = json.loads(self.ws.recv())
            if msg.get("id") == my:
                if "error" in msg:
                    raise RuntimeError(f"{method}: {msg['error']}")
                return msg.get("result", {})

    def eval(self, expr, await_promise=False):
        r = self.call("Runtime.evaluate", expression=expr, awaitPromise=await_promise, returnByValue=True)
        return r.get("result", {}).get("value")

    def wait_for(self, expr, timeout=60):
        end = time.time() + timeout
        while time.time() < end:
            try:
                if self.eval(expr):
                    return True
            except Exception:
                pass
            time.sleep(0.2)
        return False

    def goto(self, url, timeout=90):
        self.call("Page.navigate", url=url)
        time.sleep(0.3)
        self.wait_for("document.readyState === 'complete'", timeout)
        self.eval("document.fonts ? document.fonts.ready.then(() => true) : true", await_promise=True)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--url", required=True)
    ap.add_argument("--out", required=True)
    ap.add_argument("--width", type=int, default=1536)
    ap.add_argument("--height", type=int, default=1024)
    ap.add_argument("--login")
    ap.add_argument("--password", default="password-1234")
    ap.add_argument("--totp-secret")
    ap.add_argument("--base", default="http://127.0.0.1:8123")
    ap.add_argument("--full-page", action="store_true")
    ap.add_argument("--wait-ms", type=int, default=400)
    ap.add_argument("--mobile", action="store_true")
    ap.add_argument("--dark", action="store_true")
    a = ap.parse_args()

    profile = tempfile.mkdtemp(prefix="shoot-")
    port = free_port()
    proc = subprocess.Popen([
        CHROME, "--headless=new", f"--remote-debugging-port={port}", f"--user-data-dir={profile}",
        "--no-first-run", "--no-default-browser-check", "--disable-extensions", "--disable-gpu",
        "--hide-scrollbars", "--force-device-scale-factor=1", f"--window-size={a.width},{a.height}",
        "--font-render-hinting=none", "about:blank",
    ], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    try:
        ws_url = None
        for _ in range(150):  # ~30s; never block on ps
            try:
                with urllib.request.urlopen(f"http://127.0.0.1:{port}/json/list", timeout=1) as r:
                    pages = [t for t in json.load(r) if t.get("type") == "page"]
                if pages:
                    ws_url = pages[0]["webSocketDebuggerUrl"]; break
            except Exception:
                pass
            time.sleep(0.2)
        if not ws_url:
            print("ERROR: Chrome did not expose a DevTools page target (sandboxed shell? run unsandboxed)", file=sys.stderr)
            sys.exit(2)

        c = CDP(ws_url)
        c.call("Page.enable"); c.call("Runtime.enable")
        c.call("Emulation.setDeviceMetricsOverride", width=a.width, height=a.height, deviceScaleFactor=1, mobile=a.mobile)
        c.call("Emulation.setEmulatedMedia", features=[
            {"name": "prefers-reduced-motion", "value": "reduce"},
            {"name": "prefers-color-scheme", "value": "dark" if a.dark else "light"},
        ])

        if a.login:
            c.goto(a.base + "/login")
            c.eval("""(() => { const set = (n, v) => { const el = document.querySelector(`[name="${n}"]`);
                el.value = v; el.dispatchEvent(new Event('input', {bubbles: true})); };
                set('email', %s); set('password', %s); document.querySelector('form').submit(); return true; })()"""
                   % (json.dumps(a.login), json.dumps(a.password)))
            time.sleep(1.0)
            c.wait_for("document.readyState === 'complete'")
            if a.totp_secret and c.eval("location.pathname.includes('two-factor-challenge')"):
                c.eval("""(() => { const el = document.querySelector('[name="code"]'); el.value = %s;
                    el.closest('form').submit(); return true; })()""" % json.dumps(totp(a.totp_secret)))
                time.sleep(1.0)
                c.wait_for("document.readyState === 'complete'")

        c.goto(a.url)
        time.sleep(a.wait_ms / 1000)
        if a.full_page:
            h = int(c.eval("Math.ceil(document.documentElement.scrollHeight)") or a.height)
            c.call("Emulation.setDeviceMetricsOverride", width=a.width, height=h, deviceScaleFactor=1, mobile=a.mobile)
            time.sleep(0.3)
        shot = c.call("Page.captureScreenshot", format="png")
        with open(a.out, "wb") as f:
            f.write(base64.b64decode(shot["data"]))
        print(c.eval("JSON.stringify({url: location.href, title: document.title, scrollW: document.documentElement.scrollWidth, innerW: innerWidth, fonts: [...document.fonts].filter(f => f.status === 'loaded').map(f => f.family + ' ' + f.weight).slice(0, 6)})"))
    finally:
        proc.terminate()
        try:
            proc.wait(timeout=5)
        except Exception:
            proc.kill()
        shutil.rmtree(profile, ignore_errors=True)


if __name__ == "__main__":
    main()

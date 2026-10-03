# Visual verification (development only — never deployed)

Compares rendered pages with the design comps in `docs/design/comps/`.

* `shoot.py` — headless Chrome (CDP) screenshot at an exact viewport, optionally signing in first.
* `compare.py` — comp | render | diff side-by-side image plus metrics (MAE, % differing pixels, tile SSIM), globally and per region.

Requirements: Google Chrome, Python 3 with `pillow numpy websocket-client`
(`python3 -m venv .venv && .venv/bin/pip install pillow numpy websocket-client`, kept outside the repo).

On macOS inside a sandboxed agent shell, Chrome and the PHP dev server must run unsandboxed.
Run the app with file sessions so screenshots are fast:
`SESSION_DRIVER=file CACHE_STORE=file PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8123 vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php` (from `public/`).
